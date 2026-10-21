<?php
/**
 * Barre « Dossier » commune aux pages d'un dossier (PO client, PO fournisseur, licence, inspection, expédition).
 * @var string $kind @var int $orderId @var ?int $poId @var ?int $deliveryId
 */
use App\Services\DossierTrail;

$trail = DossierTrail::build($kind, (int) $orderId, isset($poId) ? (int) $poId : null, isset($deliveryId) ? (int) $deliveryId : null);
if ($trail === null) {
    return;
}
// Le menu reste sur « Commandes » : on ne quitte pas le dossier.
$GLOBALS['elm_nav_active'] = '/orders';
?>
<nav class="dossier-bar" aria-label="<?= e(t('dossier.aria')) ?>">
    <span class="dossier-bar__title"><?= icon('folder-open') ?> <?= e(t('dossier.title')) ?></span>
    <ol class="dossier-bar__steps">
        <?php foreach ($trail['steps'] as $s):
            $cls = 'dossier-step dossier-step--' . $s['state'];
            $inner = icon($s['icon']) . '<span class="dossier-step__text"><span class="dossier-step__label">' . e($s['label']) . '</span>'
                . '<span class="dossier-step__value">' . ($s['value'] !== null ? e($s['value']) : e(t('dossier.todo'))) . '</span></span>'; ?>
            <li class="<?= $cls ?>"<?= $s['state'] === 'current' ? ' aria-current="step"' : '' ?>>
                <?php if ($s['href'] && $s['state'] !== 'current'): ?>
                    <a class="dossier-step__box" href="<?= e($s['href']) ?>"><?= $inner ?></a>
                <?php else: ?>
                    <span class="dossier-step__box"><?= $inner ?></span>
                <?php endif ?>
                <?php if ($s['add'] && $s['state'] !== 'current'): ?>
                    <a class="dossier-step__add" href="<?= e($s['add']) ?>" title="<?= e(t('dossier.create')) ?>"><?= icon('plus') ?> <?= e(t('dossier.create')) ?></a>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
</nav>
