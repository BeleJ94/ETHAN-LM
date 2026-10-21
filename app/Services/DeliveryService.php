<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\DomainException;
use App\Repositories\PurchaseOrderRepository;
use App\Security\Auth;

/**
 * Expéditions fournisseur → site client (livraisons partielles).
 * 1) Expédition : quantités chargées (statut « en route ») ;
 * 2) Réception : quantités reçues et signataire client (statut « livré »). L'écart chargé/reçu reste tracé.
 */
final class DeliveryService
{
    /**
     * Crée l'expédition « en préparation », génère sa check-list, puis tente d'aller jusqu'à l'étape demandée.
     * Si des documents bloquants manquent, l'expédition reste à l'étape atteinte (pas d'échec) et les blocages sont renvoyés.
     *
     * @param array<string,mixed> $data
     * @param array<int,float> $shipped po_line_id → quantité chargée
     * @param string $target planned | in_transit | delivered
     * @param array<int,float>|null $received po_line_id → quantité reçue (si $target = delivered)
     * @param array<int,array<int,float>>|null $avAlloc importation : po_line_id → [inspection_id (AV) → quantité] ; vide = répartition automatique
     * @return array{id:int,status:string,blockers:string}
     */
    public function create(int $poId, array $data, array $shipped, string $target = 'in_transit', ?array $received = null, ?array $avAlloc = null): array
    {
        return Database::transaction(function () use ($poId, $data, $shipped, $target, $received, $avAlloc) {
            $po = $this->lockPo($poId);
            OrderService::lockOpen((int) $po['order_id']);
            if (!in_array($po['status'], ['sent', 'partial'], true)) {
                throw new DomainException('delivery.error.po_not_open');
            }
            $lines = [];
            foreach ((new PurchaseOrderRepository())->lines($poId) as $l) {
                $lines[(int) $l['id']] = $l;
            }
            $shipped = array_filter($shipped, fn ($q) => $q > 0);
            if ($shipped === []) {
                throw new DomainException('delivery.error.no_qty');
            }
            foreach ($shipped as $lineId => $qty) {
                $l = $lines[$lineId] ?? throw new DomainException('po.error.bad_line');
                $open = (float) $l['quantity'] - (float) $l['shipped'] - (float) $l['in_transit'];
                if ($qty > $open + 1e-9) {
                    throw new DomainException('delivery.error.over_ship', ['remaining' => fmt_qty(max(0, $open)), 'item' => $l['sku']]);
                }
            }

            $this->checkImport($po, $shipped);
            // Importation : chaque quantité chargée est prélevée sur des AV précises
            $allocations = $this->isImport($po) ? $this->allocateAvs($poId, $shipped, $avAlloc ?? []) : [];
            $pdo = Database::pdo();
            $ref = ReferenceGenerator::resolve('delivery', $data['reference'] ?? '', $data['reference_suggested'] ?? null);
            $pdo->prepare("INSERT INTO deliveries (reference, order_id, po_id, status, transport_mode, forwarder_id, forwarder_ref, shipped_at, transporter,
                    vehicle_plate, trailer_plate, driver_name, driver_phone, supplier_waybill, notes, created_by)
                    VALUES (?,?,?,'planned',?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$ref, $po['order_id'], $poId, $data['transport_mode'] ?? $po['transport_mode'], $data['forwarder_id'] ?? $po['forwarder_id'], $data['forwarder_ref'] ?? null,
                    $data['shipped_at'], $data['transporter'], $data['vehicle_plate'], $data['trailer_plate'],
                    $data['driver_name'], $data['driver_phone'], $data['supplier_waybill'], $data['notes'], Auth::require()->id]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO delivery_lines (delivery_id, po_line_id, quantity_shipped) VALUES (?,?,?)');
            $insAv = $pdo->prepare('INSERT INTO delivery_line_avs (delivery_line_id, inspection_id, quantity) VALUES (?,?,?)');
            foreach ($shipped as $lineId => $qty) {
                $ins->execute([$id, $lineId, $qty]);
                $dlId = (int) $pdo->lastInsertId();
                foreach ($allocations[$lineId] ?? [] as $inspectionId => $q) {
                    $insAv->execute([$dlId, $inspectionId, $q]);
                }
            }
            if ($allocations) {
                ActivityLogger::log('delivery', $id, 'av_allocation', null, ['allocations' => $allocations]);
            }
            ActivityLogger::log('delivery', $id, 'plan', null, $data + ['reference' => $ref]);
            OrderService::event((int) $po['order_id'], 'planned', $ref . ($data['vehicle_plate'] ? ' · ' . $data['vehicle_plate'] : ''));
            ChecklistService::generateForDelivery($id);
            ChecklistService::syncInspectionDocs($id);

            $status = 'planned';
            $blockers = '';
            if ($target !== 'planned') {
                $missing = ChecklistService::deliveryBlockers($id, (int) $po['order_id'], 'departure');
                if ($missing) {
                    $blockers = ChecklistService::names($missing);
                } else {
                    $this->markDeparted($id, (int) $po['order_id'], $ref);
                    $status = 'in_transit';
                }
            }
            if ($target === 'delivered' && $status === 'in_transit' && $received !== null) {
                $missing = ChecklistService::deliveryBlockers($id, (int) $po['order_id'], 'reception');
                if ($missing) {
                    $blockers = ChecklistService::names($missing);
                } else {
                    $this->doConfirm($id, $data, $received, true);
                    $status = 'delivered';
                }
            }
            OrderService::refresh((int) $po['order_id']);
            return ['id' => $id, 'status' => $status, 'blockers' => $blockers];
        });
    }

    /** Départ du camion : refusé tant qu'un document bloquant « avant départ » n'est ni validé ni dérogé. */
    public function depart(int $deliveryId): void
    {
        Database::transaction(function () use ($deliveryId) {
            $d = $this->lockDelivery($deliveryId);
            OrderService::lockOpen((int) $d['order_id']);
            if ($d['status'] !== 'planned') {
                throw new DomainException('delivery.error.not_planned');
            }
            // Importation : un ARA ouvert ou une licence expirée bloque aussi le départ des camions déjà préparés.
            $this->checkImport($this->lockPo((int) $d['po_id']), []);
            $missing = ChecklistService::deliveryBlockers($deliveryId, (int) $d['order_id'], 'departure');
            if ($missing) {
                throw new DomainException('documents.error.blocked', ['stage' => t('documents.stage.departure'), 'docs' => ChecklistService::names($missing)]);
            }
            $this->markDeparted($deliveryId, (int) $d['order_id'], $d['reference']);
            OrderService::refresh((int) $d['order_id']);
        });
    }

    /** Recalcul de la check-list (règles modifiées, mode de transport changé…) tant que la marchandise n'est pas réceptionnée. */
    public function refreshChecklist(int $deliveryId): void
    {
        Database::transaction(function () use ($deliveryId) {
            $d = $this->lockDelivery($deliveryId);
            OrderService::lockOpen((int) $d['order_id']);
            if (!in_array($d['status'], ['planned', 'in_transit'], true)) {
                throw new DomainException('delivery.error.not_in_transit');
            }
            ChecklistService::generateForDelivery($deliveryId, true);
            ChecklistService::syncInspectionDocs($deliveryId);
            ActivityLogger::log('delivery', $deliveryId, 'checklist_refresh');
        });
    }

    private function isImport(array $po): bool
    {
        $mode = Database::pdo()->prepare('SELECT fulfillment_mode FROM customer_orders WHERE id = ?');
        $mode->execute([$po['order_id']]);
        return $mode->fetchColumn() === 'import';
    }

    /**
     * Répartition des quantités chargées sur les AV (soldes verrouillés) : saisie contrôlée, ou automatique (AV la plus ancienne d'abord).
     * @param array<int,float> $shipped po_line_id → quantité
     * @param array<int,array<int,float>> $requested po_line_id → [inspection_id → quantité]
     * @return array<int,array<int,float>>
     */
    public function allocateAvs(int $poId, array $shipped, array $requested): array
    {
        $byLine = [];
        $labels = [];
        foreach ((new \App\Repositories\ImportRepository())->avBalances($poId, true) as $b) {
            $byLine[$b['po_line_id']][] = ['id' => $b['inspection_id'], 'remaining' => $b['remaining']];
            $labels[$b['inspection_id']] = $b['decision_number'] ?: $b['reference'];
        }
        $out = [];
        foreach ($shipped as $lineId => $qty) {
            $avs = $byLine[$lineId] ?? [];
            $given = array_filter(array_map('floatval', $requested[$lineId] ?? []), fn ($q) => $q != 0.0);
            if ($given === []) {
                $alloc = \App\Domain\AvAllocation::fifo($qty, $avs);
                if ($alloc === null) {
                    throw new DomainException('delivery.av.error.uncovered', ['qty' => fmt_qty($qty)]);
                }
            } else {
                $alloc = array_map(fn ($q) => round($q, 3), $given);
                $err = \App\Domain\AvAllocation::check($qty, $alloc, array_column($avs, 'remaining', 'id'));
                if ($err !== null) {
                    [$key, $p] = $err;
                    throw new DomainException($key, [
                        'av' => isset($p['id']) ? ($labels[$p['id']] ?? '') : '',
                        'remaining' => isset($p['remaining']) ? fmt_qty($p['remaining']) : '',
                        'sum' => isset($p['sum']) ? fmt_qty($p['sum']) : '', 'qty' => fmt_qty($qty),
                    ]);
                }
            }
            $out[$lineId] = $alloc;
        }
        return $out;
    }

    /** Importation : licence valide, aucun ARA ouvert, et quantités chargées couvertes par des AV (inspections partielles cumulées). */
    private function checkImport(array $po, array $shipped): void
    {
        if (!$this->isImport($po)) {
            return;
        }
        $sum = (new \App\Repositories\ImportRepository())->summary((int) $po['id']);
        $error = \App\Domain\ImportGate::checkShipment($sum['licence'], $sum['open_ara'], $shipped, $sum['shippable'], date('Y-m-d'));
        if ($error !== null) {
            [$key, $params] = $error;
            if (isset($params['line'])) {
                $params = ['cleared' => fmt_qty($params['cleared'])];
            }
            throw new DomainException($key, $params);
        }
    }

    private function markDeparted(int $deliveryId, int $orderId, string $reference): void
    {
        Database::pdo()->prepare("UPDATE deliveries SET status = 'in_transit', departed_at = NOW() WHERE id = ?")->execute([$deliveryId]);
        ActivityLogger::log('delivery', $deliveryId, 'depart', ['status' => 'planned'], ['status' => 'in_transit']);
        OrderService::event($orderId, 'shipped', $reference);
    }

    /** @param array<int,float> $received delivery_line_id ou po_line_id → quantité reçue */
    public function confirm(int $deliveryId, array $data, array $received): void
    {
        Database::transaction(function () use ($deliveryId, $data, $received) {
            $d = $this->lockDelivery($deliveryId);
            OrderService::lockOpen((int) $d['order_id']);
            $missing = ChecklistService::deliveryBlockers($deliveryId, (int) $d['order_id'], 'reception');
            if ($missing) {
                throw new DomainException('documents.error.blocked', ['stage' => t('documents.stage.reception'), 'docs' => ChecklistService::names($missing)]);
            }
            $this->doConfirm($deliveryId, $data, $received, false);
            OrderService::refresh((int) $d['order_id']);
        });
    }

    public function cancel(int $deliveryId, string $note): void
    {
        Database::transaction(function () use ($deliveryId, $note) {
            $d = $this->lockDelivery($deliveryId);
            OrderService::lockOpen((int) $d['order_id']);
            if (!in_array($d['status'], ['planned', 'in_transit'], true)) {
                throw new DomainException('delivery.error.not_in_transit');
            }
            Database::pdo()->prepare("UPDATE deliveries SET status = 'cancelled', notes = ? WHERE id = ?")
                ->execute([trim(($d['notes'] ?? '') . ' [' . t('common.cancelled') . '] ' . $note), $deliveryId]);
            ActivityLogger::log('delivery', $deliveryId, 'cancel', ['status' => $d['status']], ['status' => 'cancelled', 'note' => $note]);
            OrderService::event((int) $d['order_id'], 'delivery_cancelled', $d['reference'] . ' — ' . $note);
            OrderService::refresh((int) $d['order_id']);
        });
    }

    /** $received est indexé par ligne de PO ($byPoLine, création directe) ou par ligne de livraison (confirmation). */
    private function doConfirm(int $deliveryId, array $data, array $received, bool $byPoLine): void
    {
        $d = $this->lockDelivery($deliveryId);
        if ($d['status'] !== 'in_transit') {
            throw new DomainException('delivery.error.not_in_transit');
        }
        $pdo = Database::pdo();
        $lines = $pdo->prepare('SELECT * FROM delivery_lines WHERE delivery_id = ?');
        $lines->execute([$deliveryId]);
        $upd = $pdo->prepare('UPDATE delivery_lines SET quantity_received = ? WHERE id = ?');
        $gap = [];
        foreach ($lines->fetchAll() as $l) {
            $qty = $received[(int) ($byPoLine ? $l['po_line_id'] : $l['id'])] ?? null;
            if ($qty === null || $qty < 0) {
                throw new DomainException('delivery.error.received_required');
            }
            if ($qty > (float) $l['quantity_shipped'] + 1e-9) {
                throw new DomainException('delivery.error.over_receive');
            }
            $upd->execute([$qty, $l['id']]);
            if ($qty + 1e-9 < (float) $l['quantity_shipped']) {
                $gap[] = fmt_qty((float) $l['quantity_shipped'] - $qty);
            }
        }
        $pdo->prepare("UPDATE deliveries SET status = 'delivered', delivered_at = ?, received_by = ?,
                notes = COALESCE(?, notes) WHERE id = ?")
            ->execute([$data['delivered_at'], $data['received_by'], $data['reception_notes'] ?? null, $deliveryId]);
        ActivityLogger::log('delivery', $deliveryId, 'receive', ['status' => 'in_transit'], [
            'status' => 'delivered', 'received_by' => $data['received_by'], 'gap' => implode(', ', $gap),
        ]);
        OrderService::event((int) $d['order_id'], 'delivered', $d['reference'] . ' · ' . $data['received_by']
            . ($gap ? ' · ' . t('delivery.gap') . ' ' . implode(', ', $gap) : ''));
    }

    /** @return array<string,mixed> */
    private function lockPo(int $poId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$poId]);
        return $stmt->fetch() ?: throw new DomainException('po.error.not_found');
    }

    /** @return array<string,mixed> */
    /**
     * Agence en douane / transitaire et n° de dossier de l'agence (modifiables après la création : l'agence attribue souvent son n° plus tard).
     * $forwarderId = false : agence inchangée (mise à jour du seul n° de dossier, ex. par l'agence depuis son portail).
     */
    public function updateCustoms(int $deliveryId, int|false|null $forwarderId, ?string $ref): void
    {
        Database::transaction(function () use ($deliveryId, $forwarderId, $ref) {
            $d = $this->lockDelivery($deliveryId);
            OrderService::lockOpen((int) $d['order_id']);
            if ($d['status'] === 'cancelled') {
                throw new DomainException('delivery.error.cancelled');
            }
            $after = ['forwarder_ref' => $ref];
            if ($forwarderId !== false) {
                $after['forwarder_id'] = $forwarderId;
            }
            $before = array_intersect_key($d, $after);
            if ($before == $after) {
                return;
            }
            $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($after)));
            Database::pdo()->prepare("UPDATE deliveries SET $sets WHERE id = ?")->execute([...array_values($after), $deliveryId]);
            ActivityLogger::log('delivery', $deliveryId, 'customs', $before, $after);
            $name = null;
            $fid = $after['forwarder_id'] ?? $d['forwarder_id'];
            if ($fid) {
                $s = Database::pdo()->prepare('SELECT name FROM customs_agencies WHERE id = ?');
                $s->execute([$fid]);
                $name = $s->fetchColumn() ?: null;
            }
            OrderService::event((int) $d['order_id'], 'customs', $d['reference'] . ' · ' . ($name ?? t('delivery.customs.none')) . ($ref ? ' · ' . t('delivery.customs.file') . ' ' . $ref : ''));
        });
    }

    private function lockDelivery(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM deliveries WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new DomainException('delivery.error.not_found');
    }
}
