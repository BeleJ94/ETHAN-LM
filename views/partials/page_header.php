<?php
/**
 * @var string $icon
 * @var string $heading
 * @var string|null $meta
 * @var string|null $actions HTML déjà échappé
 * @var bool|null $alert
 */
?>
<header class="page-header">
    <div class="page-title">
        <span class="page-title__icon<?= !empty($alert) ? ' page-title__icon--alert' : '' ?>"><?= icon($icon) ?></span>
        <div>
            <h1><?= e($heading) ?></h1>
            <?php if (!empty($meta)): ?><p class="page-title__meta"><?= e($meta) ?></p><?php endif ?>
        </div>
    </div>
    <?php if (!empty($actions)): ?><div class="page-actions"><?= $actions ?></div><?php endif ?>
</header>
