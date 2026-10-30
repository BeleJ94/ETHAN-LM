<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\CurrentUser;
use App\Security\SiteScope;

/**
 * Cockpit d'accueil centré sur les dossiers : PO clients, PO fournisseurs, licences, livraisons.
 * Tout est limité au périmètre de sites de l'utilisateur ; les montants sont convertis en devise de base (dernier taux connu).
 */
final class CockpitRepository
{
    private const OPEN = "o.status NOT IN ('delivered','closed','cancelled')";

    /** @var array<string,?float> */
    private array $rates = [];
    /** Devises sans taux connu : montants non convertis, signalés. @var array<string,true> */
    private array $missingRates = [];

    /** @var array{0:string,1:list<mixed>} */
    private array $scope;

    public function __construct()
    {
        $this->scope = SiteScope::clause('o.site_id');
    }

    /** @return list<string> */
    public function missingRates(): array
    {
        return array_keys($this->missingRates);
    }

    /* ---------- ② Tuiles ---------- */

    /** @return array<string,array<string,float|int>> */
    public function tiles(): array
    {
        $orders = $this->row("SELECT COUNT(*) AS open,
                SUM(o.required_date < CURDATE()) AS late,
                SUM(o.status IN ('received','sourcing')) AS to_source
            FROM customer_orders o WHERE " . self::OPEN);
        $orders['backlog'] = $this->sumBase("SELECT o.currency AS cur, SUM(l.quantity * l.unit_price) AS amt
            FROM customer_orders o JOIN customer_order_lines l ON l.order_id = o.id WHERE " . self::OPEN . ' {S} GROUP BY o.currency');

        $pos = $this->row("SELECT SUM(p.status IN ('sent','partial')) AS open, SUM(p.status = 'draft') AS drafts,
                SUM(p.status IN ('sent','partial') AND p.expected_date < CURDATE()) AS late
            FROM purchase_orders p JOIN customer_orders o ON o.id = p.order_id WHERE p.status <> 'cancelled'");
        $pos['committed'] = $this->sumBase("SELECT p.currency AS cur, SUM(pl.quantity * pl.unit_price) AS amt
            FROM purchase_orders p JOIN purchase_order_lines pl ON pl.po_id = p.id JOIN customer_orders o ON o.id = p.order_id
            WHERE p.status IN ('sent','partial') {S} GROUP BY p.currency");

        $lic = $this->row("SELECT COUNT(*) AS active,
                SUM(l.expires_at BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY) AS expiring,
                SUM(l.expires_at < CURDATE()) AS expired
            FROM import_licences l JOIN customer_orders o ON o.id = l.order_id WHERE l.status = 'active' AND o.status NOT IN ('closed','cancelled')");
        $lic['missing'] = $this->count("SELECT COUNT(*) FROM purchase_orders p JOIN customer_orders o ON o.id = p.order_id
            WHERE o.fulfillment_mode = 'import' AND o.status NOT IN ('closed','cancelled') AND p.status IN ('sent','partial')
            AND NOT EXISTS (SELECT 1 FROM import_licences l WHERE l.po_id = p.id AND l.status = 'active')");
        $lic['authorized'] = $this->sumBase("SELECT l.currency AS cur, SUM(l.amount) AS amt FROM import_licences l JOIN customer_orders o ON o.id = l.order_id
            WHERE l.status = 'active' AND o.status NOT IN ('closed','cancelled') {S} GROUP BY l.currency");

        $del = $this->row("SELECT SUM(d.status = 'planned') AS planned, SUM(d.status = 'in_transit') AS in_transit,
                SUM(d.status = 'delivered' AND d.delivered_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS delivered_month
            FROM deliveries d JOIN customer_orders o ON o.id = d.order_id WHERE d.status <> 'cancelled'");
        return ['orders' => $orders, 'pos' => $pos, 'licences' => $lic, 'deliveries' => $del];
    }

    /* ---------- ③ Pipeline des dossiers ouverts ---------- */

    /** @return array<string,int> étape → nombre de dossiers */
    public function pipeline(): array
    {
        $out = array_fill_keys(['to_source', 'licence', 'inspection', 'ordered', 'in_delivery', 'to_close'], 0);
        $map = ['received' => 'to_source', 'sourcing' => 'to_source', 'awaiting_licence' => 'licence', 'inspection' => 'inspection',
            'blocked' => 'inspection', 'ordered' => 'ordered', 'in_delivery' => 'in_delivery', 'delivered' => 'to_close'];
        [$scope, $params] = $this->scope;
        $stmt = Database::pdo()->prepare("SELECT o.status, COUNT(*) n FROM customer_orders o WHERE o.status NOT IN ('closed','cancelled')" . $scope . ' GROUP BY o.status');
        $stmt->execute($params);
        foreach ($stmt as $r) {
            if (isset($map[$r['status']])) {
                $out[$map[$r['status']]] += (int) $r['n'];
            }
        }
        $out['blocked'] = $this->count("SELECT COUNT(*) FROM customer_orders o WHERE o.status = 'blocked'");
        return $out;
    }

    /* ---------- ① À faire aujourd'hui (source unique : cockpit + pastilles du menu) ---------- */

    /**
     * Actions classées par urgence (bad > warn > info). section = rubrique du menu concernée ; n = nombre d'objets couverts par la ligne.
     * @return list<array{level:string,icon:string,text:string,ref:string,href:string,cta:string,sort:int,section:string,n:int}>
     */
    public function todo(CurrentUser $user): array
    {
        $items = [];
        $add = function (string $section, string $level, string $icon, string $text, string $ref, string $href, string $cta, int $sort = 0, int $n = 1) use (&$items) {
            $items[] = compact('section', 'level', 'icon', 'text', 'ref', 'href', 'cta', 'sort', 'n');
        };
        // Licences : expirées ou proches de l'échéance, encore utiles (PO non entièrement livré)
        foreach ($this->rows("SELECT l.id, l.number, l.cod_number, l.expires_at, DATEDIFF(l.expires_at, CURDATE()) AS days, p.reference AS po_ref
            FROM import_licences l JOIN purchase_orders p ON p.id = l.po_id JOIN customer_orders o ON o.id = l.order_id
            WHERE l.status = 'active' AND p.status IN ('sent','partial') AND l.expires_at <= CURDATE() + INTERVAL 30 DAY {S} ORDER BY l.expires_at LIMIT 20") as $r) {
            $days = (int) $r['days'];
            $add('licences', $days <= 7 ? 'bad' : 'warn', 'stamp', $days < 0 ? t('cockpit.todo.licence_expired', ['days' => -$days]) : t('cockpit.todo.licence_expiring', ['days' => $days]),
                ($r['cod_number'] ?: $r['number']) . ' · ' . $r['po_ref'], url('/licences/' . (int) $r['id']), t('cockpit.cta.view_licence'), $days);
        }
        // Inspections ARA non régularisées
        foreach ($this->rows("SELECT i.id, i.reference, p.id AS po_id, p.reference AS po_ref FROM inspections i JOIN purchase_orders p ON p.id = i.po_id
            JOIN customer_orders o ON o.id = p.order_id WHERE i.decision = 'ara' AND o.status NOT IN ('closed','cancelled')
            AND NOT EXISTS (SELECT 1 FROM inspections r WHERE r.regularizes_id = i.id AND r.decision = 'av') {S} LIMIT 20") as $r) {
            $add('licences', 'bad', 'octagon-x', t('cockpit.todo.ara'), $r['reference'] . ' · ' . $r['po_ref'], url('/purchase-orders/' . (int) $r['po_id']) . '#import', t('cockpit.cta.regularize'), -50);
        }
        // PO clients en retard
        foreach ($this->rows("SELECT o.id, o.customer_po_number, c.name AS customer, DATEDIFF(CURDATE(), o.required_date) AS days FROM customer_orders o
            JOIN customers c ON c.id = o.customer_id WHERE " . self::OPEN . " AND o.required_date < CURDATE() {S} ORDER BY o.required_date LIMIT 20") as $r) {
            $add('orders', 'bad', 'clock-alert', t('cockpit.todo.order_late', ['days' => (int) $r['days']]), $r['customer_po_number'] . ' · ' . $r['customer'],
                url('/orders/' . (int) $r['id']), t('cockpit.cta.open'), -(int) $r['days']);
        }
        // Factures échues
        if ($user->can('finance.view')) {
            // Rubrique du menu selon le sens : factures clients (à encaisser) / fournisseurs (à payer)
            foreach ($this->rows("SELECT i.id, i.reference, i.direction, DATEDIFF(CURDATE(), i.due_date) AS days FROM invoices i JOIN customer_orders o ON o.id = i.order_id
                WHERE i.status = 'open' AND i.due_date < CURDATE() {S} ORDER BY i.due_date LIMIT 20") as $r) {
                $add($r['direction'] === 'receivable' ? 'receivables' : 'payables', 'bad', 'banknote', t('cockpit.todo.invoice_overdue', ['days' => (int) $r['days']]), $r['reference'],
                    url('/invoices/' . (int) $r['id']), t('cockpit.cta.open'), 10);
            }
        }
        // Factures clients à approuver (approbateur habilité, hors factures qu'il a lui-même émises)
        if ($user->can('finance.approve')) {
            foreach ($this->rows("SELECT i.id, i.reference, i.amount, i.currency, c.name AS customer FROM invoices i JOIN customer_orders o ON o.id = i.order_id
                JOIN customers c ON c.id = i.customer_id
                WHERE i.status = 'draft'" . ($user->role === \App\Security\Role::Admin ? '' : ' AND i.created_by <> ' . (int) $user->id) . " AND o.status NOT IN ('closed','cancelled') {S} ORDER BY i.created_at LIMIT 20") as $r) {
                $add('receivables', 'warn', 'badge-check', t('cockpit.todo.invoice_to_approve'), $r['reference'] . ' · ' . $r['customer'] . ' · ' . fmt_money($r['amount'], $r['currency']),
                    url('/invoices/' . (int) $r['id']), t('cockpit.cta.approve'), 5);
            }
        }
        // Documents obligatoires manquants ou rejetés (dossiers et expéditions encore ouverts) : une ligne par expédition / dossier
        if ($user->can('documents.view')) {
            foreach ($this->rows("SELECT sd.order_id, sd.delivery_id, COALESCE(d.reference, o.customer_po_number) AS ref,
                    SUM(sd.status = 'rejected') AS rejected, COUNT(*) AS n
                FROM shipment_documents sd JOIN customer_orders o ON o.id = sd.order_id LEFT JOIN deliveries d ON d.id = sd.delivery_id
                WHERE sd.required = 1 AND sd.status IN ('pending','rejected') AND o.status NOT IN ('closed','cancelled')
                  AND (sd.delivery_id IS NULL OR d.status IN ('planned','in_transit')) {S}
                GROUP BY sd.order_id, sd.delivery_id, ref ORDER BY rejected DESC, n DESC LIMIT 20") as $r) {
                $href = $r['delivery_id'] ? url('/deliveries/' . (int) $r['delivery_id']) : url('/orders/' . (int) $r['order_id']) . '#documents';
                $add('deliveries', (int) $r['rejected'] ? 'bad' : 'warn', 'file-x', t((int) $r['rejected'] ? 'cockpit.todo.docs_rejected' : 'cockpit.todo.docs_missing', ['n' => (int) $r['n']]),
                    $r['ref'], $href, t('cockpit.cta.complete'), 15, (int) $r['n']);
            }
        }
        // Documents à valider
        if ($user->can('documents.validate')) {
            $n = $this->count("SELECT COUNT(*) FROM shipment_documents sd JOIN customer_orders o ON o.id = sd.order_id WHERE sd.status = 'submitted'");
            if ($n) {
                $add('deliveries', 'warn', 'file-check-2', t('cockpit.todo.docs_to_validate', ['n' => $n]), '', url('/deliveries'), t('cockpit.cta.validate'), 20, $n);
            }
        }
        // Expéditions d'import sans agence en douane ou sans n° de dossier
        foreach ($this->rows("SELECT d.id, d.reference, d.forwarder_id FROM deliveries d JOIN customer_orders o ON o.id = d.order_id
            WHERE d.status IN ('planned','in_transit') AND o.fulfillment_mode = 'import' AND (d.forwarder_id IS NULL OR d.forwarder_ref IS NULL) {S} LIMIT 20") as $r) {
            $add('deliveries', 'warn', 'landmark', t($r['forwarder_id'] ? 'cockpit.todo.no_file_ref' : 'cockpit.todo.no_agency'), $r['reference'],
                url('/deliveries/' . (int) $r['id']) . '#transport', t('cockpit.cta.fill'), 30);
        }
        // PO fournisseurs : livraison attendue dépassée
        foreach ($this->rows("SELECT p.id, p.reference, s.name AS supplier, DATEDIFF(CURDATE(), p.expected_date) AS days FROM purchase_orders p
            JOIN suppliers s ON s.id = p.supplier_id JOIN customer_orders o ON o.id = p.order_id
            WHERE p.status IN ('sent','partial') AND p.expected_date < CURDATE() {S} ORDER BY p.expected_date LIMIT 20") as $r) {
            $add('purchase_orders', 'warn', 'clock-alert', t('cockpit.todo.po_late', ['days' => (int) $r['days']]), $r['reference'] . ' · ' . $r['supplier'],
                url('/purchase-orders/' . (int) $r['id']), t('cockpit.cta.open'), 35);
        }
        // PO fournisseurs à envoyer
        foreach ($this->rows("SELECT p.id, p.reference, s.name AS supplier FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id
            JOIN customer_orders o ON o.id = p.order_id WHERE p.status = 'draft' {S} LIMIT 20") as $r) {
            $add('purchase_orders', 'info', 'send', t('cockpit.todo.po_draft'), $r['reference'] . ' · ' . $r['supplier'], url('/purchase-orders/' . (int) $r['id']), t('cockpit.cta.send'), 40);
        }
        // PO d'import envoyés sans licence
        foreach ($this->rows("SELECT p.id, p.reference, s.name AS supplier FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id
            JOIN customer_orders o ON o.id = p.order_id WHERE o.fulfillment_mode = 'import' AND o.status NOT IN ('closed','cancelled') AND p.status IN ('sent','partial')
            AND NOT EXISTS (SELECT 1 FROM import_licences l WHERE l.po_id = p.id AND l.status = 'active') {S} LIMIT 20") as $r) {
            $add('licences', 'info', 'stamp', t('cockpit.todo.licence_missing'), $r['reference'] . ' · ' . $r['supplier'], url('/licences/new', ['po_id' => (int) $r['id']]), t('cockpit.cta.licence'), 50);
        }
        // PO clients à sourcer
        foreach ($this->rows("SELECT o.id, o.customer_po_number, c.name AS customer FROM customer_orders o JOIN customers c ON c.id = o.customer_id
            WHERE o.status IN ('received','sourcing') {S} LIMIT 20") as $r) {
            $add('orders', 'info', 'search', t('cockpit.todo.to_source'), $r['customer_po_number'] . ' · ' . $r['customer'], url('/orders/' . (int) $r['id']) . '#purchasing', t('cockpit.cta.source'), 45);
        }
        // Livrées, à clôturer
        foreach ($this->rows("SELECT o.id, o.customer_po_number, c.name AS customer FROM customer_orders o JOIN customers c ON c.id = o.customer_id
            WHERE o.status = 'delivered' {S} LIMIT 20") as $r) {
            $add('orders', 'info', 'flag', t('cockpit.todo.to_close'), $r['customer_po_number'] . ' · ' . $r['customer'], url('/orders/' . (int) $r['id']) . '#closure', t('cockpit.cta.close'), 60);
        }
        $rank = ['bad' => 0, 'warn' => 1, 'info' => 2];
        usort($items, fn ($a, $b) => [$rank[$a['level']], $a['sort']] <=> [$rank[$b['level']], $b['sort']]);
        return $items;
    }

    /* ---------- ④ Livraisons en cours ---------- */

    /** @return list<array<string,mixed>> */
    public function activeDeliveries(int $limit = 8): array
    {
        return $this->rows("SELECT d.id, d.reference, d.status, d.vehicle_plate, d.shipped_at, d.departed_at, d.forwarder_ref, o.fulfillment_mode,
                c.name AS customer, s.name AS supplier, fw.name AS agency,
                DATEDIFF(CURDATE(), COALESCE(d.departed_at, d.shipped_at)) AS days
            FROM deliveries d JOIN customer_orders o ON o.id = d.order_id JOIN customers c ON c.id = o.customer_id
            JOIN purchase_orders p ON p.id = d.po_id JOIN suppliers s ON s.id = p.supplier_id
            LEFT JOIN customs_agencies fw ON fw.id = d.forwarder_id
            WHERE d.status IN ('planned','in_transit') {S}
            ORDER BY d.status = 'in_transit' DESC, COALESCE(d.departed_at, d.shipped_at) LIMIT " . $limit);
    }

    /* ---------- ⑤ Licences : échéances et consommation ---------- */

    /** @return list<array<string,mixed>> licences actives, valeur expédiée / autorisée */
    public function licences(int $limit = 8): array
    {
        return $this->rows("SELECT l.id, l.number, l.cod_number, l.currency, l.amount, l.expires_at, DATEDIFF(l.expires_at, CURDATE()) AS days,
                p.id AS po_id, p.reference AS po_ref, s.name AS supplier,
                (SELECT COALESCE(SUM(dl.quantity_shipped * pl.unit_price), 0) FROM deliveries d JOIN delivery_lines dl ON dl.delivery_id = d.id
                    JOIN purchase_order_lines pl ON pl.id = dl.po_line_id WHERE d.po_id = l.po_id AND d.status <> 'cancelled') AS used
            FROM import_licences l JOIN purchase_orders p ON p.id = l.po_id JOIN suppliers s ON s.id = p.supplier_id
            JOIN customer_orders o ON o.id = l.order_id
            WHERE l.status = 'active' AND o.status NOT IN ('closed','cancelled') {S}
            ORDER BY l.expires_at LIMIT " . $limit);
    }

    /* ---------- ⑥ Activité récente des dossiers ---------- */

    /** @return list<array<string,mixed>> */
    public function activity(int $limit = 10): array
    {
        return $this->rows("SELECT e.event, e.detail, e.created_at, o.id AS order_id, o.customer_po_number, u.name AS user_name
            FROM order_events e JOIN customer_orders o ON o.id = e.order_id LEFT JOIN users u ON u.id = e.user_id
            WHERE 1=1 {S} ORDER BY e.id DESC LIMIT " . $limit);
    }

    /* ---------- Graphiques ---------- */

    /**
     * months : livraisons réceptionnées par mois (6 derniers mois) — nombre et valeur en devise de base ;
     * suppliers : engagement ouvert par fournisseur (top 6) ; licences : expédié / reste par licence active.
     * @return array<string,mixed>
     */
    public function charts(): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
            $months[$ym] = ['key' => $ym, 'label' => CockpitDrill::monthLabel($ym), 'count' => 0, 'value' => 0.0];
        }
        foreach ($this->rows("SELECT DATE_FORMAT(d.delivered_at, '%Y-%m') AS ym, p.currency AS cur, COUNT(DISTINCT d.id) AS n,
                COALESCE(SUM(dl.quantity_shipped * pl.unit_price), 0) AS amt
            FROM deliveries d JOIN customer_orders o ON o.id = d.order_id JOIN purchase_orders p ON p.id = d.po_id
            LEFT JOIN delivery_lines dl ON dl.delivery_id = d.id LEFT JOIN purchase_order_lines pl ON pl.id = dl.po_line_id
            WHERE d.status = 'delivered' AND d.delivered_at >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01') {S}
            GROUP BY ym, p.currency") as $r) {
            if (isset($months[$r['ym']])) {
                $months[$r['ym']]['count'] += (int) $r['n'];
                $months[$r['ym']]['value'] += $this->toBase((float) $r['amt'], (string) $r['cur']);
            }
        }
        $suppliers = [];
        foreach ($this->rows("SELECT s.id, s.name, p.currency AS cur, SUM(pl.quantity * pl.unit_price) AS amt
            FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id JOIN purchase_order_lines pl ON pl.po_id = p.id
            JOIN customer_orders o ON o.id = p.order_id WHERE p.status IN ('sent','partial') {S} GROUP BY s.id, p.currency") as $r) {
            $suppliers[$r['id']] ??= ['id' => (int) $r['id'], 'label' => $r['name'], 'value' => 0.0];
            $suppliers[$r['id']]['value'] += $this->toBase((float) $r['amt'], (string) $r['cur']);
        }
        usort($suppliers, fn ($a, $b) => $b['value'] <=> $a['value']);
        $licences = array_map(fn ($l) => [
            'id' => (int) $l['id'], 'label' => $l['cod_number'] ?: $l['number'],
            'used' => $this->toBase((float) $l['used'], (string) $l['currency']),
            'remaining' => $this->toBase(max(0, (float) $l['amount'] - (float) $l['used']), (string) $l['currency']),
        ], $this->licences(6));
        return ['months' => array_values($months), 'suppliers' => array_slice($suppliers, 0, 6), 'licences' => $licences, 'currency' => base_currency()];
    }

    private function toBase(float $amount, string $cur): float
    {
        $this->rates[$cur] ??= (new LookupRepository())->rateAt($cur, date('Y-m-d'));
        if ($this->rates[$cur] === null) {
            $this->missingRates[$cur] = true;
            return 0.0;
        }
        return round($amount * $this->rates[$cur], 2);
    }

    /* ---------- Outils ---------- */

    /** Requête avec {S} = filtre de périmètre (ajouté en fin de WHERE si absent). @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        [$scope, $params] = $this->scope;
        $sql = str_contains($sql, '{S}') ? str_replace('{S}', $scope, $sql) : $sql . $scope;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,int> */
    private function row(string $sql): array
    {
        return array_map('intval', $this->rows($sql)[0] ?? []);
    }

    private function count(string $sql): int
    {
        $row = $this->rows($sql)[0] ?? [];
        return (int) (reset($row) ?: 0);
    }

    /** Somme convertie en devise de base (lignes cur / amt). */
    private function sumBase(string $sql): float
    {
        $lookup = new LookupRepository();
        $total = 0.0;
        foreach ($this->rows($sql) as $r) {
            $cur = (string) $r['cur'];
            $this->rates[$cur] ??= $lookup->rateAt($cur, date('Y-m-d'));
            if ($this->rates[$cur] === null) {
                $this->missingRates[$cur] = true;
                continue;
            }
            $total += (float) $r['amt'] * $this->rates[$cur];
        }
        return round($total, 2);
    }
}
