<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\CurrentUser;
use App\Security\SiteScope;

/**
 * Détail d'un indicateur du cockpit (KPI, étape du pipeline, barre de graphique) : colonnes typées + lignes,
 * affichés dans une modale (tableau compact, export Excel / PDF). Toujours limité au périmètre de sites.
 * Colonne : key, title, type (text|date|number|money|pct) ; la première colonne porte le lien (href) de la ligne.
 */
final class CockpitDrill
{
    private const OPEN = "o.status NOT IN ('delivered','closed','cancelled')";

    /** Étape du pipeline → statuts de PO client. */
    public const PIPE = [
        'to_source' => ['received', 'sourcing'], 'licence' => ['awaiting_licence'], 'inspection' => ['inspection', 'blocked'],
        'ordered' => ['ordered'], 'in_delivery' => ['in_delivery'], 'to_close' => ['delivered'],
    ];

    /** @var array<string,?float> */
    private array $rates = [];

    public function __construct(private readonly CurrentUser $user)
    {
    }

    /** @return array{title:string,columns:list<array<string,string>>,rows:list<array<string,mixed>>}|null */
    public function get(string $key, string $param = ''): ?array
    {
        $t = fn (string $k, array $p = []) => t('cockpit.drill.' . $k, $p);
        return match ($key) {
            'orders_open' => $this->orders($t('orders_open'), self::OPEN),
            'orders_late' => $this->orders($t('orders_late'), self::OPEN . ' AND o.required_date < CURDATE()'),
            'orders_to_source' => $this->orders($t('orders_to_source'), "o.status IN ('received','sourcing')"),
            'pipe' => isset(self::PIPE[$param])
                ? $this->orders(t('cockpit.pipe.' . $param), "o.status IN ('" . implode("','", self::PIPE[$param]) . "')") : null,
            'pos_open' => $this->pos($t('pos_open'), "p.status IN ('sent','partial')"),
            'pos_drafts' => $this->pos($t('pos_drafts'), "p.status = 'draft'"),
            'pos_late' => $this->pos($t('pos_late'), "p.status IN ('sent','partial') AND p.expected_date < CURDATE()"),
            'pos_supplier' => ctype_digit($param)
                ? $this->pos($t('pos_supplier'), "p.status IN ('sent','partial') AND p.supplier_id = " . (int) $param) : null,
            'licences_active' => $this->licences($t('licences_active'), '1=1'),
            'licences_expiring' => $this->licences($t('licences_expiring'), 'l.expires_at <= CURDATE() + INTERVAL 30 DAY'),
            'licences_missing' => $this->pos($t('licences_missing'), "o.fulfillment_mode = 'import' AND o.status NOT IN ('closed','cancelled') AND p.status IN ('sent','partial')
                AND NOT EXISTS (SELECT 1 FROM import_licences l WHERE l.po_id = p.id AND l.status = 'active')", '/licences/new?po_id='),
            'deliveries_in_transit' => $this->deliveries($t('deliveries_in_transit'), "d.status = 'in_transit'"),
            'deliveries_planned' => $this->deliveries($t('deliveries_planned'), "d.status = 'planned'"),
            'deliveries_active' => $this->deliveries($t('deliveries_active'), "d.status IN ('planned','in_transit')"),
            'deliveries_month' => $this->deliveries($t('deliveries_month', ['month' => self::monthLabel($param ?: date('Y-m'))]),
                "d.status = 'delivered' AND DATE_FORMAT(d.delivered_at, '%Y-%m') = " . Database::pdo()->quote(preg_match('/^\d{4}-\d{2}$/', $param) ? $param : date('Y-m'))),
            // Factures (indicateurs des pages Factures clients / fournisseurs) : param = « sens:vue »
            'invoices' => $this->invoices($param),
            'aging' => $this->aging($param),
            'todo' => $this->todo(),
            'nav' => in_array($param, ['orders', 'purchase_orders', 'licences', 'deliveries', 'receivables', 'payables'], true) ? $this->todo($param) : null,
            'activity' => $this->activity(),
            default => null,
        };
    }

    public static function monthLabel(string $ym): string
    {
        [$y, $m] = array_map('intval', explode('-', $ym) + [1 => 1]);
        return t('cockpit.month.' . $m) . ' ' . $y;
    }

    /* ---------- Jeux de données ---------- */

    private function orders(string $title, string $where): array
    {
        $rows = $this->rows("SELECT o.id, o.customer_po_number, o.reference, c.name AS customer, o.fulfillment_mode, o.po_date, o.required_date,
                o.status, o.currency, COALESCE(SUM(l.quantity * l.unit_price), 0) AS amount
            FROM customer_orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN customer_order_lines l ON l.order_id = o.id
            WHERE $where {S} GROUP BY o.id ORDER BY o.required_date IS NULL, o.required_date, o.id");
        foreach ($rows as &$r) {
            $r['href'] = url('/orders/' . (int) $r['id']);
            $r['status'] = t('order.status.' . $r['status']);
            $r['fulfillment_mode'] = t('order.mode_short.' . $r['fulfillment_mode']);
            $r['amount_base'] = $this->base((float) $r['amount'], $r['currency']);
            $r['late'] = $r['required_date'] && $r['required_date'] < date('Y-m-d') ? max(0, (int) ((strtotime('today') - strtotime($r['required_date'])) / 86400)) : null;
        }
        return $this->out($title, [
            ['customer_po_number', t('cockpit.col.customer_po')], ['customer', t('orders.col.customer')], ['fulfillment_mode', t('cockpit.col.mode')],
            ['po_date', t('cockpit.col.po_date'), 'date'], ['required_date', t('cockpit.col.required'), 'date'], ['late', t('cockpit.col.late_days'), 'number'],
            ['status', t('common.status')], ['currency', t('cockpit.col.currency')], ['amount', t('cockpit.col.amount'), 'money'], ['amount_base', t('cockpit.col.amount_base'), 'money'],
        ], $rows);
    }

    private function pos(string $title, string $where, string $link = '/purchase-orders/'): array
    {
        $rows = $this->rows("SELECT p.id, p.reference, s.name AS supplier, o.customer_po_number, c.name AS customer, p.order_date, p.expected_date,
                p.status, p.currency, COALESCE(SUM(pl.quantity * pl.unit_price), 0) AS amount
            FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id JOIN customer_orders o ON o.id = p.order_id
            JOIN customers c ON c.id = o.customer_id LEFT JOIN purchase_order_lines pl ON pl.po_id = p.id
            WHERE $where {S} GROUP BY p.id ORDER BY p.expected_date IS NULL, p.expected_date, p.id");
        foreach ($rows as &$r) {
            $r['href'] = url($link . (int) $r['id']);
            $r['status'] = t('po.status.' . $r['status']);
            $r['amount_base'] = $this->base((float) $r['amount'], $r['currency']);
        }
        return $this->out($title, [
            ['reference', t('cockpit.col.po')], ['supplier', t('cockpit.col.supplier')], ['customer_po_number', t('cockpit.col.customer_po')], ['customer', t('orders.col.customer')],
            ['order_date', t('cockpit.col.order_date'), 'date'], ['expected_date', t('cockpit.col.expected'), 'date'], ['status', t('common.status')],
            ['currency', t('cockpit.col.currency')], ['amount', t('cockpit.col.amount'), 'money'], ['amount_base', t('cockpit.col.amount_base'), 'money'],
        ], $rows);
    }

    private function licences(string $title, string $where): array
    {
        $rows = $this->rows("SELECT l.id, COALESCE(l.cod_number, l.number) AS licence, l.number, p.reference AS po, s.name AS supplier, l.issued_at, l.expires_at,
                DATEDIFF(l.expires_at, CURDATE()) AS days, l.currency, l.amount,
                (SELECT COALESCE(SUM(dl.quantity_shipped * pl.unit_price), 0) FROM deliveries d JOIN delivery_lines dl ON dl.delivery_id = d.id
                    JOIN purchase_order_lines pl ON pl.id = dl.po_line_id WHERE d.po_id = l.po_id AND d.status <> 'cancelled') AS used
            FROM import_licences l JOIN purchase_orders p ON p.id = l.po_id JOIN suppliers s ON s.id = p.supplier_id JOIN customer_orders o ON o.id = l.order_id
            WHERE l.status = 'active' AND o.status NOT IN ('closed','cancelled') AND $where {S} ORDER BY l.expires_at");
        foreach ($rows as &$r) {
            $r['href'] = url('/licences/' . (int) $r['id']);
            $r['remaining'] = round((float) $r['amount'] - (float) $r['used'], 2);
            $r['pct'] = (float) $r['amount'] > 0 ? round((float) $r['used'] / (float) $r['amount'] * 100, 1) : 0;
            $r['days'] = (int) $r['days'];
        }
        return $this->out($title, [
            ['licence', t('cockpit.col.licence')], ['number', t('cockpit.col.validation')], ['po', t('cockpit.col.po')], ['supplier', t('cockpit.col.supplier')],
            ['issued_at', t('cockpit.col.issued'), 'date'], ['expires_at', t('cockpit.col.expires'), 'date'], ['days', t('cockpit.col.days_left'), 'number'],
            ['currency', t('cockpit.col.currency')], ['amount', t('cockpit.col.authorized'), 'money'], ['used', t('cockpit.col.used'), 'money'],
            ['remaining', t('cockpit.col.remaining'), 'money'], ['pct', t('cockpit.col.pct'), 'pct'],
        ], $rows);
    }

    private function deliveries(string $title, string $where): array
    {
        $rows = $this->rows("SELECT d.id, d.reference, d.status, c.name AS customer, s.name AS supplier, p.reference AS po, d.shipped_at, d.departed_at, d.delivered_at,
                d.vehicle_plate, fw.name AS agency, d.forwarder_ref, DATEDIFF(COALESCE(d.delivered_at, CURDATE()), COALESCE(d.departed_at, d.shipped_at)) AS days
            FROM deliveries d JOIN customer_orders o ON o.id = d.order_id JOIN customers c ON c.id = o.customer_id
            JOIN purchase_orders p ON p.id = d.po_id JOIN suppliers s ON s.id = p.supplier_id LEFT JOIN customs_agencies fw ON fw.id = d.forwarder_id
            WHERE $where {S} ORDER BY COALESCE(d.delivered_at, d.departed_at, d.shipped_at) DESC");
        foreach ($rows as &$r) {
            $r['href'] = url('/deliveries/' . (int) $r['id']);
            $r['status'] = t('delivery.status.' . $r['status']);
            $r['days'] = max(0, (int) $r['days']);
        }
        return $this->out($title, [
            ['reference', t('cockpit.col.delivery')], ['status', t('common.status')], ['customer', t('orders.col.customer')], ['supplier', t('cockpit.col.supplier')],
            ['po', t('cockpit.col.po')], ['shipped_at', t('cockpit.col.loaded'), 'date'], ['departed_at', t('cockpit.col.departed'), 'date'],
            ['delivered_at', t('cockpit.col.delivered'), 'date'], ['days', t('cockpit.col.transit_days'), 'number'], ['vehicle_plate', t('cockpit.col.plate')],
            ['agency', t('delivery.customs.agency')], ['forwarder_ref', t('delivery.customs.file_label')],
        ], $rows);
    }

    /** À faire : tout, ou seulement une rubrique du menu ($section). */
    private function todo(?string $section = null): array
    {
        $actions = (new CockpitRepository())->todo($this->user);
        if ($section !== null) {
            $actions = array_values(array_filter($actions, fn ($a) => $a['section'] === $section));
        }
        $rows = array_map(fn ($a) => [
            'level' => t('cockpit.level.' . $a['level']), 'text' => $a['text'], 'ref' => $a['ref'], 'cta' => $a['cta'], 'href' => $a['href'],
        ], $actions);
        return $this->out($section !== null ? t('nav.badge.drill_title', ['section' => t('nav.' . $section)]) : t('cockpit.todo_title'), [
            ['text', t('cockpit.col.action')], ['level', t('cockpit.col.level')], ['ref', t('cockpit.col.object')], ['cta', t('cockpit.col.next')],
        ], $rows);
    }

    private function activity(): array
    {
        $rows = $this->rows("SELECT e.created_at, o.id AS order_id, o.customer_po_number, e.event, e.detail, u.name AS user_name
            FROM order_events e JOIN customer_orders o ON o.id = e.order_id LEFT JOIN users u ON u.id = e.user_id
            WHERE e.created_at >= CURDATE() - INTERVAL 30 DAY {S} ORDER BY e.id DESC LIMIT 500");
        foreach ($rows as &$r) {
            $r['href'] = url('/orders/' . (int) $r['order_id']) . '#timeline';
            $r['detail'] = $r['event'] === 'status' && $r['detail'] ? t('order.status.' . $r['detail']) : (string) $r['detail'];
            $r['event'] = t('event.' . $r['event']);
        }
        return $this->out(t('cockpit.drill.activity'), [
            ['customer_po_number', t('cockpit.col.customer_po')], ['created_at', t('cockpit.col.when'), 'datetime'], ['event', t('cockpit.col.event')],
            ['detail', t('cockpit.col.detail')], ['user_name', t('cockpit.col.user')],
        ], $rows);
    }

    /* ---------- Outils ---------- */

    /** @param list<array{0:string,1:string,2?:string}> $cols */
    /** Vues des indicateurs de factures : condition SQL. */
    private const INVOICE_VIEWS = [
        'open' => "i.status = 'open'",
        'overdue' => "i.status = 'open' AND i.due_date < CURDATE()",
        'due_soon' => "i.status = 'open' AND i.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY",
        'draft' => "i.status = 'draft'",
    ];

    /** Détail des factures d'un sens (receivable|payable) pour une vue (open, overdue, due_soon, draft). */
    private function invoices(string $param): ?array
    {
        [$dir, $view] = explode(':', $param) + [1 => ''];
        if (!in_array($dir, ['receivable', 'payable'], true) || !isset(self::INVOICE_VIEWS[$view]) || !$this->user->can('finance.view')) {
            return null;
        }
        $rec = $dir === 'receivable';
        $paid = '(SELECT COALESCE(SUM(py.amount), 0) FROM payments py WHERE py.invoice_id = i.id)';
        $rows = $this->rows("SELECT i.id, i.reference, i.external_number, COALESCE(c.name, s.name) AS party, o.reference AS order_ref, o.customer_po_number,
                dv.reference AS delivery, p.reference AS po, i.invoice_date, i.due_date, i.status, i.currency,
                COALESCE(i.subtotal, i.amount) AS subtotal, i.vat_amount, i.amount, $paid AS paid, i.amount - $paid AS balance, uc.name AS created_by
            FROM invoices i JOIN customer_orders o ON o.id = i.order_id JOIN users uc ON uc.id = i.created_by
            LEFT JOIN customers c ON c.id = i.customer_id LEFT JOIN suppliers s ON s.id = i.supplier_id
            LEFT JOIN deliveries dv ON dv.id = i.delivery_id LEFT JOIN purchase_orders p ON p.id = i.po_id
            WHERE i.direction = '$dir' AND " . self::INVOICE_VIEWS[$view] . ' {S} ORDER BY i.due_date, i.id');
        $today = strtotime('today');
        foreach ($rows as &$r) {
            $r['href'] = url('/invoices/' . (int) $r['id']);
            $late = (int) (($today - strtotime($r['due_date'])) / 86400);
            $r['late'] = $r['status'] === 'open' && $late > 0 ? $late : null;
            $r['status'] = t($r['late'] ? 'invoice.status.overdue' : 'invoice.status.' . $r['status']);
            $r['balance_base'] = $this->base((float) $r['balance'], $r['currency']);
        }
        unset($r);
        $title = t($rec ? 'nav.invoices_customers' : 'nav.invoices_suppliers') . ' — ' . match ($view) {
            'open' => t('cockpit.drill.invoices_open'),
            'overdue' => t('cockpit.drill.invoices_overdue'),
            'due_soon' => t('cockpit.drill.invoices_due_soon'),
            'draft' => t('cockpit.drill.invoices_draft'),
        };
        $cols = $rec
            ? [['reference', t('orders.col.reference')], ['party', t('orders.col.customer')], ['order_ref', t('invoice.col.order')],
                ['customer_po_number', t('cockpit.col.customer_po')], ['delivery', t('js.col.shipment')]]
            : [['external_number', t('js.col.supplier_invoice')], ['reference', t('reference.internal')], ['party', t('cockpit.col.supplier')],
                ['po', t('cockpit.col.po')], ['order_ref', t('invoice.col.order')]];
        $cols = [...$cols,
            ['invoice_date', t('invoice.col.date'), 'date'], ['due_date', t('invoice.col.due'), 'date'], ['late', t('cockpit.col.late_days'), 'number'],
            ['status', t('common.status')], ['currency', t('cockpit.col.currency')],
            ...($rec ? [['subtotal', t('js.col.subtotal'), 'money'], ['vat_amount', t('js.col.vat'), 'money']] : []),
            ['amount', t($rec ? 'js.col.amount_ttc' : 'cockpit.col.amount'), 'money'], ['paid', t($rec ? 'js.col.collected' : 'js.col.paid_out'), 'money'],
            ['balance', t('invoice.col.balance'), 'money'], ['balance_base', t('cockpit.drill.balance_base'), 'money'],
            ['created_by', t($rec ? 'js.col.issued_by' : 'js.col.recorded_by')],
        ];
        // Facture fournisseur sans n° : la référence interne porte le lien (première colonne)
        if (!$rec) {
            foreach ($rows as &$r) {
                $r['external_number'] = $r['external_number'] ?: $r['reference'];
            }
        }
        return $this->out($title, $cols, $rows);
    }

    /** Balance âgée de tous les tiers d'un sens, au jour : une ligne par tiers et par devise ; la ligne ouvre le relevé du tiers. */
    private function aging(string $direction): ?array
    {
        if (!in_array($direction, ['receivable', 'payable'], true) || !$this->user->can('finance.view')) {
            return null;
        }
        $today = date('Y-m-d');
        $rows = [];
        foreach ((new StatementRepository())->load($direction, null, []) as $id => $d) {
            foreach ($d['currencies'] as $cur => $x) {
                $a = \App\Domain\Statement::aging($x['invoices'], $x['payments'], $today);
                if ($a['total'] <= 0.004) {
                    continue;
                }
                $rows[] = ['party' => $d['party']['name'], 'code' => $d['party']['code'], 'currency' => $cur] + $a['buckets'] + [
                    'total' => $a['total'], 'overdue' => $a['overdue'], 'pct' => round($a['overdue'] / $a['total'] * 100, 1),
                    'total_base' => $this->base($a['total'], $cur),
                    'href' => url('/finance/statements', ['direction' => $direction, 'party_id' => $id, 'type' => 'aging']),
                ];
            }
        }
        usort($rows, fn ($a, $b) => $b['overdue'] <=> $a['overdue'] ?: $b['total'] <=> $a['total']);
        return $this->out(t('statement.title.balance') . ' — ' . t($direction === 'receivable' ? 'nav.invoices_customers' : 'nav.invoices_suppliers') . ' · ' . fmt_date($today), [
            ['party', t($direction === 'receivable' ? 'orders.col.customer' : 'cockpit.col.supplier')], ['code', t('statement.col.code')], ['currency', t('cockpit.col.currency')],
            ['current', t('statement.bucket.current'), 'money'], ['d30', t('statement.bucket.d30'), 'money'], ['d60', t('statement.bucket.d60'), 'money'],
            ['d90', t('statement.bucket.d90'), 'money'], ['over90', t('statement.bucket.over90'), 'money'],
            ['total', t('statement.total_due'), 'money'], ['overdue', t('statement.overdue'), 'money'], ['pct', t('statement.overdue_share'), 'pct'],
            ['total_base', t('cockpit.drill.balance_base'), 'money'],
        ], $rows);
    }

    private function out(string $title, array $cols, array $rows): array
    {
        return [
            'title' => $title,
            'columns' => array_map(fn ($c) => ['key' => $c[0], 'title' => $c[1], 'type' => $c[2] ?? 'text'], $cols),
            'rows' => array_values($rows),
        ];
    }

    private function rows(string $sql): array
    {
        [$scope, $params] = SiteScope::clause('o.site_id');
        $stmt = Database::pdo()->prepare(str_replace('{S}', $scope, $sql));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function base(float $amount, string $currency): ?float
    {
        $this->rates[$currency] ??= (new LookupRepository())->rateAt($currency, date('Y-m-d'));
        return $this->rates[$currency] === null ? null : round($amount * $this->rates[$currency], 2);
    }
}
