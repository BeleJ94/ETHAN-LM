<?php
/**
 * Factures clients (à encaisser) ou fournisseurs (à payer) — une page par sens : encours, onglets de statut avec compteurs,
 * tableau compact aux colonnes propres au sens, filtres compacts + volet « Plus de filtres », choix des colonnes, exports.
 * @var string $direction receivable|payable @var array<string,array{open:float,overdue:float}> $outstanding devise → montants
 * @var array<string,int> $counts @var list<array> $parties @var array $sites @var array $currencies @var array<string,string> $filters
 */
use App\Core\View;

$f = $filters;
$rec = $direction === 'receivable';
$table = $rec ? 'invoices_receivable' : 'invoices_payable';
$title = t($rec ? 'nav.invoices_customers' : 'nav.invoices_suppliers');
$sel = fn (string $k, string $v) => ($f[$k] ?? '') === $v ? 'selected' : '';
$advancedKeys = ['site_id', 'currency', 'payment', 'from', 'to', ...($rec ? ['vat', 'delivery'] : [])];
$advanced = array_filter(array_intersect_key($f, array_flip($advancedKeys)), fn ($v) => $v !== '');

$actions = '<div class="dropdown">'
    . '<button type="button" class="btn" data-menu-toggle aria-haspopup="true" aria-expanded="false">' . icon('download') . ' ' . e(t('common.export')) . ' ' . icon('chevron-down') . '</button>'
    . '<div class="menu" data-menu role="menu" hidden>'
    . '<button type="button" class="menu__item" role="menuitem" data-export="excel" data-export-table="' . $table . '" data-export-title="' . e($title) . '">' . icon('file-spreadsheet') . ' Excel</button>'
    . '<button type="button" class="menu__item" role="menuitem" data-export="pdf" data-export-table="' . $table . '" data-export-title="' . e($title) . '">' . icon('file-text') . ' PDF</button>'
    . '</div></div>';
echo View::partial('partials/page_header', ['icon' => $rec ? 'file-up' : 'file-down', 'heading' => $title,
    'meta' => t($rec ? 'finance.meta_customers' : 'finance.meta_suppliers'), 'actions' => $actions]);
$fmt = function (string $k) use ($outstanding): string {
    $parts = [];
    foreach ($outstanding as $c => $v) {
        if ($v[$k] > 0.004) {
            $parts[] = fmt_money($v[$k], $c);
        }
    }
    return $parts ? implode(' · ', $parts) : '0';
};
$hasOverdue = (bool) array_filter($outstanding, fn ($v) => $v['overdue'] > 0.004);

// Onglets : statut => [libellé, compteur, tonalité si non nul] ; l'approbation ne concerne que les factures clients
$tabs = ($rec ? ['draft' => ['finance.tab.draft', $counts['draft'] ?? 0, 'warn']] : []) + [
    'open' => ['finance.tab.open', $counts['open'] ?? 0, null],
    'overdue' => ['finance.tab.overdue', $counts['overdue'] ?? 0, 'alert'],
    'due_soon' => ['finance.tab.due_soon', $counts['due_soon'] ?? 0, 'warn'],
    'paid' => ['finance.tab.paid', $counts['paid'] ?? 0, null],
    'cancelled' => ['finance.tab.cancelled', $counts['cancelled'] ?? 0, null],
    '' => ['finance.tab.all', $counts['all_invoices'] ?? 0, null],
];
?>
<section class="kpis kpis--compact">
    <button type="button" class="kpi kpi--button" data-set-filters='{"status":"open"}'>
        <span class="kpi__label"><?= e(t($rec ? 'finance.kpi.receivable' : 'finance.kpi.payable')) ?></span><span class="kpi__value kpi__value--sm"><?= e($fmt('open')) ?></span>
        <span class="kpi__hint"><?= e(t($rec ? 'finance.kpi.receivable_hint' : 'finance.kpi.payable_hint')) ?></span></button>
    <button type="button" class="kpi kpi--button<?= $hasOverdue ? ($rec ? ' kpi--alert' : ' kpi--warn') : '' ?>" data-set-filters='{"status":"overdue"}'>
        <span class="kpi__label"><?= e(t($rec ? 'finance.kpi.receivable_overdue' : 'finance.kpi.payable_overdue')) ?></span><span class="kpi__value kpi__value--sm"><?= e($fmt('overdue')) ?></span>
        <span class="kpi__hint"><?= e(t('finance.kpi.overdue_hint')) ?></span></button>
    <?php if ($rec): ?>
    <button type="button" class="kpi kpi--button<?= ($counts['draft'] ?? 0) ? ' kpi--warn' : '' ?>" data-set-filters='{"status":"draft"}'>
        <span class="kpi__label"><?= e(t('finance.tab.draft')) ?></span><span class="kpi__value kpi__value--sm"><?= (int) ($counts['draft'] ?? 0) ?></span>
        <span class="kpi__hint"><?= e(t('finance.kpi.draft_hint')) ?></span></button>
    <?php endif ?>
    <button type="button" class="kpi kpi--button" data-set-filters='{"status":"due_soon"}'>
        <span class="kpi__label"><?= e(t('finance.tab.due_soon')) ?></span><span class="kpi__value kpi__value--sm"><?= (int) ($counts['due_soon'] ?? 0) ?></span>
        <span class="kpi__hint"><?= e(t('finance.kpi.due_soon_hint')) ?></span></button>
</section>

<nav class="status-tabs" data-status-tabs aria-label="<?= e(t('finance.tabs_label')) ?>">
    <?php foreach ($tabs as $key => [$label, $n, $tone]): ?>
        <button type="button" class="status-tab<?= $tone && $n ? ' status-tab--' . $tone : '' ?>" data-set-filter="status" data-value="<?= e($key) ?>" data-no-scroll
                aria-pressed="<?= $f['status'] === $key ? 'true' : 'false' ?>">
            <span class="status-tab__label"><?= e(t($label)) ?></span>
            <span class="status-tab__count"><?= (int) $n ?></span>
        </button>
    <?php endforeach ?>
</nav>

<section class="card card--table">
    <form class="filters filters--compact" data-filters-for="<?= $table ?>">
        <input type="hidden" data-filter="status" value="<?= e($f['status']) ?>">
        <input type="hidden" data-filter="direction" value="<?= e($direction) ?>">
        <div class="field">
            <label for="f-party"><?= e(t($rec ? 'orders.col.customer' : 'sourcing.col.supplier')) ?></label>
            <select id="f-party" data-filter="party">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php $p = $rec ? 'c' : 's'; foreach ($parties as $x): ?><option value="<?= $p . ':' . (int) $x['id'] ?>" <?= $sel('party', $p . ':' . (int) $x['id']) ?>><?= e($x['name']) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="field field--dates">
            <label for="f-due-from"><?= e(t('finance.filter.due_between')) ?></label>
            <div class="date-range">
                <input id="f-due-from" type="date" data-filter="due_from" value="<?= e($f['due_from']) ?>" aria-label="<?= e(t('delivery.filter.from')) ?>">
                <span class="muted">→</span>
                <input id="f-due-to" type="date" data-filter="due_to" value="<?= e($f['due_to']) ?>" aria-label="<?= e(t('delivery.filter.to')) ?>">
            </div>
        </div>
        <div class="filters__meta">
            <div class="dropdown" data-columns-for="<?= $table ?>">
                <button type="button" class="btn btn--sm btn--ghost" data-menu-toggle aria-haspopup="true" aria-expanded="false"><?= icon('columns-3') ?> <?= e(t('common.columns')) ?> <span class="chip" data-columns-count></span></button>
                <div class="menu menu--columns" data-menu role="menu" hidden></div>
            </div>
            <button type="button" class="btn btn--sm btn--ghost" data-toggle-advanced aria-expanded="<?= $advanced ? 'true' : 'false' ?>" aria-controls="adv-filters"><?= icon('sliders-horizontal') ?> <?= e(t('common.more_filters')) ?> <span class="chip" data-advanced-count <?= $advanced ? '' : 'hidden' ?>><?= count($advanced) ?></span></button>
            <span class="chip" data-filters-count hidden></span>
            <button type="button" class="btn btn--ghost btn--sm" data-filters-reset hidden><?= icon('rotate-ccw') ?> <?= e(t('common.reset')) ?></button>
        </div>
        <div class="filters__advanced" id="adv-filters" <?= $advanced ? '' : 'hidden' ?>>
            <?php if (count($sites) > 1): ?>
            <div class="field">
                <label for="f-site"><?= e(t('orders.col.site')) ?></label>
                <select id="f-site" data-filter="site_id" data-advanced>
                    <option value=""><?= e(t('common.all')) ?></option>
                    <?php foreach ($sites as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sel('site_id', (string) $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach ?>
                </select>
            </div>
            <?php endif ?>
            <div class="field">
                <label for="f-cur"><?= e(t('movements.currency')) ?></label>
                <select id="f-cur" data-filter="currency" data-advanced>
                    <option value=""><?= e(t('common.all')) ?></option>
                    <?php foreach ($currencies as $c): ?><option value="<?= e($c['code']) ?>" <?= $sel('currency', $c['code']) ?>><?= e($c['code']) ?></option><?php endforeach ?>
                </select>
            </div>
            <div class="field">
                <label for="f-pay"><?= e(t($rec ? 'finance.filter.collection' : 'finance.filter.payment')) ?></label>
                <select id="f-pay" data-filter="payment" data-advanced>
                    <?php foreach (['' => 'common.all', 'none' => 'finance.filter.payment_none', 'partial' => 'finance.filter.payment_partial'] as $v => $k): ?>
                        <option value="<?= e($v) ?>" <?= $sel('payment', $v) ?>><?= e(t($k)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?php if ($rec): ?>
            <div class="field">
                <label for="f-vat"><?= e(t('invoice.vat')) ?></label>
                <select id="f-vat" data-filter="vat" data-advanced>
                    <?php foreach (['' => 'common.all', 'with' => 'finance.filter.vat_with', 'without' => 'finance.filter.vat_without'] as $v => $k): ?>
                        <option value="<?= e($v) ?>" <?= $sel('vat', $v) ?>><?= e(t($k)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="field">
                <label for="f-dl"><?= e(t('invoice.print.shipment')) ?></label>
                <select id="f-dl" data-filter="delivery" data-advanced>
                    <?php foreach (['' => 'common.all', 'with' => 'finance.filter.delivery_with', 'without' => 'finance.filter.delivery_without'] as $v => $k): ?>
                        <option value="<?= e($v) ?>" <?= $sel('delivery', $v) ?>><?= e(t($k)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?php endif ?>
            <div class="field field--dates">
                <label for="f-from"><?= e(t('finance.filter.invoiced_between')) ?></label>
                <div class="date-range">
                    <input id="f-from" type="date" data-filter="from" data-advanced value="<?= e($f['from']) ?>" aria-label="<?= e(t('delivery.filter.from')) ?>">
                    <span class="muted">→</span>
                    <input id="f-to" type="date" data-filter="to" data-advanced value="<?= e($f['to']) ?>" aria-label="<?= e(t('delivery.filter.to')) ?>">
                </div>
            </div>
        </div>
    </form>
    <div class="table-wrap">
        <table class="table table--data table--compact" data-table="<?= $table ?>" data-source="<?= e(url('/finance/data')) ?>" data-row-link="<?= e(url('/invoices/{id}')) ?>" data-page-length="15" data-sync-url></table>
    </div>
</section>
