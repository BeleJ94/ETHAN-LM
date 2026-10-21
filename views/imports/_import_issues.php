<?php /** Contrôles de cohérence affichés pendant la vérification. @var list<array{level:string,field:?string,message:string}> $issues */ ?>
<?php if (!$issues): ?>
    <p class="xissue xissue--ok"><?= icon('circle-check') ?> <?= e(t('licence_import.no_issue')) ?></p>
<?php else: ?>
    <p class="xissues__title"><?= icon('list-checks') ?> <?= e(t('licence_import.issues_title')) ?></p>
    <ul>
        <?php foreach ($issues as $i): ?>
            <li class="xissue xissue--<?= e($i['level']) ?>" data-field="<?= e((string) $i['field']) ?>"><?= icon($i['level'] === 'error' ? 'circle-x' : 'triangle-alert') ?> <span><?= e($i['message']) ?></span></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
