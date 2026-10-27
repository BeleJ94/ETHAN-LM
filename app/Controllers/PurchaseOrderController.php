<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Repositories\LookupRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PartyRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Services\PurchasingService;

/** Sourcing (devis) et PO fournisseurs. */
final class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('orders.view');
        $status = (string) $request->query('status', 'open');
        return $this->view('purchase_orders/index', [
            'title' => t('nav.purchase_orders'),
            'pos' => (new PurchaseOrderRepository())->all($status),
            'status' => $status,
        ]);
    }

    /* ---------- Sourcing ---------- */

    public function storeQuote(Request $request): Response
    {
        $this->authorize('sourcing.manage');
        $order = $this->orNotFound((new OrderRepository())->find($request->intParam('id')));
        $back = '/orders/' . $order['id'] . '#sourcing';
        $input = $request->all();
        $v = new Validator($input, ['supplier_id' => 'required|int', 'quote_date' => 'required|date', 'currency' => 'required|max:3',
            'quote_ref' => 'max:80', 'lead_time_days' => 'int|min:0', 'valid_until' => 'date', 'notes' => 'max:500']);
        $supplier = isset($v->errors()['supplier_id']) ? null : (new PartyRepository('supplier'))->find((int) $input['supplier_id']);
        if (!$supplier) {
            $v->addError('supplier_id', t('validation.in'));
        }
        if (!isset($v->errors()['currency']) && !(new LookupRepository())->currencyExists((string) $input['currency'])) {
            $v->addError('currency', t('validation.in'));
        }
        $prices = [];
        foreach ((array) ($input['prices'] ?? []) as $lineId => $price) {
            if ($price !== '' && is_numeric($price) && (float) $price >= 0) {
                $prices[(int) $lineId] = (float) $price;
            }
        }
        $quantities = [];
        foreach ((array) ($input['quantities'] ?? []) as $lineId => $qty) {
            if (!isset($prices[(int) $lineId]) || $qty === '') {
                continue;
            }
            if (!is_numeric($qty) || (float) $qty <= 0) {
                $v->addError('quantities', t('sourcing.error.bad_qty'));
                continue;
            }
            $quantities[(int) $lineId] = round((float) $qty, 3);
        }
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input, implode(' ', $v->errors()));
        }
        try {
            (new PurchasingService())->addQuote((int) $order['id'], [
                'supplier_id' => (int) $supplier['id'], 'supplier_name' => $supplier['name'],
                'quote_ref' => self::nul($input['quote_ref'] ?? null), 'quote_date' => $input['quote_date'], 'currency' => $input['currency'],
                'lead_time_days' => self::nul($input['lead_time_days'] ?? null), 'valid_until' => self::nul($input['valid_until'] ?? null),
                'notes' => self::nul($input['notes'] ?? null),
            ], $prices, $quantities);
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'sourcing.quote_added');
    }

    public function rejectQuote(Request $request): Response
    {
        $this->authorize('sourcing.manage');
        $order = $this->orNotFound((new OrderRepository())->find($request->intParam('id')));
        try {
            (new PurchasingService())->rejectQuote((int) $order['id'], $request->intParam('quote_id'));
        } catch (DomainException $e) {
            return $this->domainError('/orders/' . $order['id'] . '#sourcing', $e);
        }
        return $this->success('/orders/' . $order['id'] . '#sourcing', 'sourcing.quote_rejected');
    }

    /* ---------- PO fournisseur ---------- */

    public function create(Request $request): Response
    {
        $this->authorize('sourcing.manage');
        $repo = new OrderRepository();
        $order = $this->orNotFound($repo->find($request->intParam('id')));
        $quote = null;
        if ($quoteId = (int) $request->query('quote_id', 0)) {
            $quote = $this->orNotFound($repo->findQuote($quoteId, (int) $order['id']));
        }
        return $this->form($order, null, $quote, []);
    }

    public function store(Request $request): Response
    {
        $this->authorize('sourcing.manage');
        $order = $this->orNotFound((new OrderRepository())->find($request->intParam('id')));
        $back = '/orders/' . $order['id'] . '/purchase-orders/new' . ($request->input('quote_id') ? '?quote_id=' . (int) $request->input('quote_id') : '');
        [$data, $lines, $errors, $input] = $this->parse($request, $order);
        if ($errors) {
            return $this->backWithErrors($back, $errors, $input);
        }
        try {
            $id = (new PurchasingService())->createPo((int) $order['id'], $data, $lines);
        } catch (DomainException $e) {
            return $this->domainError($back, $e, $input);
        }
        return $this->success("/purchase-orders/$id", 'po.created');
    }

    public function show(Request $request): Response
    {
        $this->authorize('orders.view');
        $repo = new PurchaseOrderRepository();
        $po = $this->orNotFound($repo->find($request->intParam('id')));
        $orderRepo = new OrderRepository();
        $import = null;
        if ($po['fulfillment_mode'] === 'import') {
            $imp = new \App\Repositories\ImportRepository();
            $import = ['summary' => $imp->summary((int) $po['id']), 'inspections' => $imp->inspections((int) $po['id'])];
        }
        $deliveries = array_values(array_filter($orderRepo->deliveries((int) $po['order_id']), fn ($d) => (int) $d['po_id'] === (int) $po['id']));
        $invoices = array_values(array_filter($orderRepo->invoices((int) $po['order_id']), fn ($i) => (int) $i['po_id'] === (int) $po['id']));
        // Historique du PO : événements du dossier qui le concernent (PO, sa licence, ses inspections, livraisons et factures)
        $needles = array_filter(array_merge([$po['reference'], $import['summary']['licence']['number'] ?? null],
            array_column($import['inspections'] ?? [], 'reference'), array_column($deliveries, 'reference'), array_column($invoices, 'reference')));
        $events = array_values(array_filter($orderRepo->events((int) $po['order_id']), function ($ev) use ($needles) {
            foreach ($needles as $n) {
                if ($ev['detail'] !== null && str_contains($ev['detail'], (string) $n)) {
                    return true;
                }
            }
            return false;
        }));
        return $this->view('purchase_orders/show', [
            'title' => $po['reference'],
            'po' => $po,
            'import' => $import,
            'lines' => $repo->lines((int) $po['id']),
            'deliveries' => $deliveries,
            'invoices' => $invoices,
            'docSummaries' => (new \App\Repositories\ShipmentDocumentRepository())->summaries(array_map(fn ($d) => (int) $d['id'], $deliveries)),
            'events' => $events,
        ]);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('sourcing.manage');
        $po = $this->orNotFound((new PurchaseOrderRepository())->find($request->intParam('id')));
        if ($po['status'] !== 'draft') {
            return $this->domainError('/purchase-orders/' . $po['id'], new DomainException('po.error.not_draft'));
        }
        $order = $this->orNotFound((new OrderRepository())->find((int) $po['order_id']));
        return $this->form($order, $po, null, (new PurchaseOrderRepository())->lines((int) $po['id']));
    }

    public function update(Request $request): Response
    {
        $this->authorize('sourcing.manage');
        $po = $this->orNotFound((new PurchaseOrderRepository())->find($request->intParam('id')));
        $order = $this->orNotFound((new OrderRepository())->find((int) $po['order_id']));
        [$data, $lines, $errors, $input] = $this->parse($request, $order);
        if ($errors) {
            return $this->backWithErrors('/purchase-orders/' . $po['id'] . '/edit', $errors, $input);
        }
        try {
            (new PurchasingService())->updatePo((int) $po['id'], $data, $lines);
        } catch (DomainException $e) {
            return $this->domainError('/purchase-orders/' . $po['id'] . '/edit', $e, $input);
        }
        return $this->success('/purchase-orders/' . $po['id'], 'flash.updated');
    }

    public function send(Request $request): Response
    {
        $this->authorize('sourcing.manage');
        $po = $this->orNotFound((new PurchaseOrderRepository())->find($request->intParam('id')));
        try {
            (new PurchasingService())->sendPo((int) $po['id']);
        } catch (DomainException $e) {
            return $this->domainError('/purchase-orders/' . $po['id'], $e);
        }
        $needsLicence = $po['fulfillment_mode'] === 'import' && (new \App\Repositories\ImportRepository())->licenceForPo((int) $po['id']) === null;
        return $this->successNext('/purchase-orders/' . $po['id'], 'po.sent', $needsLicence ? [t('dossier.next.licence'), '/licences/new?po_id=' . $po['id']] : null);
    }

    public function cancel(Request $request): Response
    {
        $this->authorize('sourcing.manage');
        $po = $this->orNotFound((new PurchaseOrderRepository())->find($request->intParam('id')));
        $note = trim((string) $request->input('note', ''));
        try {
            if ($note === '') {
                throw new DomainException('orders.error.note_required');
            }
            (new PurchasingService())->cancelPo((int) $po['id'], mb_substr($note, 0, 400));
        } catch (DomainException $e) {
            return $this->domainError('/purchase-orders/' . $po['id'], $e);
        }
        return $this->success('/purchase-orders/' . $po['id'], 'po.cancelled');
    }

    /** Document imprimable / PDF à envoyer au fournisseur. */
    public function print(Request $request): Response
    {
        $this->authorize('orders.view');
        $repo = new PurchaseOrderRepository();
        $po = $this->orNotFound($repo->find($request->intParam('id')));
        return $this->view('purchase_orders/print', [
            'title' => $po['reference'],
            'po' => $po,
            'lines' => $repo->lines((int) $po['id']),
            'company' => self::company(),
        ], 'print');
    }

    /** En-tête des documents imprimés (Paramètres → Société), logo en data URI. @return array<string,mixed> */
    public static function company(): array
    {
        return \App\Services\CompanySettings::get() + ['logo' => \App\Services\CompanySettings::logoDataUri()];
    }

    private function form(array $order, ?array $po, ?array $quote, array $poLines): Response
    {
        $orderLines = (new OrderRepository())->lines((int) $order['id']);
        $existing = [];
        foreach ($poLines as $pl) {
            $existing[(int) $pl['order_line_id']] = $pl;
        }
        $customerSites = (new PartyRepository('customer'))->sites((int) $order['customer_id'], true);
        return $this->view('purchase_orders/form', [
            'title' => $po ? t('po.edit') . ' ' . $po['reference'] : t('po.new'),
            'order' => $order,
            'po' => $po,
            'quote' => $quote,
            'orderLines' => $orderLines,
            'existing' => $existing,
            'suppliers' => (new PartyRepository('supplier'))->all(true),
            'forwarders' => DeliveryController::forwarders(),
            'currencies' => (new LookupRepository())->currencies(),
            'deliverySites' => $customerSites,
            'siteAddresses' => array_combine(
                array_map(fn ($s) => (string) $s['id'], $customerSites),
                array_map(fn ($s) => ['address' => PartyRepository::formatSite($s), 'contact' => trim(($s['contact_name'] ?? '') . ' ' . ($s['phone'] ?? ''))], $customerSites)
            ) ?: [],
        ]);
    }

    /** @return array{0:array<string,mixed>,1:list<array<string,mixed>>,2:array<string,string>,3:array<string,mixed>} */
    private function parse(Request $request, array $order): array
    {
        $input = $request->all();
        $v = new Validator($input, [
            'reference' => 'max:30', 'supplier_id' => 'required|int', 'currency' => 'required|max:3', 'order_date' => 'required|date', 'expected_date' => 'date',
            'delivery_address' => 'required|max:600', 'delivery_contact' => 'max:200', 'payment_terms_days' => 'required|int|min:0',
            'transport_mode' => 'required|in:' . implode(',', \App\Domain\Checklist::TRANSPORTS), 'forwarder_id' => 'int',
            'delivery_site_id' => 'int', 'quote_id' => 'int',
        ]);
        if (!isset($v->errors()['supplier_id']) && !(new PartyRepository('supplier'))->find((int) $input['supplier_id'])) {
            $v->addError('supplier_id', t('validation.in'));
        }
        if (!isset($v->errors()['currency']) && !(new LookupRepository())->currencyExists((string) $input['currency'])) {
            $v->addError('currency', t('validation.in'));
        }
        $siteId = self::nul($input['delivery_site_id'] ?? null);
        if ($siteId !== null) {
            $site = (new PartyRepository('customer'))->findSite((int) $siteId);
            if (!$site || (int) $site['customer_id'] !== (int) $order['customer_id']) {
                $v->addError('delivery_site_id', t('orders.error.site_customer'));
            }
        }
        $quoteId = self::nul($input['quote_id'] ?? null);
        if ($quoteId !== null && !(new OrderRepository())->findQuote((int) $quoteId, (int) $order['id'])) {
            throw new HttpException(404);
        }
        $lines = [];
        foreach ((array) ($input['lines'] ?? []) as $orderLineId => $row) {
            $qty = $row['quantity'] ?? '';
            if ($qty === '' || (float) $qty == 0.0) {
                continue;
            }
            if (!is_numeric($qty) || (float) $qty < 0 || !is_numeric($row['unit_price'] ?? '') || (float) $row['unit_price'] < 0) {
                $v->addError('lines', t('orders.error.bad_line'));
                continue;
            }
            $lines[] = ['order_line_id' => (int) $orderLineId, 'quantity' => round((float) $qty, 3), 'unit_price' => round((float) $row['unit_price'], 6)];
        }
        if ($lines === [] && !isset($v->errors()['lines'])) {
            $v->addError('lines', t('po.error.no_lines'));
        }
        $data = [
            'reference' => (string) ($input['reference'] ?? ''),
            'reference_suggested' => (string) ($input['reference_suggested'] ?? ''),
            'supplier_id' => (int) ($input['supplier_id'] ?? 0),
            'quote_id' => $quoteId !== null ? (int) $quoteId : null,
            'currency' => (string) ($input['currency'] ?? ''),
            'order_date' => $input['order_date'] ?? null,
            'expected_date' => self::nul($input['expected_date'] ?? null),
            'delivery_site_id' => $siteId !== null ? (int) $siteId : null,
            'delivery_address' => (string) ($input['delivery_address'] ?? ''),
            'delivery_contact' => self::nul($input['delivery_contact'] ?? null),
            'transport_mode' => (string) ($input['transport_mode'] ?? 'road'),
            'forwarder_id' => in_array((int) ($input['forwarder_id'] ?? 0), array_map('intval', array_column(DeliveryController::forwarders(), 'id')), true) ? (int) $input['forwarder_id'] : null,
            'payment_terms_days' => (int) ($input['payment_terms_days'] ?? 30),
            'notes' => self::nul($input['notes'] ?? null),
        ];
        return [$data, $lines, $v->errors(), $input];
    }
}
