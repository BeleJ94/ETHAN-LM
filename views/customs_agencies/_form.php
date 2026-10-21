<?php /** Agence en douane / transitaire (création / modification). @var array|null $a @var string|null $back */ ?>
<form method="post" action="<?= e(url($a ? '/customs-agencies/' . (int) $a['id'] : '/customs-agencies')) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <?php if (!empty($back)): ?><input type="hidden" name="back" value="<?= e($back) ?>"><?php endif ?>
    <div class="form-grid form-grid--modal">
        <?= \App\Core\View::partial('partials/ref_field', ['type' => 'agency', 'name' => 'code', 'label' => t('agencies.col.code'), 'current' => $a['code'] ?? null]) ?>
        <div class="field"><label><?= e(t('agencies.col.approval')) ?></label><input name="approval_number" maxlength="60" class="mono" value="<?= e($a['approval_number'] ?? '') ?>"><p class="hint"><?= e(t('agencies.approval_hint')) ?></p></div>
    </div>
    <div class="field"><label class="required"><?= e(t('agencies.col.name')) ?></label><input name="name" maxlength="190" required value="<?= e($a['name'] ?? '') ?>" placeholder="<?= e(t('agencies.name_placeholder')) ?>"></div>
    <div class="form-grid form-grid--modal">
        <div class="field"><label><?= e(t('agencies.col.contact')) ?></label><input name="contact_name" maxlength="120" value="<?= e($a['contact_name'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('banks.col.phone')) ?></label><input name="phone" type="tel" maxlength="60" value="<?= e($a['phone'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('banks.col.email')) ?></label><input name="email" type="email" maxlength="190" value="<?= e($a['email'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('agencies.col.city')) ?></label><input name="city" maxlength="120" value="<?= e($a['city'] ?? '') ?>"></div>
        <div class="field"><label>NIF</label><input name="nif" maxlength="60" class="mono" value="<?= e($a['nif'] ?? '') ?>"></div>
        <div class="field"><label>RCCM</label><input name="rccm" maxlength="60" class="mono" value="<?= e($a['rccm'] ?? '') ?>"></div>
    </div>
    <div class="field"><label><?= e(t('agencies.col.address')) ?></label><input name="address" maxlength="255" value="<?= e($a['address'] ?? '') ?>"></div>
    <div class="field"><label><?= e(t('agencies.col.posts')) ?></label><input name="customs_posts" maxlength="255" value="<?= e($a['customs_posts'] ?? '') ?>" placeholder="<?= e(t('agencies.posts_placeholder')) ?>"></div>
    <div class="field"><label><?= e(t('movements.note')) ?></label><textarea name="notes" rows="2" maxlength="500"><?= e($a['notes'] ?? '') ?></textarea></div>
    <?php if ($a): ?>
        <label class="switch"><input type="checkbox" name="active" value="1" <?= $a['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label>
        <p class="hint"><?= e(t('agencies.deactivate_hint')) ?></p>
    <?php endif ?>
</form>
