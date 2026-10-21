<?php /** @var array|null $w @var array $sites */ ?>
<form method="post" action="<?= e(url($w ? '/warehouses/' . (int) $w['id'] : '/warehouses')) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <div class="field">
        <label class="required"><?= e(t('warehouses.col.site')) ?></label>
        <select name="site_id" required>
            <?php foreach ($sites as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= $w && (int) $w['site_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <?= \App\Core\View::partial('partials/ref_field', ['type' => 'warehouse', 'name' => 'code', 'label' => t('warehouses.col.code'),
        'current' => $w['code'] ?? null, 'ctx' => ['site_id' => $w['site_id'] ?? ($sites[0]['id'] ?? null)], 'depends' => 'site_id']) ?>
    <div class="field">
        <label class="required"><?= e(t('warehouses.col.name')) ?></label>
        <input name="name" maxlength="120" required value="<?= e($w['name'] ?? '') ?>">
    </div>
    <?php if ($w): ?>
        <label class="switch"><input type="checkbox" name="active" value="1" <?= $w['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label>
    <?php endif ?>
</form>
