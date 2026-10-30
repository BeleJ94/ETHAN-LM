<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Statement;
use App\Repositories\StatementRepository;

/**
 * Relevés de tiers, clients ou fournisseurs :
 *  - full   : relevé de compte + échéancier ; ledger : relevé de compte seul ; aging : échéancier seul (un tiers) ;
 *  - balance : balance âgée de tous les tiers (sans tiers choisi).
 * Filtres : période, date d'arrêté (= fin de période), devise, site, dossier, factures soldées ; orientation du document.
 * Une page (filtres + document affiché) ; le document A4 (aperçu / PDF) et ses données (export Excel) ont leur propre adresse.
 */
final class StatementController extends Controller
{
    public const TYPES = ['full', 'ledger', 'aging', 'balance'];

    /** Page des relevés : filtres, document affiché, aperçu PDF et export Excel. */
    public function page(Request $request): Response
    {
        $this->authorize('finance.view');
        $q = $this->params($request);
        $repo = new StatementRepository();
        return $this->view('finance/statements', [
            'title' => t('statement.page_title'),
            'q' => $q,
            'partyList' => (new \App\Repositories\InvoiceRepository())->parties()[$q['direction'] === 'receivable' ? 'customers' : 'suppliers'],
            'orders' => $q['party_id'] !== null ? $repo->orders($q['direction'], $q['party_id']) : [],
            'sites' => (new \App\Repositories\SiteRepository())->allowed(),
            'currencies' => (new \App\Repositories\LookupRepository())->currencies(),
        ]);
    }

    /** Document A4 (affiché dans la page, aperçu, impression / PDF) ou ses données (format=json, export Excel). */
    public function show(Request $request): Response
    {
        $this->authorize('finance.view');
        $q = $this->params($request);
        $doc = $this->build($q);
        if ($request->query('format') === 'json') {
            return Response::json(self::sheets($doc));
        }
        return $this->view('finance/statement', $doc + ['company' => PurchaseOrderController::company(), 'user' => $this->user()->name], 'print');
    }

    /**
     * Paramètres validés (adresse de la page, du document et de l'export).
     * @return array{direction:string,party_id:?int,type:string,from:string,to:string,currency:string,site_id:string,order_id:string,settled:bool,orientation:string}
     */
    private function params(Request $request): array
    {
        $direction = (string) $request->query('direction', 'receivable');
        if (!in_array($direction, ['receivable', 'payable'], true)) {
            throw new HttpException(404);
        }
        $partyId = ctype_digit((string) $request->query('party_id', '')) ? (int) $request->query('party_id') : null;
        $type = (string) $request->query('type', 'full');
        $type = $partyId === null ? 'balance' : (in_array($type, ['full', 'ledger', 'aging'], true) ? $type : 'full');
        $date = fn (string $k, string $default) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($k, '')) ? (string) $request->query($k) : $default;
        $to = $date('to', date('Y-m-d'));
        $from = $date('from', date('Y-01-01', strtotime($to)));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [
            'direction' => $direction, 'party_id' => $partyId, 'type' => $type, 'from' => $from, 'to' => $to,
            'currency' => preg_match('/^[A-Z]{3}$/', (string) $request->query('currency', '')) ? (string) $request->query('currency') : '',
            'site_id' => ctype_digit((string) $request->query('site_id', '')) ? (string) $request->query('site_id') : '',
            'order_id' => $partyId !== null && ctype_digit((string) $request->query('order_id', '')) ? (string) $request->query('order_id') : '',
            'settled' => $request->query('settled') === '1',
            'orientation' => $request->query('orientation') === 'landscape' ? 'landscape' : 'portrait',
        ];
    }

    /** Calcul du relevé : tiers, blocs par devise (relevé de compte, échéancier), libellés des filtres. */
    private function build(array $q): array
    {
        $partyId = $q['party_id'];
        $type = $q['type'];
        $data = (new StatementRepository())->load($q['direction'], $partyId, $q);
        if ($partyId !== null && !isset($data[$partyId])) {
            // Tiers sans facture dans le périmètre : relevé vide mais coordonnées affichées
            $party = (new \App\Repositories\PartyRepository($q['direction'] === 'receivable' ? 'customer' : 'supplier'))->find($partyId)
                ?? throw new HttpException(404);
            $data = [$partyId => ['party' => $party, 'currencies' => []]];
        }
        $blocks = [];
        foreach ($data as $id => $d) {
            foreach ($d['currencies'] ?? [] as $cur => $x) {
                $blocks[$id][$cur] = [
                    'ledger' => $type === 'aging' || $type === 'balance' ? null : Statement::ledger($x['invoices'], $x['payments'], $q['from'], $q['to']),
                    'aging' => Statement::aging($x['invoices'], $x['payments'], $q['to'], $q['settled']),
                ];
            }
        }
        $site = $q['site_id'] !== '' ? (new \App\Repositories\SiteRepository())->find((int) $q['site_id']) : null;
        $order = $q['order_id'] !== '' ? (new \App\Repositories\OrderRepository())->find((int) $q['order_id']) : null;
        $rec = $q['direction'] === 'receivable';
        return [
            'title' => t($type === 'balance' ? 'statement.title.balance' : 'statement.title.statement') . ' — '
                . ($partyId !== null ? ($data[$partyId]['party']['name'] ?? '') : t($rec ? 'nav.invoices_customers' : 'nav.invoices_suppliers')),
            'docTitle' => match ($type) {
                'balance' => t($rec ? 'statement.doc.balance_customers' : 'statement.doc.balance_suppliers'),
                'aging' => t('statement.doc.aging'),
                default => t($rec ? 'statement.doc.customer' : 'statement.doc.supplier'),
            },
            'direction' => $q['direction'], 'type' => $type, 'from' => $q['from'], 'to' => $q['to'], 'settled' => $q['settled'],
            'orientation' => $q['orientation'],
            'parties' => array_map(fn ($d) => $d['party'], $data), 'blocks' => $blocks,
            'filterLabels' => array_filter([
                t('orders.col.site') => $site['name'] ?? null,
                t('movements.currency') => $q['currency'] ?: null,
                t('invoice.col.order') => $order ? $order['reference'] . ' · ' . $order['customer_po_number'] : null,
            ]),
        ];
    }

    /** Tranches d'ancienneté : libellé et couleur (mêmes teintes que le document imprimé). */
    private static function bucketMeta(): array
    {
        return [
            'current' => [t('statement.bucket.current'), '2E7D4F'], 'd30' => [t('statement.bucket.d30'), 'E0A100'],
            'd60' => [t('statement.bucket.d60'), 'E06A00'], 'd90' => [t('statement.bucket.d90'), 'C53D2B'], 'over90' => [t('statement.bucket.over90'), '7E1A12'],
        ];
    }

    /** Bloc « Informations » du résumé : période, arrêté, filtres. @return list<array{0:string,1:string}> */
    private static function contextInfo(array $doc): array
    {
        $info = [];
        if (in_array($doc['type'], ['full', 'ledger'], true)) {
            $info[] = [t('statement.period'), fmt_date($doc['from']) . ' → ' . fmt_date($doc['to'])];
        }
        $info[] = [t('statement.as_of'), fmt_date($doc['to'])];
        foreach ($doc['filterLabels'] as $k => $v) {
            $info[] = [$k, $v];
        }
        if ($doc['settled']) {
            $info[] = [t('statement.filters'), t('statement.with_settled')];
        }
        return $info;
    }

    /**
     * Tableau de bord exécutif d'un tiers (feuille « Résumé ») : coordonnées, puis par devise indicateurs, ancienneté, factures les plus en retard.
     * @return array{info:list<array>,currencies:list<array>}
     */
    private static function partyDashboard(array $doc, int $id, array $p): array
    {
        $rec = $doc['direction'] === 'receivable';
        $info = array_values(array_filter([
            [t($rec ? 'statement.customer' : 'statement.supplier'), $p['name'] . ' (' . $p['code'] . ')'],
            ($p['address'] ?? '') || ($p['city'] ?? '') ? [t('parties.address'), implode(', ', array_filter([$p['address'] ?? '', $p['city'] ?? '', $p['country'] ?? '']))] : null,
            ($p['nif'] ?? '') || ($p['rccm'] ?? '') ? [t('statement.legal_ids'), implode(' · ', array_filter(['RCCM ' . ($p['rccm'] ?? ''), 'NIF ' . ($p['nif'] ?? '')], fn ($x) => !str_ends_with($x, ' ')))] : null,
            [t('statement.terms'), t('parties.terms_short', ['n' => (int) $p['payment_terms_days']])],
            ($p['contact_name'] ?? '') || ($p['email'] ?? '') ? [t('statement.contact'), implode(' · ', array_filter([$p['contact_name'] ?? '', $p['phone'] ?? '', $p['email'] ?? '']))] : null,
            ...self::contextInfo($doc),
        ]));
        $currencies = [];
        foreach ($doc['blocks'][$id] ?? [] as $cur => $b) {
            $a = $b['aging'];
            $l = $b['ledger'];
            $open = array_values(array_filter($a['rows'], fn ($r) => $r['open'] > 0.004));
            $late = array_filter($open, fn ($r) => $r['days_late'] > 0);
            $lateAmount = array_sum(array_column($late, 'open'));
            $weighted = $lateAmount > 0 ? array_sum(array_map(fn ($r) => $r['open'] * $r['days_late'], $late)) / $lateAmount : 0;
            $base = $l ? $l['opening'] + $l['invoiced'] : 0;
            $kpis = [];
            if ($l) {
                $kpis[] = [t('statement.opening'), $l['opening'], 'money', null];
                $kpis[] = [t($rec ? 'statement.invoiced' : 'statement.invoiced_supplier'), $l['invoiced'], 'money', null];
                $kpis[] = [t($rec ? 'statement.collected' : 'statement.paid'), $l['paid'], 'money', 'good'];
            }
            $kpis[] = [t('statement.balance_due', ['date' => fmt_date($doc['to'])]), $a['total'], 'money', 'accent'];
            $kpis[] = [t('statement.overdue'), $a['overdue'], 'money', $a['overdue'] > 0 ? 'bad' : 'good'];
            $kpis[] = [t('statement.overdue_share'), $a['total'] > 0 ? $a['overdue'] / $a['total'] : 0, 'pct', $a['overdue'] > 0 ? 'bad' : 'good'];
            if ($l) {
                $kpis[] = [t($rec ? 'statement.kpi.collection_rate' : 'statement.kpi.payment_rate'), $base > 0 ? $l['paid'] / $base : 0, 'pct', null];
            }
            $kpis[] = [t('statement.kpi.open_invoices'), count($open), 'number', null];
            $kpis[] = [t('statement.kpi.avg_delay'), round($weighted), 'days', $weighted > 30 ? 'bad' : null];
            $kpis[] = [t('statement.kpi.oldest'), $open ? max(array_column($open, 'days_late')) : 0, 'days', null];
            usort($open, fn ($x, $y) => [$y['days_late'], $y['open']] <=> [$x['days_late'], $x['open']]);
            $currencies[] = [
                'currency' => $cur,
                'kpis' => array_map(fn ($k) => ['label' => $k[0], 'value' => $k[1], 'type' => $k[2], 'tone' => $k[3]], $kpis),
                'buckets' => self::bucketRows($a['buckets'], $a['total']),
                'top' => [
                    'title' => t('statement.top_invoices'),
                    'columns' => [[t('statement.col.invoice'), 'text'], [t('statement.col.label'), 'text'], [t('invoice.col.due'), 'day'],
                        [t('statement.col.open'), 'money'], [t('statement.col.days_late'), 'number']],
                    'rows' => array_map(fn ($r) => [$r['reference'], $r['label'], $r['due_date'], $r['open'], $r['days_late'] ?: null], array_slice($open, 0, 5)),
                ],
            ];
        }
        return ['info' => $info, 'currencies' => $currencies];
    }

    /** Tableau de bord exécutif de la balance âgée : par devise, indicateurs, ancienneté, tiers les plus exposés. */
    private static function balanceDashboard(array $doc): array
    {
        $rec = $doc['direction'] === 'receivable';
        $byCur = [];
        foreach ($doc['blocks'] as $id => $cur) {
            foreach ($cur as $c => $b) {
                if ($b['aging']['total'] > 0.004) {
                    $byCur[$c][] = ['party' => $doc['parties'][$id]['name'], 'aging' => $b['aging']];
                }
            }
        }
        $currencies = [];
        foreach ($byCur as $c => $list) {
            $buckets = array_fill_keys(array_keys(self::bucketMeta()), 0.0);
            $total = $overdue = 0.0;
            foreach ($list as $x) {
                $total += $x['aging']['total'];
                $overdue += $x['aging']['overdue'];
                foreach ($x['aging']['buckets'] as $k => $v) {
                    $buckets[$k] += $v;
                }
            }
            usort($list, fn ($x, $y) => [$y['aging']['overdue'], $y['aging']['total']] <=> [$x['aging']['overdue'], $x['aging']['total']]);
            $currencies[] = [
                'currency' => $c,
                'kpis' => array_map(fn ($k) => ['label' => $k[0], 'value' => $k[1], 'type' => $k[2], 'tone' => $k[3]], [
                    [t('statement.total_due'), round($total, 2), 'money', 'accent'],
                    [t('statement.overdue'), round($overdue, 2), 'money', $overdue > 0 ? 'bad' : 'good'],
                    [t('statement.overdue_share'), $total > 0 ? $overdue / $total : 0, 'pct', $overdue > 0 ? 'bad' : 'good'],
                    [t('statement.col.parties'), count($list), 'number', null],
                    [t('statement.kpi.parties_late'), count(array_filter($list, fn ($x) => $x['aging']['overdue'] > 0.004)), 'number', null],
                    [t('statement.kpi.over90'), round($buckets['over90'], 2), 'money', $buckets['over90'] > 0 ? 'bad' : null],
                ]),
                'buckets' => self::bucketRows($buckets, $total),
                'top' => [
                    'title' => t($rec ? 'statement.top_customers' : 'statement.top_suppliers'),
                    'columns' => [[t($rec ? 'orders.col.customer' : 'sourcing.col.supplier'), 'text'], [t('statement.total_due'), 'money'],
                        [t('statement.overdue'), 'money'], [t('statement.overdue_share'), 'pct'], [t('statement.kpi.share_of_total'), 'pct']],
                    'rows' => array_map(fn ($x) => [$x['party'], $x['aging']['total'], $x['aging']['overdue'],
                        $x['aging']['total'] > 0 ? $x['aging']['overdue'] / $x['aging']['total'] : 0, $total > 0 ? $x['aging']['total'] / $total : 0], array_slice($list, 0, 10)),
                ],
            ];
        }
        return ['info' => self::contextInfo($doc), 'currencies' => $currencies];
    }

    /** Libellés du tableau de bord Excel (traduits). @return array<string,string> */
    private static function dashboardLabels(): array
    {
        return [
            'infoTitle' => t('statement.dash.info'), 'currencyTitle' => t('statement.currency_block', ['cur' => ':cur']),
            'agingTitle' => t('statement.dash.aging'), 'bucketLabel' => t('statement.col.bucket'), 'amountLabel' => t('orders.col.amount'),
            'shareLabel' => t('statement.dash.share'), 'barLabel' => t('statement.dash.breakdown'),
        ];
    }

    /** @return list<array{label:string,amount:float,share:float,color:string}> */
    private static function bucketRows(array $buckets, float $total): array
    {
        $rows = [];
        foreach (self::bucketMeta() as $k => [$label, $color]) {
            $v = round((float) ($buckets[$k] ?? 0), 2);
            $rows[] = ['label' => $label, 'amount' => $v, 'share' => $total > 0 ? $v / $total : 0, 'color' => $color];
        }
        return $rows;
    }

    /**
     * Données de l'export Excel, en feuilles séparées :
     *  - relevé d'un tiers : « Résumé » (indicateurs par devise), « Relevé de compte », « Factures non soldées » (selon le contenu choisi) ;
     *  - balance âgée : « Résumé » (totaux par devise), « Balance âgée » (détail par tiers).
     * @return array{title:string,subtitle:string,orientation:string,sheets:list<array>}
     */
    private static function sheets(array $doc): array
    {
        $rec = $doc['direction'] === 'receivable';
        $buckets = ['current', 'd30', 'd60', 'd90', 'over90'];
        $bucketTitles = [t('statement.bucket.current'), t('statement.bucket.d30'), t('statement.bucket.d60'), t('statement.bucket.d90'), t('statement.bucket.over90')];
        $bucketCols = array_map(fn ($tt) => [$tt, 'money'], $bucketTitles);
        $period = in_array($doc['type'], ['full', 'ledger'], true) ? t('statement.period') . ' ' . fmt_date($doc['from']) . ' → ' . fmt_date($doc['to']) . ' · ' : '';
        $subtitle = $period . t('statement.as_of') . ' ' . fmt_date($doc['to'])
            . ($doc['filterLabels'] ? ' · ' . implode(' · ', array_map(fn ($k, $v) => "$k : $v", array_keys($doc['filterLabels']), $doc['filterLabels'])) : '');
        $sheet = fn (string $name, ?string $party, array $sections) => ['name' => $name, 'title' => $doc['docTitle'], 'party' => $party, 'sections' => $sections];
        $paidLabel = t($rec ? 'statement.collected' : 'statement.paid');

        if ($doc['type'] === 'balance') {
            $byCur = [];
            foreach ($doc['blocks'] as $id => $cur) {
                foreach ($cur as $c => $b) {
                    $a = $b['aging'];
                    if ($a['total'] <= 0.004) {
                        continue;
                    }
                    $byCur[$c][] = [$doc['parties'][$id]['name'], $doc['parties'][$id]['code'], ...array_map(fn ($k) => $a['buckets'][$k], $buckets), $a['total'], $a['overdue']];
                }
            }
            // Résumé : le tableau de bord (indicateurs, ancienneté, tiers les plus exposés par devise) suffit
            $summary = [];
            $detail = [];
            foreach ($byCur as $c => $rows) {
                $detail[] = [
                    'heading' => t('statement.currency_block', ['cur' => $c]),
                    'columns' => [[t($rec ? 'orders.col.customer' : 'sourcing.col.supplier'), 'text'], [t('statement.col.code'), 'text'],
                        ...$bucketCols, [t('statement.total_due'), 'money'], [t('statement.overdue'), 'money']],
                    'rows' => $rows, 'total' => true,
                ];
            }
            $sheets = [$sheet(t('statement.sheet.summary'), null, $summary) + ['dashboard' => self::balanceDashboard($doc)], $sheet(t('statement.title.balance'), null, $detail)];
            return ['title' => $doc['title'], 'subtitle' => $subtitle, 'orientation' => $doc['orientation'], 'sheets' => $sheets] + self::dashboardLabels();
        }

        $sheets = [];
        $many = count($doc['parties']) > 1;
        foreach ($doc['parties'] as $id => $p) {
            $party = $p['name'] . ' (' . $p['code'] . ')';
            $prefix = $many ? $p['code'] . ' · ' : '';
            $summaryRows = $ledger = $aging = [];
            foreach ($doc['blocks'][$id] ?? [] as $c => $b) {
                $a = $b['aging'];
                $l = $b['ledger'];
                $summaryRows[] = [$c, $l['opening'] ?? null, $l['invoiced'] ?? null, $l['paid'] ?? null, $a['total'], $a['overdue'],
                    ...array_map(fn ($k) => $a['buckets'][$k], $buckets)];
                if ($l) {
                    $rows = [[$doc['from'], t('statement.opening'), '', '', null, null, null, $l['opening']]];
                    foreach ($l['lines'] as $m) {
                        $rows[] = [$m['date'], t($m['kind'] === 'invoice' ? 'statement.kind.invoice' : ($rec ? 'statement.kind.collection' : 'statement.kind.payment')),
                            $m['reference'], $m['label'], $m['due_date'], $m['debit'] ?: null, $m['credit'] ?: null, $m['balance']];
                    }
                    $ledger[] = [
                        'heading' => t('statement.ledger') . ' — ' . $c,
                        'columns' => [[t('statement.col.date'), 'day'], [t('statement.col.type'), 'text'], [t('statement.col.reference'), 'text'], [t('statement.col.label'), 'text'],
                            [t('invoice.col.due'), 'day'], [t($rec ? 'statement.invoiced' : 'statement.invoiced_supplier'), 'money'], [$paidLabel, 'money'], [t('statement.col.balance'), 'money']],
                        'rows' => $rows,
                        'footer' => [t('statement.closing', ['date' => fmt_date($doc['to'])]), '', '', '', null, $l['invoiced'], $l['paid'], $l['closing']],
                    ];
                }
                if ($doc['type'] !== 'ledger') {
                    $aging[] = [
                        'heading' => t($doc['settled'] ? 'statement.invoices_all' : 'statement.invoices_open', ['date' => fmt_date($doc['to'])]) . ' — ' . $c,
                        'columns' => [[t('statement.col.invoice'), 'text'], [t('statement.col.label'), 'text'], [t('invoice.col.date'), 'day'], [t('invoice.col.due'), 'day'],
                            [t('orders.col.amount'), 'money'], [$paidLabel, 'money'], [t('statement.col.open'), 'money'],
                            [t('statement.col.days_late'), 'number'], [t('statement.col.bucket'), 'text']],
                        'rows' => array_map(fn ($r) => [$r['reference'], $r['label'], $r['date'], $r['due_date'], $r['amount'], $r['paid'], $r['open'],
                            $r['open'] > 0 && $r['days_late'] > 0 ? $r['days_late'] : null,
                            $r['bucket'] ? $bucketTitles[array_search($r['bucket'], $buckets, true)] : t('invoice.status.paid')], $a['rows']),
                        'footer' => [t('statement.total_due') . ' ' . $c, '', null, null, null, null, $a['total'], null, ''],
                    ];
                }
            }
            // Synthèse chiffrée sous le tableau de bord : un indicateur par ligne, une colonne par devise
            // (les lignes du relevé de compte ne figurent que s'il a été demandé)
            $withLedger = $doc['type'] !== 'aging';
            $labels = [
                ...($withLedger ? [t('statement.opening'), t($rec ? 'statement.invoiced' : 'statement.invoiced_supplier'), $paidLabel] : []),
                t('statement.balance_due', ['date' => fmt_date($doc['to'])]), t('statement.overdue'), ...$bucketTitles,
            ];
            $values = array_map(fn ($r) => $withLedger ? array_slice($r, 1) : array_slice($r, 4), $summaryRows);
            $cols = [[t('statement.dash.indicator'), 'text'], ...array_map(fn ($r) => [$r[0], 'money'], $summaryRows)];
            $rows = array_map(fn ($i, $label) => [$label, ...array_map(fn ($v) => $v[$i], $values)], array_keys($labels), $labels);
            $sheets[] = $sheet($prefix . t('statement.sheet.summary'), $party, $summaryRows ? [['heading' => t('statement.sheet.summary_table'), 'columns' => $cols, 'rows' => $rows]] : [])
                + ['dashboard' => self::partyDashboard($doc, $id, $p)];
            if ($ledger) {
                $sheets[] = $sheet($prefix . t('statement.ledger'), $party, $ledger);
            }
            if ($aging) {
                $sheets[] = $sheet($prefix . t('statement.sheet.open_invoices'), $party, $aging);
            }
        }
        return ['title' => $doc['title'], 'subtitle' => $subtitle, 'orientation' => $doc['orientation'], 'sheets' => $sheets] + self::dashboardLabels();
    }
}
