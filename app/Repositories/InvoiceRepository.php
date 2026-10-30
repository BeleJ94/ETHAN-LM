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
        'direction' => 'i.direction',
        'party' => 'party_name',
        'order' => 'o.reference',
        'delivery' => 'dv.reference',
        'site' => 'st.code',
        'po' => 'p.reference',
        'invoice_date' => 'i.invoice_date',
        'due_date' => 'i.due_date',
        'subtotal' => 'subtotal',
        'vat' => 'i.vat_amount',
        'amount' => 'i.amount',
        'paid' => 'paid',
        'balance' => 'balance',
        'status' => 'i.status',
        'created_by' => 'uc.name',
        'created_at' => 'i.created_at',
    ];

    /** Onglets de la liste (filtre « status ») : à approuver, à régler, échues, échéance sous 7 jours, soldées, annulées. */
    public const TABS = ['draft', 'open', 'overdue', 'due_soon', 'paid', 'cancelled'];

    private const PAID_SQL = '(SELECT COALESCE(SUM(py.amount), 0) FROM payments py WHERE py.invoice_id = i.id)';

    private const FROM_SQL = 'FROM invoices i JOIN customer_orders o ON o.id = i.order_id JOIN sites st ON st.id = o.site_id
            LEFT JOIN customers c ON c.id = i.customer_id LEFT JOIN suppliers s ON s.id = i.supplier_id
            LEFT JOIN deliveries dv ON dv.id = i.delivery_id LEFT JOIN purchase_orders p ON p.id = i.po_id
            JOIN users uc ON uc.id = i.created_by LEFT JOIN users ua ON ua.id = i.approved_by';

    /** @param array<string,string> $filters */
    public function datatable(DataTable $dt, array $filters): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        // Sens de la page (clients / fournisseurs) : fait partie du total, pas des filtres
        $dir = in_array($filters['direction'] ?? '', ['receivable', 'payable'], true) ? " AND i.direction = '" . $filters['direction'] . "'" : '';
        $base = self::FROM_SQL . ' WHERE 1=1' . $dir . $scope;
        unset($filters['direction']);
        $pdo = Database::pdo();
        $total = $pdo->prepare('SELECT COUNT(*) ' . $base);
        $total->execute($params);

        [$where, $extra] = self::filterSql($filters);
        $params = [...$params, ...$extra];
        if ($dt->search !== '') {
            $where .= ' AND (i.reference LIKE ? OR i.external_number LIKE ? OR o.reference LIKE ? OR o.customer_po_number LIKE ?
                OR c.name LIKE ? OR s.name LIKE ? OR dv.reference LIKE ? OR p.reference LIKE ?)';
            $params = [...$params, ...array_fill(0, 8, '%' . $dt->search . '%')];
        }
        $filtered = $pdo->prepare('SELECT COUNT(*) ' . $base . $where);
        $filtered->execute($params);

        $rows = $pdo->prepare('SELECT i.id, i.reference, i.direction, i.external_number, i.invoice_date, i.due_date, i.currency,
                COALESCE(i.subtotal, i.amount) AS subtotal, i.vat_rate, i.vat_amount, i.amount, i.status, i.order_id, i.created_at,
                o.reference AS order_reference, o.customer_po_number, st.code AS site, COALESCE(c.name, s.name) AS party_name,
                dv.reference AS delivery_reference, p.reference AS po_reference, uc.name AS created_by_name, ua.name AS approved_by_name,
                (SELECT a.id FROM attachments a WHERE a.entity = \'invoice\' AND a.entity_id = i.id ORDER BY a.id DESC LIMIT 1) AS attachment_id,
                (SELECT a.mime FROM attachments a WHERE a.entity = \'invoice\' AND a.entity_id = i.id ORDER BY a.id DESC LIMIT 1) AS attachment_mime,
                ' . self::PAID_SQL . ' AS paid, i.amount - ' . self::PAID_SQL . ' AS balance,
                (i.status = \'open\' AND i.due_date < CURDATE()) AS overdue,
                CASE WHEN i.status = \'open\' THEN DATEDIFF(i.due_date, CURDATE()) END AS days_left '
            . $base . $where . ' ORDER BY ' . $dt->orderBy . ', i.id DESC' . $dt->limitSql());
        $rows->execute($params);
        return [(int) $total->fetchColumn(), (int) $filtered->fetchColumn(), $rows->fetchAll()];
    }

    /**
     * Filtres de la liste (onglet, filtres compacts, volet « Plus de filtres »).
     * @param array<string,string> $f
     * @return array{0:string,1:list<mixed>}
     */
    private static function filterSql(array $f, bool $withStatus = true): array
    {
        $where = '';
        $params = [];
        if ($withStatus) {
            $where .= match ($f['status'] ?? '') {
                'draft', 'paid', 'cancelled' => " AND i.status = '" . $f['status'] . "'",
                'open' => " AND i.status = 'open'",
                'overdue' => " AND i.status = 'open' AND i.due_date < CURDATE()",
                'due_soon' => " AND i.status = 'open' AND i.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY",
                default => '',
            };
        }
        if (in_array($f['direction'] ?? '', ['payable', 'receivable'], true)) {
            $where .= ' AND i.direction = ?';
            $params[] = $f['direction'];
        }
        // Tiers : « c:ID » client ou « s:ID » fournisseur
        if (preg_match('/^([cs]):(\d+)$/', $f['party'] ?? '', $m)) {
            $where .= $m[1] === 'c' ? ' AND i.customer_id = ?' : ' AND i.supplier_id = ?';
            $params[] = (int) $m[2];
        }
        if (($f['site_id'] ?? '') !== '') {
            $where .= ' AND o.site_id = ?';
            $params[] = (int) $f['site_id'];
        }
        if (($f['currency'] ?? '') !== '') {
            $where .= ' AND i.currency = ?';
            $params[] = $f['currency'];
        }
        if (($f['vat'] ?? '') === 'with') {
            $where .= ' AND i.vat_rate IS NOT NULL';
        } elseif (($f['vat'] ?? '') === 'without') {
            $where .= ' AND i.vat_rate IS NULL';
        }
        if (($f['delivery'] ?? '') === 'with') {
            $where .= ' AND i.delivery_id IS NOT NULL';
        } elseif (($f['delivery'] ?? '') === 'without') {
            $where .= ' AND i.delivery_id IS NULL';
        }
        if (($f['payment'] ?? '') === 'partial') {
            $where .= " AND i.status = 'open' AND " . self::PAID_SQL . ' > 0';
        } elseif (($f['payment'] ?? '') === 'none') {
            $where .= " AND i.status IN ('draft','open') AND " . self::PAID_SQL . ' = 0';
        }
        foreach (['from' => 'i.invoice_date >= ?', 'to' => 'i.invoice_date <= ?', 'due_from' => 'i.due_date >= ?', 'due_to' => 'i.due_date <= ?'] as $k => $sql) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k] ?? '')) {
                $where .= ' AND ' . $sql;
                $params[] = $f[$k];
            }
        }
        return [$where, $params];
    }

    /**
     * Compteurs des onglets, avec les autres filtres appliqués (sens, tiers…).
     * @param array<string,string> $filters
     * @return array<string,int>
     */
    public function tabCounts(array $filters): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        [$where, $extra] = self::filterSql($filters, false);
        $stmt = Database::pdo()->prepare("SELECT
                SUM(i.status = 'draft') AS draft, SUM(i.status = 'open') AS open,
                SUM(i.status = 'open' AND i.due_date < CURDATE()) AS overdue,
                SUM(i.status = 'open' AND i.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY) AS due_soon,
                SUM(i.status = 'paid') AS paid, SUM(i.status = 'cancelled') AS cancelled, COUNT(*) AS all_invoices
            " . self::FROM_SQL . ' WHERE 1=1' . $scope . $where);
        $stmt->execute([...$params, ...$extra]);
        $row = $stmt->fetch() ?: [];
        return array_map('intval', array_map(fn ($v) => $v ?? 0, $row));
    }

    /** Tiers présents dans les factures du périmètre (filtre « Tiers »). @return array{customers:list<array>,suppliers:list<array>} */
    public function parties(): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $out = [];
        foreach (['customers' => ['c', 'customer_id'], 'suppliers' => ['s', 'supplier_id']] as $k => [$alias, $fk]) {
            $stmt = Database::pdo()->prepare("SELECT DISTINCT $alias.id, $alias.name FROM invoices i JOIN customer_orders o ON o.id = i.order_id
                JOIN " . ($alias === 'c' ? 'customers c' : 'suppliers s') . " ON $alias.id = i.$fk WHERE 1=1" . $scope . " ORDER BY $alias.name");
            $stmt->execute($params);
            $out[$k] = $stmt->fetchAll();
        }
        return $out;
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
                p.reference AS po_reference, p.status AS po_status, dv.id AS delivery_ref_id, dv.reference AS delivery_reference,
                (SELECT COALESCE(SUM(pl.quantity * pl.unit_price), 0) FROM purchase_order_lines pl WHERE pl.po_id = p.id) AS po_amount,
                u.name AS created_by_name, ua.name AS approved_by_name
            FROM invoices i
            JOIN customer_orders o ON o.id = i.order_id
            JOIN sites s2 ON s2.id = o.site_id
            LEFT JOIN customers c ON c.id = i.customer_id
            LEFT JOIN suppliers s ON s.id = i.supplier_id
            LEFT JOIN purchase_orders p ON p.id = i.po_id
            LEFT JOIN deliveries dv ON dv.id = i.delivery_id
            JOIN users u ON u.id = i.created_by
            LEFT JOIN users ua ON ua.id = i.approved_by
            WHERE i.id = ?' . $scope);
        $stmt->execute([$id, ...$params]);
        $inv = $stmt->fetch();
        if (!$inv) {
            return null;
        }
        $pay = $pdo->prepare('SELECT py.*, u.name AS user_name FROM payments py JOIN users u ON u.id = py.created_by WHERE py.invoice_id = ? ORDER BY py.paid_at, py.id');
        $pay->execute([$id]);
        $inv['payments'] = $pay->fetchAll();

        $lines = $pdo->prepare('SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY line_no');
        $lines->execute([$id]);
        $inv['lines'] = $lines->fetchAll();

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
                    (SELECT COALESCE(SUM(COALESCE(x.subtotal, x.amount)), 0) FROM invoices x WHERE x.order_id = o.id AND x.direction = 'receivable' AND x.status <> 'cancelled') AS invoiced_value
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
