<?php /** @var array $inv @var float $balance @var string $back */ ?>
<form method="post" action="<?= e(url('/invoices/' . (int) $inv['id'] . '/payments')) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <p class="muted"><?= e(t('invoice.balance_due', ['amount' => fmt_money($balance, $inv['currency'])])) ?></p>
    <div class="form-grid form-grid--modal">
        <div class="field"><label class="required"><?= e(t('payment.date')) ?></label><input name="paid_at" type="date" required value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
        <div class="field"><label class="required"><?= e(t('orders.col.amount')) ?> (<?= e($inv['currency']) ?>)</label><input name="amount" type="number" step="0.01" min="0.01" max="<?= e(number_format($balance, 2, '.', '')) ?>" required value="<?= e(number_format($balance, 2, '.', '')) ?>" inputmode="decimal"></div>
        <div class="field"><label class="required"><?= e(t('payment.method')) ?></label>
            <select name="method" required>
                <?php foreach (\App\Controllers\InvoiceController::METHODS as $m): ?><option value="<?= e($m) ?>"><?= e(t('payment.method.' . $m)) ?></option><?php endforeach ?>
            </select></div>
        <div class="field"><label><?= e(t('payment.bank_ref')) ?></label><input name="bank_ref" maxlength="120"></div>
    </div>
    <div class="field"><label><?= e(t('movements.note')) ?></label><textarea name="notes" rows="2" maxlength="500"></textarea></div>
</form>
