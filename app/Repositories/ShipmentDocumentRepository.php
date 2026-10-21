<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\CurrentUser;
use App\Security\SiteScope;

/** Check-lists figées des expéditions et dossiers, avec contrôle d'accès interne (sites) ou transitaire. */
final class ShipmentDocumentRepository
{
    private const SELECT = 'SELECT sd.*, t.code, t.name, t.provider, t.level, t.requires_number, t.requires_file, t.requires_issue_date, t.has_expiry,
            a.original_name AS file_name, a.mime AS file_mime,
            CASE WHEN sd.delivery_id IS NOT NULL
                THEN EXISTS (SELECT 1 FROM deliveries d2 JOIN purchase_orders p2 ON p2.id = d2.po_id WHERE d2.id = sd.delivery_id AND COALESCE(d2.forwarder_id, p2.forwarder_id) IS NOT NULL)
                ELSE (EXISTS (SELECT 1 FROM purchase_orders p3 WHERE p3.order_id = sd.order_id AND p3.status <> \'cancelled\' AND p3.forwarder_id IS NOT NULL)
                    OR EXISTS (SELECT 1 FROM deliveries d3 WHERE d3.order_id = sd.order_id AND d3.status <> \'cancelled\' AND d3.forwarder_id IS NOT NULL))
            END AS agency_ok,
            us.name AS submitted_by_name, ud.name AS decided_by_name, d.reference AS delivery_reference
        FROM shipment_documents sd
        JOIN document_types t ON t.id = sd.document_type_id
        LEFT JOIN attachments a ON a.id = sd.attachment_id
        LEFT JOIN users us ON us.id = sd.submitted_by
        LEFT JOIN users ud ON ud.id = sd.decided_by
        LEFT JOIN deliveries d ON d.id = sd.delivery_id';

    /** @return list<array<string,mixed>> Documents d'une expédition + documents de niveau dossier de son PO client */
    public function forDelivery(int $deliveryId, int $orderId): array
    {
        $stmt = Database::pdo()->prepare(self::SELECT . ' WHERE sd.order_id = ? AND (sd.delivery_id = ? OR sd.delivery_id IS NULL)
            ORDER BY sd.delivery_id IS NOT NULL, t.sort_order, t.name');
        $stmt->execute([$orderId, $deliveryId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> Tous les documents du dossier (hors expéditions annulées) */
    public function forOrder(int $orderId, bool $orderLevelOnly = false): array
    {
        $stmt = Database::pdo()->prepare(self::SELECT . ' WHERE sd.order_id = ?
            AND (sd.delivery_id IS NULL OR d.status <> \'cancelled\')' . ($orderLevelOnly ? ' AND sd.delivery_id IS NULL' : '') . '
            ORDER BY sd.delivery_id IS NOT NULL, d.id, t.sort_order, t.name');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(self::SELECT . ' WHERE sd.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Droit de voir / déposer : interne = PO client dans le périmètre de sites ;
     * transitaire = expédition (ou PO) confiée à son entreprise, ou document dossier d'un PO client qu'il traite.
     */
    public function canAccess(CurrentUser $user, array $doc): bool
    {
        if ($user->isForwarder()) {
            return $user->forwarderId !== null && in_array((int) $doc['order_id'], $this->forwarderOrderIds($user->forwarderId), true)
                && ($doc['delivery_id'] === null || in_array((int) $doc['delivery_id'], $this->forwarderDeliveryIds($user->forwarderId), true));
        }
        return (new OrderRepository())->find((int) $doc['order_id']) !== null;
    }

    /** @return list<int> */
    public function forwarderDeliveryIds(int $forwarderId): array
    {
        $stmt = Database::pdo()->prepare('SELECT d.id FROM deliveries d JOIN purchase_orders p ON p.id = d.po_id
            WHERE d.status <> \'cancelled\' AND (d.forwarder_id = ? OR (d.forwarder_id IS NULL AND p.forwarder_id = ?))');
        $stmt->execute([$forwarderId, $forwarderId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    public function forwarderOrderIds(int $forwarderId): array
    {
        $stmt = Database::pdo()->prepare('SELECT DISTINCT p.order_id FROM purchase_orders p LEFT JOIN deliveries d ON d.po_id = p.id
            WHERE p.status <> \'cancelled\' AND (p.forwarder_id = ? OR d.forwarder_id = ?)');
        $stmt->execute([$forwarderId, $forwarderId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Avancement documentaire par expédition (liste des livraisons) : id → [done, total, pending_validation].
     * @param list<int> $deliveryIds
     * @return array<int,array{done:int,total:int,to_validate:int}>
     */
    public function summaries(array $deliveryIds): array
    {
        if ($deliveryIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $deliveryIds));
        $rows = Database::pdo()->query("SELECT d.id AS delivery_id, sd.required, sd.status, sd.expires_at, sd.blocking_stage
            FROM deliveries d JOIN shipment_documents sd ON sd.order_id = d.order_id AND (sd.delivery_id = d.id OR sd.delivery_id IS NULL)
            WHERE d.id IN ($in)")->fetchAll();
        $out = [];
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $id = (int) $r['delivery_id'];
            $out[$id] ??= ['done' => 0, 'total' => 0, 'to_validate' => 0];
            if ($r['required']) {
                $out[$id]['total']++;
                $out[$id]['done'] += \App\Domain\Checklist::isSatisfied($r, $today) ? 1 : 0;
            }
            $out[$id]['to_validate'] += $r['status'] === 'submitted' ? 1 : 0;
        }
        return $out;
    }

    /** @return array{to_validate:int,missing_blocking:int,expiring:int} Indicateurs du tableau de bord (périmètre de sites) */
    public function kpis(): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $stmt = Database::pdo()->prepare("SELECT
                SUM(sd.status = 'submitted') AS to_validate,
                SUM(sd.required = 1 AND sd.blocking_stage IS NOT NULL AND sd.status IN ('pending','rejected') AND o.status NOT IN ('closed','cancelled')) AS missing_blocking,
                SUM(sd.status = 'validated' AND sd.expires_at IS NOT NULL AND sd.expires_at <= CURDATE() + INTERVAL 30 DAY) AS expiring
            FROM shipment_documents sd JOIN customer_orders o ON o.id = sd.order_id
            LEFT JOIN deliveries d ON d.id = sd.delivery_id
            WHERE (d.id IS NULL OR d.status <> 'cancelled')" . $scope);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetch() ?: []) + ['to_validate' => 0, 'missing_blocking' => 0, 'expiring' => 0];
    }
}
