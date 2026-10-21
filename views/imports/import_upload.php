<?php
/** Étape 1 : téléverser le document de la licence. @var int $maxMb @var bool $ocr @var ?int $poId PO d'origine */
use App\Core\View;

if ($poId) {
    echo View::partial('partials/dossier_bar', ['kind' => 'licence', 'orderId' => 0, 'poId' => $poId]);
}
echo View::partial('partials/page_header', [
    'icon' => 'scan-text',
    'heading' => t('licence_import.title'),
    'meta' => t('licence_import.meta'),
    'actions' => '<a class="btn" href="' . e(url('/licences/new', $poId ? ['po_id' => $poId] : [])) . '">' . icon('keyboard') . ' ' . e(t('licence_import.manual')) . '</a>',
]);
?>
<ol class="import-steps" aria-label="<?= e(t('licence_import.steps')) ?>">
    <li class="is-current"><span>1</span> <?= e(t('licence_import.step.upload')) ?></li>
    <li><span>2</span> <?= e(t('licence_import.step.extract')) ?></li>
    <li><span>3</span> <?= e(t('licence_import.step.review')) ?></li>
    <li><span>4</span> <?= e(t('licence_import.step.confirm')) ?></li>
</ol>

<section class="card import-upload">
    <form method="post" action="<?= e(url('/licences/import')) ?>" enctype="multipart/form-data" data-no-ajax data-pdf-upload data-max="<?= (int) $maxMb * 1024 * 1024 ?>">
        <?= csrf_field() ?>
        <?php if ($poId): ?><input type="hidden" name="po_id" value="<?= (int) $poId ?>"><?php endif ?>
        <label class="dropzone" data-dropzone>
            <input type="file" name="file" accept="application/pdf,.pdf" required class="sr-only" data-pdf-input>
            <?= icon('file-up', 'dropzone__icon') ?>
            <strong data-drop-title><?= e(t('licence_import.drop_title')) ?></strong>
            <span class="muted" data-drop-hint><?= e(t('licence_import.drop_hint', ['max' => $maxMb])) ?></span>
            <span class="btn btn--primary btn--sm dropzone__btn"><?= icon('folder-open') ?> <?= e(t('licence_import.choose')) ?></span>
        </label>
        <div class="upload-progress" data-progress hidden>
            <div class="upload-progress__bar"><span data-progress-bar></span></div>
            <p class="muted" data-progress-label><?= e(t('licence_import.uploading')) ?></p>
        </div>
        <p class="error" data-upload-error role="alert" hidden></p>
    </form>
    <ul class="import-upload__notes">
        <li><?= icon('file-check') ?> <?= e(t('licence_import.note_native')) ?></li>
        <li><?= icon($ocr ? 'scan' : 'scan-line') ?> <?= e(t($ocr ? 'licence_import.note_ocr_on' : 'licence_import.note_ocr_off')) ?></li>
        <li><?= icon('shield-check') ?> <?= e(t('licence_import.note_private')) ?></li>
        <li><?= icon('eye') ?> <?= e(t('licence_import.note_review')) ?></li>
    </ul>
</section>
<?= json_script('pdf-upload-i18n', [
    'not_pdf' => t('licence_import.error.not_pdf'),
    'too_big' => t('licence_import.error.too_big', ['max' => $maxMb]),
    'uploading' => t('licence_import.uploading'),
    'extracting' => t('licence_import.extracting'),
    'network' => t('js.network'),
    'selected' => t('licence_import.selected'),
]) ?>
