<?php
/** Fiche d'une licence d'importation. @var array $lic @var array $licLines @var array $lines @var array $summary @var array $inspections */
use App\Core\View;
use App\Domain\ImportGate;
use App\Security\Auth;

$user = Auth::require();
$today = date('Y-m-d');
$ok = ImportGate::licenceValid($lic, $today);
$days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($lic['expires_at']))->format('%r%a');
$state = $lic['status'] === 'cancelled' ? 'cancelled' : ($ok ? ((int) $lic['open_ara'] > 0 ? 'blocked' : 'valid') : 'expired');
?>
<?= View::partial('partials/dossier_bar', ['kind' => 'licence', 'orderId' => (int) $lic['order_id'], 'poId' => (int) $lic['po_id']]) ?>
<section class="hero">
    <div class="hero__icon"><?= icon('stamp') ?></div>
    <div class="hero__body">
        <div class="hero__line">
            <h1 class="hero__code mono"><?= e($lic['number']) ?></h1>
            <?= View::partial('partials/status_badge', ['key' => 'imports.state.' . $state, 'status' => ['valid' => 'delivered', 'blocked' => 'blocked', 'expired' => 'overdue', 'cancelled' => 'cancelled'][$state]]) ?>
        </div>
        <?php if ($lic['cod_number']): ?><p class="hero__cod"><span class="cod-ref mono"><?= icon('hash') ?> <?= e($lic['cod_number']) ?></span></p><?php endif ?>
        <p class="hero__name"><?php if ($lic['source'] === 'document'): ?><span class="tag" title="<?= e(t('imports.origin_document')) ?>"><?= icon('scan-text') ?> <?= e(t('imports.origin_document')) ?></span> <?php endif ?><?= e(t('imports.licence')) ?> · <?= e($lic['supplier_name']) ?><?= $lic['origin_country'] ? ' · ' . e($lic['origin_country']) : '' ?></p>
    </div>
    <div class="hero__actions">
        <?php if ($lic['attachment_id']): ?><a class="btn"<?= preview_attrs(url('/attachments/' . (int) $lic['attachment_id']), t('imports.licence') . ' ' . $lic['number'], $lic['file_mime']) ?>><?= icon('file-text') ?> <?= e(t('imports.licence_file')) ?></a><?php endif ?>
        <?php if ($user->can('imports.manage') && !in_array($lic['order_status'], ['closed', 'cancelled'], true)): ?>
            <a class="btn" href="<?= e(url('/licences/' . (int) $lic['id'] . '/edit')) ?>"><?= icon('pencil') ?> <?= e(t('common.edit')) ?></a>
        <?php endif ?>
    </div>
</section>

<section class="vitals">
    <div class="vital"><?= icon('calendar', 'vital__icon') ?><span class="vital__label"><?= e(t('documents.issued_at')) ?></span><span class="vital__value"><?= e(fmt_date($lic['issued_at'])) ?></span></div>
    <div class="vital<?= $ok ? '' : ' vital--alert' ?>"><?= icon('calendar-clock', 'vital__icon vital__icon--' . ($ok ? ($days <= 30 ? 'warn' : 'good') : 'bad')) ?><span class="vital__label"><?= e(t('documents.expires_at')) ?></span>
        <span class="vital__value"><?= e(fmt_date($lic['expires_at'])) ?> <span class="<?= $ok ? 'muted' : 'text-bad' ?>">· <?= e($days >= 0 ? t('imports.days_left', ['n' => $days]) : t('imports.expired_since', ['n' => -$days])) ?></span></span></div>
    <div class="vital"><?= icon('wallet', 'vital__icon') ?><span class="vital__label"><?= e(t('imports.cost.fob')) ?></span><span class="vital__value"><?= $lic['amount'] !== null ? e(fmt_money($lic['amount'], $lic['currency'])) : '—' ?></span></div>
    <?php $costs = ['imports.cost.freight' => $lic['freight'], 'imports.cost.insurance' => $lic['insurance'], 'imports.cost.other' => $lic['other_costs']];
    if ($lic['amount'] !== null && array_filter($costs, fn ($v) => $v !== null)): ?>
        <div class="vital"><?= icon('calculator', 'vital__icon') ?><span class="vital__label"><?= e(t('imports.cost.cif')) ?></span>
            <span class="vital__value"><?= e(fmt_money((float) $lic['amount'] + array_sum(array_map('floatval', $costs)), $lic['currency'])) ?></span>
            <span class="vital__hint muted"><?= e(implode(' · ', array_map(fn ($k, $v) => t($k) . ' ' . fmt_number((float) $v), array_keys(array_filter($costs, fn ($v) => $v !== null)), array_filter($costs, fn ($v) => $v !== null)))) ?></span></div>
    <?php endif ?>
    <div class="vital"><?= icon('shopping-cart', 'vital__icon') ?><span class="vital__label"><?= e(t('imports.po_amount')) ?></span><span class="vital__value"><?= e(fmt_money($lic['po_amount'], $lic['po_currency'])) ?></span></div>
    <div class="vital"><?= icon('landmark', 'vital__icon') ?><span class="vital__label"><?= e(t('imports.bank')) ?></span><span class="vital__value"><?= e($lic['bank'] ?: '—') ?></span></div>
</section>
<?php if ($lic['notes']): ?><p class="prewrap muted"><?= e($lic['notes']) ?></p><?php endif ?>

<?php if ($licLines): ?>
<section class="card">
    <div class="card__title"><h2><?= icon('package', 'title-icon') ?> <?= e(t('imports.lines_title')) ?></h2></div>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th>#</th><th><?= e(t('licence_import.col.description')) ?></th><th><?= e(t('licence_import.col.hs')) ?></th><th class="num"><?= e(t('movements.col.quantity')) ?></th><th><?= e(t('items.col.unit')) ?></th><th class="num"><?= e(t('orders.col.unit_price')) ?></th><th class="num"><?= e(t('orders.col.amount')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($licLines as $l): ?>
            <tr><td><?= (int) $l['line_no'] ?></td><td><?= e($l['description']) ?></td><td class="mono"><?= e($l['hs_code'] ?? '—') ?></td>
                <td class="num"><?= $l['quantity'] !== null ? e(fmt_qty($l['quantity'])) : '—' ?></td><td><?= e($l['unit'] ?? '') ?></td>
                <td class="num"><?= $l['unit_price'] !== null ? e(fmt_money($l['unit_price'], $lic['currency'])) : '—' ?></td>
                <td class="num"><?= $l['amount'] !== null ? e(fmt_money($l['amount'], $lic['currency'])) : '—' ?></td></tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</section>
<?php endif ?>

<?= View::partial('imports/_panel', ['po' => ['id' => $lic['po_id'], 'reference' => $lic['po_reference'], 'status' => $lic['po_status'], 'order_status' => $lic['order_status']], 'lines' => $lines, 'summary' => $summary, 'inspections' => $inspections]) ?>
