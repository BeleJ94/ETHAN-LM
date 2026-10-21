<?php
/** @var array $po @var array $lines @var array $company — bon de commande fournisseur (A4). */
$total = array_sum(array_map(fn ($l) => (float) $l['quantity'] * (float) $l['unit_price'], $lines));
$legal = array_filter(['RCCM ' . $company['rccm'] => $company['rccm'], 'ID NAT ' . $company['id_nat'] => $company['id_nat'], 'NIF ' . $company['nif'] => $company['nif']]);
?>
<article class="doc">
    <header class="doc__head">
        <div class="doc__company">
            <strong><?= e($company['name']) ?></strong>
            <?php if ($company['address'] || $company['city']): ?><p><?= e(trim($company['address'] . ' ' . $company['city'])) ?></p><?php endif ?>
            <?php if ($company['phone'] || $company['email']): ?><p><?= e(trim($company['phone'] . ' · ' . $company['email'], ' ·')) ?></p><?php endif ?>
            <?php if ($legal): ?><p><?= e(implode(' · ', array_keys($legal))) ?></p><?php endif ?>
        </div>
        <div class="doc__title">
            <h1><?= e(t('print.po_title')) ?></h1>
            <p class="ref"><?= e($po['reference']) ?></p>
            <p><?= e(t('orders.col.date')) ?> : <?= e(fmt_date($po['order_date'])) ?></p>
            <?php if ($po['status'] === 'draft'): ?><p><strong><?= e(t('po.status.draft')) ?></strong></p><?php endif ?>
        </div>
    </header>

    <div class="boxes">
        <div class="box">
            <h2><?= e(t('print.supplier')) ?></h2>
            <p><strong><?= e($po['supplier_name']) ?></strong></p>
            <p><?= e(trim(($po['supplier_address'] ?? '') . ' ' . ($po['supplier_city'] ?? ''))) ?></p>
            <?php if ($po['supplier_contact'] || $po['supplier_phone']): ?><p><?= e(trim(($po['supplier_contact'] ?? '') . ' · ' . ($po['supplier_phone'] ?? ''), ' ·')) ?></p><?php endif ?>
            <?php if ($po['supplier_email']): ?><p><?= e($po['supplier_email']) ?></p><?php endif ?>
            <?php if ($po['supplier_nif']): ?><p>NIF <?= e($po['supplier_nif']) ?></p><?php endif ?>
        </div>
        <div class="box box--accent">
            <h2><?= e(t('print.deliver_to')) ?></h2>
            <p><strong><?= e($po['customer_name']) ?></strong></p>
            <p><?= e($po['delivery_address']) ?></p>
            <?php if ($po['delivery_contact']): ?><p><?= e(t('po.delivery_contact')) ?> : <?= e($po['delivery_contact']) ?></p><?php endif ?>
        </div>
    </div>

    <div class="meta">
        <div><span><?= e(t('print.our_ref')) ?></span><?= e($po['order_reference']) ?></div>
        <div><span><?= e(t('po.expected_date')) ?></span><?= e(fmt_date($po['expected_date'])) ?></div>
        <div><span><?= e(t('parties.terms')) ?></span><?= e(t('parties.terms_short', ['n' => (int) $po['payment_terms_days']])) ?></div>
        <div><span><?= e(t('movements.currency')) ?></span><?= e($po['currency']) ?></div>
    </div>

    <table>
        <thead><tr><th>#</th><th><?= e(t('orders.col.product')) ?></th><th class="num"><?= e(t('movements.col.quantity')) ?></th><th class="num"><?= e(t('orders.col.unit_price')) ?></th><th class="num"><?= e(t('orders.col.total')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($lines as $i => $l): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= e($l['sku']) ?></strong> — <?= e($l['item_name']) ?><?php if ($l['specification']): ?><br><span class="muted"><?= e($l['specification']) ?></span><?php endif ?></td>
                <td class="num"><?= e(fmt_qty($l['quantity'])) ?> <?= e($l['unit']) ?></td>
                <td class="num"><?= e(fmt_number($l['unit_price'])) ?></td>
                <td class="num"><?= e(fmt_number((float) $l['quantity'] * (float) $l['unit_price'])) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
        <tfoot><tr><td colspan="4" class="num"><?= e(t('print.total')) ?> (<?= e($po['currency']) ?>)</td><td class="num"><?= e(fmt_number($total)) ?></td></tr></tfoot>
    </table>

    <?php if ($po['notes']): ?><div class="notes"><?= e($po['notes']) ?></div><?php endif ?>
    <p class="muted"><?= e(t('print.po_terms', ['ref' => $po['reference']])) ?></p>

    <div class="signatures">
        <div><?= e(t('print.issued_by')) ?><br><strong><?= e($po['created_by_name']) ?></strong></div>
        <div><?= e(t('print.supplier_ack')) ?></div>
    </div>
    <p class="doc__foot"><?= e($company['name']) ?> · <?= e($po['reference']) ?> · ETHAN LM</p>
</article>
