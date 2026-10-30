<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTable;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Domain\OrderProgress;
use App\Repositories\InvoiceRepository;
use App\Repositories\OrderRepository;
use App\Services\InvoiceService;

/** Suivi des factures et paiements fournisseurs (à payer) et clients (à encaisser). */
final class InvoiceController extends Controller
{
    public const METHODS = ['transfer', 'cash', 'cheque', 'mobile', 'other'];

    /** Filtres de la liste (onglet + filtres compacts + volet « Plus de filtres »), repris de l'adresse. */
    private const FILTERS = ['status', 'direction', 'party', 'site_id', 'currency', 'vat', 'delivery', 'payment', 'from', 'to', 'due_from', 'due_to'];

    /** Factures clients (à encaisser). */
    public function customers(Request $request): Response
    {
        return $this->index($request, 'receivable');
    }

    /** Factures fournisseurs (à payer). */
    public function suppliers(Request $request): Response
    {
        return $this->index($request, 'payable');
    }

    /** Ancienne page commune : les liens existants mènent à la page du sens demandé (clients par défaut). */
    public function legacy(Request $request): Response
    {
        $this->authorize('finance.view');
        $query = array_diff_key($_GET, ['direction' => 1]);
        $path = $request->query('direction') === 'payable' ? '/finance/suppliers' : '/finance/customers';
        return Response::redirect($path . ($query ? '?' . http_build_query($query) : ''));
    }

    private function index(Request $request, string $direction): Response
    {
        $this->authorize('finance.view');
        $filters = ['direction' => $direction] + self::filters($request);
        // Onglet par défaut : « À régler » (lien direct ou retour arrière : onglet de l'adresse)
        $filters['status'] = (string) $request->query('status', 'open');
        $repo = new InvoiceRepository();
        return $this->view('finance/index', [
            'title' => t($direction === 'receivable' ? 'nav.invoices_customers' : 'nav.invoices_suppliers'),
            'direction' => $direction,
            'outstanding' => $repo->outstanding()[$direction],
            'counts' => $repo->tabCounts($filters),
            'parties' => $repo->parties()[$direction === 'receivable' ? 'customers' : 'suppliers'],
            'sites' => (new \App\Repositories\SiteRepository())->allowed(),
            'currencies' => (new \App\Repositories\LookupRepository())->currencies(),
            'filters' => $filters,
        ]);
    }

    public function data(Request $request): Response
    {
        $this->authorize('finance.view');
        $dt = new DataTable($request, InvoiceRepository::SORTABLE, 'i.due_date ASC');
        $filters = self::filters($request);
        // Chaque page ne liste qu'un sens (clients ou fournisseurs)
        if (!in_array($filters['direction'], ['receivable', 'payable'], true)) {
            throw new \App\Core\HttpException(404);
        }
        [$total, $filtered, $rows] = (new InvoiceRepository())->datatable($dt, $filters);
        return $dt->response($total, $filtered, $rows);
    }

    /** @return array<string,string> */
    private static function filters(Request $request): array
    {
        $out = [];
        foreach (self::FILTERS as $k) {
            $out[$k] = (string) $request->query($k, '');
        }
        return $out;
    }

    /** Fiche finance d'une facture (client ou fournisseur). */
    public function show(Request $request): Response
    {
        $this->authorize('finance.view');
        $inv = $this->orNotFound((new InvoiceRepository())->details($request->intParam('id')));
        // Facture en attente d'approbation : expédition chargée pour le formulaire de modification
        $delivery = $inv['status'] === 'draft' && $inv['delivery_id'] !== null
            ? (new \App\Repositories\PurchaseOrderRepository())->findDelivery((int) $inv['delivery_id']) : null;
        return $this->view('finance/show', ['title' => $inv['reference'], 'inv' => $inv, 'delivery' => $delivery]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('finance.manage');
        $order = $this->orNotFound((new OrderRepository())->find($request->intParam('id')));
        $input = $request->all();
        $back = self::backTo($input['back'] ?? null, '/orders/' . $order['id'] . '#finance');
        $receivable = ($input['direction'] ?? '') === 'receivable';
        $v = new Validator($input, [
            'reference' => 'max:30', 'direction' => 'required|in:payable,receivable', 'invoice_date' => 'required|date', 'due_date' => 'date',
            // Facture client : montant = somme des lignes
            'amount' => $receivable ? '' : 'required|numeric|gt:0', 'external_number' => 'max:80', 'notes' => 'max:500',
            'po_id' => 'int', 'delivery_id' => 'int',
        ]);
        if ($receivable && ($input['delivery_id'] ?? '') === '') {
            $v->addError('delivery_id', t('invoice.error.delivery_required'));
        }
        if (($input['direction'] ?? '') === 'payable' && ($input['po_id'] ?? '') === '') {
            $v->addError('po_id', t('invoice.error.po_required'));
        }
        if (($input['direction'] ?? '') === 'payable' && ($input['external_number'] ?? '') === '') {
            $v->addError('external_number', t('validation.required'));
        }
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input, implode(' ', $v->errors()));
        }
        $terms = (int) $order['payment_terms_days'];
        if ($input['direction'] === 'payable') {
            foreach ((new OrderRepository())->purchaseOrders((int) $order['id']) as $po) {
                if ((int) $po['id'] === (int) $input['po_id']) {
                    $terms = (int) $po['payment_terms_days'];
                }
            }
        }
        try {
            (new InvoiceService())->create((int) $order['id'], [
                'direction' => $input['direction'],
                'reference' => (string) ($input['reference'] ?? ''),
                'reference_suggested' => (string) ($input['reference_suggested'] ?? ''),
                'po_id' => self::nul($input['po_id'] ?? null) !== null ? (int) $input['po_id'] : null,
                'delivery_id' => $receivable ? (int) $input['delivery_id'] : null,
                'external_number' => self::nul($input['external_number'] ?? null),
                'invoice_date' => $input['invoice_date'],
                'due_date' => self::nul($input['due_date'] ?? null) ?? OrderProgress::dueDate($input['invoice_date'], $terms),
                'amount' => $receivable ? null : round((float) $input['amount'], 2),
                'lines' => $receivable ? self::lines($input['lines'] ?? []) : [],
                'vat_rate' => $receivable ? self::vatRate($input) : null,
                'notes' => self::nul($input['notes'] ?? null),
            ]);
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, $receivable ? 'invoice.created_draft' : 'invoice.created');
    }

    /** Facture client imprimable (A4) : aperçu en modale, impression, enregistrement PDF. */
    public function print(Request $request): Response
    {
        $this->authorize('finance.view');
        $inv = $this->orNotFound((new InvoiceRepository())->details($request->intParam('id')));
        if ($inv['direction'] !== 'receivable') {
            throw new \App\Core\HttpException(404);
        }
        $order = $this->orNotFound((new OrderRepository())->find((int) $inv['order_id']));
        return $this->document($inv, $order, $inv['delivery_id'] !== null ? (int) $inv['delivery_id'] : null, false);
    }

    /** Aperçu avant enregistrement : le formulaire de facturation est rendu sur le gabarit, sans rien enregistrer. */
    public function preview(Request $request): Response
    {
        $this->authorize('finance.manage');
        $order = $this->orNotFound((new OrderRepository())->find($request->intParam('id')));
        $input = $request->all();
        $deliveryId = (int) ($input['delivery_id'] ?? 0);
        $date = (string) ($input['invoice_date'] ?? '') ?: date('Y-m-d');
        try {
            $lines = InvoiceService::receivableLines($deliveryId, self::lines($input['lines'] ?? []));
            $totals = InvoiceService::totals($lines, self::vatRate($input));
        } catch (DomainException $e) {
            return $this->view('finance/print_error', ['title' => t('invoice.preview'), 'message' => t($e->getMessage(), $e->params)], 'print');
        }
        $reference = trim((string) ($input['reference'] ?? '')) ?: (string) ($input['reference_suggested'] ?? '');
        $inv = $totals + [
            'reference' => $reference, 'invoice_date' => $date, 'currency' => $order['currency'], 'paid' => 0,
            'due_date' => self::nul($input['due_date'] ?? null) ?? OrderProgress::dueDate($date, (int) $order['payment_terms_days']),
            'notes' => self::nul(trim((string) ($input['notes'] ?? ''))), 'status' => 'open', 'lines' => $lines, 'created_by_name' => $this->user()->name,
        ];
        return $this->document($inv, $order, $deliveryId, true);
    }

    /** Données complètes du document : société, client, dossier, expédition (transport, réception, licence / AV), lignes. */
    private function document(array $inv, array $order, ?int $deliveryId, bool $preview): Response
    {
        $delivery = $deliveryId !== null ? (new \App\Repositories\PurchaseOrderRepository())->findDelivery($deliveryId) : null;
        if ($delivery !== null && (int) $delivery['order_id'] !== (int) $order['id']) {
            throw new \App\Core\HttpException(404);
        }
        $licence = null;
        $avs = [];
        if ($delivery !== null && $order['fulfillment_mode'] === 'import') {
            $licence = (new \App\Repositories\ImportRepository())->licenceForPo((int) $delivery['po_id']);
            foreach ($delivery['lines'] as $l) {
                foreach ($l['avs'] as $a) {
                    $avs[$a['inspection_id']] = $a['decision_number'] ?: $a['reference'];
                }
            }
        }
        return $this->view('finance/print', [
            'title' => ($preview ? t('invoice.preview') . ' — ' : '') . $inv['reference'],
            'inv' => $inv,
            'order' => $order,
            'customer' => (new \App\Repositories\PartyRepository('customer'))->find((int) $order['customer_id']),
            'delivery' => $delivery,
            'licence' => $licence,
            'avs' => array_values($avs),
            'company' => PurchaseOrderController::company(),
            'preview' => $preview,
        ], 'print');
    }

    /** Lignes saisies (lines[i][delivery_line_id|description|quantity|unit_price]) ; les lignes entièrement vides sont ignorées. */
    /** TVA cochée → taux saisi (un taux invalide est refusé par le service) ; non cochée → sans TVA. */
    private static function vatRate(array $input): ?float
    {
        if (empty($input['vat_enabled'])) {
            return null;
        }
        $rate = trim((string) ($input['vat_rate'] ?? ''));
        return is_numeric($rate) ? (float) $rate : -1.0;
    }

    private static function lines(mixed $raw): array
    {
        $lines = [];
        foreach (is_array($raw) ? $raw : [] as $l) {
            if (!is_array($l)) {
                continue;
            }
            $desc = trim((string) ($l['description'] ?? ''));
            $qty = trim((string) ($l['quantity'] ?? ''));
            $price = trim((string) ($l['unit_price'] ?? ''));
            if ($desc === '' && $qty === '' && $price === '') {
                continue;
            }
            $lines[] = [
                'delivery_line_id' => ctype_digit((string) ($l['delivery_line_id'] ?? '')) ? (int) $l['delivery_line_id'] : null,
                'description' => $desc,
                'quantity' => is_numeric($qty) ? (float) $qty : 0.0,
                'unit_price' => is_numeric($price) ? (float) $price : -1.0,
            ];
        }
        return $lines;
    }

    /** Modification d'une facture client en attente d'approbation. */
    public function update(Request $request): Response
    {
        $this->authorize('finance.manage');
        $inv = $this->orNotFound((new InvoiceRepository())->find($request->intParam('id')));
        $input = $request->all();
        $back = self::backTo($input['back'] ?? null, '/invoices/' . $inv['id']);
        $v = new Validator($input, ['invoice_date' => 'required|date', 'due_date' => 'date', 'notes' => 'max:500']);
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input, implode(' ', $v->errors()));
        }
        $order = $this->orNotFound((new OrderRepository())->find((int) $inv['order_id']));
        try {
            (new InvoiceService())->update((int) $inv['id'], [
                'invoice_date' => $input['invoice_date'],
                'due_date' => self::nul($input['due_date'] ?? null) ?? OrderProgress::dueDate($input['invoice_date'], (int) $order['payment_terms_days']),
                'lines' => self::lines($input['lines'] ?? []),
                'vat_rate' => self::vatRate($input),
                'notes' => self::nul(trim((string) ($input['notes'] ?? ''))),
            ]);
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'invoice.updated');
    }

    /** Approbation : la facture devient « à régler » (encaissements possibles). */
    public function approve(Request $request): Response
    {
        $this->authorize('finance.approve');
        $inv = $this->orNotFound((new InvoiceRepository())->find($request->intParam('id')));
        $back = self::backFor($request, $inv);
        try {
            (new InvoiceService())->approve((int) $inv['id']);
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'invoice.approved');
    }

    public function pay(Request $request): Response
    {
        $this->authorize('finance.manage');
        $inv = $this->orNotFound((new InvoiceRepository())->find($request->intParam('id')));
        $back = self::backFor($request, $inv);
        $input = $request->all();
        $v = new Validator($input, ['paid_at' => 'required|date', 'amount' => 'required|numeric|gt:0',
            'method' => 'required|in:' . implode(',', self::METHODS), 'bank_ref' => 'max:120', 'notes' => 'max:500']);
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input, implode(' ', $v->errors()));
        }
        try {
            (new InvoiceService())->pay((int) $inv['id'], [
                'paid_at' => $input['paid_at'], 'amount' => round((float) $input['amount'], 2), 'method' => $input['method'],
                'bank_ref' => self::nul($input['bank_ref'] ?? null), 'notes' => self::nul($input['notes'] ?? null),
            ]);
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'invoice.payment_recorded');
    }

    /** Pièce jointe de la facture (scan, avis de virement…), rattachée à son dossier. */
    public function attach(Request $request): Response
    {
        $this->authorize('finance.manage');
        $inv = $this->orNotFound((new InvoiceRepository())->find($request->intParam('id')));
        $back = '/invoices/' . $inv['id'] . '#documents';
        try {
            (new \App\Services\AttachmentService())->store((int) $inv['order_id'], 'invoice', (int) $inv['id'], $_FILES['file'] ?? [],
                self::nul(mb_substr(trim((string) $request->input('label', '')), 0, 160)));
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'attachment.uploaded');
    }

    /** Retour après action : fiche facture, liste finance ou dossier. */
    private static function backFor(Request $request, array $inv): string
    {
        return match ($request->input('back')) {
            'invoice' => '/invoices/' . $inv['id'],
            'finance' => $inv['direction'] === 'receivable' ? '/finance/customers' : '/finance/suppliers',
            default => '/orders/' . $inv['order_id'] . '#finance',
        };
    }

    public function cancel(Request $request): Response
    {
        $this->authorize('finance.manage');
        $inv = $this->orNotFound((new InvoiceRepository())->find($request->intParam('id')));
        $back = self::backFor($request, $inv);
        try {
            $note = trim((string) $request->input('note', ''));
            if ($note === '') {
                throw new DomainException('orders.error.note_required');
            }
            (new InvoiceService())->cancel((int) $inv['id'], mb_substr($note, 0, 300));
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'invoice.cancelled');
    }
}
