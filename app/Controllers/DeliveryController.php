<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\Checklist;
use App\Domain\DomainException;
use App\Repositories\DeliveryRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\ShipmentDocumentRepository;
use App\Services\DeliveryService;

/** Suivi des expéditions fournisseur → client : chargement, transport, réception signée. */
final class DeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('orders.view');
        return $this->view('deliveries/index', [
            'title' => t('nav.deliveries'),
            'counts' => (new DeliveryRepository())->counts(),
            'customers' => (new \App\Repositories\PartyRepository('customer'))->all(),
            'suppliers' => array_values(array_filter((new \App\Repositories\PartyRepository('supplier'))->all(), fn ($s) => !$s['is_forwarder'])),
            'forwarders' => self::forwarders(),
            'filters' => array_map('strval', array_intersect_key($request->allQuery(), array_flip(self::FILTERS))),
        ]);
    }

    public const FILTERS = ['status', 'period', 'customer_id', 'supplier_id', 'forwarder_id', 'transport_mode', 'docs', 'gap', 'from', 'to', 'order_id'];

    /** Données du tableau serveur (filtres, tri sur liste blanche, export length=-1). */
    public function data(Request $request): Response
    {
        $this->authorize('orders.view');
        $dt = new \App\Core\DataTable($request, DeliveryRepository::SORTABLE, 'd.shipped_at DESC');
        $filters = [];
        foreach (self::FILTERS as $k) {
            $filters[$k] = (string) $request->query($k, '');
        }
        [$total, $filtered, $rows] = (new DeliveryRepository())->datatable($dt, $filters);
        foreach ($rows as &$r) {
            $r['status_label'] = t('delivery.status.' . $r['status']);
            $r['transport_label'] = t('transport.' . $r['transport_mode']);
        }
        return $dt->response($total, $filtered, $rows);
    }

    public function create(Request $request): Response
    {
        $this->authorize('deliveries.manage');
        $repo = new PurchaseOrderRepository();
        $po = $this->orNotFound($repo->find($request->intParam('id')));
        if (!in_array($po['status'], ['sent', 'partial'], true)) {
            return $this->domainError('/purchase-orders/' . $po['id'], new DomainException('delivery.error.po_not_open'));
        }
        $import = $po['fulfillment_mode'] === 'import' ? new \App\Repositories\ImportRepository() : null;
        return $this->view('deliveries/form', [
            'title' => t('delivery.new') . ' — ' . $po['reference'],
            'po' => $po,
            'lines' => $repo->lines((int) $po['id']),
            'forwarders' => self::forwarders(),
            'importShippable' => $import?->summary((int) $po['id']),
            // Importation : inspections (AV / ARA) qui expliquent ce qui peut être chargé
            'inspections' => $import?->inspections((int) $po['id']) ?? [],
            'avBalances' => $import?->avBalances((int) $po['id']) ?? [],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('deliveries.manage');
        $po = $this->orNotFound((new PurchaseOrderRepository())->find($request->intParam('id')));
        $back = '/purchase-orders/' . $po['id'] . '/deliveries/new';
        $input = $request->all();
        // Étape visée : en préparation, en route ou déjà réceptionnée (les documents bloquants peuvent la ramener plus tôt)
        $target = in_array($input['reception_mode'] ?? '', ['planned', 'in_transit', 'delivered'], true) ? $input['reception_mode'] : 'planned';
        $already = $target === 'delivered';
        $rules = [
            'reference' => 'max:30', 'shipped_at' => 'required|date', 'transporter' => 'max:160', 'vehicle_plate' => 'max:60', 'trailer_plate' => 'max:60',
            'driver_name' => 'max:120', 'driver_phone' => 'max:60', 'supplier_waybill' => 'max:80', 'notes' => 'max:500',
            'transport_mode' => 'required|in:' . implode(',', Checklist::TRANSPORTS), 'forwarder_id' => 'int', 'forwarder_ref' => 'max:60',
        ];
        if ($already) {
            $rules += ['delivered_at' => 'required|date', 'received_by' => 'required|max:160'];
        }
        $v = new Validator($input, $rules);
        $shipped = $received = $avAlloc = [];
        foreach ((array) ($input['lines'] ?? []) as $lineId => $row) {
            $s = $row['shipped'] ?? '';
            if ($s === '' || (float) $s == 0.0) {
                continue;
            }
            if (!is_numeric($s) || (float) $s < 0) {
                $v->addError('lines', t('orders.error.bad_line'));
                continue;
            }
            $shipped[(int) $lineId] = round((float) $s, 3);
            foreach ((array) ($row['av'] ?? []) as $inspectionId => $aq) {
                if ($aq !== '' && $aq !== null) {
                    if (!is_numeric($aq)) {
                        $v->addError('lines', t('orders.error.bad_line'));
                        continue;
                    }
                    $avAlloc[(int) $lineId][(int) $inspectionId] = (float) $aq;
                }
            }
            if ($already) {
                $r = $row['received'] ?? '';
                $received[(int) $lineId] = $r === '' ? $shipped[(int) $lineId] : (is_numeric($r) ? round((float) $r, 3) : -1);
            }
        }
        $forwarderId = self::nul($input['forwarder_id'] ?? null) !== null ? (int) $input['forwarder_id'] : null;
        if ($forwarderId !== null && !$this->isForwarder($forwarderId)) {
            $v->addError('forwarder_id', t('validation.in'));
        }
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input);
        }
        $data = ['transport_mode' => $input['transport_mode'], 'forwarder_id' => $forwarderId, 'forwarder_ref' => self::nul(mb_strtoupper(trim((string) ($input['forwarder_ref'] ?? '')))), 'reference' => (string) ($input['reference'] ?? ''), 'reference_suggested' => (string) ($input['reference_suggested'] ?? ''), 'shipped_at' => $input['shipped_at'], 'delivered_at' => $input['delivered_at'] ?? null, 'received_by' => $input['received_by'] ?? null];
        foreach (['transporter', 'vehicle_plate', 'trailer_plate', 'driver_name', 'driver_phone', 'supplier_waybill', 'notes'] as $f) {
            $data[$f] = self::nul($input[$f] ?? null);
        }
        if ($data['vehicle_plate']) {
            $data['vehicle_plate'] = mb_strtoupper($data['vehicle_plate']);
        }
        try {
            $res = (new DeliveryService())->create((int) $po['id'], $data, $shipped, $target, $already ? $received : null, $avAlloc);
        } catch (DomainException $e) {
            return $this->domainError($back, $e, $input);
        }
        $to = '/deliveries/' . $res['id'] . '#documents';
        if ($res['blockers'] !== '') {
            // Créée, mais retenue à l'étape atteinte : on dit précisément quels documents manquent.
            return $this->success($to, 'delivery.created_blocked', ['status' => t('delivery.status.' . $res['status']), 'docs' => $res['blockers']]);
        }
        return $this->success($to, ['planned' => 'delivery.recorded_planned', 'in_transit' => 'delivery.recorded_shipped', 'delivered' => 'delivery.recorded_delivered'][$res['status']]);
    }

    /** Fiche d'une expédition : avancement, check-list documentaire, actions de l'étape suivante. */
    public function show(Request $request): Response
    {
        $this->authorize('orders.view');
        $d = $this->orNotFound((new PurchaseOrderRepository())->findDelivery($request->intParam('id')));
        $docs = (new ShipmentDocumentRepository())->forDelivery((int) $d['id'], (int) $d['order_id']);
        $next = ['planned' => 'departure', 'in_transit' => 'reception', 'delivered' => 'invoicing'][$d['status']] ?? null;
        $today = date('Y-m-d');
        // Importation : AV qui couvrent l'expédition (quantité prélevée ici, rapport), et licence du PO
        $avs = [];
        $licence = null;
        if ($d['fulfillment_mode'] === 'import') {
            $imp = new \App\Repositories\ImportRepository();
            $drawn = [];
            foreach ($d['lines'] as $l) {
                foreach ($l['avs'] as $a) {
                    $drawn[$a['inspection_id']] = ($drawn[$a['inspection_id']] ?? 0) + $a['quantity'];
                }
            }
            foreach ($imp->inspections((int) $d['po_id']) as $i) {
                if (isset($drawn[(int) $i['id']])) {
                    $avs[] = $i + ['drawn' => $drawn[(int) $i['id']]];
                }
            }
            usort($avs, fn ($a, $b) => [$a['decision_date'] ?? $a['inspection_date'], $a['id']] <=> [$b['decision_date'] ?? $b['inspection_date'], $b['id']]);
            $licence = $imp->licenceForPo((int) $d['po_id']);
        }
        // Historique : événements du dossier qui citent cette expédition
        $events = array_values(array_filter((new \App\Repositories\OrderRepository())->events((int) $d['order_id']),
            fn ($ev) => $ev['detail'] !== null && str_contains($ev['detail'], $d['reference'])));
        return $this->view('deliveries/show', [
            'title' => $d['reference'],
            'd' => $d,
            'docs' => $docs,
            'progress' => Checklist::progress($docs, $today),
            'nextStage' => $next,
            'blockers' => $next ? Checklist::blockers($docs, $next, $today) : [],
            'avs' => $avs,
            'forwarders' => self::forwarders(),
            'licence' => $licence,
            'events' => $events,
        ]);
    }

    /** Agence en douane / transitaire et n° de dossier de l'agence. */
    public function customs(Request $request): Response
    {
        $this->authorize('deliveries.manage');
        $d = $this->orNotFound((new PurchaseOrderRepository())->findDelivery($request->intParam('id')));
        $back = '/deliveries/' . $d['id'] . '#transport';
        $input = $request->all();
        $v = new Validator($input, ['forwarder_id' => 'int', 'forwarder_ref' => 'max:60']);
        $forwarderId = self::nul($input['forwarder_id'] ?? null) !== null ? (int) $input['forwarder_id'] : null;
        if ($forwarderId !== null && !$this->isForwarder($forwarderId)) {
            $v->addError('forwarder_id', t('validation.in'));
        }
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input);
        }
        try {
            (new DeliveryService())->updateCustoms((int) $d['id'], $forwarderId, self::nul(mb_strtoupper(trim((string) ($input['forwarder_ref'] ?? '')))));
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'delivery.customs.saved');
    }

    public function depart(Request $request): Response
    {
        $this->authorize('deliveries.manage');
        $d = $this->orNotFound((new PurchaseOrderRepository())->findDelivery($request->intParam('id')));
        try {
            (new DeliveryService())->depart((int) $d['id']);
        } catch (DomainException $e) {
            return $this->domainError('/deliveries/' . $d['id'] . '#documents', $e);
        }
        return $this->success('/deliveries/' . $d['id'], 'delivery.departed');
    }

    public function refreshChecklist(Request $request): Response
    {
        $this->authorize('deliveries.manage');
        $d = $this->orNotFound((new PurchaseOrderRepository())->findDelivery($request->intParam('id')));
        try {
            (new DeliveryService())->refreshChecklist((int) $d['id']);
        } catch (DomainException $e) {
            return $this->domainError('/deliveries/' . $d['id'] . '#documents', $e);
        }
        return $this->success('/deliveries/' . $d['id'] . '#documents', 'documents.refreshed');
    }

    private function isForwarder(int $supplierId): bool
    {
        return (new \App\Repositories\CustomsAgencyRepository())->isActive($supplierId);
    }

    /** @return list<array<string,mixed>> Transitaires actifs (sélecteurs) */
    public static function forwarders(): array
    {
        // Agences en douane actives (référentiel dédié)
        return \App\Core\Database::pdo()->query('SELECT id, name FROM customs_agencies WHERE active = 1 ORDER BY name')->fetchAll();
    }

    public function confirm(Request $request): Response
    {
        $this->authorize('deliveries.manage');
        $d = $this->orNotFound((new PurchaseOrderRepository())->findDelivery($request->intParam('id')));
        $back = '/deliveries/' . $d['id'];
        $input = $request->all();
        $v = new Validator($input, ['delivered_at' => 'required|date', 'received_by' => 'required|max:160', 'reception_notes' => 'max:500']);
        if ($v->fails()) {
            return $this->backWithErrors($back, $v->errors(), $input, implode(' ', $v->errors()));
        }
        $received = [];
        foreach ((array) ($input['received'] ?? []) as $lineId => $qty) {
            $received[(int) $lineId] = is_numeric($qty) ? round((float) $qty, 3) : -1.0;
        }
        try {
            (new DeliveryService())->confirm((int) $d['id'], [
                'delivered_at' => $input['delivered_at'], 'received_by' => $input['received_by'],
                'reception_notes' => self::nul($input['reception_notes'] ?? null),
            ], $received);
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'delivery.confirmed');
    }

    public function cancel(Request $request): Response
    {
        $this->authorize('deliveries.manage');
        $d = $this->orNotFound((new PurchaseOrderRepository())->findDelivery($request->intParam('id')));
        $back = '/deliveries/' . $d['id'];
        try {
            $note = trim((string) $request->input('note', ''));
            if ($note === '') {
                throw new DomainException('orders.error.note_required');
            }
            (new DeliveryService())->cancel((int) $d['id'], mb_substr($note, 0, 300));
        } catch (DomainException $e) {
            return $this->domainError($back, $e);
        }
        return $this->success($back, 'delivery.cancelled');
    }

    /** Bon de livraison imprimable (signature du réceptionnaire client). */
    public function print(Request $request): Response
    {
        $this->authorize('orders.view');
        $d = $this->orNotFound((new PurchaseOrderRepository())->findDelivery($request->intParam('id')));
        return $this->view('deliveries/print', ['title' => $d['reference'], 'd' => $d, 'company' => PurchaseOrderController::company()], 'print');
    }
}
