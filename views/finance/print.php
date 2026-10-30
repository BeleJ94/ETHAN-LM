<?php
/**
 * Facture client (A4) au format logistique : émetteur, client facturé, lieu de livraison, références du dossier,
 * transport et réception de l'expédition, lignes, totaux, montant en lettres, règlement, mentions.
 * @var array $inv @var array $order @var ?array $customer @var ?array $delivery @var ?array $licence @var list<string> $avs
 * @var array $company @var bool $preview aperçu avant enregistrement (rien n'est enregistré)
 */
use App\Core\I18n;
use App\Domain\AmountInWords;

$cur = $inv['currency'];
$paid = (float) ($inv['paid'] ?? 0);
$hasVat = ($inv['vat_rate'] ?? null) !== null;
$balance = max(0, round((float) $inv['amount'] - $paid, 2));
$dlLines = [];
foreach ($delivery['lines'] ?? [] as $l) {
    $dlLines[(int) $l['id']] = $l;
}
$c = $customer ?? [];
$transport = $delivery ? array_filter([
    t('delivery.transporter') => $delivery['transporter'],
    t('delivery.vehicle') => trim(($delivery['vehicle_plate'] ?? '') . (($delivery['trailer_plate'] ?? '') ? ' / ' . $delivery['trailer_plate'] : '')),
    t('delivery.driver') => trim(($delivery['driver_name'] ?? '') . (($delivery['driver_phone'] ?? '') ? ' · ' . $delivery['driver_phone'] : '')),
    t('invoice.print.waybill') => $delivery['supplier_waybill'],
    t('invoice.print.customs') => trim(($delivery['forwarder_name'] ?? '') . (($delivery['forwarder_ref'] ?? '') ? ' · ' . $delivery['forwarder_ref'] : '')),
    t('invoice.print.licence') => $licence['number'] ?? null,
    t('invoice.print.av') => $avs ? implode(', ', $avs) : null,
]) : [];
$state = $preview ? null : match (true) {
    $inv['status'] === 'draft' => 'draft',
    $inv['status'] === 'cancelled' => 'cancelled',
    $inv['status'] === 'paid' => 'paid',
    default => null,
};
?>
<article class="doc doc--invoice<?= $preview ? ' doc--preview' : '' ?><?= $state ? ' doc--' . $state : '' ?>">
    <?php if ($preview || $state): ?><div class="doc__stamp"><?= e(t($preview ? 'invoice.print.stamp_preview' : 'invoice.status.' . $state)) ?></div><?php endif ?>
    <header class="doc__head">
        <?= \App\Core\View::partial('partials/doc_company', ['company' => $company]) ?>
        <div class="doc__title">
            <h1><?= e(t('invoice.print.title')) ?></h1>
            <p class="ref"><?= e($inv['reference'] ?: t('invoice.print.ref_auto')) ?></p>
            <p><?= e(t('invoice.col.date')) ?> : <strong><?= e(fmt_date($inv['invoice_date'])) ?></strong></p>
            <p><?= e(t('invoice.col.due')) ?> : <strong><?= e(fmt_date($inv['due_date'])) ?></strong></p>
        </div>
    </header>

    <div class="boxes">
        <div class="box box--accent">
            <h2><?= e(t('invoice.print.bill_to')) ?></h2>
            <p><strong><?= e($c['name'] ?? $order['customer_name']) ?></strong><?= !empty($c['code']) ? ' <span class="muted">(' . e($c['code']) . ')</span>' : '' ?></p>
            <?php if (!empty($c['address'])): ?><p><?= e($c['address']) ?></p><?php endif ?>
            <?php if (!empty($c['city']) || !empty($c['country'])): ?><p><?= e(implode(', ', array_filter([$c['city'] ?? '', $c['country'] ?? '']))) ?></p><?php endif ?>
            <?php $ids = array_filter(['RCCM' => $c['rccm'] ?? null, 'ID NAT' => $c['id_nat'] ?? null, 'NIF' => $c['nif'] ?? null]); ?>
            <?php if ($ids): ?><p class="muted"><?= e(implode(' · ', array_map(fn ($k, $v) => "$k $v", array_keys($ids), $ids))) ?></p><?php endif ?>
            <?php $contact = array_filter([$c['contact_name'] ?? null, $c['phone'] ?? null, $c['email'] ?? null]); ?>
            <?php if ($contact): ?><p class="muted"><?= e(implode(' · ', $contact)) ?></p><?php endif ?>
        </div>
        <div class="box">
            <h2><?= e(t('invoice.print.delivered_to')) ?></h2>
            <?php if ($delivery): ?>
                <p><?= e($delivery['delivery_address'] ?: trim(($order['delivery_site_name'] ?? '') . "\n" . ($order['delivery_site_address'] ?? ''))) ?></p>
                <?php if ($delivery['delivery_contact']): ?><p class="muted"><?= e(t('po.delivery_contact')) ?> : <?= e($delivery['delivery_contact']) ?></p><?php endif ?>
                <p><?= e(t('delivery.delivered_at')) ?> : <strong><?= e(fmt_date($delivery['delivered_at'])) ?></strong><?= $delivery['received_by'] ? ' · ' . e(t('invoice.print.received_by')) . ' ' . e($delivery['received_by']) : '' ?></p>
            <?php else: ?>
                <p><?= e(trim(($order['delivery_site_name'] ?? '') . "\n" . ($order['delivery_site_address'] ?? '')) ?: '—') ?></p>
            <?php endif ?>
        </div>
    </div>

    <div class="meta meta--refs">
        <div><span><?= e(t('invoice.print.customer_po')) ?></span><?= e($order['customer_po_number']) ?></div>
        <div><span><?= e(t('print.our_ref')) ?></span><?= e($order['reference']) ?></div>
        <div><span><?= e(t('invoice.print.shipment')) ?></span><?= e($delivery['reference'] ?? '—') ?></div>
        <div><span><?= e(t('delivery.shipped_at')) ?></span><?= e($delivery ? fmt_date($delivery['shipped_at']) : '—') ?></div>
        <div><span><?= e(t('invoice.print.terms')) ?></span><?= e(t('parties.terms_short', ['n' => (int) $order['payment_terms_days']])) ?></div>
        <div><span><?= e(t('movements.currency')) ?></span><?= e($cur) ?></div>
    </div>

    <?php if ($transport): ?>
    <div class="transport">
        <h2><?= e(t('invoice.print.transport')) ?></h2>
        <dl><?php foreach ($transport as $k => $v): ?><div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div><?php endforeach ?></dl>
    </div>
    <?php endif ?>

    <table class="lines-table">
        <thead><tr><th>#</th><th><?= e(t('invoice.line.description')) ?></th><th class="num"><?= e(t('invoice.print.qty')) ?></th><th><?= e(t('invoice.print.unit')) ?></th><th class="num"><?= e(t('orders.col.unit_price')) ?></th><th class="num"><?= e(t('orders.col.amount')) ?> (<?= e($cur) ?>)</th></tr></thead>
        <tbody>
        <?php foreach ($inv['lines'] as $i => $l):
            $src = $l['delivery_line_id'] !== null ? ($dlLines[(int) $l['delivery_line_id']] ?? null) : null; ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= e($l['description']) ?>
                    <?php if ($src): ?><br><span class="muted"><?= e(t('invoice.print.loaded_received', ['loaded' => fmt_qty($src['quantity_shipped']), 'received' => fmt_qty($src['quantity_received']), 'unit' => $src['unit']])) ?></span><?php endif ?></td>
                <td class="num"><?= e(fmt_qty($l['quantity'])) ?></td>
                <td><?= e((string) ($l['unit'] ?? '')) ?></td>
                <td class="num"><?= e(fmt_number($l['unit_price'])) ?></td>
                <td class="num"><?= e(fmt_number($l['amount'])) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>

    <div class="totals-row">
        <div class="words">
            <h2><?= e(t('invoice.print.words_title')) ?></h2>
            <p><?= e(ucfirst(AmountInWords::format((float) $inv['amount'], $cur, I18n::locale()))) ?><?= $hasVat ? ' ' . e(t('invoice.print.ttc_suffix')) : '' ?>.</p>
            <?php if (!$hasVat): ?><p class="muted vat-note"><?= e(t('invoice.print.no_vat')) ?></p><?php endif ?>
        </div>
        <table class="totals">
            <tr><th><?= e(t($hasVat ? 'invoice.total_ht' : 'invoice.print.total')) ?></th><td class="num"><?= e(fmt_money($inv['subtotal'] ?? $inv['amount'], $cur)) ?></td></tr>
            <?php if ($hasVat): ?>
                <tr><th><?= e(t('invoice.vat')) ?> (<?= e(fmt_number($inv['vat_rate'])) ?> %)</th><td class="num"><?= e(fmt_money($inv['vat_amount'], $cur)) ?></td></tr>
                <tr><th><strong><?= e(t('invoice.total_ttc')) ?></strong></th><td class="num"><?= e(fmt_money($inv['amount'], $cur)) ?></td></tr>
            <?php endif ?>
            <?php if ($paid > 0): ?><tr><th><?= e(t('invoice.collected')) ?></th><td class="num">− <?= e(fmt_money($paid, $cur)) ?></td></tr><?php endif ?>
            <tr class="totals__due"><th><?= e(t('invoice.print.net_due')) ?></th><td class="num"><?= e(fmt_money($inv['status'] === 'cancelled' ? 0 : $balance, $cur)) ?></td></tr>
        </table>
    </div>

    <div class="boxes boxes--pay">
        <div class="box">
            <h2><?= e(t('invoice.print.payment')) ?></h2>
            <p><?= e(t('invoice.print.payment_due', ['date' => fmt_date($inv['due_date']), 'ref' => $inv['reference'] ?: '—'])) ?></p>
            <?php if ($company['bank_details'] ?? ''): ?><p class="bank"><?= e($company['bank_details']) ?></p><?php endif ?>
        </div>
        <?php if ($inv['notes'] || ($company['invoice_terms'] ?? '')): ?>
        <div class="box">
            <h2><?= e(t('print.remarks')) ?></h2>
            <?php if ($inv['notes']): ?><p><?= e($inv['notes']) ?></p><?php endif ?>
            <?php if ($company['invoice_terms'] ?? ''): ?><p class="muted"><?= e($company['invoice_terms']) ?></p><?php endif ?>
        </div>
        <?php endif ?>
    </div>

    <div class="signatures">
        <div><?= e(t('print.issued_by')) ?><br><strong><?= e($inv['created_by_name'] ?? '') ?></strong><br><span class="muted"><?= e(t('invoice.print.stamp_company')) ?></span></div>
        <div><?= e(t('invoice.print.customer_ack')) ?></div>
    </div>
    <p class="doc__foot"><?= e(implode(' · ', array_filter([$company['name'], $company['rccm'] ? 'RCCM ' . $company['rccm'] : '', $company['nif'] ? 'NIF ' . $company['nif'] : '', $inv['reference'], 'ETHAN LM']))) ?></p>
</article>
