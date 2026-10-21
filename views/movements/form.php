<?php
/** @var string $kind @var array|null $item @var array $warehouses @var array $currencies @var array $rates */
use App\Core\View;

$icons = ['in' => 'package-plus', 'out' => 'package-minus', 'transfer' => 'arrow-left-right', 'adjust' => 'clipboard-check'];
echo View::partial('partials/page_header', ['icon' => $icons[$kind], 'heading' => t('movements.new.' . $kind), 'meta' => t('movements.help.' . $kind)]);
$itemId = old('item_id', $item['id'] ?? '');
$selectedLabel = $item ? $item['sku'] . ' — ' . $item['name'] : '';
?>
<nav class="segmented" aria-label="<?= e(t('movements.col.type')) ?>">
    <?php foreach ($icons as $k => $ic): ?>
        <a class="segmented__item" href="<?= e(url('/movements/new', array_filter(['type' => $k, 'item_id' => $itemId]))) ?>" <?= $k === $kind ? 'aria-current="page"' : '' ?>><?= icon($ic) ?> <?= e(t('movements.group.' . $k)) ?></a>
    <?php endforeach ?>
</nav>

<form method="post" action="<?= e(url('/movements')) ?>" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>">

    <div class="form-grid">
        <?= View::partial('partials/ref_field', ['type' => ['in' => 'movement_in', 'out' => 'movement_out', 'transfer' => 'movement_transfer', 'adjust' => 'movement_adjust'][$kind], 'name' => 'reference', 'label' => t('movements.col.reference')]) ?>
        <div class="field" aria-hidden="true"></div>
        <div class="field span-2" data-equipment-picker data-picker-source="<?= e(url('/items/search')) ?>">
            <label for="picker-q" class="required"><?= e(t('movements.col.item')) ?></label>
            <input type="hidden" name="item_id" value="<?= e($itemId) ?>" data-picker-value>
            <div class="picker__selected" data-picker-selected <?= $itemId ? '' : 'hidden' ?>>
                <?= icon('package') ?> <strong data-picker-label><?= e($selectedLabel ?: old('item_label')) ?></strong>
                <button type="button" class="btn btn--sm" data-picker-change><?= e(t('common.change')) ?></button>
            </div>
            <div class="picker__search" data-picker-search <?= $itemId ? 'hidden' : '' ?>>
                <input id="picker-q" type="search" autocomplete="off" placeholder="<?= e(t('movements.search_item')) ?>" data-picker-input<?= error_attr('item_id') ?>>
                <ul class="picker__results" data-picker-results role="listbox"></ul>
            </div>
            <input type="hidden" name="item_label" value="<?= e($selectedLabel ?: old('item_label')) ?>" data-picker-label-input>
            <?= error_msg('item_id') ?>
        </div>

        <div class="field">
            <label for="warehouse_id" class="required"><?= e(t($kind === 'transfer' ? 'movements.from_warehouse' : 'movements.col.warehouse')) ?></label>
            <select id="warehouse_id" name="warehouse_id" required<?= error_attr('warehouse_id') ?>>
                <option value=""><?= e(t('common.choose')) ?></option>
                <?php foreach ($warehouses as $w): ?>
                    <option value="<?= (int) $w['id'] ?>" <?= old('warehouse_id') === (string) $w['id'] || count($warehouses) === 1 ? 'selected' : '' ?>><?= e($w['code'] . ' — ' . $w['name'] . ' (' . $w['site_name'] . ')') ?></option>
                <?php endforeach ?>
            </select>
            <?= error_msg('warehouse_id') ?>
        </div>

        <?php if ($kind === 'transfer'): ?>
        <div class="field">
            <label for="dest_warehouse_id" class="required"><?= e(t('movements.to_warehouse')) ?></label>
            <select id="dest_warehouse_id" name="dest_warehouse_id" required<?= error_attr('dest_warehouse_id') ?>>
                <option value=""><?= e(t('common.choose')) ?></option>
                <?php foreach ($warehouses as $w): ?>
                    <option value="<?= (int) $w['id'] ?>" <?= old('dest_warehouse_id') === (string) $w['id'] ? 'selected' : '' ?>><?= e($w['code'] . ' — ' . $w['name'] . ' (' . $w['site_name'] . ')') ?></option>
                <?php endforeach ?>
            </select>
            <?= error_msg('dest_warehouse_id') ?>
        </div>
        <?php endif ?>

        <div class="field">
            <label for="quantity" class="required"><?= e(t($kind === 'adjust' ? 'movements.counted_qty' : 'movements.col.quantity')) ?></label>
            <input id="quantity" name="quantity" type="number" step="0.001" min="<?= $kind === 'adjust' ? '0' : '0.001' ?>" inputmode="decimal" required value="<?= e(old('quantity')) ?>"<?= error_attr('quantity') ?>>
            <?php if ($kind === 'adjust'): ?><p class="hint"><?= e(t('movements.counted_hint')) ?></p><?php endif ?>
            <?= error_msg('quantity') ?>
        </div>

        <div class="field">
            <label for="moved_at" class="required"><?= e(t('movements.col.date')) ?></label>
            <input id="moved_at" name="moved_at" type="date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('moved_at', date('Y-m-d'))) ?>"<?= error_attr('moved_at') ?>>
            <?= error_msg('moved_at') ?>
        </div>

        <?php if ($kind === 'in'): ?>
        <div class="field">
            <label for="unit_cost" class="required"><?= e(t('movements.unit_cost')) ?></label>
            <input id="unit_cost" name="unit_cost" type="number" step="0.000001" min="0" inputmode="decimal" required value="<?= e(old('unit_cost')) ?>"<?= error_attr('unit_cost') ?>>
            <p class="hint"><?= e(t('movements.unit_cost_hint')) ?></p>
            <?= error_msg('unit_cost') ?>
        </div>
        <div class="field">
            <label for="currency_code" class="required"><?= e(t('movements.currency')) ?></label>
            <select id="currency_code" name="currency_code" required data-rate-target="#exchange_rate"<?= error_attr('currency_code') ?>>
                <?php $cur = old('currency_code', base_currency()); foreach ($currencies as $c): ?>
                    <option value="<?= e($c['code']) ?>" data-rate="<?= e((string) ($rates[$c['code']] ?? '')) ?>" <?= $cur === $c['code'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
                <?php endforeach ?>
            </select>
            <?= error_msg('currency_code') ?>
        </div>
        <div class="field">
            <label for="exchange_rate" class="required"><?= e(t('movements.rate', ['base' => base_currency()])) ?></label>
            <input id="exchange_rate" name="exchange_rate" type="number" step="0.00000001" min="0" inputmode="decimal" required value="<?= e(old('exchange_rate', '1')) ?>"<?= error_attr('exchange_rate') ?>>
            <p class="hint" data-cost-preview data-base="<?= e(base_currency()) ?>"></p>
            <?= error_msg('exchange_rate') ?>
        </div>
        <?php endif ?>

        <div class="field span-2">
            <label for="note"<?= $kind === 'adjust' ? ' class="required"' : '' ?>><?= e(t($kind === 'adjust' ? 'movements.reason' : 'movements.note')) ?></label>
            <textarea id="note" name="note" rows="2" maxlength="500" data-autogrow<?= $kind === 'adjust' ? ' required' : '' ?><?= error_attr('note') ?>><?= e(old('note')) ?></textarea>
            <?= error_msg('note') ?>
        </div>
    </div>

    <div class="form-actions">
        <a class="link-btn" href="<?= e(url($item ? '/items/' . (int) $item['id'] : '/movements')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary" data-once><?= icon('save') ?> <?= e(t('movements.submit')) ?></button>
    </div>
</form>
