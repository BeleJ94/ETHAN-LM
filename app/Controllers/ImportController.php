<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Repositories\ImportRepository;
use App\Repositories\LookupRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Services\ImportService;

/** Répertoire des licences d'importation et inspections BIVAC / OCC (AV / ARA). */
final class ImportController extends Controller
{
    /* ---------- Répertoire des licences ---------- */

    public function index(Request $request): Response
    {
        $this->authorize('orders.view');
        $repo = new ImportRepository();
        return $this->view('imports/licences', [
            'title' => t('nav.licences'),
            'licences' => $repo->licences(),
            'pending' => $repo->posWithoutLicence(),
        ]);
    }

    public function show(Request $request): Response
    {
        $this->authorize('orders.view');
        $repo = new ImportRepository();
        $lic = $this->orNotFound($repo->licence($request->intParam('id')));
        $poId = (int) $lic['po_id'];
        return $this->view('imports/licence_show', [
            'title' => $lic['number'],
            'lic' => $lic,
            'licLines' => $repo->licenceLines((int) $lic['id']),
            'lines' => (new PurchaseOrderRepository())->lines($poId),
            'summary' => $repo->summary($poId),
            'inspections' => $repo->inspections($poId),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('imports.manage');
        $repo = new ImportRepository();
        $poId = (int) $request->query('po_id', 0);
        $pos = $repo->posWithoutLicence();
        return $this->view('imports/licence_form', [
            'title' => t('imports.licence_new'),
            'lic' => null,
            'pos' => $pos,
            'poId' => $poId,
            'back' => self::backTo($request->query('back'), $poId ? "/purchase-orders/$poId#import" : '/licences'),
            'currencies' => (new LookupRepository())->currencies(),
            'banks' => (new \App\Repositories\BankRepository())->all(true),
            'goods' => self::goods(array_map(fn ($p) => (int) $p['id'], $pos)),
            'branches' => (new \App\Repositories\BankRepository())->allBranches(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('imports.manage');
        $input = $request->all();
        $back = '/licences/new' . (($input['po_id'] ?? '') !== '' ? '?po_id=' . (int) $input['po_id'] : '');
        [$data, $errors] = self::parseLicence($input, null);
        $po = isset($errors['po_id']) ? null : (new PurchaseOrderRepository())->find((int) ($input['po_id'] ?? 0));
        if (!$po) {
            $errors['po_id'] = t('validation.required');
        }
        if ($errors) {
            return $this->backWithErrors($back, $errors, $input);
        }
        try {
            $id = (new ImportService())->createLicence((int) $po['id'], $data, $_FILES['file'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($back, $e, $input);
        }
        return $this->successNext(self::backTo($input['back'] ?? null, '/purchase-orders/' . $po['id'] . '#import'), 'imports.licence_created',
            [t('dossier.next.inspection'), '/purchase-orders/' . $po['id'] . '/inspections/new']);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('imports.manage');
        $lic = $this->orNotFound((new ImportRepository())->licence($request->intParam('id')));
        return $this->view('imports/licence_form', [
            'title' => t('imports.licence_edit') . ' ' . $lic['number'],
            'lic' => $lic,
            'back' => self::backTo($request->query('back'), '/licences/' . (int) $lic['id']),
            'pos' => [],
            'poId' => (int) $lic['po_id'],
            'currencies' => (new LookupRepository())->currencies(),
            'banks' => (new \App\Repositories\BankRepository())->all(true),
            'goods' => self::goods([(int) $lic['po_id']]),
            'branches' => (new \App\Repositories\BankRepository())->allBranches(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorize('imports.manage');
        $lic = $this->orNotFound((new ImportRepository())->licence($request->intParam('id')));
        $input = $request->all();
        [$data, $errors] = self::parseLicence($input, (int) $lic['id']);
        unset($errors['po_id']);
        if ($errors) {
            return $this->backWithErrors('/licences/' . $lic['id'] . '/edit', $errors, $input);
        }
        try {
            (new ImportService())->updateLicence((int) $lic['id'], $data, $_FILES['file'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError('/licences/' . $lic['id'] . '/edit', $e, $input);
        }
        return $this->success(self::backTo($input['back'] ?? null, '/licences/' . $lic['id']), 'flash.updated');
    }

    /* ---------- Inspections ---------- */

    public function createInspection(Request $request): Response
    {
        $this->authorize('imports.manage');
        $poRepo = new PurchaseOrderRepository();
        $po = $this->orNotFound($poRepo->find($request->intParam('id')));
        $repo = new ImportRepository();
        $licence = $repo->licenceForPo((int) $po['id']);
        if (!$licence) {
            return $this->domainError('/purchase-orders/' . $po['id'] . '#import', new DomainException('imports.error.no_licence'));
        }
        $regularizes = null;
        if ($rid = (int) $request->query('regularizes', 0)) {
            $regularizes = $repo->inspection($rid);
            if (!$regularizes || (int) $regularizes['po_id'] !== (int) $po['id'] || $regularizes['decision'] !== 'ara') {
                $regularizes = null;
            }
        }
        $inspections = $repo->inspections((int) $po['id']);
        return $this->view('imports/inspection_form', [
            'title' => t($regularizes ? 'imports.regularize' : 'imports.inspection_new') . ' — ' . $po['reference'],
            'po' => $po,
            'licence' => $licence,
            'lines' => $poRepo->lines((int) $po['id']),
            'summary' => $repo->summary((int) $po['id']),
            'regularizes' => $regularizes,
            'codRef' => $repo->nextInspectionRef($licence),
            'regularizesLines' => $regularizes ? (array_values(array_filter($inspections, fn ($i) => (int) $i['id'] === (int) $regularizes['id']))[0]['lines'] ?? []) : [],
        ]);
    }

    public function storeInspection(Request $request): Response
    {
        $this->authorize('imports.manage');
        $po = $this->orNotFound((new PurchaseOrderRepository())->find($request->intParam('id')));
        $input = $request->all();
        $back = '/purchase-orders/' . $po['id'] . '/inspections/new' . (($input['regularizes_id'] ?? '') !== '' ? '?regularizes=' . (int) $input['regularizes_id'] : '');
        $v = new Validator($input, [
            'inspector' => 'required|max:120', 'inspection_date' => 'required|date', 'location' => 'max:160',
            'report_number' => 'max:80', 'report_date' => 'date', 'decision' => 'required|in:pending,av,ara',
            'decision_number' => 'max:80', 'decision_date' => 'date', 'reason' => 'max:500', 'notes' => 'max:500', 'reference' => 'max:30',
        ]);
        $lines = $this->parseLines($input, $v);
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input);
        }
        try {
            (new ImportService())->createInspection((int) $po['id'], $this->decisionData($input) + [
                'reference' => (string) ($input['reference'] ?? ''), 'reference_suggested' => (string) ($input['reference_suggested'] ?? ''),
                'inspector' => $input['inspector'], 'inspection_date' => $input['inspection_date'], 'location' => self::nul($input['location'] ?? null),
                'regularizes_id' => self::nul($input['regularizes_id'] ?? null) !== null ? (int) $input['regularizes_id'] : null,
                'notes' => self::nul($input['notes'] ?? null),
            ], $lines, $_FILES['file'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($back, $e, $input, str_starts_with($e->getMessage(), 'imports.error.inspection_ref') ? 'reference' : null);
        }
        return $this->successNext('/purchase-orders/' . $po['id'] . '#import', 'imports.inspection_created',
            ($input['decision'] ?? '') === 'av' ? [t('dossier.next.delivery'), '/purchase-orders/' . $po['id'] . '/deliveries/new'] : null);
    }

    public function decide(Request $request): Response
    {
        $this->authorize('imports.manage');
        $repo = new ImportRepository();
        $insp = $this->orNotFound($repo->inspection($request->intParam('id')));
        $this->orNotFound((new PurchaseOrderRepository())->find((int) $insp['po_id']));
        $back = '/purchase-orders/' . $insp['po_id'] . '#import';
        try {
            (new ImportService())->recordDecision((int) $insp['id'], $this->decisionData($request->all()), $_FILES['file'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        $av = ($request->all()['decision'] ?? '') === 'av';
        return $this->successNext($back, 'imports.decision_recorded', $av ? [t('dossier.next.delivery'), '/purchase-orders/' . $insp['po_id'] . '/deliveries/new'] : null);
    }

    /** Joindre (ou remplacer) le rapport d'inspection après coup. */
    public function attachReport(Request $request): Response
    {
        $this->authorize('imports.manage');
        $insp = $this->orNotFound((new ImportRepository())->inspection($request->intParam('id')));
        $this->orNotFound((new PurchaseOrderRepository())->find((int) $insp['po_id']));
        $back = '/purchase-orders/' . $insp['po_id'] . '#import';
        try {
            (new ImportService())->attachReport((int) $insp['id'], $_FILES['file'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($back, $e, [], 'file');
        }
        return $this->success($back, 'imports.report_attached');
    }

    /** @return array<string,mixed> */
    private function decisionData(array $input): array
    {
        return [
            'report_number' => self::nul($input['report_number'] ?? null),
            'report_date' => self::nul($input['report_date'] ?? null),
            'decision' => (string) ($input['decision'] ?? 'pending'),
            'decision_number' => self::nul($input['decision_number'] ?? null),
            'decision_date' => self::nul($input['decision_date'] ?? null),
            'reason' => self::nul($input['reason'] ?? null),
        ];
    }

    /** @return array<int,array{inspected:float,conform:float}> */
    private function parseLines(array $input, Validator $v): array
    {
        $out = [];
        foreach ((array) ($input['lines'] ?? []) as $lineId => $row) {
            $ins = $row['inspected'] ?? '';
            if ($ins === '' || (float) $ins == 0.0) {
                continue;
            }
            $conf = ($row['conform'] ?? '') === '' ? $ins : $row['conform'];
            if (!is_numeric($ins) || !is_numeric($conf)) {
                $v->addError('lines', t('imports.error.bad_quantities'));
                continue;
            }
            $out[(int) $lineId] = ['inspected' => round((float) $ins, 3), 'conform' => round((float) $conf, 3)];
        }
        return $out;
    }

    /**
     * Validation commune à la saisie manuelle et à l'import de document.
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    /** @param list<int> $poIds @return array<int,list<array<string,mixed>>> marchandises par PO fournisseur (code SH inclus) */
    private static function goods(array $poIds): array
    {
        $repo = new PurchaseOrderRepository();
        $out = [];
        foreach ($poIds as $id) {
            $out[$id] = $repo->lines($id);
        }
        return $out;
    }

    public static function parseLicence(array $input, ?int $id): array
    {
        $input['number'] = mb_strtoupper(trim((string) ($input['number'] ?? '')));
        $v = new Validator($input, [
            'po_id' => 'required|int', 'number' => 'required|max:60', 'issued_at' => 'required|date', 'expires_at' => 'required|date',
            'origin_country' => 'max:80', 'currency' => 'required|max:3', 'amount' => 'numeric|min:0', 'freight' => 'numeric|min:0', 'insurance' => 'numeric|min:0', 'other_costs' => 'numeric|min:0', 'bank' => 'max:120', 'bank_id' => 'int', 'branch_id' => 'int', 'notes' => 'max:500',
        ]);
        if (!isset($v->errors()['number']) && (new ImportRepository())->numberExists($input['number'], $id)) {
            $v->addError('number', t('validation.unique'));
        }
        if (!isset($v->errors()['currency']) && !(new LookupRepository())->currencyExists((string) $input['currency'])) {
            $v->addError('currency', t('validation.in'));
        }
        // N° COD : format officiel « COD AAAA NNNN », unique ; il sert de racine aux n° d'inspection / AV / ARA
        $cod = trim((string) ($input['cod_number'] ?? ''));
        if ($cod !== '') {
            $norm = \App\Domain\LicenceTextParser::normalizeCod($cod);
            if ($norm === null) {
                $v->addError('cod_number', t('imports.error.cod_format'));
            } elseif ((new ImportRepository())->codExists($norm, $id)) {
                $v->addError('cod_number', t('validation.unique'));
            }
            $cod = $norm ?? $cod;
        }
        // Banque / agence du référentiel ; une banque ou agence désactivée reste acceptée si c'est déjà celle de la licence
        $banks = new \App\Repositories\BankRepository();
        $current = $id ? (new ImportRepository())->licence($id) : null;
        $bankId = ($input['bank_id'] ?? '') !== '' ? (int) $input['bank_id'] : null;
        $branchId = ($input['branch_id'] ?? '') !== '' ? (int) $input['branch_id'] : null;
        $bankText = mb_substr(trim((string) ($input['bank'] ?? '')), 0, 120) ?: null;
        if ($bankId !== null) {
            $bank = $banks->find($bankId);
            if (!$bank || (!$bank['active'] && $bankId !== (int) ($current['bank_id'] ?? 0))) {
                $v->addError('bank_id', t('validation.in'));
            } else {
                $bankText = $bank['name'];
            }
        }
        if ($branchId !== null) {
            $branch = $banks->findBranch($branchId);
            if ($bankId === null || !$branch || (int) $branch['bank_id'] !== $bankId || (!$branch['active'] && $branchId !== (int) ($current['branch_id'] ?? 0))) {
                $v->addError('branch_id', t('banks.error.branch_mismatch'));
            } elseif ($bankId !== null && !isset($v->errors()['bank_id'])) {
                $bankText .= ' — ' . $branch['name'];
            }
        }
        return [[
            'number' => $input['number'],
            'issued_at' => $input['issued_at'] ?? '',
            'expires_at' => $input['expires_at'] ?? '',
            'origin_country' => self::nul($input['origin_country'] ?? null),
            'currency' => (string) ($input['currency'] ?? ''),
            'amount' => self::nul($input['amount'] ?? null) !== null ? round((float) $input['amount'], 2) : null,
            'freight' => self::nul($input['freight'] ?? null) !== null ? round((float) $input['freight'], 2) : null,
            'insurance' => self::nul($input['insurance'] ?? null) !== null ? round((float) $input['insurance'], 2) : null,
            'other_costs' => self::nul($input['other_costs'] ?? null) !== null ? round((float) $input['other_costs'], 2) : null,
            'cod_number' => $cod !== '' ? $cod : null,
            'bank' => $bankText,
            'bank_id' => $bankId,
            'branch_id' => $branchId,
            'notes' => self::nul($input['notes'] ?? null),
        ], $v->errors()];
    }
}
