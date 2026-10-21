<?php
/** @var array $pos @var string $status */
use App\Core\View;

echo View::partial('partials/page_header', ['icon' => 'shopping-cart', 'heading' => t('nav.purchase_orders'), 'meta' => t('po.meta')]);
$tabs = ['open' => 'po.filter.open', 'draft' => 'po.status.draft', 'partial' => 'po.status.partial', 'delivered' => 'po.status.delivered', 'cancelled' => 'po.status.cancelled', '' => 'common.all'];
?>
<nav class="segmented" aria-label="<?= e(t('common.status')) ?>">
    <?php foreach ($tabs as $v => $k): ?>
        <a class="segmented__item" href="<?= e(url('/purchase-orders', ['status' => $v])) ?>" <?= $status === $v ? 'aria-current="page"' : '' ?>><?= e(t($k)) ?></a>
    <?php endforeach ?>
</nav>
<section class="card">
    <?php if (!$pos): ?>
        <div class="empty-state"><?= icon('shopping-cart') ?><p><?= e(t('po.none')) ?></p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th><?= e(t('orders.col.reference')) ?></th><th><?= e(t('sourcing.col.supplier')) ?></th><th><?= e(t('po.col.for_order')) ?></th>
            <th><?= e(t('orders.col.date')) ?></th><th class="num"><?= e(t('orders.col.amount')) ?></th><th><?= e(t('orders.vital.delivered')) ?></th><th><?= e(t('common.status')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($pos as $p):
            $pct = \App\Domain\OrderProgress::percent((float) $p['received'], (float) $p['qty']); ?>
            <tr class="row-link" tabindex="0" data-href="<?= e(url('/purchase-orders/' . (int) $p['id'])) ?>">
                <td><strong><?= e($p['reference']) ?></strong></td>
                <td><?= e($p['supplier_name']) ?></td>
                <td><?= e($p['order_reference']) ?><br><span class="muted"><?= e($p['customer_name']) ?></span></td>
                <td data-order="<?= e($p['order_date']) ?>"><?= e(fmt_date($p['order_date'])) ?><?= $p['expected_date'] ? '<br><span class="muted' . ($p['expected_date'] < date('Y-m-d') && in_array($p['status'], ['sent', 'partial'], true) ? ' overdue' : '') . '">' . e(t('po.expected')) . ' ' . e(fmt_date($p['expected_date'])) . '</span>' : '' ?></td>
                <td class="num"><?= e(fmt_money($p['amount'], $p['currency'])) ?></td>
                <td><span class="meter meter--inline"><span class="meter__bar" style="width: <?= $pct ?>%"></span></span> <span class="muted"><?= $pct ?> %</span></td>
                <td><?= View::partial('partials/status_badge', ['key' => 'po.status.' . $p['status'], 'status' => $p['status']]) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
