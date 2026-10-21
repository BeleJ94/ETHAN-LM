<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\DomainException;
use App\Domain\LicenceReview;
use App\Services\Licence\LicenceImportService;
use App\Services\Licence\PdfTextReader;

/**
 * « Importer depuis un document » : téléverser → extraire → vérifier / corriger → confirmer.
 * Toutes les actions exigent imports.manage ; un brouillon n'est visible que par son auteur.
 */
final class LicenceImportController extends Controller
{
    public function create(Request $request): Response
    {
        $this->authorize('imports.manage');
        return $this->view('imports/import_upload', [
            'title' => t('licence_import.title'),
            'maxMb' => LicenceImportService::MAX_BYTES / 1024 / 1024,
            'poId' => (int) $request->query('po_id', 0) ?: null,
            'ocr' => PdfTextReader::ocrAvailable(),
        ]);
    }

    public function upload(Request $request): Response
    {
        $this->authorize('imports.manage');
        try {
            $token = (new LicenceImportService())->upload($_FILES['file'] ?? []);
            if ((int) ($request->all()['po_id'] ?? 0) > 0) {
                \App\Core\Session::set('licence_import_po.' . $token, (int) $request->all()['po_id']);
            }
        } catch (DomainException $e) {
            return $this->domainError('/licences/import', $e);
        }
        return Response::redirect('/licences/import/' . $token);
    }

    public function review(Request $request): Response
    {
        $this->authorize('imports.manage');
        $service = new LicenceImportService();
        $draft = $this->draft($request);
        if ($draft['status'] === 'confirmed') {
            return $this->success('/licences/' . (int) $draft['licence_id'], 'licence_import.already_confirmed');
        }
        if ($draft['status'] !== 'draft' || !is_file($service->path($draft))) {
            return $this->domainError('/licences/import', new DomainException('licence_import.error.expired'));
        }
        $proposal = $service->proposal($draft);
        // Lancé depuis un PO fournisseur : ce PO est retenu (sauf correction déjà saisie)
        $ctxPo = (int) \App\Core\Session::get('licence_import_po.' . $draft['token'], 0);
        if ($ctxPo && !is_array($draft['corrections']) && in_array($ctxPo, array_map(fn ($p) => (int) $p['id'], $proposal['pos']), true)) {
            $proposal['data']['po_id'] = $ctxPo;
            $proposal['status']['po_id'] = 'extracted';
        }
        return $this->view('imports/import_review', [
            'title' => t('licence_import.review_title'),
            'draft' => $draft,
            'p' => $proposal,
            'issues' => $service->issues($draft, $proposal['data'], $proposal['lines']),
            'currencies' => (new \App\Repositories\LookupRepository())->currencies(),
            'banks' => (new \App\Repositories\BankRepository())->all(true),
            'branches' => (new \App\Repositories\BankRepository())->allBranches(),
        ]);
    }

    /** Document source, servi uniquement à l'auteur du brouillon (aperçu pendant la vérification). */
    public function document(Request $request): Response
    {
        $this->authorize('imports.manage');
        $draft = $this->draft($request);
        $path = (new LicenceImportService())->path($draft);
        if (!is_file($path)) {
            throw new HttpException(404);
        }
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . str_replace(['"', "\r", "\n"], '', $draft['original_name']) . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Sauvegarde automatique des corrections + contrôles recalculés (affichés en direct). */
    public function save(Request $request): Response
    {
        $this->authorize('imports.manage');
        $service = new LicenceImportService();
        $draft = $this->draft($request);
        [$data, $lines] = self::readForm($request->all());
        $service->saveCorrections($draft, $data, $lines);
        return Response::json(['ok' => true, 'issues' => self::presentIssues($service->issues($draft, $data, $lines))]);
    }

    public function confirm(Request $request): Response
    {
        $this->authorize('imports.manage');
        $service = new LicenceImportService();
        $draft = $this->draft($request);
        // Double soumission (double clic, rejeu) : pas de nouvelle validation, on renvoie la licence déjà créée
        if ($draft['status'] === 'confirmed') {
            return $this->success('/licences/' . (int) $draft['licence_id'], 'licence_import.already_confirmed');
        }
        $back = '/licences/import/' . $draft['token'];
        $input = $request->all();
        [$data, $lines] = self::readForm($input);
        // Mêmes validations que la création manuelle…
        [$valid, $errors] = ImportController::parseLicence($input, null);
        // …et mêmes contrôles de cohérence (bloquants) que ceux affichés pendant la vérification
        foreach ($service->issues($draft, $valid + ['po_id' => $data['po_id']], $lines) as $issue) {
            if ($issue['level'] === 'error' && $issue['field'] && !isset($errors[$issue['field']])) {
                $errors[$issue['field']] = t('licence_import.issue.' . $issue['code'], $issue['params']);
            }
        }
        if (empty($input['confirm_ack'])) {
            $errors['confirm_ack'] = t('licence_import.error.ack');
        }
        if ($errors) {
            $service->saveCorrections($draft, $data, $lines);
            return $this->backWithErrors($back, $errors, $input);
        }
        try {
            $res = $service->confirm($draft['token'], $valid + ['po_id' => $data['po_id']], $lines);
        } catch (DomainException $e) {
            $service->saveCorrections($draft, $data, $lines);
            return $this->domainError($back, $e, $input);
        }
        $poId = (int) $data['po_id'];
        return $this->successNext('/purchase-orders/' . $poId . '#import', $res['already'] ? 'licence_import.already_confirmed' : 'licence_import.created',
            [t('dossier.next.inspection'), '/purchase-orders/' . $poId . '/inspections/new']);
    }

    public function discard(Request $request): Response
    {
        $this->authorize('imports.manage');
        (new LicenceImportService())->discard($this->draft($request));
        return $this->success('/licences', 'licence_import.discarded');
    }

    /** @return array<string,mixed> */
    private function draft(Request $request): array
    {
        return (new LicenceImportService())->find($request->param('token')) ?? throw new HttpException(404);
    }

    /** @return array{0:array<string,mixed>,1:list<array<string,mixed>>} champs de la fiche + lignes (valeurs vides → null) */
    private static function readForm(array $input): array
    {
        $num = fn ($v) => ($v ?? '') === '' ? null : (is_numeric(str_replace([' ', ','], ['', '.'], (string) $v)) ? round((float) str_replace([' ', ','], ['', '.'], (string) $v), 6) : null);
        $data = [
            'po_id' => ($input['po_id'] ?? '') !== '' ? (int) $input['po_id'] : null,
            'number' => mb_strtoupper(trim((string) ($input['number'] ?? ''))) ?: null,
            'issued_at' => self::nul($input['issued_at'] ?? null),
            'expires_at' => self::nul($input['expires_at'] ?? null),
            'origin_country' => self::nul($input['origin_country'] ?? null),
            'cod_number' => self::nul($input['cod_number'] ?? null),
            'currency' => self::nul($input['currency'] ?? null),
            'amount' => $num($input['amount'] ?? null),
            'freight' => $num($input['freight'] ?? null),
            'insurance' => $num($input['insurance'] ?? null),
            'other_costs' => $num($input['other_costs'] ?? null),
            'bank' => self::nul($input['bank'] ?? null),
            'bank_id' => ($input['bank_id'] ?? '') !== '' ? (int) $input['bank_id'] : null,
            'branch_id' => ($input['branch_id'] ?? '') !== '' ? (int) $input['branch_id'] : null,
            'notes' => self::nul($input['notes'] ?? null),
        ];
        $lines = [];
        foreach (array_values((array) ($input['lines'] ?? [])) as $l) {
            if (!is_array($l) || trim(implode('', array_map('strval', $l))) === '') {
                continue;
            }
            $lines[] = [
                'description' => mb_substr(trim((string) ($l['description'] ?? '')), 0, 255),
                'hs_code' => self::nul(mb_substr(trim((string) ($l['hs_code'] ?? '')), 0, 20)),
                'quantity' => $num($l['quantity'] ?? null),
                'unit' => self::nul(mb_substr(trim((string) ($l['unit'] ?? '')), 0, 20)),
                'unit_price' => $num($l['unit_price'] ?? null),
                'amount' => $num($l['amount'] ?? null),
            ];
        }
        return [$data, $lines];
    }

    /** @return list<array{level:string,field:?string,message:string}> */
    public static function presentIssues(array $issues): array
    {
        return array_map(fn ($i) => ['level' => $i['level'], 'field' => $i['field'], 'message' => t('licence_import.issue.' . $i['code'], $i['params'] + ['field' => $i['field'] ? t('licence_import.field.' . $i['field']) : ''])], $issues);
    }
}
