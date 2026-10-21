<?php /** @var array|null $s @var int $customerId */ ?>
<form method="post" action="<?= e(url('/customers/' . $customerId . '/sites' . ($s ? '/' . (int) $s['id'] : ''))) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <div class="field"><label class="required"><?= e(t('sites.col.name')) ?></label><input name="name" maxlength="160" required value="<?= e($s['name'] ?? '') ?>" placeholder="<?= e(t('sites.delivery_placeholder')) ?>"></div>
    <div class="field"><label><?= e(t('parties.address')) ?></label><input name="address" maxlength="255" value="<?= e($s['address'] ?? '') ?>"></div>
    <div class="field"><label><?= e(t('sites.col.city')) ?></label><input name="city" maxlength="120" value="<?= e($s['city'] ?? '') ?>"></div>
    <div class="field"><label><?= e(t('parties.col.contact')) ?></label><input name="contact_name" maxlength="120" value="<?= e($s['contact_name'] ?? '') ?>"></div>
    <div class="field"><label><?= e(t('parties.phone')) ?></label><input name="phone" type="tel" maxlength="60" value="<?= e($s['phone'] ?? '') ?>"></div>
    <div class="field"><label>GPS</label><input name="gps" maxlength="60" value="<?= e($s['gps'] ?? '') ?>" placeholder="-10.7146, 25.4730"></div>
    <div class="field"><label><?= e(t('sites.instructions')) ?></label><textarea name="instructions" rows="2" maxlength="500"><?= e($s['instructions'] ?? '') ?></textarea></div>
    <?php if ($s): ?><label class="switch"><input type="checkbox" name="active" value="1" <?= $s['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label><?php endif ?>
</form>
