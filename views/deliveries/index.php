<?php
/**
 * Liste des expéditions : tableau serveur compact (15 lignes), filtres principaux + volet « Plus de filtres ».
 * @var array $counts @var array $customers @var array $suppliers @var array $forwarders @var array<string,string> $filters
 */
use App\Core\View;
use App\Domain\Checklist;

$f = $filters + ['status' => ''];
$sel = fn (string $k, string $v) => ($f[$k] ?? '') === $v ? 'selected' : '';
$advanced = array_filter(array_intersect_key($f, array_flip(['customer_id', 'supplier_id', 'forwarder_id', 'transport_mode', 'docs', 'gap', 'from', 'to'])), fn ($v) => $v !== '');
$actions = '<button type="button" class="btn" data-export="excel" data-export-table="deliveries" data-export-title="' . e(t('nav.deliveries')) . '">' . icon('file-spreadsheet') . ' Excel</button>'
    . '<button type="button" class="btn" data-export="pdf" data-export-table="deliveries" data-export-title="' . e(t('nav.deliveries')) . '">' . icon('file-text') . ' PDF</button>';
echo View::partial('partials/page_header', ['icon' => 'truck', 'heading' => t('nav.deliveries'), 'meta' => t('delivery.meta'), 'actions' => $actions]);
?>
<section class="kpis kpis--compact">
    <button type="button" class="kpi kpi--button" data-set-filter="status" data-value="open"><span class="kpi__label"><?= e(t('delivery.kpi.open')) ?></span><span class="kpi__value"><?= $counts['open'] ?></span></button>
    <button type="button" class="kpi kpi--button<?= $counts['planned'] ? ' kpi--warn' : '' ?>" data-set-filter="status" data-value="planned"><span class="kpi__label"><?= e(t('delivery.status.planned')) ?></span><span class="kpi__value"><?= $counts['planned'] ?></span></button>
    <button type="button" class="kpi kpi--button" data-set-filter="status" data-value="in_transit"><span class="kpi__label"><?= e(t('delivery.status.in_transit')) ?></span><span class="kpi__value"><?= $counts['in_transit'] ?></span></button>
    <button type="button" class="kpi kpi--button" data-set-filter="status" data-value="delivered"><span class="kpi__label"><?= e(t('delivery.status.delivered')) ?></span><span class="kpi__value"><?= $counts['delivered'] ?></span></button>
    <button type="button" class="kpi kpi--button<?= $counts['incomplete'] ? ' kpi--alert' : '' ?>" data-set-filters='{"status":"","docs":"incomplete"}'><span class="kpi__label"><?= e(t('delivery.filter.docs_incomplete')) ?></span><span class="kpi__value"><?= $counts['incomplete'] ?></span><span class="kpi__hint"><?= e(t('documents.checklist')) ?></span></button>
</section>

<section class="card card--table">
    <form class="filters filters--compact" data-filters-for="deliveries">
        <?php if (($f['order_id'] ?? '') !== ''): ?><input type="hidden" data-filter="order_id" value="<?= (int) $f['order_id'] ?>"><?php endif ?>
        <div class="field">
            <label for="f-status"><?= e(t('common.status')) ?></label>
            <select id="f-status" data-filter="status">
                <option value="" <?= $sel('status', '') ?>><?= e(t('common.all')) ?></option>
                <option value="open" <?= $sel('status', 'open') ?>><?= e(t('delivery.kpi.open')) ?></option>
                <?php foreach (['planned', 'in_transit', 'delivered', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= $sel('status', $s) ?>><?= e(t('delivery.status.' . $s)) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-period"><?= e(t('movements.period')) ?></label>
            <select id="f-period" data-filter="period">
                <option value=""><?= e(t('movements.period.all')) ?></option>
                <?php foreach (['7' => 'movements.period.7d', '30' => 'movements.period.30d', '90' => 'delivery.period.90d'] as $v => $k): ?><option value="<?= $v ?>" <?= $sel('period', (string) $v) ?>><?= e(t($k)) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-docs"><?= e(t('documents.checklist')) ?></label>
            <select id="f-docs" data-filter="docs">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (['incomplete' => 'delivery.filter.docs_incomplete', 'to_validate' => 'delivery.filter.docs_to_validate', 'complete' => 'delivery.filter.docs_complete'] as $v => $k): ?><option value="<?= $v ?>" <?= $sel('docs', $v) ?>><?= e(t($k)) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="filters__meta">
            <div class="dropdown" data-columns-for="deliveries">
                <button type="button" class="btn btn--sm btn--ghost" data-menu-toggle aria-haspopup="true" aria-expanded="false"><?= icon('columns-3') ?> <?= e(t('common.columns')) ?> <span class="chip" data-columns-count></span></button>
                <div class="menu menu--columns" data-menu role="menu" hidden></div>
            </div>
            <button type="button" class="btn btn--sm btn--ghost" data-toggle-advanced aria-expanded="<?= $advanced ? 'true' : 'false' ?>" aria-controls="adv-filters"><?= icon('sliders-horizontal') ?> <?= e(t('common.more_filters')) ?> <span class="chip" data-advanced-count <?= $advanced ? '' : 'hidden' ?>><?= count($advanced) ?></span></button>
            <span class="chip" data-filters-count hidden></span>
            <button type="button" class="btn btn--ghost btn--sm" data-filters-reset hidden><?= icon('rotate-ccw') ?> <?= e(t('common.reset')) ?></button>
        </div>
        <div class="filters__advanced" id="adv-filters" <?= $advanced ? '' : 'hidden' ?>>
            <div class="field">
                <label for="f-cust"><?= e(t('orders.col.customer')) ?></label>
                <select id="f-cust" data-filter="customer_id" data-advanced>
                    <option value=""><?= e(t('common.all')) ?></option>
                    <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('customer_id', (string) $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach ?>
                </select>
            </div>
            <div class="field">
                <label for="f-sup"><?= e(t('sourcing.col.supplier')) ?></label>
                <select id="f-sup" data-filter="supplier_id" data-advanced>
                    <option value=""><?= e(t('common.all')) ?></option>
                    <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sel('supplier_id', (string) $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach ?>
                </select>
            </div>
            <div class="field">
                <label for="f-fw"><?= e(t('delivery.forwarder')) ?></label>
                <select id="f-fw" data-filter="forwarder_id" data-advanced>
                    <option value=""><?= e(t('common.all')) ?></option>
                    <?php foreach ($forwarders as $fw): ?><option value="<?= (int) $fw['id'] ?>" <?= $sel('forwarder_id', (string) $fw['id']) ?>><?= e($fw['name']) ?></option><?php endforeach ?>
                </select>
            </div>
            <div class="field">
                <label for="f-tm"><?= e(t('transport.mode')) ?></label>
                <select id="f-tm" data-filter="transport_mode" data-advanced>
                    <option value=""><?= e(t('common.all')) ?></option>
                    <?php foreach (Checklist::TRANSPORTS as $tm): ?><option value="<?= $tm ?>" <?= $sel('transport_mode', $tm) ?>><?= e(t('transport.' . $tm)) ?></option><?php endforeach ?>
                </select>
            </div>
            <div class="field">
                <label for="f-gap"><?= e(t('delivery.gap')) ?></label>
                <select id="f-gap" data-filter="gap" data-advanced>
                    <option value=""><?= e(t('common.all')) ?></option>
                    <option value="1" <?= $sel('gap', '1') ?>><?= e(t('delivery.filter.with_gap')) ?></option>
                </select>
            </div>
            <div class="field field--dates">
                <label for="f-from"><?= e(t('delivery.filter.shipped_between')) ?></label>
                <div class="date-range">
                    <input id="f-from" type="date" data-filter="from" data-advanced value="<?= e($f['from'] ?? '') ?>" aria-label="<?= e(t('delivery.filter.from')) ?>">
                    <span class="muted">→</span>
                    <input id="f-to" type="date" data-filter="to" data-advanced value="<?= e($f['to'] ?? '') ?>" aria-label="<?= e(t('delivery.filter.to')) ?>">
                </div>
            </div>
        </div>
    </form>
    <div class="table-wrap">
        <table class="table table--data table--compact" data-table="deliveries" data-source="<?= e(url('/deliveries/data')) ?>" data-row-link="<?= e(url('/deliveries/{id}')) ?>" data-page-length="15"></table>
    </div>
</section>
