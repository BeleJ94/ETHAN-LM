<?php
/**
 * Répertoire des licences d'importation + PO fournisseurs d'import encore sans licence.
 * @var array $licences @var array $pending
 */
use App\Core\View;
use App\Domain\ImportGate;
use App\Security\Auth;

$user = Auth::require();
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+30 days'));
$actions = $user->can('imports.manage') ? '<a class="btn" href="' . e(url('/licences/import')) . '">' . icon('scan-text') . ' ' . e(t('imports.from_document')) . '</a> <a class="btn btn--primary" href="' . e(url('/licences/new')) . '">' . icon('plus') . ' ' . e(t('imports.licence_new')) . '</a>' : '';
echo View::partial('partials/page_header', ['icon' => 'stamp', 'heading' => t('nav.licences'), 'meta' => t('imports.meta'), 'actions' => $actions]);
$valid = array_filter($licences, fn ($l) => ImportGate::licenceValid($l, $today));
$expiring = array_filter($valid, fn ($l) => $l['expires_at'] <= $soon);
$ara = array_filter($licences, fn ($l) => (int) $l['open_ara'] > 0);
?>
<section class="kpis kpis--compact">
    <div class="kpi"><span class="kpi__label"><?= e(t('imports.kpi.valid')) ?></span><span class="kpi__value"><?= count($valid) ?></span></div>
    <div class="kpi<?= $pending ? ' kpi--warn' : '' ?>"><span class="kpi__label"><?= e(t('imports.kpi.awaiting')) ?></span><span class="kpi__value"><?= count($pending) ?></span><span class="kpi__hint"><?= e(t('imports.kpi.awaiting_hint')) ?></span></div>
    <div class="kpi<?= $expiring ? ' kpi--warn' : '' ?>"><span class="kpi__label"><?= e(t('imports.kpi.expiring')) ?></span><span class="kpi__value"><?= count($expiring) ?></span><span class="kpi__hint"><?= e(t('imports.kpi.expiring_hint')) ?></span></div>
    <div class="kpi<?= $ara ? ' kpi--alert' : '' ?>"><span class="kpi__label"><?= e(t('imports.kpi.ara')) ?></span><span class="kpi__value"><?= count($ara) ?></span><span class="kpi__hint"><?= e(t('imports.kpi.ara_hint')) ?></span></div>
</section>

<?php if ($pending): ?>
<section class="card">
    <div class="card__title"><h2><?= icon('hourglass', 'title-icon title-icon--warn') ?> <?= e(t('imports.awaiting_title')) ?></h2></div>
    <div class="table-wrap">
    <table class="table table--compact">
        <thead><tr><th><?= e(t('nav.purchase_orders')) ?></th><th><?= e(t('sourcing.col.supplier')) ?></th><th><?= e(t('js.col.customer_po')) ?></th><th class="num"><?= e(t('orders.col.amount')) ?></th><th class="actions-col"></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $p): ?>
            <tr>
                <td><a href="<?= e(url('/purchase-orders/' . (int) $p['id'])) ?>"><strong class="mono"><?= e($p['reference']) ?></strong></a></td>
                <td><?= e($p['supplier_name']) ?></td>
                <td><span class="mono"><?= e($p['customer_po_number']) ?></span><br><span class="muted"><?= e($p['customer_name']) ?> · <?= e($p['order_reference']) ?></span></td>
                <td class="num"><?= e(fmt_money($p['amount'], $p['currency'])) ?></td>
                <td class="actions-col"><?php if ($user->can('imports.manage')): ?><a class="btn btn--sm btn--primary" href="<?= e(url('/licences/new', ['po_id' => (int) $p['id']])) ?>"><?= icon('plus') ?> <?= e(t('imports.licence_new')) ?></a><?php endif ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif ?>

<section class="card">
    <?php if (!$licences): ?>
        <div class="empty-state"><?= icon('stamp') ?><p><?= e(t('imports.none')) ?></p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table table--compact" data-dt-client data-dt-length="15" data-dt-order='[[3,"asc"]]'>
        <thead><tr>
            <th><?= e(t('imports.col.number')) ?></th>
            <th><?= e(t('imports.col.chain')) ?></th>
            <th><?= e(t('sourcing.col.supplier')) ?></th>
            <th><?= e(t('imports.col.validity')) ?></th>
            <th class="num"><?= e(t('imports.col.value')) ?></th>
            <th><?= e(t('imports.col.inspections')) ?></th>
            <th><?= e(t('common.status')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($licences as $l):
            $ok = ImportGate::licenceValid($l, $today);
            $state = $l['status'] === 'cancelled' ? 'cancelled' : ($ok ? ((int) $l['open_ara'] > 0 ? 'blocked' : 'valid') : 'expired'); ?>
            <tr class="row-link" tabindex="0" data-href="<?= e(url('/licences/' . (int) $l['id'])) ?>">
                <td data-order="<?= e($l['number']) ?>"><strong class="mono"><?= e($l['number']) ?></strong><?= $l['cod_number'] ? '<br><span class="cod-ref cod-ref--sm mono">' . e($l['cod_number']) . '</span>' : '' ?><?= $l['origin_country'] ? '<br><span class="muted">' . e($l['origin_country']) . '</span>' : '' ?></td>
                <td><span class="mono"><?= e($l['customer_po_number']) ?></span> → <span class="mono"><?= e($l['po_reference']) ?></span><br><span class="muted"><?= e($l['customer_name']) ?> · <?= e($l['order_reference']) ?></span></td>
                <td><?= e($l['supplier_name']) ?></td>
                <td data-order="<?= e($l['expires_at']) ?>"><?= e(fmt_date($l['issued_at'])) ?> → <span class="<?= !$ok ? 'text-bad' : ($l['expires_at'] <= $soon ? 'text-warn' : '') ?>"><?= e(fmt_date($l['expires_at'])) ?></span></td>
                <td class="num" data-order="<?= e((string) $l['amount']) ?>"><?= $l['amount'] !== null ? e(fmt_money($l['amount'], $l['currency'])) : '—' ?></td>
                <td data-order="<?= (int) $l['av_count'] ?>"><?= (int) $l['inspection_count'] ?> · <span class="text-good">AV <?= (int) $l['av_count'] ?></span><?= (int) $l['open_ara'] ? ' · <span class="text-bad">ARA ' . (int) $l['open_ara'] . '</span>' : '' ?></td>
                <td><?= View::partial('partials/status_badge', ['key' => 'imports.state.' . $state, 'status' => ['valid' => 'delivered', 'blocked' => 'blocked', 'expired' => 'overdue', 'cancelled' => 'cancelled'][$state]]) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
