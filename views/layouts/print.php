<?php
/** @var string $content @var string $title — gabarit A4 imprimable (PO fournisseur, bon de livraison). */

use App\Core\I18n;
?>
<!doctype html>
<html lang="<?= e(I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '') ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
</head>
<body>
<div class="print-toolbar">
    <button type="button" data-print>🖨 <?= e(t('print.print')) ?></button>
    <span><?= e(t('print.hint')) ?></span>
</div>
<?= $content ?>
<script src="<?= e(asset('js/print.js')) ?>"></script>
</body>
</html>
