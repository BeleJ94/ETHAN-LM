<?php
/** @var array $categories @var array $warehouses @var string $status */
use App\Core\View;
use App\Security\Auth;

$actions = '<button type="button" class="btn" data-export="excel" data-export-table="items" data-export-title="' . e(t('nav.items')) . '">' . icon('file-spreadsheet') . ' Excel</button>'
    . '<button type="button" class="btn" data-export="pdf" data-export-table="items" data-export-title="' . e(t('nav.items')) . '">' . icon('file-text') . ' PDF</button>';
if (Auth::require()->can('items.manage')) {
    $actions .= '<a class="btn btn--primary" href="' . e(url('/items/new')) . '">' . icon('plus') . ' ' . e(t('items.new')) . '</a>';
}
echo View::partial('partials/page_header', ['icon' => 'package', 'heading' => t('nav.items'), 'meta' => t('items.meta'), 'actions' => $actions]);
?>
<section class="kpis kpis--compact">
    <button type="button" class="kpi kpi--button" data-set-filter="status" data-value=""><span class="kpi__label"><?= e(t('items.filter.all')) ?></span><span class="kpi__hint"><?= e(t('items.kpi.all_hint')) ?></span></button>
    <button type="button" class="kpi kpi--button kpi--warn" data-set-filter="status" data-value="low"><span class="kpi__label"><?= e(t('items.filter.low')) ?></span><span class="kpi__hint"><?= e(t('dashboard.low_hint')) ?></span></button>
    <button type="button" class="kpi kpi--button kpi--alert" data-set-filter="status" data-value="out"><span class="kpi__label"><?= e(t('items.filter.out')) ?></span><span class="kpi__hint"><?= e(t('dashboard.out_hint')) ?></span></button>
</section>

<section class="card">
    <form class="filters" data-filters-for="items">
        <div class="field">
            <label for="f-wh"><?= e(t('items.col.warehouse')) ?></label>
            <select id="f-wh" data-filter="warehouse_id">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($warehouses as $w): ?>
                    <option value="<?= (int) $w['id'] ?>"><?= e($w['code'] . ' — ' . $w['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-family"><?= e(t('item.family')) ?></label>
            <select id="f-family" data-filter="family_id">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($families as $fam): ?><option value="<?= (int) $fam['id'] ?>" <?= $familyId === (string) $fam['id'] ? 'selected' : '' ?>><?= e($fam['name']) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-cat"><?= e(t('items.col.category')) ?></label>
            <select id="f-cat" data-filter="category_id">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-status"><?= e(t('items.col.status')) ?></label>
            <select id="f-status" data-filter="status">
                <?php foreach (['' => 'items.filter.all', 'action' => 'items.filter.action', 'low' => 'items.filter.low', 'out' => 'items.filter.out', 'inactive' => 'items.filter.inactive'] as $val => $key): ?>
                    <option value="<?= e($val) ?>" <?= $status === $val ? 'selected' : '' ?>><?= e(t($key)) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="filters__meta">
            <span class="chip" data-filters-count hidden></span>
            <button type="button" class="btn btn--ghost btn--sm" data-filters-reset hidden><?= icon('rotate-ccw') ?> <?= e(t('common.reset')) ?></button>
        </div>
    </form>
    <div class="table-wrap">
        <table class="table table--data" data-table="items" data-source="<?= e(url('/items/data')) ?>" data-row-link="<?= e(url('/items/{id}')) ?>"></table>
    </div>
</section>
