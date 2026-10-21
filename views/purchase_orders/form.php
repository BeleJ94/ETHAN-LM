<?php
/**
 * @var array $order @var array|null $po @var array|null $quote @var array $orderLines @var array $existing
 * @var array $suppliers @var array $currencies @var array $deliverySites @var array $siteAddresses
 */
use App\Core\Session;
use App\Core\View;

$old = Session::getFlash('_old', []);
$oldLines = (array) ($old['lines'] ?? []);
$supplierId = old('supplier_id', $po['supplier_id'] ?? ($quote['supplier_id'] ?? ''));
$currency = old('currency', $po['currency'] ?? ($quote['currency'] ?? $order['currency']));
$siteId = old('delivery_site_id', $po['delivery_site_id'] ?? ($order['delivery_site_id'] ?? ''));
$defaultAddress = $siteId !== '' && isset($siteAddresses[(string) $siteId]) ? $siteAddresses[(string) $siteId] : ['address' => '', 'contact' => ''];
$expected = $quote && $quote['lead_time_days'] !== null ? date('Y-m-d', strtotime('+' . (int) $quote['lead_time_days'] . ' days')) : '';
$action = $po ? '/purchase-orders/' . (int) $po['id'] : '/orders/' . (int) $order['id'] . '/purchase-orders';

echo View::partial('partials/page_header', [
    'icon' => 'shopping-cart',
    'heading' => $title,
    'meta' => t('po.form_meta', ['order' => $order['reference'], 'customer' => $order['customer_name']]),
]);
?>
<?php if ($quote): ?>
    <div class="alert alert--info"><?= icon('info') ?> <span><?= e(t('po.from_quote', ['supplier' => $quote['supplier_name'], 'ref' => $quote['quote_ref'] ?: '—'])) ?></span></div>
<?php endif ?>
<form method="post" action="<?= e(url($action)) ?>" class="card form form--sections">
    <?= csrf_field() ?>
    <?php if ($quote): ?><input type="hidden" name="quote_id" value="<?= (int) $quote['id'] ?>"><?php endif ?>

    <fieldset class="form-section">
        <legend><?= e(t('po.section.supplier')) ?></legend>
        <div class="form-grid">
            <?= View::partial('partials/ref_field', ['type' => 'po', 'name' => 'reference', 'label' => t('reference.po'), 'current' => $po['reference'] ?? null]) ?>
            <div class="field" aria-hidden="true"></div>
            <div class="field">
                <label for="supplier_id" class="required"><?= e(t('sourcing.col.supplier')) ?></label>
                <select id="supplier_id" name="supplier_id" required<?= error_attr('supplier_id') ?>>
                    <option value=""><?= e(t('common.choose')) ?></option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" data-currency="<?= e($s['currency']) ?>" data-terms="<?= (int) $s['payment_terms_days'] ?>" <?= (string) $supplierId === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <?= error_msg('supplier_id') ?>
            </div>
            <div class="field">
                <label for="currency" class="required"><?= e(t('movements.currency')) ?></label>
                <select id="currency" name="currency" required data-fill-from="supplier_id:currency">
                    <?php foreach ($currencies as $c): ?><option value="<?= e($c['code']) ?>" <?= $currency === $c['code'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option><?php endforeach ?>
                </select>
            </div>
            <div class="field">
                <label for="order_date" class="required"><?= e(t('orders.col.date')) ?></label>
                <input id="order_date" name="order_date" type="date" required value="<?= e(old('order_date', $po['order_date'] ?? date('Y-m-d'))) ?>"<?= error_attr('order_date') ?>>
            </div>
            <div class="field">
                <label for="expected_date"><?= e(t('po.expected_date')) ?></label>
                <input id="expected_date" name="expected_date" type="date" value="<?= e(old('expected_date', $po['expected_date'] ?? $expected)) ?>">
            </div>
            <div class="field">
                <label for="payment_terms_days" class="required"><?= e(t('parties.terms')) ?></label>
                <input id="payment_terms_days" name="payment_terms_days" type="number" min="0" step="1" required data-fill-from="supplier_id:terms" value="<?= e(old('payment_terms_days', $po['payment_terms_days'] ?? '30')) ?>">
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><?= e(t('po.section.delivery')) ?></legend>
        <div class="form-grid">
            <div class="field span-2">
                <label for="delivery_site_id"><?= e(t('orders.col.delivery_site')) ?> — <?= e($order['customer_name']) ?></label>
                <select id="delivery_site_id" name="delivery_site_id" data-address-source<?= error_attr('delivery_site_id') ?>>
                    <option value=""><?= e(t('po.other_address')) ?></option>
                    <?php foreach ($deliverySites as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (string) $siteId === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name'] . ($s['city'] ? ' — ' . $s['city'] : '')) ?></option>
                    <?php endforeach ?>
                </select>
                <?php if (!$deliverySites): ?><p class="hint"><a href="<?= e(url('/customers/' . (int) $order['customer_id'] . '#sites')) ?>"><?= e(t('po.add_site')) ?></a></p><?php endif ?>
                <?= error_msg('delivery_site_id') ?>
                <?= json_script('site-addresses', $siteAddresses) ?>
            </div>
            <div class="field">
                <label for="delivery_address" class="required"><?= e(t('po.delivery_address')) ?></label>
                <textarea id="delivery_address" name="delivery_address" rows="4" maxlength="600" required data-address-target<?= error_attr('delivery_address') ?>><?= e(old('delivery_address', $po['delivery_address'] ?? $defaultAddress['address'])) ?></textarea>
                <p class="hint"><?= e(t('po.delivery_address_hint')) ?></p>
                <?= error_msg('delivery_address') ?>
            </div>
            <div class="field">
                <label for="transport_mode" class="required"><?= e(t('transport.mode')) ?></label>
                <select id="transport_mode" name="transport_mode" required>
                    <?php $cur = old('transport_mode', $po['transport_mode'] ?? 'road'); foreach (\App\Domain\Checklist::TRANSPORTS as $tm): ?>
                        <option value="<?= e($tm) ?>" <?= $cur === $tm ? 'selected' : '' ?>><?= e(t('transport.' . $tm)) ?></option>
                    <?php endforeach ?>
                </select>
                <p class="hint"><?= e(t('transport.hint')) ?></p>
            </div>
            <div class="field">
                <label for="forwarder_id"><?= e(t('delivery.forwarder')) ?></label>
                <select id="forwarder_id" name="forwarder_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php $cur = old('forwarder_id', $po['forwarder_id'] ?? ''); foreach ($forwarders as $fw): ?>
                        <option value="<?= (int) $fw['id'] ?>" <?= (string) $cur === (string) $fw['id'] ? 'selected' : '' ?>><?= e($fw['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <p class="hint"><?= e(t('delivery.forwarder_hint')) ?></p>
            </div>
            <div class="field">
                <label for="delivery_contact"><?= e(t('po.delivery_contact')) ?></label>
                <input id="delivery_contact" name="delivery_contact" maxlength="200" data-contact-target value="<?= e(old('delivery_contact', $po['delivery_contact'] ?? $defaultAddress['contact'])) ?>">
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><?= e(t('orders.section.lines')) ?></legend>
        <?= error_msg('lines') ?>
        <div class="table-wrap">
        <table class="table lines" data-lines>
            <thead><tr>
                <th><?= e(t('orders.col.product')) ?></th>
                <th class="num"><?= e(t('po.col.to_order')) ?></th>
                <th class="lines__qty"><?= e(t('movements.col.quantity')) ?></th>
                <th class="lines__price"><?= e(t('po.col.purchase_price')) ?></th>
                <th class="num"><?= e(t('sourcing.sale_price')) ?></th>
                <th class="num"><?= e(t('orders.col.total')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($orderLines as $l):
                $lid = (int) $l['id'];
                $mine = $existing[$lid] ?? null;
                $remaining = (float) $l['quantity'] - (float) $l['purchased'] + ($mine ? (float) $mine['quantity'] : 0);
                $quoted = $quote['quantities'][$lid] ?? null;
                $default = $remaining > 0 ? ($quoted !== null ? min($remaining, $quoted) : $remaining) : '';
                $qty = $oldLines[$lid]['quantity'] ?? ($mine['quantity'] ?? $default);
                $price = $oldLines[$lid]['unit_price'] ?? ($mine['unit_price'] ?? ($quote['prices'][$lid] ?? '')); ?>
                <tr>
                    <td><strong><?= e($l['sku']) ?></strong><br><span class="muted"><?= e($l['item_name']) ?><?= $l['specification'] ? ' · ' . e($l['specification']) : '' ?></span></td>
                    <td class="num"><?= e(fmt_qty(max(0, $remaining))) ?> <?= e($l['unit']) ?></td>
                    <td><input name="lines[<?= $lid ?>][quantity]" type="number" step="0.001" min="0" max="<?= e((string) max(0, $remaining)) ?>" inputmode="decimal" value="<?= e(is_numeric($qty) ? (string) (float) $qty : '') ?>" data-line-qty <?= $remaining <= 0 ? 'readonly' : '' ?>></td>
                    <td><input name="lines[<?= $lid ?>][unit_price]" type="number" step="0.000001" min="0" inputmode="decimal" value="<?= e(is_numeric($price) ? (string) (float) $price : '') ?>" data-line-price></td>
                    <td class="num muted"><?= e(fmt_number($l['unit_price'])) ?> <?= e($order['currency']) ?></td>
                    <td class="num" data-line-total>—</td>
                </tr>
            <?php endforeach ?>
            </tbody>
            <tfoot><tr><th colspan="5"><?= e(t('common.total')) ?></th><th class="num" data-lines-total>—</th></tr></tfoot>
        </table>
        </div>
        <p class="hint"><?= e(t('po.lines_hint')) ?></p>
    </fieldset>

    <div class="field">
        <label for="notes"><?= e(t('po.notes')) ?></label>
        <textarea id="notes" name="notes" rows="3" data-autogrow placeholder="<?= e(t('po.notes_placeholder')) ?>"><?= e(old('notes', $po['notes'] ?? '')) ?></textarea>
    </div>

    <div class="form-actions">
        <a class="link-btn" href="<?= e(url($po ? '/purchase-orders/' . (int) $po['id'] : '/orders/' . (int) $order['id'] . '#sourcing')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary" data-once><?= icon('save') ?> <?= e(t('po.save_draft')) ?></button>
    </div>
</form>
