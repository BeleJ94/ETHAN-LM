<?php
/**
 * Bandeau « Prochaine action » du dossier : ce qu'il faut faire maintenant (avec le bouton qui le fait) + alertes cliquables.
 * @var array $o @var ?array $next @var array $cta @var ?array $cta2 @var array $alerts @var bool $open @var ?string $prefix clés de traduction de l'action (orders.next. par défaut)
 */
if (!$open && !$alerts) {
    return;
}
$btn = function (array $c, string $class): string {
    $label = icon($c['icon']) . ' ' . e($c['label']);
    if (isset($c['open'])) {
        return '<button type="button" class="' . $class . '" data-open="' . e($c['open']) . '" data-title="' . e($c['title'] ?? $c['label']) . '">' . $label . '</button>';
    }
    if (isset($c['form'])) {
        return '<form method="post" action="' . e($c['form']) . '" class="inline" data-confirm="' . e($c['confirm'] ?? '') . '">' . csrf_field()
            . '<button type="submit" class="' . $class . '">' . $label . '</button></form>';
    }
    return '<a class="' . $class . '" href="' . e($c['href']) . '">' . $label . '</a>';
};
?>
<section class="action-center<?= $next ? ' action-center--' . e($next['tone']) : '' ?>" aria-label="<?= e(t('orders.next_title')) ?>">
    <?php if ($next): ?>
    <div class="action-center__main">
        <span class="action-center__eyebrow"><?= e(t('orders.next_title')) ?></span>
        <p class="action-center__what"><?= e(t(($prefix ?? 'orders.next.') . $next['code'])) ?></p>
        <?php if (!empty($cta['hint'])): ?><p class="action-center__hint"><?= e($cta['hint']) ?></p><?php endif ?>
    </div>
    <div class="action-center__ctas">
        <?php if (!empty($cta['label'])): ?><?= $btn($cta, 'btn btn--primary') ?><?php endif ?>
        <?php if ($cta2): ?><?= $btn($cta2, 'btn') ?><?php endif ?>
    </div>
    <?php endif ?>
    <?php if ($alerts): ?>
    <ul class="action-center__alerts">
        <?php foreach ($alerts as [$tone, $ic, $text, $href]): ?>
            <li><a class="alert-chip alert-chip--<?= e($tone) ?>" href="<?= e($href) ?>" data-nav-jump><?= icon($ic) ?> <?= e($text) ?></a></li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>
