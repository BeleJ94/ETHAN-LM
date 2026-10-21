<?php /** Banque (création / modification). @var array|null $b */ ?>
<form method="post" action="<?= e(url($b ? '/banks/' . (int) $b['id'] : '/banks')) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <?php if (!empty($back)): ?><input type="hidden" name="back" value="<?= e($back) ?>"><?php endif ?>
    <div class="form-grid form-grid--modal">
        <?= \App\Core\View::partial('partials/ref_field', ['type' => 'bank', 'name' => 'code', 'label' => t('banks.col.code'), 'current' => $b['code'] ?? null]) ?>
        <div class="field"><label><?= e(t('banks.col.short_name')) ?></label><input name="short_name" maxlength="40" value="<?= e($b['short_name'] ?? '') ?>" placeholder="<?= e(t('banks.short_name_placeholder')) ?>"></div>
    </div>
    <div class="field"><label class="required"><?= e(t('banks.col.name')) ?></label><input name="name" maxlength="160" required value="<?= e($b['name'] ?? '') ?>" placeholder="<?= e(t('banks.name_placeholder')) ?>"></div>
    <div class="form-grid form-grid--modal">
        <div class="field"><label><?= e(t('banks.col.swift')) ?></label><input name="swift" maxlength="11" class="mono" value="<?= e($b['swift'] ?? '') ?>" placeholder="RAWBCDKI" autocapitalize="characters">
            <p class="hint"><?= e(t('banks.swift_hint')) ?></p></div>
        <div class="field"><label><?= e(t('banks.col.country')) ?></label><input name="country" maxlength="80" value="<?= e($b['country'] ?? '') ?>" placeholder="<?= e(t('banks.country_placeholder')) ?>"></div>
        <div class="field"><label><?= e(t('banks.col.phone')) ?></label><input name="phone" maxlength="60" type="tel" value="<?= e($b['phone'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('banks.col.email')) ?></label><input name="email" maxlength="190" type="email" value="<?= e($b['email'] ?? '') ?>"></div>
    </div>
    <div class="field"><label><?= e(t('banks.col.website')) ?></label><input name="website" maxlength="190" value="<?= e($b['website'] ?? '') ?>" placeholder="https://"></div>
    <div class="field"><label><?= e(t('movements.note')) ?></label><textarea name="notes" rows="2" maxlength="500"><?= e($b['notes'] ?? '') ?></textarea></div>
    <?php if ($b): ?>
        <label class="switch"><input type="checkbox" name="active" value="1" <?= $b['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label>
        <p class="hint"><?= e(t('banks.deactivate_hint')) ?></p>
    <?php endif ?>
</form>
