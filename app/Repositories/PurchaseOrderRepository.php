<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\SiteScope;

final class PurchaseOrderRepository
{
    /** @return array<string,mixed>|null PO fournisseur du périmètre (via son PO client) */
    public function find(int $id): ?array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $stmt = Database::pdo()->prepare('SELECT p.*, s.name AS supplier_name, s.code AS supplier_code, s.address AS supplier_address,
                s.city AS supplier_city, s.contact_name AS supplier_contact, s.phone AS supplier_phone, s.email AS supplier_email,
                s.nif AS supplier_nif, s.rccm AS supplier_rccm,
                o.reference AS order_reference, o.customer_po_number, o.site_id, o.fulfillment_mode, o.status AS order_status,
                c.name AS customer_name, u.name AS created_by_name
            FROM purchase_orders p
            JOIN suppliers s ON s.id = p.supplier_id
            JOIN customer_orders o ON o.id = p.order_id
            JOIN customers c ON c.id = o.customer_id
            JOIN users u ON u.id = p.created_by
            WHERE p.id = ?' . $scope);
        $stmt->execute([$id, ...$params]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Lignes avec quantités en route, chargées (livraisons arrivées) et réceptionnées par le client.
     * Reste à expédier = quantité − shipped − in_transit.
     * @return list<array<string,mixed>>
     */
    public function lines(int $poId): array
    {
        $stmt = Database::pdo()->prepare("SELECT pl.*, i.sku, i.name AS item_name, i.hs_code, u.code AS unit, col.specification,
                COALESCE((SELECT SUM(dl.quantity_shipped) FROM delivery_lines dl JOIN deliveries d ON d.id = dl.delivery_id
                    WHERE dl.po_line_id = pl.id AND d.status = 'delivered'), 0) AS shipped,
                COALESCE((SELECT SUM(dl.quantity_shipped) FROM delivery_lines dl JOIN deliveries d ON d.id = dl.delivery_id
                    WHERE dl.po_line_id = pl.id AND d.status IN ('planned', 'in_transit')), 0) AS in_transit,
                COALESCE((SELECT SUM(dl.quantity_received) FROM delivery_lines dl JOIN deliveries d ON d.id = dl.delivery_id
                    WHERE dl.po_line_id = pl.id AND d.status = 'delivered'), 0) AS received
            FROM purchase_order_lines pl
            JOIN items i ON i.id = pl.item_id JOIN units u ON u.id = i.unit_id
            JOIN customer_order_lines col ON col.id = pl.order_line_id
            WHERE pl.po_id = ? ORDER BY col.line_no, pl.id");
        $stmt->execute([$poId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> Liste du périmètre */
    public function all(string $status = ''): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $where = '';
        if ($status === 'open') {
            $where = " AND p.status IN ('draft','sent','partial')";
        } elseif ($status !== '') {
            $where = ' AND p.status = ?';
            $params[] = $status;
        }
        $stmt = Database::pdo()->prepare('SELECT p.*, s.name AS supplier_name, o.reference AS order_reference, c.name AS customer_name,
                (SELECT COALESCE(SUM(pl.quantity * pl.unit_price), 0) FROM purchase_order_lines pl WHERE pl.po_id = p.id) AS amount,
                (SELECT COALESCE(SUM(pl.quantity), 0) FROM purchase_order_lines pl WHERE pl.po_id = p.id) AS qty,
                (SELECT COALESCE(SUM(dl.quantity_received), 0) FROM delivery_lines dl JOIN deliveries d ON d.id = dl.delivery_id AND d.status = \'delivered\'
                    JOIN purchase_order_lines pl ON pl.id = dl.po_line_id WHERE pl.po_id = p.id) AS received
            FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id
            JOIN customer_orders o ON o.id = p.order_id JOIN customers c ON c.id = o.customer_id
            WHERE 1=1' . $scope . $where . ' ORDER BY p.id DESC LIMIT 1000');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> Livraisons du périmètre */
    public function deliveries(string $status = ''): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $where = '';
        if ($status !== '') {
            $where = ' AND d.status = ?';
            $params[] = $status;
        }
        $stmt = Database::pdo()->prepare('SELECT d.*, p.reference AS po_reference, s.name AS supplier_name, o.reference AS order_reference,
                c.name AS customer_name, ds.name AS delivery_site_name,
                (SELECT COALESCE(SUM(dl.quantity_shipped), 0) FROM delivery_lines dl WHERE dl.delivery_id = d.id) AS shipped,
                (SELECT SUM(dl.quantity_received) FROM delivery_lines dl WHERE dl.delivery_id = d.id) AS received,
                (SELECT MIN(u.code) FROM delivery_lines dl JOIN purchase_order_lines pl ON pl.id = dl.po_line_id
                    JOIN items i ON i.id = pl.item_id JOIN units u ON u.id = i.unit_id WHERE dl.delivery_id = d.id) AS unit
            FROM deliveries d JOIN purchase_orders p ON p.id = d.po_id JOIN suppliers s ON s.id = p.supplier_id
            JOIN customer_orders o ON o.id = d.order_id JOIN customers c ON c.id = o.customer_id
            LEFT JOIN delivery_sites ds ON ds.id = p.delivery_site_id
            WHERE 1=1' . $scope . $where . ' ORDER BY d.status = \'in_transit\' DESC, d.shipped_at DESC, d.id DESC LIMIT 1000');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null Livraison du périmètre ($scoped = false : accès déjà contrôlé, ex. portail transitaire) */
    public function findDelivery(int $id, bool $scoped = true): ?array
    {
        [$scope, $params] = $scoped ? SiteScope::clause('o.site_id') : ['', []];
        $stmt = Database::pdo()->prepare('SELECT d.*, p.reference AS po_reference, p.delivery_address, p.delivery_contact,
                s.name AS supplier_name, o.reference AS order_reference, o.customer_po_number, o.fulfillment_mode, o.status AS order_status,
                c.name AS customer_name, fw.name AS forwarder_name
            FROM deliveries d JOIN purchase_orders p ON p.id = d.po_id JOIN suppliers s ON s.id = p.supplier_id
            JOIN customer_orders o ON o.id = d.order_id JOIN customers c ON c.id = o.customer_id
            LEFT JOIN customs_agencies fw ON fw.id = COALESCE(d.forwarder_id, p.forwarder_id)
            WHERE d.id = ?' . $scope);
        $stmt->execute([$id, ...$params]);
        $d = $stmt->fetch();
        if (!$d) {
            return null;
        }
        $lines = Database::pdo()->prepare('SELECT dl.*, i.sku, i.name AS item_name, u.code AS unit, col.specification FROM delivery_lines dl
            JOIN purchase_order_lines pl ON pl.id = dl.po_line_id JOIN customer_order_lines col ON col.id = pl.order_line_id
            JOIN items i ON i.id = pl.item_id JOIN units u ON u.id = i.unit_id WHERE dl.delivery_id = ?');
        $lines->execute([$id]);
        $d['lines'] = $lines->fetchAll();
        // Importation : AV qui couvrent chaque ligne (traçabilité AV → camion)
        $avs = (new ImportRepository())->allocationsByDelivery([(int) $d['id']])[(int) $d['id']] ?? [];
        foreach ($d['lines'] as &$l) {
            $l['avs'] = $avs[(int) $l['po_line_id']] ?? [];
        }
        unset($l);
        return $d;
    }
}
