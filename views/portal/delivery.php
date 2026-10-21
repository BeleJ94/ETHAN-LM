<?php
/** @var array $d @var array $docs @var array{done:int,total:int} $progress */
use App\Core\View;

$pct = $progress['total'] > 0 ? (int) floor($progress['done'] / $progress['total'] * 100) : 100;
echo View::partial('partials/page_header', [
    'icon' => 'truck',
    'heading' => $d['reference'],
    'meta' => $d['customer_name'] . ' · ' . t('transport.' . $d['transport_mode']) . ($d['vehicle_plate'] ? ' · ' . $d['vehicle_plate'] : ''),
    'actions' => '<a class="btn" href="' . e(url('/portal')) . '">' . icon('arrow-left') . ' ' . e(t('nav.portal')) . '</a>',
]);
?>
<section class="card customs-card">
    <div class="card__title"><h2><?= icon('landmark', 'title-icon') ?> <?= e(t('delivery.customs.file_label')) ?></h2></div>
    <form method="post" action="<?= e(url('/portal/deliveries/' . (int) $d['id'] . '/file-number')) ?>" class="form customs-inline">
        <?= csrf_field() ?>
        <div class="field"><label class="sr-only" for="portal-ref"><?= e(t('delivery.customs.file_label')) ?></label>
            <input id="portal-ref" name="forwarder_ref" maxlength="60" class="mono" value="<?= e($d['forwarder_ref'] ?? '') ?>" placeholder="<?= e(t('delivery.customs.file_placeholder')) ?>"<?= in_array($d['order_status'], ['closed', 'cancelled'], true) || $d['status'] === 'cancelled' ? ' disabled' : '' ?>></div>
        <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= e(t('common.save')) ?></button>
    </form>
    <p class="hint"><?= e(t('delivery.customs.portal_file_hint')) ?></p>
</section>

<section class="card" id="documents">
    <div class="card__title">
        <h2><?= icon('file-check-2', 'title-icon') ?> <?= e(t('documents.checklist')) ?></h2>
        <span class="doc-progress"><span class="doc-progress__bar"><span style="width: <?= $pct ?>%"></span></span><strong><?= $progress['done'] ?>/<?= $progress['total'] ?></strong></span>
    </div>
    <p class="muted"><?= e(t('portal.upload_hint')) ?></p>
    <?= View::partial('documents/_checklist', ['docs' => $docs, 'back' => 'delivery:' . (int) $d['id'], 'readonly' => in_array($d['order_status'], ['closed', 'cancelled'], true) || $d['status'] === 'cancelled']) ?>
</section>
