<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\DataTable;
use App\Security\SiteScope;

final class InvoiceRepository
{
    public const SORTABLE = [
        'reference' => 'i.reference',
        'party' => 'party_name',
        'order' => 'o.reference',
        'invoice_date' => 'i.invoice_date',
        'due_date' => 'i.due_date',
        'amount' => 'i.amount',
        'balance' => 'balance',
    ];

    private const PAID_SQL = '(SELECT COALESCE(SUM(py.amount), 0) FROM payments py WHERE py.invoice_id = i.id)';

    /** @param array<string,string> $filters */
    public function datatable(DataTable $dt, array $filters): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $base = 'FROM invoices i JOIN customer_orders o ON o.id = i.order_id
            LEFT JOIN customers c ON c.id = i.customer_id LEFT JOIN suppliers s ON s.id = i.supplier_id
            WHERE 1=1' . $scope;
        $pdo = Database::pdo();
        $total = $pdo->prepare('SELECT COUNT(*) ' . $base);
        $total->execute($params);

        $where = '';
        if (in_array($filters['direction'] ?? '', ['payable', 'receivable'], true)) {
            $where .= ' AND i.direction = ?';
            $params[] = $filters['direction'];
        }
        $status = $filters['status'] ?? '';
        if ($status === 'open') {
            $where .= " AND i.status = 'open'";
        } elseif ($status === 'overdue') {
            $where .= " AND i.status = 'open' AND i.due_date < CURDATE()";
        } elseif ($status === 'due_soon') {
            $where .= " AND i.status = 'open' AND i.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY";
        } elseif (in_array($status, ['paid', 'cancelled'], true)) {
            $where .= ' AND i.status = ?';
            $params[] = $status;
        }
        if ($dt->search !== '') {
            $where .= ' AND (i.reference LIKE ? OR i.external_number LIKE ? OR o.reference LIKE ? OR c.name LIKE ? OR s.name LIKE ?)';
            $like = '%' . $dt->search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $filtered = $pdo->prepare('SELECT COUNT(*) ' . $base . $where);
        $filtered->execute($params);

        $rows = $pdo->prepare('SELECT i.id, i.reference, i.direction, i.external_number, i.invoice_date, i.due_date, i.currency, i.amount,
                i.status, i.order_id, o.reference AS order_reference, COALESCE(c.name, s.name) AS party_name,
                ' . self::PAID_SQL . ' AS paid, i.amount - ' . self::PAID_SQL . ' AS balance,
                (i.status = \'open\' AND i.due_date < CURDATE()) AS overdue '
            . $base . $where . ' ORDER BY ' . $dt->orderBy . ', i.id DESC' . $dt->limitSql());
        $rows->execute($params);
        return [(int) $total->fetchColumn(), (int) $filtered->fetchColumn(), $rows->fetchAll()];
    }

    /**
     * Encours par sens et par devise (pas de conversion implicite des montants).
     * @return array<string,array<string,array{open:float,overdue:float}>> direction → devise → montants
     */
    public function outstanding(): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $stmt = Database::pdo()->prepare('SELECT i.direction, i.currency,
                SUM(i.amount - ' . self::PAID_SQL . ') AS open,
                SUM(CASE WHEN i.due_date < CURDATE() THEN i.amount - ' . self::PAID_SQL . ' ELSE 0 END) AS overdue
            FROM invoices i JOIN customer_orders o ON o.id = i.order_id
            WHERE i.status = \'open\'' . $scope . ' GROUP BY i.direction, i.currency');
        $stmt->execute($params);
        $out = ['receivable' => [], 'payable' => []];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['direction']][$r['currency']] = ['open' => (float) $r['open'], 'overdue' => (float) $r['overdue']];
        }
        return $out;
    }

    /**
     * Fiche complète d'une facture du périmètre : tiers, dossier, PO, règlements, pièces jointes, historique.
     * @return array<string,mixed>|null
     */
    public function details(int $id): ?array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT i.*, ' . self::PAID_SQL . ' AS paid,
                o.reference AS order_reference, o.customer_po_number, o.status AS order_status, o.currency AS order_currency,
                o.payment_terms_days AS order_terms, s2.name AS site_name,
                c.id AS party_customer_id, c.name AS customer_name, c.code AS customer_code, c.phone AS customer_phone, c.email AS customer_email,
                s.name AS supplier_name, s.code AS supplier_code, s.phone AS supplier_phone, s.email AS supplier_email,
                p.reference AS po_reference, p.status AS po_status,
                (SELECT COALESCE(SUM(pl.quantity * pl.unit_price), 0) FROM purchase_order_lines pl WHERE pl.po_id = p.id) AS po_amount,
                u.name AS created_by_name
            FROM invoices i
            JOIN customer_orders o ON o.id = i.order_id
            JOIN sites s2 ON s2.id = o.site_id
            LEFT JOIN customers c ON c.id = i.customer_id
            LEFT JOIN suppliers s ON s.id = i.supplier_id
            LEFT JOIN purchase_orders p ON p.id = i.po_id
            JOIN users u ON u.id = i.created_by
            WHERE i.id = ?' . $scope);
        $stmt->execute([$id, ...$params]);
        $inv = $stmt->fetch();
        if (!$inv) {
            return null;
        }
        $pay = $pdo->prepare('SELECT py.*, u.name AS user_name FROM payments py JOIN users u ON u.id = py.created_by WHERE py.invoice_id = ? ORDER BY py.paid_at, py.id');
        $pay->execute([$id]);
        $inv['payments'] = $pay->fetchAll();

        $att = $pdo->prepare("SELECT a.*, u.name AS user_name FROM attachments a JOIN users u ON u.id = a.uploaded_by
            WHERE a.entity = 'invoice' AND a.entity_id = ? ORDER BY a.id DESC");
        $att->execute([$id]);
        $inv['attachments'] = $att->fetchAll();

        // Historique : la facture et ses règlements (journal d'activité)
        $paymentIds = array_map(fn ($p) => (int) $p['id'], $inv['payments']);
        $sql = "SELECT a.*, u.name AS user_name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
            WHERE (a.entity = 'invoice' AND a.entity_id = ?)";
        $hp = [$id];
        if ($paymentIds) {
            $sql .= " OR (a.entity = 'payment' AND a.entity_id IN (" . implode(',', $paymentIds) . '))';
        }
        $hist = $pdo->prepare($sql . ' ORDER BY a.id DESC LIMIT 50');
        $hist->execute($hp);
        $inv['history'] = $hist->fetchAll();

        // Contexte du dossier : valeur livrée et déjà facturée (même sens), pour situer cette facture
        if ($inv['direction'] === 'receivable') {
            $ctx = $pdo->prepare("SELECT
                    (SELECT COALESCE(SUM(l.quantity * l.unit_price), 0) FROM customer_order_lines l WHERE l.order_id = o.id) AS ordered_value,
                    (SELECT COALESCE(SUM(dl.quantity_received * col.unit_price), 0) FROM delivery_lines dl
                        JOIN deliveries d ON d.id = dl.delivery_id AND d.status = 'delivered'
                        JOIN purchase_order_lines pl ON pl.id = dl.po_line_id JOIN customer_order_lines col ON col.id = pl.order_line_id
                        WHERE d.order_id = o.id) AS delivered_value,
                    (SELECT COALESCE(SUM(x.amount), 0) FROM invoices x WHERE x.order_id = o.id AND x.direction = 'receivable' AND x.status <> 'cancelled') AS invoiced_value
                FROM customer_orders o WHERE o.id = ?");
            $ctx->execute([$inv['order_id']]);
            $inv['context'] = $ctx->fetch();
        } else {
            $ctx = $pdo->prepare("SELECT (SELECT COALESCE(SUM(x.amount), 0) FROM invoices x WHERE x.po_id = ? AND x.status <> 'cancelled') AS invoiced_value");
            $ctx->execute([$inv['po_id']]);
            $inv['context'] = $ctx->fetch() + ['ordered_value' => $inv['po_amount'], 'delivered_value' => null];
        }
        return $inv;
    }

    /** @return array<string,mixed>|null Facture du périmètre */
    public function find(int $id): ?array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $stmt = Database::pdo()->prepare('SELECT i.*, ' . self::PAID_SQL . ' AS paid FROM invoices i
            JOIN customer_orders o ON o.id = i.order_id WHERE i.id = ?' . $scope);
        $stmt->execute([$id, ...$params]);
        return $stmt->fetch() ?: null;
    }
}
