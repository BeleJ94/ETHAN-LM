<?php /** Agence d'une banque. @var array $bank @var array|null $br */ ?>
<form method="post" action="<?= e(url('/banks/' . (int) $bank['id'] . '/branches' . ($br ? '/' . (int) $br['id'] : ''))) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <div class="form-grid form-grid--modal">
        <div class="field"><label class="required"><?= e(t('banks.branch.name')) ?></label><input name="name" maxlength="160" required value="<?= e($br['name'] ?? '') ?>" placeholder="<?= e(t('banks.branch.name_placeholder')) ?>"></div>
        <div class="field"><label><?= e(t('banks.branch.code')) ?></label><input name="code" maxlength="20" class="mono" value="<?= e($br['code'] ?? '') ?>"><p class="hint"><?= e(t('banks.branch.code_hint')) ?></p></div>
        <div class="field"><label><?= e(t('banks.branch.city')) ?></label><input name="city" maxlength="120" value="<?= e($br['city'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('banks.branch.contact')) ?></label><input name="contact_name" maxlength="120" value="<?= e($br['contact_name'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('banks.col.phone')) ?></label><input name="phone" maxlength="60" type="tel" value="<?= e($br['phone'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('banks.col.email')) ?></label><input name="email" maxlength="190" type="email" value="<?= e($br['email'] ?? '') ?>"></div>
    </div>
    <div class="field"><label><?= e(t('banks.branch.address')) ?></label><input name="address" maxlength="255" value="<?= e($br['address'] ?? '') ?>"></div>
    <?php if ($br): ?>
        <label class="switch"><input type="checkbox" name="active" value="1" <?= $br['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label>
    <?php endif ?>
</form>
