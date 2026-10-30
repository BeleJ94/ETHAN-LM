<?php
/** Paramètres → Société. @var array $company valeurs en base @var bool $hasLogo @var int $logoMaxKb */
use App\Core\View;

echo View::partial('partials/page_header', ['icon' => 'building', 'heading' => t('nav.company'), 'meta' => t('company.meta')]);
$field = function (string $name, string $labelKey, bool $required = false, string $type = 'text', int $max = 190, string $hint = '') use ($company): string {
    return '<div class="field"><label for="' . $name . '"' . ($required ? ' class="required"' : '') . '>' . e(t($labelKey)) . '</label>'
        . '<input id="' . $name . '" name="' . $name . '" type="' . $type . '" maxlength="' . $max . '"' . ($required ? ' required' : '')
        . ' value="' . e(old($name, $company[$name] ?? '')) . '"' . error_attr($name) . '>'
        . ($hint ? '<p class="hint">' . e(t($hint)) . '</p>' : '') . error_msg($name) . '</div>';
};
?>
<form method="post" action="<?= e(url('/settings/company')) ?>" enctype="multipart/form-data" class="card form form--sections" data-no-ajax>
    <?= csrf_field() ?>
    <fieldset class="form-section">
        <legend><?= e(t('company.section.identity')) ?></legend>
        <div class="form-grid">
            <?= $field('name', 'company.name', true) ?>
            <?= $field('legal_form', 'company.legal_form', false, 'text', 60, 'company.legal_form_hint') ?>
            <?= $field('share_capital', 'company.share_capital', false, 'text', 60) ?>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><?= e(t('company.section.legal')) ?></legend>
        <div class="form-grid">
            <?= $field('rccm', 'parties.rccm', false, 'text', 60) ?>
            <?= $field('id_nat', 'parties.id_nat', false, 'text', 60) ?>
            <?= $field('nif', 'parties.nif', false, 'text', 60) ?>
            <?= $field('vat_number', 'company.vat_number', false, 'text', 60) ?>
            <?= $field('importer_number', 'company.importer_number', false, 'text', 60) ?>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><?= e(t('parties.section.contact')) ?></legend>
        <div class="form-grid">
            <?= $field('address', 'parties.address', false, 'text', 255) ?>
            <?= $field('city', 'parties.col.city', false, 'text', 120) ?>
            <?= $field('country', 'parties.country', false, 'text', 80) ?>
            <?= $field('phone', 'parties.phone', false, 'tel', 60) ?>
            <?= $field('email', 'auth.email', false, 'email', 190) ?>
            <?= $field('website', 'company.website', false, 'text', 190) ?>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><?= e(t('company.section.invoicing')) ?></legend>
        <div class="form-grid">
            <div class="field"><label for="vat_rate"><?= e(t('company.vat_rate')) ?></label>
                <input id="vat_rate" name="vat_rate" type="number" step="0.01" min="0" max="100" inputmode="decimal" value="<?= e(old('vat_rate', $company['vat_rate'] ?? '')) ?>"<?= error_attr('vat_rate') ?>>
                <p class="hint"><?= e(t('company.vat_rate_hint')) ?></p><?= error_msg('vat_rate') ?></div>
            <?php foreach (['bank_details' => 'company.bank_details', 'invoice_terms' => 'company.invoice_terms'] as $name => $key): ?>
                <div class="field"><label for="<?= $name ?>"><?= e(t($key)) ?></label>
                    <textarea id="<?= $name ?>" name="<?= $name ?>" rows="4" maxlength="1000"<?= error_attr($name) ?>><?= e(old($name, $company[$name] ?? '')) ?></textarea>
                    <p class="hint"><?= e(t($key . '_hint')) ?></p><?= error_msg($name) ?></div>
            <?php endforeach ?>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><?= e(t('company.section.logo')) ?></legend>
        <div class="company-logo">
            <div class="company-logo__preview">
                <?php if ($hasLogo): ?>
                    <img src="<?= e(url('/company/logo')) ?>?v=<?= time() ?>" alt="<?= e(t('company.logo')) ?>">
                <?php else: ?>
                    <span class="muted"><?= icon('image') ?> <?= e(t('company.no_logo')) ?></span>
                <?php endif ?>
            </div>
            <div class="field">
                <label for="logo"><?= e(t($hasLogo ? 'company.logo_replace' : 'company.logo')) ?></label>
                <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp"<?= error_attr('logo') ?>>
                <p class="hint"><?= e(t('company.logo_hint', ['max' => $logoMaxKb])) ?></p>
                <?= error_msg('logo') ?>
            </div>
        </div>
    </fieldset>
    <div class="form-actions">
        <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= e(t('common.save')) ?></button>
    </div>
</form>
<?php if ($hasLogo): ?>
<form method="post" action="<?= e(url('/settings/company/logo/delete')) ?>" data-no-ajax>
    <?= csrf_field() ?>
    <button type="submit" class="btn btn--sm"><?= icon('trash-2') ?> <?= e(t('company.logo_delete')) ?></button>
</form>
<?php endif ?>
