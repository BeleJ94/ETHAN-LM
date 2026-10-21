<?php
/**
 * Champ de référence automatique et modifiable.
 * @var string $type     type ReferenceGenerator (order, po, item…)
 * @var string $name     nom du champ (reference, code, sku)
 * @var string $label    libellé déjà traduit
 * @var string|null $current  valeur existante (modification) — sinon une proposition est calculée
 * @var array  $ctx      contexte (family_id, site_id)
 * @var string|null $depends  champ du formulaire dont dépend la proposition (family_id, site_id)
 * @var bool|null $locked     référence figée (document émis) : lecture seule
 */
use App\Services\ReferenceGenerator;

$ctx ??= [];
$current ??= null;
$depends ??= null;
$locked ??= false;
$suggested = $current === null ? ReferenceGenerator::suggest($type, $ctx) : '';
$value = old($name, $current ?? $suggested);
$max = ReferenceGenerator::TYPES[$type][5];
$fid = 'ref-' . $name . '-' . bin2hex(random_bytes(3));
?>
<div class="field">
    <label for="<?= e($fid) ?>"><?= e($label) ?></label>
    <div class="ref-input">
        <input id="<?= e($fid) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" maxlength="<?= (int) $max ?>" class="mono" autocomplete="off" spellcheck="false"
            <?= $locked ? 'readonly' : '' ?>
            data-ref-auto data-ref-type="<?= e($type) ?>" data-ref-url="<?= e(url('/references/next')) ?>"
            <?= $depends ? 'data-ref-depends="' . e($depends) . '"' : '' ?>
            <?= $current === null ? '' : 'data-ref-dirty="1"' ?> placeholder="<?= e(t('reference.auto_placeholder')) ?>"<?= error_attr($name) ?>>
        <?php if (!$locked): ?>
            <button type="button" class="icon-btn" data-ref-regenerate title="<?= e(t('reference.regenerate')) ?>" aria-label="<?= e(t('reference.regenerate')) ?>"><?= icon('refresh-cw') ?></button>
        <?php endif ?>
    </div>
    <input type="hidden" name="<?= e($name) ?>_suggested" value="<?= e(old($name . '_suggested', $suggested)) ?>" data-ref-suggested>
    <p class="hint"><?= e(t($locked ? 'reference.locked' : 'reference.hint')) ?></p>
    <?= error_msg($name) ?>
</div>
