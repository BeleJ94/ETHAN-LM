<?php /** @var array $d @var array $company — bon de livraison (A4), signé par le réceptionnaire client. */ ?>
<article class="doc">
    <header class="doc__head">
        <?= \App\Core\View::partial('partials/doc_company', ['company' => $company]) ?>
        <div class="doc__title">
            <h1><?= e(t('print.dn_title')) ?></h1>
            <p class="ref"><?= e($d['reference']) ?></p>
            <p><?= e(t('delivery.shipped_at')) ?> : <?= e(fmt_date($d['shipped_at'])) ?></p>
        </div>
    </header>

    <div class="boxes">
        <div class="box box--accent">
            <h2><?= e(t('print.customer')) ?></h2>
            <p><strong><?= e($d['customer_name']) ?></strong></p>
            <p><?= e($d['delivery_address']) ?></p>
            <?php if ($d['delivery_contact']): ?><p><?= e($d['delivery_contact']) ?></p><?php endif ?>
        </div>
        <div class="box">
            <h2><?= e(t('delivery.section.transport')) ?></h2>
            <p><?= e(t('delivery.transporter')) ?> : <?= e($d['transporter'] ?? '—') ?></p>
            <p><?= e(t('delivery.plate')) ?> : <?= e($d['vehicle_plate'] ?? '—') ?><?= $d['trailer_plate'] ? ' / ' . e($d['trailer_plate']) : '' ?></p>
            <p><?= e(t('delivery.driver')) ?> : <?= e(trim(($d['driver_name'] ?? '') . ' ' . ($d['driver_phone'] ?? '')) ?: '—') ?></p>
            <?php if ($d['supplier_waybill']): ?><p><?= e(t('delivery.waybill')) ?> : <?= e($d['supplier_waybill']) ?></p><?php endif ?>
            <?php if ($d['forwarder_name']): ?><p><?= e(t('delivery.customs.agency')) ?> : <?= e($d['forwarder_name']) ?><?= $d['forwarder_ref'] ? ' — ' . e(t('delivery.customs.file')) . ' ' . e($d['forwarder_ref']) : '' ?></p><?php endif ?>
        </div>
    </div>

    <div class="meta">
        <div><span><?= e(t('orders.col.customer_po')) ?></span><?= e($d['customer_po_number']) ?></div>
        <div><span><?= e(t('print.our_ref')) ?></span><?= e($d['order_reference']) ?></div>
        <div><span><?= e(t('print.po_ref')) ?></span><?= e($d['po_reference']) ?></div>
        <div><span><?= e(t('delivery.delivered_at')) ?></span><?= e(fmt_date($d['delivered_at'])) ?></div>
    </div>

    <table>
        <thead><tr><th><?= e(t('orders.col.product')) ?></th><th class="num"><?= e(t('delivery.shipped')) ?></th><th class="num"><?= e(t('delivery.received')) ?></th><th><?= e(t('print.remarks')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($d['lines'] as $l): ?>
            <tr>
                <td><strong><?= e($l['sku']) ?></strong> — <?= e($l['item_name']) ?><?php if ($l['specification']): ?><br><span class="muted"><?= e($l['specification']) ?></span><?php endif ?><?php if (!empty($l['avs'])): ?><br><span class="muted">AV : <?= e(implode(' + ', array_map(fn ($a) => ($a['decision_number'] ?: $a['reference']) . ' (' . fmt_qty($a['quantity']) . ')', $l['avs']))) ?></span><?php endif ?></td>
                <td class="num"><?= e(fmt_qty($l['quantity_shipped'])) ?> <?= e($l['unit']) ?></td>
                <td class="num"><?= $l['quantity_received'] !== null ? e(fmt_qty($l['quantity_received'])) . ' ' . e($l['unit']) : '' ?></td>
                <td></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php if ($d['notes']): ?><div class="notes"><?= e($d['notes']) ?></div><?php endif ?>

    <div class="signatures">
        <div><?= e(t('print.driver_sign')) ?></div>
        <div><?= e(t('print.receiver_sign')) ?><?= $d['received_by'] ? '<br><strong>' . e($d['received_by']) . '</strong>' : '' ?></div>
    </div>
    <p class="doc__foot"><?= e($company['name']) ?> · <?= e($d['reference']) ?> · ETHAN LM</p>
</article>
