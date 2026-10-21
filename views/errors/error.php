<?php /** @var int $status */ ?>
<div class="empty-state">
    <?= icon($status === 403 ? 'shield-x' : ($status === 404 ? 'search-x' : 'triangle-alert')) ?>
    <h1><?= (int) $status ?> — <?= e(t('error.' . $status)) ?></h1>
    <p class="muted"><?= e(t('error.' . $status . '.hint')) ?></p>
    <a class="btn btn--primary" href="<?= e(url('/')) ?>"><?= icon('house') ?> <?= e(t('error.home')) ?></a>
</div>
