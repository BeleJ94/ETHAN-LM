<?php
/**
 * @var array|null $order @var array $lines @var array $customers @var array $deliverySites
 * @var array $sites @var array $currencies @var array $items
 */
use App\Core\Session;
use App\Core\View;

$o = $order;
$id = $o ? (int) $o['id'] : null;
$old = Session::getFlash('_old', []);
$rows = isset($old['lines']) ? array_values((array) $old['lines']) : $lines;
if (!$rows) {
    $rows = [[]];
}
$customerId = old('customer_id', $o['customer_id'] ?? ($_GET['customer_id'] ?? ''));
echo View::partial('partials/page_header', ['icon' => 'clipboard-list', 'heading' => $title, 'meta' => t('orders.form_meta')]);

$itemOptions = function ($selected) use ($items): string {
    $html = '<option value="">' . e(t('common.choose')) . '</option>';
    $group = null;
    foreach ($items as $it) {
        if ($it['family_name'] !== $group) {
            $html .= ($group !== null ? '</optgroup>' : '') . '<optgroup label="' . e($it['family_name']) . '">';
            $group = $it['family_name'];
        }
        $html .= '<option value="' . (int) $it['id'] . '" data-unit="' . e($it['unit']) . '"' . ((string) $selected === (string) $it['id'] ? ' selected' : '') . '>'
            . e($it['sku'] . ' — ' . $it['name']) . '</option>';
    }
    return $html . ($group !== null ? '</optgroup>' : '');
};
$lineRow = function (int|string $i, array $r) use ($itemOptions): string {
    return '<tr class="lines__row">'
        . '<td><select name="lines[' . $i . '][item_id]" required>' . $itemOptions($r['item_id'] ?? '') . '</select>'
        . '<input name="lines[' . $i . '][specification]" maxlength="255" class="mt-2" placeholder="' . e(t('orders.spec_placeholder')) . '" value="' . e($r['specification'] ?? '') . '"></td>'
        . '<td><input name="lines[' . $i . '][quantity]" type="number" step="0.001" min="0.001" inputmode="decimal" required value="' . e($r['quantity'] ?? '') . '" data-line-qty></td>'
        . '<td><input name="lines[' . $i . '][unit_price]" type="number" step="0.000001" min="0" inputmode="decimal" required value="' . e($r['unit_price'] ?? '') . '" data-line-price></td>'
        . '<td class="num" data-line-total>—</td>'
        . '<td><button type="button" class="icon-btn icon-btn--danger" data-line-remove title="' . e(t('common.remove')) . '" aria-label="' . e(t('common.remove')) . '">' . icon('trash-2') . '</button></td>'
        . '</tr>';
};
?>
<form method="post" action="<?= e(url($id ? "/orders/$id" : '/orders')) ?>" class="card form form--sections">
    <?= csrf_field() ?>
    <fieldset class="form-section">
        <legend><?= e(t('orders.section.customer')) ?></legend>
        <div class="form-grid">
            <?= View::partial('partials/ref_field', ['type' => 'order', 'name' => 'reference', 'label' => t('reference.internal'), 'current' => $o['reference'] ?? null]) ?>
            <div class="field" aria-hidden="true"></div>
            <div class="field">
                <label for="customer_id" class="required"><?= e(t('orders.col.customer')) ?></label>
                <select id="customer_id" name="customer_id" required<?= error_attr('customer_id') ?>>
                    <option value=""><?= e(t('common.choose')) ?></option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" data-currency="<?= e($c['currency']) ?>" data-terms="<?= (int) $c['payment_terms_days'] ?>" <?= (string) $customerId === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <?php if (!$customers): ?><p class="hint"><a href="<?= e(url('/customers/new')) ?>"><?= e(t('orders.create_customer_first')) ?></a></p><?php endif ?>
                <?= error_msg('customer_id') ?>
            </div>
            <div class="field">
                <label for="customer_po_number" class="required"><?= e(t('orders.col.customer_po')) ?></label>
                <input id="customer_po_number" name="customer_po_number" maxlength="80" required value="<?= e(old('customer_po_number', $o['customer_po_number'] ?? '')) ?>"<?= error_attr('customer_po_number') ?>>
                <p class="hint"><?= e(t('orders.customer_po_hint')) ?></p>
                <?= error_msg('customer_po_number') ?>
            </div>
            <div class="field">
                <label for="po_date" class="required"><?= e(t('orders.col.po_date')) ?></label>
                <input id="po_date" name="po_date" type="date" required value="<?= e(old('po_date', $o['po_date'] ?? date('Y-m-d'))) ?>"<?= error_attr('po_date') ?>>
                <?= error_msg('po_date') ?>
            </div>
            <div class="field">
                <label for="required_date"><?= e(t('orders.col.required_date')) ?></label>
                <input id="required_date" name="required_date" type="date" value="<?= e(old('required_date', $o['required_date'] ?? '')) ?>"<?= error_attr('required_date') ?>>
                <?= error_msg('required_date') ?>
            </div>
            <div class="field span-2">
                <label for="delivery_site_id"><?= e(t('orders.col.delivery_site')) ?></label>
                <select id="delivery_site_id" name="delivery_site_id" data-depends-on="#customer_id"<?= error_attr('delivery_site_id') ?>>
                    <option value=""><?= e(t('orders.delivery_site_later')) ?></option>
                    <?php $cur = old('delivery_site_id', $o['delivery_site_id'] ?? ''); foreach ($deliverySites as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" data-parent="<?= (int) $s['customer_id'] ?>" <?= (string) $cur === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name'] . ($s['city'] ? ' — ' . $s['city'] : '')) ?></option>
                    <?php endforeach ?>
                </select>
                <p class="hint"><?= e(t('orders.delivery_site_hint')) ?></p>
                <?= error_msg('delivery_site_id') ?>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><?= e(t('orders.section.terms')) ?></legend>
        <div class="form-grid">
            <div class="field">
                <span class="label required"><?= e(t('orders.mode')) ?></span>
                <div class="segmented segmented--radio" role="radiogroup">
                    <?php $mode = old('fulfillment_mode', $o['fulfillment_mode'] ?? 'local_purchase');
                    foreach (['local_purchase' => 'store', 'import' => 'ship', 'stock' => 'warehouse'] as $m => $ic): ?>
                        <label class="segmented__item<?= $m === 'stock' ? ' is-disabled' : '' ?>" title="<?= $m === 'stock' ? e(t('nav.coming_soon')) : '' ?>">
                            <input type="radio" name="fulfillment_mode" value="<?= e($m) ?>" <?= $mode === $m ? 'checked' : '' ?> <?= $m === 'stock' ? 'disabled' : '' ?>>
                            <?= icon($ic) ?> <?= e(t('order.mode.' . $m)) ?>
                        </label>
                    <?php endforeach ?>
                </div>
            </div>
            <div class="field">
                <label for="site_id" class="required"><?= e(t('orders.col.site')) ?></label>
                <select id="site_id" name="site_id" required<?= error_attr('site_id') ?>>
                    <?php $cur = old('site_id', $o['site_id'] ?? (\App\Core\Session::get('working_site_id') ?? '')); foreach ($sites as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (string) $cur === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <p class="hint"><?= e(t('orders.site_hint')) ?></p>
                <?= error_msg('site_id') ?>
            </div>
            <div class="field">
                <label for="currency" class="required"><?= e(t('movements.currency')) ?></label>
                <select id="currency" name="currency" required data-fill-from="customer_id:currency">
                    <?php $cur = old('currency', $o['currency'] ?? base_currency()); foreach ($currencies as $c): ?>
                        <option value="<?= e($c['code']) ?>" <?= $cur === $c['code'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="field">
                <label for="payment_terms_days" class="required"><?= e(t('parties.terms')) ?></label>
                <input id="payment_terms_days" name="payment_terms_days" type="number" min="0" step="1" required value="<?= e(old('payment_terms_days', $o['payment_terms_days'] ?? '30')) ?>" data-fill-from="customer_id:terms"<?= error_attr('payment_terms_days') ?>>
                <?= error_msg('payment_terms_days') ?>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><?= e(t('orders.section.lines')) ?></legend>
        <?php if (!$items): ?><div class="alert alert--info"><?= icon('info') ?> <span><a href="<?= e(url('/items/new')) ?>"><?= e(t('orders.create_item_first')) ?></a></span></div><?php endif ?>
        <?= error_msg('lines') ?>
        <div class="table-wrap">
        <table class="table lines" data-lines>
            <thead><tr>
                <th><?= e(t('orders.col.product')) ?></th>
                <th class="lines__qty"><?= e(t('movements.col.quantity')) ?></th>
                <th class="lines__price"><?= e(t('orders.col.unit_price')) ?></th>
                <th class="num"><?= e(t('orders.col.total')) ?></th>
                <th></th>
            </tr></thead>
            <tbody data-lines-body>
                <?php foreach ($rows as $i => $r): ?><?= $lineRow($i, (array) $r) ?><?php endforeach ?>
            </tbody>
            <tfoot><tr><th colspan="3"><?= e(t('common.total')) ?></th><th class="num" data-lines-total>—</th><th></th></tr></tfoot>
        </table>
        </div>
        <template data-line-template><?= $lineRow('__i__', []) ?></template>
        <button type="button" class="btn btn--sm mt-2" data-line-add><?= icon('plus') ?> <?= e(t('orders.add_line')) ?></button>
    </fieldset>

    <div class="field">
        <label for="notes"><?= e(t('movements.note')) ?></label>
        <textarea id="notes" name="notes" rows="2" data-autogrow><?= e(old('notes', $o['notes'] ?? '')) ?></textarea>
    </div>

    <div class="form-actions">
        <a class="link-btn" href="<?= e(url($id ? "/orders/$id" : '/orders')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary" data-once><?= icon('save') ?> <?= e(t('common.save')) ?></button>
    </div>
</form>
