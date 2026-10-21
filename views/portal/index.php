<?php
/** @var array $deliveries @var array $summaries — portail transitaire : aucune donnée commerciale. */
use App\Core\View;

echo View::partial('partials/page_header', ['icon' => 'folder-check', 'heading' => t('nav.portal'), 'meta' => t('portal.meta')]);
$todo = array_filter($deliveries, fn ($d) => ($summaries[(int) $d['id']]['done'] ?? 0) < ($summaries[(int) $d['id']]['total'] ?? 0));
?>
<section class="kpis kpis--compact">
    <div class="kpi"><span class="kpi__label"><?= e(t('portal.kpi.shipments')) ?></span><span class="kpi__value"><?= count($deliveries) ?></span></div>
    <div class="kpi<?= $todo ? ' kpi--warn' : '' ?>"><span class="kpi__label"><?= e(t('portal.kpi.todo')) ?></span><span class="kpi__value"><?= count($todo) ?></span><span class="kpi__hint"><?= e(t('portal.kpi.todo_hint')) ?></span></div>
</section>
<section class="card">
    <?php if (!$deliveries): ?>
        <div class="empty-state"><?= icon('folder-check') ?><p><?= e(t('portal.none')) ?></p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th><?= e(t('orders.col.reference')) ?></th><th><?= e(t('orders.col.customer')) ?></th><th><?= e(t('transport.mode')) ?></th>
            <th><?= e(t('delivery.shipped_at')) ?></th><th><?= e(t('documents.checklist')) ?></th><th><?= e(t('common.status')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($deliveries as $d):
            $s = $summaries[(int) $d['id']] ?? ['done' => 0, 'total' => 0]; ?>
            <tr class="row-link" tabindex="0" data-href="<?= e(url('/portal/deliveries/' . (int) $d['id'])) ?>">
                <td><strong><?= e($d['reference']) ?></strong><br><span class="muted"><?= e($d['order_reference']) ?> · <?= e($d['po_reference']) ?></span></td>
                <td><?= e($d['customer_name']) ?></td>
                <td><?= e(t('transport.' . $d['transport_mode'])) ?><?= $d['vehicle_plate'] ? '<br><span class="muted mono">' . e($d['vehicle_plate']) . '</span>' : '' ?></td>
                <td><?= e(fmt_date($d['shipped_at'])) ?></td>
                <td><span class="chip<?= $s['done'] < $s['total'] ? ' chip--warn' : ' chip--c-green' ?>"><?= icon('file-check-2') ?> <?= $s['done'] ?>/<?= $s['total'] ?></span></td>
                <td><?= View::partial('partials/status_badge', ['key' => 'delivery.status.' . $d['status'], 'status' => $d['status']]) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
