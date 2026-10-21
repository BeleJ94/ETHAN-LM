<?php
/**
 * Liste des PO clients : onglets de statut (compteurs = filtres), tableau compact à une ligne par dossier
 * avec échéance en compte à rebours et prochaine action, filtres compacts + volet « Plus de filtres ».
 * @var array $kpis @var array $customers @var array $sites @var array $currencies @var array<string,string> $filters
 */
use App\Core\View;
use App\Repositories\OrderRepository;
use App\Security\Auth;

$f = $filters + ['status' => ''];
$sel = fn (string $k, string $v) => ($f[$k] ?? '') === $v ? 'selected' : '';
$advanced = array_filter(array_intersect_key($f, array_flip(['detail_status', 'site_id', 'currency', 'due', 'from', 'to'])), fn ($v) => $v !== '');

$actions = '<div class="dropdown">'
    . '<button type="button" class="btn" data-menu-toggle aria-haspopup="true" aria-expanded="false">' . icon('download') . ' ' . e(t('common.export')) . ' ' . icon('chevron-down') . '</button>'
    . '<div class="menu" data-menu role="menu" hidden>'
    . '<button type="button" class="menu__item" role="menuitem" data-export="excel" data-export-table="orders" data-export-title="' . e(t('nav.orders')) . '">' . icon('file-spreadsheet') . ' Excel</button>'
    . '<button type="button" class="menu__item" role="menuitem" data-export="pdf" data-export-table="orders" data-export-title="' . e(t('nav.orders')) . '">' . icon('file-text') . ' PDF</button>'
    . '</div></div>';
if (Auth::require()->can('orders.manage')) {
    $actions .= '<a class="btn btn--primary" href="' . e(url('/orders/new')) . '">' . icon('plus') . ' ' . e(t('orders.new')) . '</a>';
}
echo View::partial('partials/page_header', ['icon' => 'clipboard-list', 'heading' => t('nav.orders'), 'meta' => t('orders.meta'), 'actions' => $actions]);

// Onglets : groupe => [libellé, compteur, tonalité si non nul]
$tabs = [
    'open' => ['orders.tab.open', $kpis['open'], null],
    'to_source' => ['orders.tab.to_source', $kpis['to_source'], 'warn'],
    'import' => ['orders.tab.import', $kpis['import'], $kpis['blocked'] ? 'alert' : null],
    'in_delivery' => ['orders.tab.in_delivery', $kpis['in_delivery'], null],
    'to_close' => ['orders.tab.to_close', $kpis['to_close'], 'good'],
    'late' => ['orders.tab.late', $kpis['late'], 'alert'],
    'closed' => ['orders.tab.closed', $kpis['closed'], null],
    '' => ['orders.tab.all', $kpis['all_orders'], null],
];
?>
<nav class="status-tabs" data-status-tabs aria-label="<?= e(t('orders.tabs_label')) ?>">
    <?php foreach ($tabs as $key => [$label, $n, $tone]): ?>
        <button type="button" class="status-tab<?= $tone && $n ? ' status-tab--' . $tone : '' ?>" data-set-filter="status" data-value="<?= e($key) ?>" data-no-scroll
                aria-pressed="<?= $f['status'] === $key ? 'true' : 'false' ?>">
            <span class="status-tab__label"><?= e(t($label)) ?></span>
            <span class="status-tab__count"><?= (int) $n ?></span>
            <?php if ($key === 'import' && $kpis['blocked']): ?><span class="status-tab__note"><?= e(t('orders.tab.blocked_note', ['n' => $kpis['blocked']])) ?></span><?php endif ?>
        </button>
    <?php endforeach ?>
</nav>

<section class="card card--table">
    <form class="filters filters--compact" data-filters-for="orders">
        <input type="hidden" data-filter="status" value="<?= e($f['status']) ?>">
        <div class="field">
            <label for="f-cust"><?= e(t('orders.col.customer')) ?></label>
            <select id="f-cust" data-filter="customer_id">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('customer_id', (string) $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-mode"><?= e(t('orders.mode')) ?></label>
            <select id="f-mode" data-filter="mode">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (['local_purchase', 'import', 'stock'] as $m): ?><option value="<?= e($m) ?>" <?= $sel('mode', $m) ?>><?= e(t('order.mode.' . $m)) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-due"><?= e(t('orders.col.due')) ?></label>
            <select id="f-due" data-filter="due">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (['overdue' => 'orders.due.overdue', '7' => 'orders.due.7', '30' => 'orders.due.30'] as $v => $k): ?><option value="<?= $v ?>" <?= $sel('due', (string) $v) ?>><?= e(t($k)) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="filters__meta">
            <div class="dropdown" data-columns-for="orders">
                <button type="button" class="btn btn--sm btn--ghost" data-menu-toggle aria-haspopup="true" aria-expanded="false"><?= icon('columns-3') ?> <?= e(t('common.columns')) ?> <span class="chip" data-columns-count></span></button>
                <div class="menu menu--columns" data-menu role="menu" hidden></div>
            </div>
            <button type="button" class="btn btn--sm btn--ghost" data-toggle-advanced aria-expanded="<?= $advanced ? 'true' : 'false' ?>" aria-controls="adv-filters"><?= icon('sliders-horizontal') ?> <?= e(t('common.more_filters')) ?> <span class="chip" data-advanced-count <?= $advanced ? '' : 'hidden' ?>><?= count($advanced) ?></span></button>
            <span class="chip" data-filters-count hidden></span>
            <button type="button" class="btn btn--ghost btn--sm" data-filters-reset hidden><?= icon('rotate-ccw') ?> <?= e(t('common.reset')) ?></button>
        </div>
        <div class="filters__advanced" id="adv-filters" <?= $advanced ? '' : 'hidden' ?>>
            <div class="field">
                <label for="f-dstatus"><?= e(t('orders.filter.detail_status')) ?></label>
                <select id="f-dstatus" data-filter="detail_status" data-advanced>
                    <option value=""><?= e(t('common.all')) ?></option>
                    <?php foreach (OrderRepository::STATUSES as $s): ?><option value="<?= $s ?>" <?= $sel('detail_status', $s) ?>><?= e(t('order.status.' . $s)) ?></option><?php endforeach ?>
                </select>
            </div>
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
            <div class="field field--dates">
                <label for="f-from"><?= e(t('orders.filter.po_between')) ?></label>
                <div class="date-range">
                    <input id="f-from" type="date" data-filter="from" data-advanced value="<?= e($f['from'] ?? '') ?>" aria-label="<?= e(t('delivery.filter.from')) ?>">
                    <span class="muted">→</span>
                    <input id="f-to" type="date" data-filter="to" data-advanced value="<?= e($f['to'] ?? '') ?>" aria-label="<?= e(t('delivery.filter.to')) ?>">
                </div>
            </div>
        </div>
    </form>
    <div class="table-wrap">
        <table class="table table--data table--compact" data-table="orders" data-source="<?= e(url('/orders/data')) ?>" data-row-link="<?= e(url('/orders/{id}')) ?>" data-page-length="15" data-sync-url></table>
    </div>
</section>
