<?php /** Aperçu de facture impossible (lignes invalides…). @var string $message */ ?>
<article class="doc doc--message">
    <h1><?= e(t('invoice.preview')) ?></h1>
    <p class="notes"><?= e($message) ?></p>
    <p class="muted"><?= e(t('invoice.preview_fix')) ?></p>
</article>
