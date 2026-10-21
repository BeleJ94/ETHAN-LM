<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\DataTable;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\ClosureCheck;
use App\Domain\DomainException;
use App\Repositories\LookupRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PartyRepository;
use App\Repositories\SiteRepository;
use App\Security\SiteScope;
use App\Services\OrderService;

/** PO client : liste, saisie, dossier complet (hub de traçabilité), annulation, clôture. */
final class OrderController extends Controller
{
    public const MODES = ['local_purchase', 'import', 'stock'];

    public function index(Request $request): Response
    {
        $this->authorize('orders.view');
        // Filtres repris de l'adresse (lien partageable, retour arrière) ; onglet « Tous » par défaut
        $filters = ['status' => (string) $request->query('status', '')];
        foreach (self::FILTERS as $k) {
            if ($k !== 'status' && ($v = (string) $request->query($k, '')) !== '') {
                $filters[$k] = $v;
            }
        }
        return $this->view('orders/index', [
            'title' => t('nav.orders'),
            'kpis' => (new OrderRepository())->kpis(),
            'customers' => (new PartyRepository('customer'))->all(),
            'sites' => (new SiteRepository())->allowed(),
            'currencies' => (new LookupRepository())->currencies(),
            'filters' => $filters,
        ]);
    }

    /** Filtres de la liste (onglet + filtres compacts + volet « Plus de filtres »). */
    private const FILTERS = ['status', 'customer_id', 'mode', 'detail_status', 'site_id', 'currency', 'from', 'to', 'due'];

    public function data(Request $request): Response
    {
        $this->authorize('orders.view');
        $dt = new DataTable($request, OrderRepository::SORTABLE, 'o.id DESC');
        $filters = [];
        foreach (self::FILTERS as $k) {
            $filters[$k] = (string) $request->query($k, '');
        }
        [$total, $filtered, $rows] = (new OrderRepository())->datatable($dt, $filters);
        return $dt->response($total, $filtered, $rows);
    }

    public function create(Request $request): Response
    {
        $this->authorize('orders.manage');
        return $this->form(null, []);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('orders.manage');
        $repo = new OrderRepository();
        $order = $this->orNotFound($repo->find($request->intParam('id')));
        return $this->form($order, $repo->lines((int) $order['id']));
    }

    public function store(Request $request): Response
    {
        $this->authorize('orders.manage');
        [$header, $lines, $errors, $input] = $this->parse($request, null);
        if ($errors) {
            return $this->backWithErrors('/orders/new', $errors, $input);
        }
        try {
            $id = (new OrderService())->create($header, $lines);
        } catch (DomainException $e) {
            return $this->domainError('/orders/new', $e, $input);
        }
        return $this->success("/orders/$id", 'orders.created');
    }

    public function update(Request $request): Response
    {
        $this->authorize('orders.manage');
        $order = $this->orNotFound((new OrderRepository())->find($request->intParam('id')));
        $id = (int) $order['id'];
        [$header, $lines, $errors, $input] = $this->parse($request, $id);
        if ($errors) {
            return $this->backWithErrors("/orders/$id/edit", $errors, $input);
        }
        try {
            (new OrderService())->update($id, $header, $lines);
        } catch (DomainException $e) {
            return $this->domainError("/orders/$id", $e);
        }
        return $this->success("/orders/$id", 'flash.updated');
    }

    public function show(Request $request): Response
    {
        $this->authorize('orders.view');
        $repo = new OrderRepository();
        $order = $this->orNotFound($repo->find($request->intParam('id')));
        $id = (int) $order['id'];
        $lines = $repo->lines($id);
        $pos = $repo->purchaseOrders($id);
        $deliveries = $repo->deliveries($id);
        $invoices = $repo->invoices($id);
        $lookups = new LookupRepository();
        $rates = $lookups->latestRates();
        $facts = OrderRepository::closureFacts($lines, $pos, $deliveries, $invoices);
        $facts['documents_missing'] = count(\App\Services\ChecklistService::orderBlockers($id, 'closure'));

        return $this->view('orders/show', [
            'title' => $order['reference'],
            'order' => $order,
            'lines' => $lines,
            'quotes' => $this->compareQuotes($repo->quotes($id), $lines, $order['currency'], $rates),
            'pos' => $pos,
            'deliveries' => $deliveries,
            'invoices' => $invoices,
            'events' => $repo->events($id),
            'importStates' => $order['fulfillment_mode'] === 'import' ? self::importStates($pos) : [],
            'dossierDocs' => $this->user()->can('documents.view') ? (new \App\Repositories\ShipmentDocumentRepository())->forOrder($id, true) : [],
            'docSummaries' => (new \App\Repositories\ShipmentDocumentRepository())->summaries(array_map(fn ($d) => (int) $d['id'], $deliveries)),
            'attachments' => $repo->attachments($id),
            'facts' => $facts,
            'checks' => ClosureCheck::evaluate($facts),
            'suppliers' => (new PartyRepository('supplier'))->all(true),
            'currencies' => $lookups->currencies(),
        ]);
    }

    public function cancel(Request $request): Response
    {
        $this->authorize('orders.manage');
        $order = $this->orNotFound((new OrderRepository())->find($request->intParam('id')));
        $note = trim((string) $request->input('note', ''));
        if ($note === '') {
            return $this->domainError('/orders/' . $order['id'], new DomainException('orders.error.note_required'));
        }
        try {
            (new OrderService())->cancel((int) $order['id'], mb_substr($note, 0, 500));
        } catch (DomainException $e) {
            return $this->domainError('/orders/' . $order['id'], $e);
        }
        return $this->success('/orders/' . $order['id'], 'orders.cancelled');
    }

    public function close(Request $request): Response
    {
        $this->authorize('orders.close');
        $order = $this->orNotFound((new OrderRepository())->find($request->intParam('id')));
        try {
            (new OrderService())->close((int) $order['id'], mb_substr(trim((string) $request->input('note', '')), 0, 500));
        } catch (DomainException $e) {
            return $this->domainError('/orders/' . $order['id'] . '#closure', $e);
        }
        return $this->success('/orders/' . $order['id'], 'orders.closed');
    }

    /**
     * Compare chaque devis au prix de vente : coût, marge et taux de marge, convertis en devise de base.
     * @param array<string,float> $rates
     */
    private function compareQuotes(array $quotes, array $lines, string $saleCurrency, array $rates): array
    {
        $saleRate = $rates[$saleCurrency] ?? null;
        foreach ($quotes as &$q) {
            $cost = $sale = 0.0;
            $covered = 0;
            foreach ($lines as $l) {
                if (!isset($q['prices'][(int) $l['id']])) {
                    continue;
                }
                // La marge porte sur la quantité réellement proposée par le fournisseur.
                $qty = min((float) $l['quantity'], $q['quantities'][(int) $l['id']] ?? (float) $l['quantity']);
                if ($qty + 1e-9 >= (float) $l['quantity']) {
                    $covered++;
                }
                $cost += $qty * $q['prices'][(int) $l['id']];
                $sale += $qty * (float) $l['unit_price'];
            }
            $quoteRate = $rates[$q['currency']] ?? null;
            $q['cost'] = $cost;
            $q['complete'] = $covered === count($lines);
            $q['margin_base'] = $saleRate !== null && $quoteRate !== null ? $sale * $saleRate - $cost * $quoteRate : null;
            $q['margin_pct'] = $q['margin_base'] !== null && $sale > 0 ? $q['margin_base'] / ($sale * $saleRate) * 100 : null;
            $q['expired'] = $q['valid_until'] && $q['valid_until'] < date('Y-m-d');
        }
        // Meilleure marge en premier (devis rejetés en dernier)
        usort($quotes, fn ($a, $b) => [$a['status'] === 'rejected', -($a['margin_base'] ?? -INF)] <=> [$b['status'] === 'rejected', -($b['margin_base'] ?? -INF)]);
        return $quotes;
    }

    /** @return array<int,array{licence:?array,valid:bool,cleared:float,open_ara:int}> état d'import par PO fournisseur */
    private static function importStates(array $pos): array
    {
        $repo = new \App\Repositories\ImportRepository();
        $out = [];
        foreach ($pos as $p) {
            $s = $repo->summary((int) $p['id']);
            $out[(int) $p['id']] = ['licence' => $s['licence'], 'valid' => \App\Domain\ImportGate::licenceValid($s['licence'], date('Y-m-d')), 'cleared' => $s['cleared_total'], 'open_ara' => $s['open_ara']];
        }
        return $out;
    }

    private function form(?array $order, array $lines): Response
    {
        $customers = new PartyRepository('customer');
        $sites = [];
        foreach ($customers->all(true) as $c) {
            foreach ($customers->sites((int) $c['id'], true) as $s) {
                $sites[] = $s;
            }
        }
        return $this->view('orders/form', [
            'title' => $order ? t('orders.edit') . ' ' . $order['reference'] : t('orders.new'),
            'order' => $order,
            'lines' => $lines,
            'customers' => $customers->all(true),
            'deliverySites' => $sites,
            'sites' => (new SiteRepository())->allowed(),
            'currencies' => (new LookupRepository())->currencies(),
            'items' => Database::pdo()->query('SELECT i.id, i.sku, i.name, f.name AS family_name, u.code AS unit FROM items i JOIN units u ON u.id = i.unit_id
                JOIN item_families f ON f.id = i.family_id WHERE i.active = 1 ORDER BY f.sort_order, f.name, i.name')->fetchAll(),
        ]);
    }

    /** @return array{0:array<string,mixed>,1:list<array<string,mixed>>,2:array<string,string>,3:array<string,mixed>} */
    private function parse(Request $request, ?int $id): array
    {
        $input = $request->all();
        $v = new Validator($input, [
            'reference' => 'max:30',
            'customer_id' => 'required|int',
            'customer_po_number' => 'required|max:80',
            'po_date' => 'required|date',
            'site_id' => 'required|int',
            'fulfillment_mode' => 'required|in:' . implode(',', self::MODES),
            'currency' => 'required|max:3',
            'required_date' => 'date',
            'payment_terms_days' => 'required|int|min:0',
            'delivery_site_id' => 'int',
        ]);
        $parties = new PartyRepository('customer');
        $customer = isset($v->errors()['customer_id']) ? null : $parties->find((int) $input['customer_id']);
        if (!$customer && !isset($v->errors()['customer_id'])) {
            $v->addError('customer_id', t('validation.in'));
        }
        if (!isset($v->errors()['site_id']) && !SiteScope::allows((int) $input['site_id'])) {
            $v->addError('site_id', t('validation.in'));
        }
        if (!isset($v->errors()['currency']) && !(new LookupRepository())->currencyExists((string) $input['currency'])) {
            $v->addError('currency', t('validation.in'));
        }
        $deliverySiteId = self::nul($input['delivery_site_id'] ?? null);
        if ($deliverySiteId !== null && $customer) {
            $ds = $parties->findSite((int) $deliverySiteId);
            if (!$ds || (int) $ds['customer_id'] !== (int) $customer['id']) {
                $v->addError('delivery_site_id', t('orders.error.site_customer'));
            }
        }
        if ($customer && !isset($v->errors()['customer_po_number'])
            && (new OrderRepository())->poNumberExists((int) $customer['id'], (string) $input['customer_po_number'], $id)) {
            $v->addError('customer_po_number', t('orders.error.duplicate_po'));
        }

        $lines = [];
        $itemCheck = Database::pdo()->prepare('SELECT 1 FROM items WHERE id = ? AND active = 1');
        foreach ((array) ($input['lines'] ?? []) as $row) {
            if (!is_array($row) || (($row['item_id'] ?? '') === '' && ($row['quantity'] ?? '') === '')) {
                continue;
            }
            $itemCheck->execute([(int) ($row['item_id'] ?? 0)]);
            $qty = $row['quantity'] ?? '';
            $price = $row['unit_price'] ?? '';
            if (!$itemCheck->fetchColumn() || !is_numeric($qty) || (float) $qty <= 0 || !is_numeric($price) || (float) $price < 0) {
                $v->addError('lines', t('orders.error.bad_line'));
                continue;
            }
            $lines[] = [
                'item_id' => (int) $row['item_id'],
                'specification' => self::nul(mb_substr(trim((string) ($row['specification'] ?? '')), 0, 255)),
                'quantity' => round((float) $qty, 3),
                'unit_price' => round((float) $price, 6),
            ];
        }
        if ($lines === [] && !isset($v->errors()['lines'])) {
            $v->addError('lines', t('orders.error.no_lines'));
        }

        $header = [
            'reference' => (string) ($input['reference'] ?? ''),
            'reference_suggested' => (string) ($input['reference_suggested'] ?? ''),
            'customer_id' => (int) ($input['customer_id'] ?? 0),
            'customer_po_number' => (string) ($input['customer_po_number'] ?? ''),
            'po_date' => $input['po_date'] ?? null,
            'site_id' => (int) ($input['site_id'] ?? 0),
            'fulfillment_mode' => $input['fulfillment_mode'] ?? 'local_purchase',
            'currency' => $input['currency'] ?? base_currency(),
            'delivery_site_id' => $deliverySiteId !== null ? (int) $deliverySiteId : null,
            'required_date' => self::nul($input['required_date'] ?? null),
            'payment_terms_days' => (int) ($input['payment_terms_days'] ?? 30),
            'notes' => self::nul($input['notes'] ?? null),
        ];
        return [$header, $lines, $v->errors(), $input];
    }
}
