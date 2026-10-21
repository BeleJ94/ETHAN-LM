<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\DataTable;
use App\Domain\OrderNextAction;
use App\Security\SiteScope;

/**
 * PO client et tout son dossier (lignes, devis, PO fournisseurs, livraisons, factures, chronologie).
 * Périmètre : customer_orders.site_id ; les entités liées héritent du périmètre de leur PO client.
 */
final class OrderRepository
{
    public const SORTABLE = [
        'reference' => 'o.reference',
        'customer' => 'c.name',
        'po_date' => 'o.po_date',
        'required_date' => 'o.required_date',
        'amount' => 'amount',
        'progress' => 'progress',
        'status' => 'o.status',
        'site' => 's.code',
    ];

    /** Tous les statuts d'un PO client. */
    public const STATUSES = ['received', 'sourcing', 'awaiting_licence', 'inspection', 'blocked', 'ordered', 'in_delivery', 'delivered', 'closed', 'cancelled'];

    /** En retard : date souhaitée dépassée et dossier non livré / terminé. */
    private const LATE_SQL = "(o.required_date < CURDATE() AND o.status NOT IN ('delivered','closed','cancelled'))";

    /** Sous-requêtes d'avancement réutilisées par la liste. */
    private const AMOUNT_SQL = '(SELECT COALESCE(SUM(l.quantity * l.unit_price), 0) FROM customer_order_lines l WHERE l.order_id = o.id)';
    private const PROGRESS_SQL = '(SELECT COALESCE(SUM(LEAST(l.quantity, COALESCE((SELECT SUM(dl.quantity_received) FROM delivery_lines dl
            JOIN deliveries d ON d.id = dl.delivery_id AND d.status = \'delivered\'
            JOIN purchase_order_lines pl ON pl.id = dl.po_line_id WHERE pl.order_line_id = l.id), 0)) / NULLIF(l.quantity, 0)) / COUNT(*) * 100, 0)
        FROM customer_order_lines l WHERE l.order_id = o.id)';

    /** @param array<string,string> $filters */
    public function datatable(DataTable $dt, array $filters): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $base = 'FROM customer_orders o JOIN customers c ON c.id = o.customer_id JOIN sites s ON s.id = o.site_id WHERE 1=1' . $scope;
        $pdo = Database::pdo();
        $total = $pdo->prepare('SELECT COUNT(*) ' . $base);
        $total->execute($params);

        $where = '';
        // Onglet de statut : groupe (à sourcer, import…), condition spéciale (en cours, en retard) ou statut exact
        $status = $filters['status'] ?? '';
        $groups = OrderNextAction::GROUPS;
        if ($status === 'open') {
            $where .= " AND o.status NOT IN ('closed','cancelled')";
        } elseif ($status === 'late') {
            $where .= ' AND ' . self::LATE_SQL;
        } elseif (isset($groups[$status]) && $groups[$status] !== null) {
            $where .= ' AND o.status IN (' . implode(',', array_fill(0, count($groups[$status]), '?')) . ')';
            array_push($params, ...$groups[$status]);
        } elseif ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where .= ' AND o.status = ?';
            $params[] = $status;
        }
        // Statut détaillé (volet « Plus de filtres »), combiné à l'onglet
        if (in_array($filters['detail_status'] ?? '', self::STATUSES, true)) {
            $where .= ' AND o.status = ?';
            $params[] = $filters['detail_status'];
        }
        if (($filters['site_id'] ?? '') !== '') {
            $where .= ' AND o.site_id = ?';
            $params[] = (int) $filters['site_id'];
        }
        if (($filters['currency'] ?? '') !== '') {
            $where .= ' AND o.currency = ?';
            $params[] = $filters['currency'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$k] ?? '')) {
                $where .= " AND o.po_date $op ?";
                $params[] = $filters[$k];
            }
        }
        // Échéance : dépassée, ou à venir sous N jours
        $due = $filters['due'] ?? '';
        if ($due === 'overdue') {
            $where .= ' AND ' . self::LATE_SQL;
        } elseif (in_array($due, ['7', '30'], true)) {
            $where .= " AND o.status NOT IN ('delivered','closed','cancelled') AND o.required_date BETWEEN CURDATE() AND CURDATE() + INTERVAL $due DAY";
        }
        if (($filters['customer_id'] ?? '') !== '') {
            $where .= ' AND o.customer_id = ?';
            $params[] = (int) $filters['customer_id'];
        }
        if (($filters['mode'] ?? '') !== '') {
            $where .= ' AND o.fulfillment_mode = ?';
            $params[] = $filters['mode'];
        }
        if ($dt->search !== '') {
            $where .= ' AND (o.reference LIKE ? OR o.customer_po_number LIKE ? OR c.name LIKE ?)';
            $like = '%' . $dt->search . '%';
            array_push($params, $like, $like, $like);
        }
        $filtered = $pdo->prepare('SELECT COUNT(*) ' . $base . $where);
        $filtered->execute($params);

        $rows = $pdo->prepare('SELECT o.id, o.reference, o.customer_po_number, o.po_date, o.required_date, o.status, o.currency,
                o.fulfillment_mode, c.name AS customer, s.code AS site, ' . self::AMOUNT_SQL . ' AS amount, ' . self::PROGRESS_SQL . ' AS progress,
                ' . self::LATE_SQL . ' AS late, DATEDIFF(o.required_date, CURDATE()) AS days_left,
                (SELECT COUNT(*) FROM purchase_orders p WHERE p.order_id = o.id AND p.status = \'draft\') AS draft_pos '
            . $base . $where . ' ORDER BY ' . $dt->orderBy . ', o.id DESC' . $dt->limitSql());
        $rows->execute($params);
        $list = array_map(function (array $r) {
            $next = OrderNextAction::for((string) $r['status'], (int) $r['draft_pos']);
            return $r + ['next' => $next['code'] ?? null, 'next_tone' => $next['tone'] ?? null];
        }, $rows->fetchAll());
        return [(int) $total->fetchColumn(), (int) $filtered->fetchColumn(), $list];
    }

    /**
     * Compteurs des onglets de statut (mêmes groupes que le filtre) ; « blocked » : dossiers d'import bloqués par un ARA.
     * @return array<string,int>
     */
    public function kpis(): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $in = fn (array $s) => "SUM(o.status IN ('" . implode("','", $s) . "'))";
        $g = OrderNextAction::GROUPS;
        $stmt = Database::pdo()->prepare('SELECT
                SUM(o.status NOT IN (\'closed\',\'cancelled\')) AS open, '
                . $in($g['to_source']) . ' AS to_source, '
                . $in($g['import']) . ' AS import,
                SUM(o.status = \'blocked\') AS blocked, '
                . $in($g['in_delivery']) . ' AS in_delivery, '
                . $in($g['to_close']) . ' AS to_close,
                SUM(' . self::LATE_SQL . ') AS late, '
                . $in($g['closed']) . ' AS closed,
                COUNT(*) AS all_orders
            FROM customer_orders o WHERE 1=1' . $scope);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        $out = [];
        foreach (['open', 'to_source', 'import', 'blocked', 'in_delivery', 'to_close', 'late', 'closed', 'all_orders'] as $k) {
            $out[$k] = (int) ($row[$k] ?? 0);
        }
        // Compatibilité (tableau de bord, badges) : ancien nom
        return $out + ['sourcing' => $out['to_source']];
    }

    /** @return array<string,mixed>|null PO client du périmètre */
    public function find(int $id): ?array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $stmt = Database::pdo()->prepare('SELECT o.*, c.name AS customer_name, c.code AS customer_code, s.name AS site_name,
                ds.name AS delivery_site_name, ds.address AS delivery_site_address, ds.city AS delivery_site_city,
                u.name AS created_by_name, cu.name AS closed_by_name
            FROM customer_orders o
            JOIN customers c ON c.id = o.customer_id
            JOIN sites s ON s.id = o.site_id
            LEFT JOIN delivery_sites ds ON ds.id = o.delivery_site_id
            JOIN users u ON u.id = o.created_by
            LEFT JOIN users cu ON cu.id = o.closed_by
            WHERE o.id = ?' . $scope);
        $stmt->execute([$id, ...$params]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> Lignes avec quantités commandées au fournisseur, en route et réceptionnées */
    public function lines(int $orderId): array
    {
        $stmt = Database::pdo()->prepare("SELECT l.*, i.sku, i.name AS item_name, f.name AS family_name, f.color AS family_color, u.code AS unit,
                COALESCE((SELECT SUM(pl.quantity) FROM purchase_order_lines pl JOIN purchase_orders p ON p.id = pl.po_id
                    WHERE pl.order_line_id = l.id AND p.status <> 'cancelled'), 0) AS purchased,
                COALESCE((SELECT SUM(dl.quantity_shipped) FROM delivery_lines dl JOIN deliveries d ON d.id = dl.delivery_id
                    JOIN purchase_order_lines pl ON pl.id = dl.po_line_id WHERE pl.order_line_id = l.id AND d.status IN ('planned', 'in_transit')), 0) AS in_transit,
                COALESCE((SELECT SUM(dl.quantity_received) FROM delivery_lines dl JOIN deliveries d ON d.id = dl.delivery_id
                    JOIN purchase_order_lines pl ON pl.id = dl.po_line_id WHERE pl.order_line_id = l.id AND d.status = 'delivered'), 0) AS delivered
            FROM customer_order_lines l JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
            JOIN item_families f ON f.id = i.family_id
            WHERE l.order_id = ? ORDER BY l.line_no");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> Devis avec prix par ligne (clé order_line_id) */
    public function quotes(int $orderId): array
    {
        $stmt = Database::pdo()->prepare('SELECT q.*, s.name AS supplier_name, s.code AS supplier_code FROM supplier_quotes q
            JOIN suppliers s ON s.id = q.supplier_id WHERE q.order_id = ? ORDER BY q.status = \'rejected\', q.id');
        $stmt->execute([$orderId]);
        $quotes = $stmt->fetchAll();
        $lines = Database::pdo()->prepare('SELECT order_line_id, quantity, unit_price FROM supplier_quote_lines WHERE quote_id = ?');
        foreach ($quotes as &$q) {
            $lines->execute([$q['id']]);
            $rows = $lines->fetchAll();
            $q['prices'] = array_map('floatval', array_column($rows, 'unit_price', 'order_line_id'));
            // Quantité proposée par ligne (null = quantité commandée)
            $q['quantities'] = array_map(fn ($v) => $v === null ? null : (float) $v, array_column($rows, 'quantity', 'order_line_id'));
        }
        return $quotes;
    }

    /** @return array<string,mixed>|null */
    public function findQuote(int $quoteId, int $orderId): ?array
    {
        foreach ($this->quotes($orderId) as $q) {
            if ((int) $q['id'] === $quoteId) {
                return $q;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> PO fournisseurs du dossier, avec montant */
    public function purchaseOrders(int $orderId): array
    {
        $stmt = Database::pdo()->prepare('SELECT p.*, s.name AS supplier_name,
                (SELECT COALESCE(SUM(pl.quantity * pl.unit_price), 0) FROM purchase_order_lines pl WHERE pl.po_id = p.id) AS amount
            FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id WHERE p.order_id = ? ORDER BY p.id');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> Livraisons avec leurs lignes */
    public function deliveries(int $orderId): array
    {
        $stmt = Database::pdo()->prepare('SELECT d.*, p.reference AS po_reference, s.name AS supplier_name FROM deliveries d
            JOIN purchase_orders p ON p.id = d.po_id JOIN suppliers s ON s.id = p.supplier_id
            WHERE d.order_id = ? ORDER BY d.shipped_at DESC, d.id DESC');
        $stmt->execute([$orderId]);
        $deliveries = $stmt->fetchAll();
        $lines = Database::pdo()->prepare('SELECT dl.*, i.sku, i.name AS item_name, u.code AS unit FROM delivery_lines dl
            JOIN purchase_order_lines pl ON pl.id = dl.po_line_id JOIN items i ON i.id = pl.item_id JOIN units u ON u.id = i.unit_id
            WHERE dl.delivery_id = ?');
        foreach ($deliveries as &$d) {
            $lines->execute([$d['id']]);
            $d['lines'] = $lines->fetchAll();
        }
        return $deliveries;
    }

    /** @return list<array<string,mixed>> Factures (clients et fournisseurs) avec montant payé */
    public function invoices(int $orderId): array
    {
        $stmt = Database::pdo()->prepare('SELECT i.*, COALESCE(c.name, s.name) AS party_name, p.reference AS po_reference,
                (SELECT COALESCE(SUM(py.amount), 0) FROM payments py WHERE py.invoice_id = i.id) AS paid
            FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id LEFT JOIN suppliers s ON s.id = i.supplier_id
            LEFT JOIN purchase_orders p ON p.id = i.po_id
            WHERE i.order_id = ? ORDER BY i.direction DESC, i.invoice_date, i.id');
        $stmt->execute([$orderId]);
        $invoices = $stmt->fetchAll();
        $pay = Database::pdo()->prepare('SELECT py.*, u.name AS user_name FROM payments py JOIN users u ON u.id = py.created_by WHERE py.invoice_id = ? ORDER BY py.paid_at, py.id');
        foreach ($invoices as &$inv) {
            $pay->execute([$inv['id']]);
            $inv['payments'] = $pay->fetchAll();
        }
        return $invoices;
    }

    /** @return list<array<string,mixed>> */
    public function events(int $orderId): array
    {
        $stmt = Database::pdo()->prepare('SELECT e.*, u.name AS user_name FROM order_events e LEFT JOIN users u ON u.id = e.user_id
            WHERE e.order_id = ? ORDER BY e.id DESC');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function attachments(int $orderId): array
    {
        $stmt = Database::pdo()->prepare('SELECT a.*, u.name AS user_name FROM attachments a JOIN users u ON u.id = a.uploaded_by
            WHERE a.order_id = ? ORDER BY a.id DESC');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /**
     * Faits nécessaires aux contrôles de clôture.
     * @param list<array<string,mixed>> $lines
     * @param list<array<string,mixed>> $pos
     * @param list<array<string,mixed>> $deliveries
     * @param list<array<string,mixed>> $invoices
     */
    public static function closureFacts(array $lines, array $pos, array $deliveries, array $invoices): array
    {
        $deliveredValue = 0.0;
        $undelivered = 0;
        foreach ($lines as $l) {
            $deliveredValue += (float) $l['delivered'] * (float) $l['unit_price'];
            if ((float) $l['delivered'] + 1e-9 < (float) $l['quantity']) {
                $undelivered++;
            }
        }
        $invoiced = $receivableOpen = $payableOpen = 0.0;
        $invoicedPos = [];
        foreach ($invoices as $inv) {
            if ($inv['status'] === 'cancelled') {
                continue;
            }
            $open = max(0, (float) $inv['amount'] - (float) $inv['paid']);
            if ($inv['direction'] === 'receivable') {
                $invoiced += (float) $inv['amount'];
                $receivableOpen += $open;
            } else {
                $payableOpen += $open;
                $invoicedPos[(int) $inv['po_id']] = true;
            }
        }
        $posWithoutInvoice = count(array_filter($pos, fn ($p) => in_array($p['status'], ['sent', 'partial', 'delivered'], true) && !isset($invoicedPos[(int) $p['id']])));
        return [
            'undelivered_lines' => $undelivered,
            'in_transit' => count(array_filter($deliveries, fn ($d) => in_array($d['status'], ['planned', 'in_transit'], true))),
            'delivered_value' => round($deliveredValue, 2),
            'invoiced_value' => round($invoiced, 2),
            'receivable_open' => round($receivableOpen, 2),
            'pos_without_invoice' => $posWithoutInvoice,
            'payable_open' => round($payableOpen, 2),
        ];
    }

    public function poNumberExists(int $customerId, string $number, ?int $exceptId = null): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM customer_orders WHERE customer_id = ? AND customer_po_number = ? AND id <> ?');
        $stmt->execute([$customerId, $number, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }
}
