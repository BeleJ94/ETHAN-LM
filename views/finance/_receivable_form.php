<?php
/**
 * Facture client d'une expédition livrée (une facture par expédition), en lignes :
 * lignes reprises de l'expédition (quantité réceptionnée × prix de vente, modifiables) + lignes libres (frais…).
 * Sert aussi à modifier une facture en attente d'approbation ($invoice).
 * @var int $orderId @var array $delivery expédition livrée (avec lines) @var string $currency @var int $terms @var string $back
 * @var ?array $invoice facture à modifier (avec lines), null = nouvelle facture
 */
use App\Core\View;

$invoice ??= null;
$row = function (string|int $i, array $l): string {
    $fromDelivery = isset($l['delivery_line_id']);
    return '<tr class="lines__row">'
        . '<td>' . ($fromDelivery ? '<input type="hidden" name="lines[' . $i . '][delivery_line_id]" value="' . (int) $l['delivery_line_id'] . '">' : '')
        . '<input name="lines[' . $i . '][description]" maxlength="255" required value="' . e($l['description'] ?? '') . '" placeholder="' . e(t('invoice.line.free_placeholder')) . '">'
        . ($fromDelivery ? '<span class="hint">' . e(t('invoice.line.received', ['qty' => fmt_qty($l['max']), 'unit' => $l['unit']])) . '</span>' : '') . '</td>'
        . '<td><input name="lines[' . $i . '][quantity]" type="number" step="0.001" min="0.001"' . ($fromDelivery ? ' max="' . e((string) (float) $l['max']) . '"' : '')
        . ' inputmode="decimal" required value="' . e($l['quantity'] ?? '') . '" data-line-qty></td>'
        . '<td><input name="lines[' . $i . '][unit_price]" type="number" step="0.000001" min="0" inputmode="decimal" required value="' . e($l['unit_price'] ?? '') . '" data-line-price></td>'
        . '<td class="num" data-line-total>—</td>'
        . '<td><button type="button" class="icon-btn icon-btn--danger" data-line-remove title="' . e(t('common.remove')) . '" aria-label="' . e(t('common.remove')) . '">' . icon('trash-2') . '</button></td>'
        . '</tr>';
};
$received = [];
foreach ($delivery['lines'] as $l) {
    $received[(int) $l['id']] = $l;
}
$lines = [];
if ($invoice !== null) {
    // Modification : lignes enregistrées (plafond = quantité réceptionnée de la ligne d'expédition)
    foreach ($invoice['lines'] as $l) {
        $src = $l['delivery_line_id'] !== null ? ($received[(int) $l['delivery_line_id']] ?? null) : null;
        $lines[] = ['description' => $l['description'], 'quantity' => (string) (float) $l['quantity'], 'unit_price' => (string) (float) $l['unit_price']]
            + ($src ? ['delivery_line_id' => (int) $src['id'], 'max' => (float) $src['quantity_received'], 'unit' => $src['unit']] : []);
    }
} else {
    foreach ($delivery['lines'] as $l) {
        if ((float) $l['quantity_received'] <= 0) {
            continue;
        }
        $lines[] = [
            'delivery_line_id' => (int) $l['id'],
            'description' => $l['sku'] . ' — ' . $l['item_name'] . ($l['specification'] ? ' · ' . $l['specification'] : ''),
            'quantity' => (string) (float) $l['quantity_received'], 'max' => (float) $l['quantity_received'], 'unit' => $l['unit'],
            'unit_price' => (string) (float) $l['sale_price'],
        ];
    }
}
$hasVat = $invoice !== null && $invoice['vat_rate'] !== null;
$vatRate = $hasVat ? (string) (float) $invoice['vat_rate'] : (string) (\App\Services\CompanySettings::get()['vat_rate'] ?: '16');
$action = $invoice !== null ? '/invoices/' . (int) $invoice['id'] : "/orders/$orderId/invoices";
?>
<form method="post" action="<?= e(url($action)) ?>" class="form modal-form" data-modal-wide>
    <?= csrf_field() ?>
    <input type="hidden" name="direction" value="receivable">
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <input type="hidden" name="delivery_id" value="<?= (int) $delivery['id'] ?>">
    <p class="muted"><?= icon('truck') ?> <strong><?= e($delivery['reference']) ?></strong>
        · <?= e(t('delivery.delivered_at')) ?> <?= e(fmt_date($delivery['delivered_at'])) ?>
        <?= $delivery['received_by'] ?? null ? ' · ' . e($delivery['received_by']) : '' ?></p>
    <div class="form-grid form-grid--modal">
        <?php if ($invoice !== null): ?>
            <div class="field field--span"><label><?= e(t('reference.invoice')) ?></label><input name="reference" value="<?= e($invoice['reference']) ?>" readonly></div>
        <?php else: ?>
            <div class="field field--span"><?= View::partial('partials/ref_field', ['type' => 'invoice_receivable', 'name' => 'reference', 'label' => t('reference.invoice')]) ?></div>
        <?php endif ?>
        <div class="field"><label class="required"><?= e(t('invoice.col.date')) ?></label><input name="invoice_date" type="date" required value="<?= e($invoice['invoice_date'] ?? date('Y-m-d')) ?>"></div>
        <div class="field"><label><?= e(t('invoice.col.due')) ?></label><input name="due_date" type="date" value="<?= e($invoice['due_date'] ?? '') ?>"><p class="hint"><?= e(t('invoice.due_auto', ['n' => $terms])) ?></p></div>
    </div>
    <div class="table-wrap">
    <table class="table lines" data-lines>
        <thead><tr>
            <th><?= e(t('invoice.line.description')) ?></th>
            <th class="lines__qty"><?= e(t('movements.col.quantity')) ?></th>
            <th class="lines__price"><?= e(t('orders.col.unit_price')) ?> (<?= e($currency) ?>)</th>
            <th class="num"><?= e(t('orders.col.amount')) ?></th>
            <th></th>
        </tr></thead>
        <tbody data-lines-body>
            <?php foreach ($lines as $i => $l): ?><?= $row($i, $l) ?><?php endforeach ?>
            <?php if (!$lines): ?><?= $row(0, []) ?><?php endif ?>
        </tbody>
        <tfoot>
            <tr><th colspan="3"><?= e(t('invoice.total_ht')) ?> (<?= e($currency) ?>)</th><th class="num" data-lines-total>—</th><th></th></tr>
            <tr data-vat-row hidden><th colspan="3"><?= e(t('invoice.vat')) ?> <span data-vat-label></span></th><th class="num" data-lines-vat>—</th><th></th></tr>
            <tr><th colspan="3"><?= e(t('invoice.total_ttc')) ?> (<?= e($currency) ?>)</th><th class="num" data-lines-ttc>—</th><th></th></tr>
        </tfoot>
    </table>
    </div>
    <div class="vat-field">
        <label class="checkbox"><input type="checkbox" name="vat_enabled" value="1" data-vat-toggle<?= $hasVat ? ' checked' : '' ?>> <?= e(t('invoice.vat_apply')) ?></label>
        <label class="vat-field__rate"><?= e(t('invoice.vat_rate')) ?>
            <input name="vat_rate" type="number" step="0.01" min="0.01" max="100" inputmode="decimal" value="<?= e($vatRate) ?>" data-vat-rate<?= $hasVat ? ' required' : ' disabled' ?>> %</label>
    </div>
    <template data-line-template><?= $row('__i__', []) ?></template>
    <div class="page-actions">
        <button type="button" class="btn btn--sm" data-line-add><?= icon('plus') ?> <?= e(t('invoice.line.add')) ?></button>
        <button type="button" class="btn btn--sm" data-form-preview="<?= e(url("/orders/$orderId/invoices/preview")) ?>"><?= icon('eye') ?> <?= e(t('invoice.preview')) ?></button>
    </div>
    <p class="hint"><?= e(t($invoice !== null ? 'invoice.edit_hint' : 'invoice.receivable_hint_lines')) ?></p>
    <div class="field"><label><?= e(t('movements.note')) ?></label><textarea name="notes" rows="2" maxlength="500"><?= e($invoice['notes'] ?? '') ?></textarea></div>
</form>
