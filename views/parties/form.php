<?php
/** @var string $type @var string $base @var array|null $party @var array $currencies */
use App\Core\View;

$p = $party;
$id = $p ? (int) $p['id'] : null;
echo View::partial('partials/page_header', ['icon' => $type === 'customer' ? 'building-2' : 'factory', 'heading' => $title]);
$field = function (string $name, string $labelKey, bool $required = false, string $type = 'text', int $max = 190, string $hint = '') use ($p): string {
    return '<div class="field"><label for="' . $name . '"' . ($required ? ' class="required"' : '') . '>' . e(t($labelKey)) . '</label>'
        . '<input id="' . $name . '" name="' . $name . '" type="' . $type . '" maxlength="' . $max . '"' . ($required ? ' required' : '')
        . ' value="' . e(old($name, $p[$name] ?? '')) . '"' . error_attr($name) . '>'
        . ($hint ? '<p class="hint">' . e(t($hint)) . '</p>' : '') . error_msg($name) . '</div>';
};
?>
<form method="post" action="<?= e(url($id ? "$base/$id" : $base)) ?>" class="card form form--sections">
    <?= csrf_field() ?>
    <fieldset class="form-section">
        <legend><?= e(t('parties.section.identity')) ?></legend>
        <div class="form-grid">
            <?= View::partial('partials/ref_field', ['type' => $type, 'name' => 'code', 'label' => t('parties.col.code'), 'current' => $p['code'] ?? null]) ?>
            <?= $field('name', 'parties.col.name', true) ?>
            <?= $field('rccm', 'parties.rccm', false, 'text', 60) ?>
            <?= $field('id_nat', 'parties.id_nat', false, 'text', 60) ?>
            <?= $field('nif', 'parties.nif', false, 'text', 60) ?>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><?= e(t('parties.section.contact')) ?></legend>
        <div class="form-grid">
            <?= $field('contact_name', 'parties.col.contact', false, 'text', 120) ?>
            <?= $field('phone', 'parties.phone', false, 'tel', 60) ?>
            <?= $field('email', 'auth.email', false, 'email', 190) ?>
            <?= $field('address', 'parties.address', false, 'text', 255) ?>
            <?= $field('city', 'parties.col.city', false, 'text', 120) ?>
            <?= $field('country', 'parties.country', false, 'text', 80) ?>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><?= e(t('parties.section.terms')) ?></legend>
        <div class="form-grid">
            <div class="field">
                <label for="payment_terms_days" class="required"><?= e(t('parties.terms')) ?></label>
                <input id="payment_terms_days" name="payment_terms_days" type="number" min="0" step="1" required value="<?= e(old('payment_terms_days', $p['payment_terms_days'] ?? '30')) ?>"<?= error_attr('payment_terms_days') ?>>
                <p class="hint"><?= e(t('parties.terms_hint')) ?></p>
                <?= error_msg('payment_terms_days') ?>
            </div>
            <div class="field">
                <label for="currency" class="required"><?= e(t('movements.currency')) ?></label>
                <select id="currency" name="currency" required>
                    <?php $cur = old('currency', $p['currency'] ?? base_currency()); foreach ($currencies as $c): ?>
                        <option value="<?= e($c['code']) ?>" <?= $cur === $c['code'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="field span-2">
                <label for="notes"><?= e(t('movements.note')) ?></label>
                <textarea id="notes" name="notes" rows="2" data-autogrow><?= e(old('notes', $p['notes'] ?? '')) ?></textarea>
            </div>
            <?php if ($type === 'supplier'): ?>
            <?php endif ?>
            <?php if ($p): ?>
            <div class="field span-2"><label class="switch"><input type="checkbox" name="active" value="1" <?= $p['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label></div>
            <?php endif ?>
        </div>
    </fieldset>
    <div class="form-actions">
        <a class="link-btn" href="<?= e(url($id ? "$base/$id" : $base)) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= e(t('common.save')) ?></button>
    </div>
</form>
