<?php
/**
 * Fiche finance d'une facture (client à encaisser / fournisseur à payer).
 * @var array $inv
 */
use App\Core\View;
use App\Security\Auth;

$user = Auth::require();
$id = (int) $inv['id'];
$cur = $inv['currency'];
$receivable = $inv['direction'] === 'receivable';
$amount = (float) $inv['amount'];
$paid = (float) $inv['paid'];
$balance = max(0, round($amount - $paid, 2));
$today = date('Y-m-d');
$overdue = $inv['status'] === 'open' && $inv['due_date'] < $today;
$daysLate = $overdue ? (int) (new DateTimeImmutable($inv['due_date']))->diff(new DateTimeImmutable($today))->days : 0;
$daysLeft = $inv['status'] === 'open' && !$overdue ? (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($inv['due_date']))->days : null;
$pct = $amount > 0 ? (int) min(100, floor($paid / $amount * 100)) : 0;
$state = $overdue ? 'overdue' : $inv['status'];
$open = !in_array($inv['order_status'], ['closed', 'cancelled'], true);
$canManage = $open && $user->can('finance.manage');
$party = $receivable ? $inv['customer_name'] : $inv['supplier_name'];
$partyLink = $receivable ? '/customers/' . (int) $inv['customer_id'] : '/suppliers/' . (int) $inv['supplier_id'];
$ctx = $inv['context'];
?>
<section class="hero">
    <div class="hero__icon hero__icon--<?= $receivable ? 'in' : 'out' ?>"><?= icon($receivable ? 'file-up' : 'file-down') ?></div>
    <div class="hero__body">
        <div class="hero__line">
            <h1 class="hero__code"><?= e($inv['reference']) ?></h1>
            <?= View::partial('partials/status_badge', ['key' => 'invoice.status.' . $state, 'status' => $state]) ?>
            <span class="chip"><?= e(t('invoice.dir.' . $inv['direction'])) ?></span>
        </div>
        <p class="hero__name"><a href="<?= e(url($partyLink)) ?>"><strong><?= e($party) ?></strong></a><?= $inv['external_number'] ? ' · ' . e(t('invoice.external_number')) . ' <span class="mono">' . e($inv['external_number']) . '</span>' : '' ?></p>
        <p class="hero__tags">
            <a class="tag" href="<?= e(url('/orders/' . (int) $inv['order_id'] . '#finance')) ?>"><?= icon('clipboard-list') ?> <?= e($inv['order_reference']) ?> · <?= e($inv['customer_po_number']) ?></a>
            <?php if ($inv['po_reference']): ?><a class="tag" href="<?= e(url('/purchase-orders/' . (int) $inv['po_id'])) ?>"><?= icon('shopping-cart') ?> <?= e($inv['po_reference']) ?></a><?php endif ?>
            <span class="tag"><?= icon('calendar') ?> <?= e(t('invoice.col.date')) ?> <?= e(fmt_date($inv['invoice_date'])) ?></span>
            <span class="tag"><?= icon('map-pin') ?> <?= e($inv['site_name']) ?></span>
        </p>
    </div>
    <div class="hero__actions">
        <?php if ($canManage && $inv['status'] === 'open'): ?>
            <button type="button" class="btn btn--primary" data-open="#tpl-pay" data-title="<?= e(t($receivable ? 'invoice.collect' : 'invoice.pay') . ' ' . $inv['reference']) ?>"><?= icon('hand-coins') ?> <?= e(t($receivable ? 'invoice.collect' : 'invoice.pay')) ?></button>
            <?php if (!$inv['payments']): ?>
                <button type="button" class="icon-btn icon-btn--danger" data-open="#tpl-icancel" data-title="<?= e(t('invoice.cancel')) ?>" title="<?= e(t('invoice.cancel')) ?>" aria-label="<?= e(t('invoice.cancel')) ?>"><?= icon('ban') ?></button>
            <?php endif ?>
        <?php endif ?>
        <a class="btn" href="<?= e(url('/finance')) ?>"><?= icon('arrow-left') ?> <?= e(t('nav.finance')) ?></a>
    </div>
</section>

<section class="vitals">
    <div class="vital"><?= icon('receipt', 'vital__icon') ?><span class="vital__label"><?= e(t('orders.col.amount')) ?></span><span class="vital__value"><?= e(fmt_money($amount, $cur)) ?></span></div>
    <div class="vital"><?= icon('hand-coins', 'vital__icon vital__icon--good') ?><span class="vital__label"><?= e(t($receivable ? 'invoice.collected' : 'invoice.paid_out')) ?></span><span class="vital__value"><?= e(fmt_money($paid, $cur)) ?></span>
        <span class="meter"><span class="meter__bar" style="width: <?= $pct ?>%"></span></span></div>
    <div class="vital<?= $overdue ? ' vital--alert' : '' ?>"><?= icon('wallet', 'vital__icon vital__icon--' . ($balance > 0 ? ($overdue ? 'bad' : 'warn') : 'good')) ?><span class="vital__label"><?= e(t('invoice.col.balance')) ?></span><span class="vital__value"><?= $inv['status'] === 'cancelled' ? '—' : e(fmt_money($balance, $cur)) ?></span></div>
    <div class="vital<?= $overdue ? ' vital--alert' : '' ?>"><?= icon('calendar-clock', 'vital__icon vital__icon--' . ($overdue ? 'bad' : 'good')) ?><span class="vital__label"><?= e(t('invoice.col.due')) ?></span>
        <span class="vital__value"><?= e(fmt_date($inv['due_date'])) ?>
            <?php if ($overdue): ?><span class="text-bad"> · <?= e(t('invoice.days_late', ['n' => $daysLate])) ?></span><?php elseif ($daysLeft !== null): ?><span class="muted"> · <?= e(t('invoice.days_left', ['n' => $daysLeft])) ?></span><?php endif ?></span></div>
</section>

<div class="grid grid--2-1">
    <div>
        <section class="card" id="payments">
            <div class="card__title">
                <h2><?= icon('list', 'title-icon') ?> <?= e(t('invoice.payments')) ?></h2>
                <span class="muted"><?= count($inv['payments']) ?> · <?= $pct ?> %</span>
            </div>
            <?php if (!$inv['payments']): ?>
                <div class="empty-state"><?= icon('hand-coins') ?><p><?= e(t('invoice.no_payment')) ?></p></div>
            <?php else: ?>
            <div class="table-wrap">
            <table class="table table--compact">
                <thead><tr><th><?= e(t('payment.date')) ?></th><th><?= e(t('payment.method')) ?></th><th><?= e(t('payment.bank_ref')) ?></th><th class="num"><?= e(t('orders.col.amount')) ?></th><th class="num" data-orderable="false"><?= e(t('invoice.balance_after')) ?></th><th><?= e(t('invoice.recorded_by')) ?></th></tr></thead>
                <tbody>
                <?php $running = $amount; foreach ($inv['payments'] as $py): $running -= (float) $py['amount']; ?>
                    <tr>
                        <td><?= e(fmt_date($py['paid_at'])) ?></td>
                        <td><?= e(t('payment.method.' . $py['method'])) ?></td>
                        <td class="mono"><?= e($py['bank_ref'] ?: '—') ?><?= $py['notes'] ? '<br><span class="muted">' . e($py['notes']) . '</span>' : '' ?></td>
                        <td class="num"><strong><?= e(fmt_number($py['amount'])) ?></strong></td>
                        <td class="num"><?= e(fmt_number(max(0, $running))) ?></td>
                        <td><?= e($py['user_name']) ?><br><span class="muted"><?= e(fmt_date($py['created_at'], true)) ?></span></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
                <tfoot><tr><th colspan="3"><?= e(t('common.total')) ?></th><th class="num"><?= e(fmt_money($paid, $cur)) ?></th><th class="num"><?= e(fmt_number($balance)) ?></th><th></th></tr></tfoot>
            </table>
            </div>
            <?php endif ?>
            <?php if ($inv['notes']): ?><p class="prewrap muted mt-2"><?= e($inv['notes']) ?></p><?php endif ?>
        </section>

        <section class="card" id="history">
            <div class="card__title"><h2><?= icon('history', 'title-icon') ?> <?= e(t('invoice.history')) ?></h2></div>
            <ol class="timeline">
                <?php foreach ($inv['history'] as $h): $after = json_decode((string) $h['after_json'], true) ?: []; ?>
                    <li class="timeline__item timeline__item--<?= $h['entity'] === 'payment' ? 'customer_paid' : ($h['action'] === 'cancel' ? 'cancelled' : 'po_sent') ?>">
                        <span class="timeline__date"><?= e(fmt_date($h['created_at'], true)) ?> · <?= e($h['user_name'] ?? '—') ?></span>
                        <span><strong><?= e(t('invoice.history.' . $h['entity'] . '_' . $h['action'])) ?></strong>
                            <?php if ($h['entity'] === 'payment' && isset($after['amount'])): ?> — <?= e(fmt_money($after['amount'], $cur)) ?><?php endif ?>
                            <?php if (!empty($after['note'])): ?> — <?= e($after['note']) ?><?php endif ?></span>
                    </li>
                <?php endforeach ?>
            </ol>
        </section>
    </div>

    <div>
        <section class="card">
            <div class="card__title"><h2><?= icon('id-card', 'title-icon') ?> <?= e(t('invoice.col.party')) ?></h2></div>
            <dl class="dl">
                <dt><?= e(t('parties.col.name')) ?></dt><dd><a href="<?= e(url($partyLink)) ?>"><?= e($party) ?></a></dd>
                <dt><?= e(t('parties.col.code')) ?></dt><dd class="mono"><?= e($receivable ? $inv['customer_code'] : $inv['supplier_code']) ?></dd>
                <?php if ($phone = $receivable ? $inv['customer_phone'] : $inv['supplier_phone']): ?><dt><?= e(t('parties.phone')) ?></dt><dd><?= e($phone) ?></dd><?php endif ?>
                <?php if ($mail = $receivable ? $inv['customer_email'] : $inv['supplier_email']): ?><dt><?= e(t('auth.email')) ?></dt><dd><?= e($mail) ?></dd><?php endif ?>
            </dl>
        </section>

        <section class="card">
            <div class="card__title"><h2><?= icon('scale', 'title-icon') ?> <?= e(t('invoice.context')) ?></h2></div>
            <dl class="dl">
                <dt><?= e(t($receivable ? 'invoice.ctx.ordered' : 'invoice.ctx.po_amount')) ?></dt><dd><?= e(fmt_money($ctx['ordered_value'], $receivable ? $inv['order_currency'] : $cur)) ?></dd>
                <?php if ($receivable): ?><dt><?= e(t('invoice.ctx.delivered')) ?></dt><dd><?= e(fmt_money($ctx['delivered_value'], $inv['order_currency'])) ?></dd><?php endif ?>
                <dt><?= e(t('invoice.ctx.invoiced')) ?></dt><dd><?= e(fmt_money($ctx['invoiced_value'], $cur)) ?></dd>
                <?php if ($receivable): $left = (float) $ctx['delivered_value'] - (float) $ctx['invoiced_value']; ?>
                    <dt><?= e(t('invoice.ctx.to_invoice')) ?></dt><dd class="<?= $left > 0.01 ? 'text-warn' : '' ?>"><?= e(fmt_money(max(0, $left), $inv['order_currency'])) ?></dd>
                <?php endif ?>
                <dt><?= e(t('invoice.ctx.created')) ?></dt><dd><?= e($inv['created_by_name']) ?> · <?= e(fmt_date($inv['created_at'], true)) ?></dd>
            </dl>
        </section>

        <section class="card" id="documents">
            <div class="card__title"><h2><?= icon('paperclip', 'title-icon') ?> <?= e(t('orders.section.documents')) ?></h2></div>
            <?php if ($inv['attachments']): ?>
                <ul class="file-list">
                    <?php foreach ($inv['attachments'] as $a): ?>
                        <li><a<?= preview_attrs(url('/attachments/' . (int) $a['id']), $a['label'] ?: $a['original_name'], $a['mime']) ?>><?= icon($a['mime'] === 'application/pdf' ? 'file-text' : 'image') ?> <?= e($a['label'] ?: $a['original_name']) ?></a>
                            <span class="muted"><?= e(fmt_date($a['created_at'])) ?> · <?= e($a['user_name']) ?></span></li>
                    <?php endforeach ?>
                </ul>
            <?php else: ?><p class="empty"><?= e(t('attachment.none')) ?></p><?php endif ?>
            <?php if ($canManage): ?>
            <form method="post" action="<?= e(url("/invoices/$id/attachments")) ?>" enctype="multipart/form-data" class="form upload-form">
                <?= csrf_field() ?>
                <div class="field"><label for="inv-label"><?= e(t('attachment.label')) ?></label><input id="inv-label" name="label" maxlength="160" placeholder="<?= e(t('invoice.attach_placeholder')) ?>"></div>
                <div class="field"><label for="inv-file" class="required"><?= e(t('attachment.file')) ?></label><input id="inv-file" name="file" type="file" required accept="application/pdf,image/jpeg,image/png,image/webp"><p class="hint"><?= e(t('attachment.hint')) ?></p></div>
                <button type="submit" class="btn btn--sm" data-once><?= icon('upload') ?> <?= e(t('attachment.upload')) ?></button>
            </form>
            <?php endif ?>
        </section>
    </div>
</div>

<?php if ($canManage && $inv['status'] === 'open'): ?>
<template id="tpl-pay"><?= View::partial('finance/_payment_form', ['inv' => $inv, 'balance' => $balance, 'back' => 'invoice']) ?></template>
<template id="tpl-icancel">
    <form method="post" action="<?= e(url("/invoices/$id/cancel")) ?>" class="form modal-form" data-confirm-variant="danger">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="invoice">
        <div class="field"><label class="required"><?= e(t('common.reason')) ?></label><textarea name="note" rows="2" required maxlength="300"></textarea></div>
    </form>
</template>
<?php endif ?>
