<?php
/** @var array $warehouses @var string $type @var string $period */
use App\Core\View;
use App\Security\Auth;

$itemId = (int) ($_GET['item_id'] ?? 0);
$actions = '<button type="button" class="btn" data-export="excel" data-export-table="movements" data-export-title="' . e(t('nav.movements')) . '">' . icon('file-spreadsheet') . ' Excel</button>'
    . '<button type="button" class="btn" data-export="pdf" data-export-table="movements" data-export-title="' . e(t('nav.movements')) . '">' . icon('file-text') . ' PDF</button>';
if (Auth::require()->can('stock.move')) {
    $actions .= '<a class="btn" href="' . e(url('/movements/new', ['type' => 'transfer'])) . '">' . icon('arrow-left-right') . ' ' . e(t('movements.new.transfer')) . '</a>'
        . '<a class="btn btn--primary" href="' . e(url('/movements/new', ['type' => 'in'])) . '">' . icon('plus') . ' ' . e(t('movements.new.in')) . '</a>';
}
echo View::partial('partials/page_header', ['icon' => 'arrow-left-right', 'heading' => t('nav.movements'), 'meta' => t('movements.meta'), 'actions' => $actions]);
?>
<section class="card">
    <form class="filters" data-filters-for="movements">
        <?php if ($itemId): ?><input type="hidden" data-filter="item_id" value="<?= $itemId ?>"><?php endif ?>
        <div class="field">
            <label for="f-type"><?= e(t('movements.col.type')) ?></label>
            <select id="f-type" data-filter="type">
                <?php foreach (['' => 'common.all', 'in' => 'movements.group.in', 'out' => 'movements.group.out', 'transfer' => 'movements.group.transfer', 'adjust' => 'movements.group.adjust'] as $v => $k): ?>
                    <option value="<?= e($v) ?>" <?= $type === $v ? 'selected' : '' ?>><?= e(t($k)) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-wh"><?= e(t('movements.col.warehouse')) ?></label>
            <select id="f-wh" data-filter="warehouse_id">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($warehouses as $w): ?>
                    <option value="<?= (int) $w['id'] ?>"><?= e($w['code'] . ' — ' . $w['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-period"><?= e(t('movements.period')) ?></label>
            <select id="f-period" data-filter="period">
                <?php foreach (['' => 'movements.period.all', 'today' => 'movements.period.today', '7d' => 'movements.period.7d', '30d' => 'movements.period.30d'] as $v => $k): ?>
                    <option value="<?= e($v) ?>" <?= $period === $v ? 'selected' : '' ?>><?= e(t($k)) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="filters__meta">
            <?php if ($itemId): ?><a class="chip chip--warn" href="<?= e(url('/movements')) ?>"><?= e(t('movements.filtered_item')) ?> ✕</a><?php endif ?>
            <span class="chip" data-filters-count hidden></span>
            <button type="button" class="btn btn--ghost btn--sm" data-filters-reset hidden><?= icon('rotate-ccw') ?> <?= e(t('common.reset')) ?></button>
        </div>
    </form>
    <div class="table-wrap">
        <table class="table table--data" data-table="movements" data-source="<?= e(url('/movements/data')) ?>" data-row-link="<?= e(url('/items/{item_id}')) ?>"></table>
    </div>
</section>
