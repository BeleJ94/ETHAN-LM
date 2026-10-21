<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\DataTable;
use App\Domain\Checklist;
use App\Security\SiteScope;

/** Liste des expéditions (tableau serveur) avec filtres et avancement documentaire calculé en SQL. */
final class DeliveryRepository
{
    public const SORTABLE = [
        'reference' => 'd.reference',
        'po' => 'p.reference',
        'customer_po' => 'o.customer_po_number',
        'order' => 'o.reference',
        'delivered_at' => 'd.delivered_at',
        'transport' => 'd.transport_mode',
        'forwarder' => 'fw.name',
        'customer' => 'c.name',
        'supplier' => 's.name',
        'shipped_at' => 'd.shipped_at',
        'shipped' => 'shipped',
        'received' => 'received',
        'gap' => 'gap',
        'docs' => 'docs_ratio',
        'status' => "FIELD(d.status, 'planned', 'in_transit', 'delivered', 'cancelled')",
    ];

    /** Documents d'une expédition = ses documents + ceux de niveau dossier de son PO client. */
    private const DOC_SCOPE = 'FROM shipment_documents sd WHERE sd.order_id = d.order_id AND (sd.delivery_id = d.id OR sd.delivery_id IS NULL)';
    private const DOCS_TOTAL = '(SELECT COUNT(*) ' . self::DOC_SCOPE . ' AND sd.required = 1)';
    private const DOCS_DONE = "(SELECT COUNT(*) " . self::DOC_SCOPE . " AND sd.required = 1
        AND (sd.status IN ('waived','not_applicable') OR (sd.status = 'validated' AND (sd.expires_at IS NULL OR sd.expires_at >= CURDATE()))))";
    private const DOCS_TO_VALIDATE = "(SELECT COUNT(*) " . self::DOC_SCOPE . " AND sd.status = 'submitted')";
    private const SHIPPED = '(SELECT COALESCE(SUM(dl.quantity_shipped), 0) FROM delivery_lines dl WHERE dl.delivery_id = d.id)';
    private const RECEIVED = '(SELECT SUM(dl.quantity_received) FROM delivery_lines dl WHERE dl.delivery_id = d.id)';

    /** @param array<string,string> $f */
    public function datatable(DataTable $dt, array $f): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $base = 'FROM deliveries d
            JOIN purchase_orders p ON p.id = d.po_id
            JOIN suppliers s ON s.id = p.supplier_id
            JOIN customer_orders o ON o.id = d.order_id
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN customs_agencies fw ON fw.id = COALESCE(d.forwarder_id, p.forwarder_id)
            LEFT JOIN delivery_sites ds ON ds.id = p.delivery_site_id
            WHERE 1=1' . $scope;
        $pdo = Database::pdo();
        $total = $pdo->prepare('SELECT COUNT(*) ' . $base);
        $total->execute($params);

        $where = '';
        $status = $f['status'] ?? '';
        if ($status === 'open') {
            $where .= " AND d.status IN ('planned','in_transit')";
        } elseif (in_array($status, ['planned', 'in_transit', 'delivered', 'cancelled'], true)) {
            $where .= ' AND d.status = ?';
            $params[] = $status;
        }
        foreach (['customer_id' => 'o.customer_id', 'supplier_id' => 'p.supplier_id', 'forwarder_id' => 'COALESCE(d.forwarder_id, p.forwarder_id)', 'order_id' => 'd.order_id'] as $key => $col) {
            if (($f[$key] ?? '') !== '' && ctype_digit((string) $f[$key])) {
                $where .= " AND $col = ?";
                $params[] = (int) $f[$key];
            }
        }
        if (in_array($f['transport_mode'] ?? '', Checklist::TRANSPORTS, true)) {
            $where .= ' AND d.transport_mode = ?';
            $params[] = $f['transport_mode'];
        }
        $period = $f['period'] ?? '';
        if (in_array($period, ['7', '30', '90'], true)) {
            $where .= ' AND d.shipped_at >= CURDATE() - INTERVAL ' . (int) $period . ' DAY';
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $v = (string) ($f[$key] ?? '');
            if (\DateTimeImmutable::createFromFormat('!Y-m-d', $v) !== false) {
                $where .= " AND d.shipped_at $op ?";
                $params[] = $v;
            }
        }
        $docs = $f['docs'] ?? '';
        if ($docs === 'incomplete') {
            $where .= ' AND d.status <> \'cancelled\' AND ' . self::DOCS_DONE . ' < ' . self::DOCS_TOTAL;
        } elseif ($docs === 'to_validate') {
            $where .= ' AND ' . self::DOCS_TO_VALIDATE . ' > 0';
        } elseif ($docs === 'complete') {
            $where .= ' AND ' . self::DOCS_DONE . ' >= ' . self::DOCS_TOTAL;
        }
        if (($f['gap'] ?? '') === '1') {
            $where .= " AND d.status = 'delivered' AND " . self::SHIPPED . ' > COALESCE(' . self::RECEIVED . ', 0)';
        }
        if ($dt->search !== '') {
            $where .= ' AND (d.reference LIKE ? OR d.vehicle_plate LIKE ? OR d.driver_name LIKE ? OR d.supplier_waybill LIKE ? OR d.forwarder_ref LIKE ? OR fw.name LIKE ?
                OR o.reference LIKE ? OR o.customer_po_number LIKE ? OR p.reference LIKE ? OR c.name LIKE ? OR s.name LIKE ?)';
            $like = '%' . $dt->search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $filtered = $pdo->prepare('SELECT COUNT(*) ' . $base . $where);
        $filtered->execute($params);

        $rows = $pdo->prepare('SELECT d.id, d.reference, d.status, d.transport_mode, d.shipped_at, d.delivered_at, d.vehicle_plate, d.driver_name,
                d.trailer_plate, d.driver_phone, d.transporter, d.supplier_waybill, d.received_by, o.customer_po_number,
                d.order_id, o.reference AS order_reference, p.reference AS po_reference, c.name AS customer_name, s.name AS supplier_name,
                fw.name AS forwarder_name, d.forwarder_ref, ds.name AS delivery_site_name,
                ' . self::SHIPPED . ' AS shipped, ' . self::RECEIVED . ' AS received,
                ' . self::SHIPPED . ' - COALESCE(' . self::RECEIVED . ', ' . self::SHIPPED . ') AS gap,
                (SELECT MIN(u.code) FROM delivery_lines dl JOIN purchase_order_lines pl ON pl.id = dl.po_line_id
                    JOIN items i ON i.id = pl.item_id JOIN units u ON u.id = i.unit_id WHERE dl.delivery_id = d.id) AS unit,
                (SELECT GROUP_CONCAT(DISTINCT i.sku ORDER BY i.sku SEPARATOR \', \') FROM delivery_lines dl
                    JOIN purchase_order_lines pl ON pl.id = dl.po_line_id JOIN items i ON i.id = pl.item_id WHERE dl.delivery_id = d.id) AS products,
                ' . self::DOCS_DONE . ' AS docs_done, ' . self::DOCS_TOTAL . ' AS docs_total, ' . self::DOCS_TO_VALIDATE . ' AS docs_to_validate,
                COALESCE(' . self::DOCS_DONE . ' / NULLIF(' . self::DOCS_TOTAL . ', 0), 1) AS docs_ratio '
            . $base . $where . ' ORDER BY ' . $dt->orderBy . ', d.id DESC' . $dt->limitSql());
        $rows->execute($params);

        return [(int) $total->fetchColumn(), (int) $filtered->fetchColumn(), $rows->fetchAll()];
    }

    /** @return array<string,int> Compteurs par statut (cartes cliquables) */
    public function counts(): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $stmt = Database::pdo()->prepare("SELECT
                SUM(d.status IN ('planned','in_transit')) AS open, SUM(d.status = 'planned') AS planned,
                SUM(d.status = 'in_transit') AS in_transit, SUM(d.status = 'delivered') AS delivered,
                SUM(d.status <> 'cancelled' AND " . self::DOCS_DONE . ' < ' . self::DOCS_TOTAL . ') AS incomplete
            FROM deliveries d JOIN customer_orders o ON o.id = d.order_id WHERE 1=1' . $scope);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetch() ?: []) + ['open' => 0, 'planned' => 0, 'in_transit' => 0, 'delivered' => 0, 'incomplete' => 0];
    }
}
