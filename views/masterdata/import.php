<?php
/**
 * Import des référentiels : dépôt (mode mise à jour / réinitialisation) → simulation → confirmation.
 * @var int $maxMb @var ?array{token:string,mode:string} $pending fichier déposé en attente @var ?array $report @var bool $hasTemplate
 */
use App\Core\View;

$actions = $hasTemplate ? '<a class="btn" href="' . e(url('/settings/import/template')) . '">' . icon('download') . ' ' . e(t('masterdata.template')) . '</a>' : '';
echo View::partial('partials/page_header', ['icon' => 'file-spreadsheet', 'heading' => t('masterdata.title'), 'meta' => t('masterdata.meta'), 'actions' => $actions]);
$committed = $report['committed'] ?? false;
$errors = $report['errors'] ?? [];
$reset = ($report['mode'] ?? '') === 'reset';
?>
<ol class="import-steps" aria-label="<?= e(t('masterdata.title')) ?>">
    <li class="<?= $report ? 'is-done' : 'is-current' ?>"><span>1</span> <?= e(t('masterdata.step.upload')) ?></li>
    <li class="<?= $committed ? 'is-done' : ($pending ? 'is-current' : '') ?>"><span>2</span> <?= e(t('masterdata.step.check')) ?></li>
    <li class="<?= $committed ? 'is-done' : '' ?>"><span>3</span> <?= e(t('masterdata.step.import')) ?></li>
</ol>

<?php if (!$pending): ?>
<section class="card">
    <form method="post" action="<?= e(url('/settings/import')) ?>" enctype="multipart/form-data" data-no-ajax>
        <?= csrf_field() ?>
        <div class="choice-cards" role="radiogroup" aria-label="<?= e(t('masterdata.mode')) ?>">
            <label class="choice-card">
                <input type="radio" name="mode" value="update" checked>
                <span class="choice-card__body"><?= icon('refresh-cw') ?><strong><?= e(t('masterdata.mode.update')) ?></strong><span class="muted"><?= e(t('masterdata.mode.update_hint')) ?></span></span>
            </label>
            <label class="choice-card">
                <input type="radio" name="mode" value="reset">
                <span class="choice-card__body"><?= icon('triangle-alert') ?><strong class="text-bad"><?= e(t('masterdata.mode.reset')) ?></strong><span class="muted"><?= e(t('masterdata.mode.reset_hint')) ?></span></span>
            </label>
        </div>
        <label class="dropzone">
            <input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            <?= icon('file-up', 'dropzone__icon') ?>
            <span class="muted"><?= e(t('masterdata.drop_hint', ['max' => $maxMb])) ?></span>
        </label>
        <p><button type="submit" class="btn btn--primary"><?= icon('scan-search') ?> <?= e(t('masterdata.analyse')) ?></button></p>
    </form>
    <ul class="import-upload__notes">
        <li><?= icon('shield-check') ?> <?= e(t('masterdata.note_simulation')) ?></li>
        <li><?= icon('user-check') ?> <?= e(t('masterdata.note_users')) ?></li>
        <li><?= icon('package') ?> <?= e(t('masterdata.note_stock')) ?></li>
    </ul>
</section>
<?php endif ?>

<?php if ($report): ?>
    <div class="alert alert--<?= $reset ? 'error' : 'info' ?>" role="status">
        <?= icon($reset ? 'triangle-alert' : 'refresh-cw') ?>
        <span><strong><?= e(t($reset ? 'masterdata.mode.reset' : 'masterdata.mode.update')) ?></strong> — <?= e(t($reset ? 'masterdata.mode.reset_hint' : 'masterdata.mode.update_hint')) ?></span>
    </div>
    <?php foreach ($report['warnings'] as $w): ?>
        <div class="alert alert--info" role="status"><?= icon('info') ?> <span><?= e($w) ?></span></div>
    <?php endforeach ?>

    <?php if ($errors): ?>
    <section class="card">
        <h2><?= icon('circle-alert') ?> <?= e(t('masterdata.errors', ['n' => count($errors)])) ?></h2>
        <p class="muted"><?= e(t('masterdata.errors_hint')) ?></p>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th><?= e(t('masterdata.col.sheet')) ?></th><th class="num"><?= e(t('masterdata.col.row')) ?></th><th><?= e(t('masterdata.col.problem')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($errors as $err): ?>
                <tr><td><?= e($err['sheet']) ?></td><td class="num"><?= (int) $err['row'] ?></td><td class="text-bad"><?= e($err['message']) ?></td></tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif ?>

    <?php if ($reset): ?>
    <section class="card">
        <h2><?= icon('trash-2') ?> <?= e(t($committed ? 'masterdata.wiped_done' : 'masterdata.wiped', ['n' => array_sum($report['wiped'])])) ?></h2>
        <p class="muted"><?= e(t('masterdata.wiped_hint')) ?></p>
        <?php if ($report['wiped']): ?>
        <p class="mono"><?= e(implode(' · ', array_map(fn ($t, $n) => "$t ($n)", array_keys($report['wiped']), $report['wiped']))) ?></p>
        <?php endif ?>
    </section>
    <?php endif ?>

    <section class="card">
        <h2><?= e(t($committed ? 'masterdata.result_done' : 'masterdata.result_simulation')) ?></h2>
        <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th><?= e(t('masterdata.col.sheet')) ?></th>
                <th class="num"><?= e(t('masterdata.col.created')) ?></th>
                <th class="num"><?= e(t('masterdata.col.updated')) ?></th>
                <th class="num"><?= e(t('masterdata.col.unchanged')) ?></th>
                <th><?= e(t('masterdata.col.updated_list')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($report['sheets'] as $s): ?>
                <tr>
                    <td><?= e($s['label']) ?></td>
                    <td class="num"><?= (int) $s['created'] ?></td>
                    <td class="num"><?= count($s['updated']) ?></td>
                    <td class="num muted"><?= (int) $s['unchanged'] ?></td>
                    <td class="muted"><?= e(implode(', ', array_slice($s['updated'], 0, 8)) . (count($s['updated']) > 8 ? '…' : '')) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </section>

    <?php if (array_filter($report['company'])): ?>
    <section class="card">
        <h2><?= icon('building') ?> <?= e(t('masterdata.company')) ?></h2>
        <p class="muted"><?= e(t('masterdata.company_hint')) ?></p>
        <p class="mono"><?= e(implode(' · ', $report['company'])) ?></p>
        <p><a href="<?= e(url('/settings/company')) ?>"><?= icon('building') ?> <?= e(t('nav.company')) ?></a></p>
    </section>
    <?php endif ?>

    <?php if ($pending): ?>
    <div class="form-actions">
        <form method="post" action="<?= e(url('/settings/import/cancel')) ?>" class="inline" data-no-ajax>
            <?= csrf_field() ?>
            <button type="submit" class="btn"><?= icon('x') ?> <?= e(t('masterdata.cancel')) ?></button>
        </form>
        <?php if (!$errors): ?>
        <form method="post" action="<?= e(url('/settings/import/confirm')) ?>" class="inline" data-no-ajax>
            <?= csrf_field() ?>
            <?php if ($reset): ?>
                <label><?= e(t('masterdata.confirm_word')) ?>
                    <input type="text" name="confirm_word" required autocomplete="off" pattern="REINITIALISER" placeholder="REINITIALISER"></label>
            <?php endif ?>
            <button type="submit" class="btn <?= $reset ? 'btn--danger' : 'btn--primary' ?>"><?= icon('check') ?> <?= e(t($reset ? 'masterdata.confirm_reset' : 'masterdata.confirm')) ?></button>
        </form>
        <?php endif ?>
    </div>
    <?php elseif ($committed): ?>
    <form method="post" action="<?= e(url('/settings/import/cancel')) ?>" data-no-ajax>
        <?= csrf_field() ?>
        <button type="submit" class="btn"><?= icon('file-up') ?> <?= e(t('masterdata.new_import')) ?></button>
    </form>
    <?php endif ?>
<?php endif ?>
