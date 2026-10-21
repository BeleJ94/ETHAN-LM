<?php
/** @var array|null $item @var array $units @var array $categories */
use App\Core\View;

$id = $item ? (int) $item['id'] : null;
echo View::partial('partials/page_header', ['icon' => 'package', 'heading' => $item ? t('items.edit') . ' — ' . $item['sku'] : t('items.new')]);
?>
<form method="post" action="<?= e(url($id ? "/items/$id" : '/items')) ?>" class="card form">
    <?= csrf_field() ?>
    <div class="form-grid">
        <?php
        $selectable = array_values(array_filter($families, fn ($f) => $f['active'] || (int) $f['id'] === (int) ($item['family_id'] ?? 0)));
        $familyId = old('family_id', $item['family_id'] ?? ($selectable[0]['id'] ?? ''));
        ?>
        <div class="field">
            <label for="family_id" class="required"><?= e(t('item.family')) ?></label>
            <select id="family_id" name="family_id" required<?= error_attr('family_id') ?>>
                <?php foreach ($selectable as $f): ?>
                    <option value="<?= (int) $f['id'] ?>" <?= (string) $familyId === (string) $f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?> (<?= e($f['code']) ?>)</option>
                <?php endforeach ?>
            </select>
            <p class="hint"><?= e(t('item.family_hint')) ?> <a href="<?= e(url('/families')) ?>"><?= e(t('families.manage')) ?></a></p>
            <?= error_msg('family_id') ?>
        </div>
        <?= View::partial('partials/ref_field', [
            'type' => 'item', 'name' => 'sku', 'label' => t('items.col.sku'),
            'current' => $item['sku'] ?? null, 'ctx' => ['family_id' => $familyId], 'depends' => 'family_id',
        ]) ?>
        <div class="field span-2">
            <label for="name" class="required"><?= e(t('items.col.name')) ?></label>
            <input id="name" name="name" maxlength="190" required value="<?= e(old('name', $item['name'] ?? '')) ?>"<?= error_attr('name') ?>>
            <?= error_msg('name') ?>
        </div>
        <div class="field">
            <label for="unit_id" class="required"><?= e(t('items.col.unit')) ?></label>
            <select id="unit_id" name="unit_id" required<?= error_attr('unit_id') ?>>
                <option value=""><?= e(t('common.choose')) ?></option>
                <?php $cur = old('unit_id', $item['unit_id'] ?? ''); foreach ($units as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (string) $cur === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['label'] . ' (' . $u['code'] . ')') ?></option>
                <?php endforeach ?>
            </select>
            <?= error_msg('unit_id') ?>
        </div>
        <div class="field">
            <label for="category_id"><?= e(t('items.col.category')) ?></label>
            <select id="category_id" name="category_id">
                <option value=""><?= e(t('common.none')) ?></option>
                <?php $cur = old('category_id', $item['category_id'] ?? ''); foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (string) $cur === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach ?>
            </select>
            <input name="category_name" maxlength="120" placeholder="<?= e(t('items.new_category')) ?>" value="<?= e(old('category_name')) ?>" class="mt-2"<?= error_attr('category_name') ?>>
            <?= error_msg('category_name') ?>
        </div>
        <div class="field">
            <label for="min_stock"><?= e(t('items.col.min_stock')) ?></label>
            <input id="min_stock" name="min_stock" type="number" step="0.001" min="0" inputmode="decimal" value="<?= e(old('min_stock', $item['min_stock'] ?? '0')) ?>"<?= error_attr('min_stock') ?>>
            <p class="hint"><?= e(t('items.min_stock_hint')) ?></p>
            <?= error_msg('min_stock') ?>
        </div>
        <div class="field">
            <label for="hs_code"><?= e(t('items.hs_code')) ?></label>
            <input id="hs_code" name="hs_code" maxlength="20" value="<?= e(old('hs_code', $item['hs_code'] ?? '')) ?>"<?= error_attr('hs_code') ?>>
            <p class="hint"><?= e(t('items.hs_code_hint')) ?></p>
            <?= error_msg('hs_code') ?>
        </div>
        <div class="field span-2">
            <label for="description"><?= e(t('items.description')) ?></label>
            <textarea id="description" name="description" rows="3" data-autogrow><?= e(old('description', $item['description'] ?? '')) ?></textarea>
        </div>
        <?php if ($item): ?>
        <div class="field span-2">
            <label class="switch"><input type="checkbox" name="active" value="1" <?= $item['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label>
        </div>
        <?php endif ?>
    </div>
    <div class="form-actions">
        <a class="link-btn" href="<?= e(url($id ? "/items/$id" : '/items')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= e(t('common.save')) ?></button>
    </div>
</form>
