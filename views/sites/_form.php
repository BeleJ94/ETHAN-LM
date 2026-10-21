<?php /** @var array|null $s */ ?>
<form method="post" action="<?= e(url($s ? '/sites/' . (int) $s['id'] : '/sites')) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <?= \App\Core\View::partial('partials/ref_field', ['type' => 'site', 'name' => 'code', 'label' => t('sites.col.code'), 'current' => $s['code'] ?? null]) ?>
    <div class="field">
        <label class="required"><?= e(t('sites.col.name')) ?></label>
        <input name="name" maxlength="120" required value="<?= e($s['name'] ?? '') ?>">
    </div>
    <div class="field">
        <label><?= e(t('sites.col.city')) ?></label>
        <input name="city" maxlength="120" value="<?= e($s['city'] ?? '') ?>">
    </div>
    <?php if ($s): ?>
        <label class="switch"><input type="checkbox" name="active" value="1" <?= $s['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label>
    <?php endif ?>
</form>
