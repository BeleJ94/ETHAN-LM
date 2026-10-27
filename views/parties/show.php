<?php
/** @var string $type @var string $base @var array $party @var array $sites @var array $orders */
use App\Core\View;
use App\Security\Auth;

$user = Auth::require();
$p = $party;
$id = (int) $p['id'];
?>
<section class="hero">
    <div class="hero__icon"><?= icon($type === 'customer' ? 'building-2' : 'factory') ?></div>
    <div class="hero__body">
        <div class="hero__line">
            <h1 class="hero__code"><?= e($p['name']) ?></h1>
            <span class="badge badge--<?= $p['active'] ? 'available' : 'immobilized' ?>"><?= e(t($p['active'] ? 'common.active' : 'common.inactive')) ?></span>
        </div>
        <p class="hero__name"><?= e(t('parties.type.' . $type)) ?> · <?= e($p['code']) ?></p>
        <p class="hero__tags">
            <?php if ($p['city']): ?><span class="tag"><?= icon('map-pin') ?> <?= e($p['city']) ?></span><?php endif ?>
            <?php if ($p['phone']): ?><span class="tag"><?= icon('phone') ?> <?= e($p['phone']) ?></span><?php endif ?>
            <?php if ($p['email']): ?><span class="tag"><?= icon('mail') ?> <?= e($p['email']) ?></span><?php endif ?>
            <span class="tag"><?= icon('calendar-clock') ?> <?= e(t('parties.terms_short', ['n' => (int) $p['payment_terms_days']])) ?> · <?= e($p['currency']) ?></span>
        </p>
    </div>
    <div class="hero__actions">
        <?php if ($type === 'customer' && $user->can('orders.manage')): ?>
            <a class="btn btn--primary" href="<?= e(url('/orders/new', ['customer_id' => $id])) ?>"><?= icon('plus') ?> <?= e(t('orders.new')) ?></a>
        <?php endif ?>
        <?php if ($user->can('parties.manage')): ?>
            <a class="btn" href="<?= e(url("$base/$id/edit")) ?>"><?= icon('pencil') ?> <?= e(t('common.edit')) ?></a>
        <?php endif ?>
        <?php if ($type === 'customer' && $user->can('parties.delete')): ?>
            <a class="btn btn--danger" href="<?= e(url("/customers/$id/delete")) ?>"><?= icon('trash-2') ?> <?= e(t('common.delete')) ?></a>
        <?php endif ?>
    </div>
</section>

<div class="grid grid--2-1">
    <section class="card">
        <div class="card__title"><h2><?= icon('clipboard-list', 'title-icon') ?> <?= e(t($type === 'customer' ? 'nav.orders' : 'nav.purchase_orders')) ?></h2></div>
        <?php if (!$orders): ?>
            <p class="empty"><?= e(t('orders.none')) ?></p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th><?= e(t('orders.col.reference')) ?></th><th><?= e(t('orders.col.date')) ?></th><th class="num"><?= e(t('orders.col.amount')) ?></th><th><?= e(t('common.status')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o):
                $href = $type === 'customer' ? '/orders/' . (int) $o['id'] : '/purchase-orders/' . (int) $o['id'];
                $stKey = ($type === 'customer' ? 'order.status.' : 'po.status.') . $o['status']; ?>
                <tr class="row-link" tabindex="0" data-href="<?= e(url($href)) ?>">
                    <td><strong><?= e($o['reference']) ?></strong><br><span class="muted"><?= e($o['number']) ?></span></td>
                    <td><?= e(fmt_date($o['date'])) ?></td>
                    <td class="num"><?= e(fmt_money($o['amount'], $o['currency'])) ?></td>
                    <td><?= View::partial('partials/status_badge', ['key' => $stKey, 'status' => $o['status']]) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php endif ?>
    </section>

    <div>
        <section class="card">
            <div class="card__title"><h2><?= icon('id-card', 'title-icon') ?> <?= e(t('parties.section.identity')) ?></h2></div>
            <dl class="dl">
                <dt><?= e(t('parties.rccm')) ?></dt><dd><?= e($p['rccm'] ?: '—') ?></dd>
                <dt><?= e(t('parties.id_nat')) ?></dt><dd><?= e($p['id_nat'] ?: '—') ?></dd>
                <dt><?= e(t('parties.nif')) ?></dt><dd><?= e($p['nif'] ?: '—') ?></dd>
                <dt><?= e(t('parties.col.contact')) ?></dt><dd><?= e($p['contact_name'] ?: '—') ?></dd>
                <dt><?= e(t('parties.address')) ?></dt><dd><?= e(trim(($p['address'] ?? '') . ' ' . ($p['city'] ?? '') . ' ' . ($p['country'] ?? '')) ?: '—') ?></dd>
            </dl>
            <?php if ($p['notes']): ?><p class="prewrap muted"><?= e($p['notes']) ?></p><?php endif ?>
        </section>

        <?php if ($type === 'customer'): ?>
        <section class="card" id="sites">
            <div class="card__title">
                <h2><?= icon('map-pinned', 'title-icon') ?> <?= e(t('sites.delivery')) ?></h2>
                <?php if ($user->can('parties.manage')): ?>
                    <button type="button" class="btn btn--sm" data-open="#tpl-site-new" data-title="<?= e(t('sites.delivery_new')) ?>"><?= icon('plus') ?> <?= e(t('common.add')) ?></button>
                <?php endif ?>
            </div>
            <?php if (!$sites): ?>
                <div class="empty-state"><?= icon('map-pinned') ?><p><?= e(t('sites.delivery_none')) ?></p></div>
            <?php else: ?>
            <ul class="doc-list">
                <?php foreach ($sites as $s): ?>
                <li class="doc-list__item<?= $s['active'] ? '' : ' doc-list__item--muted' ?>">
                    <div class="doc-list__head">
                        <strong><?= e($s['name']) ?></strong>
                        <?php if ($user->can('parties.manage')): ?>
                            <button type="button" class="icon-btn" data-open="#tpl-site-<?= (int) $s['id'] ?>" data-title="<?= e($s['name']) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
                            <template id="tpl-site-<?= (int) $s['id'] ?>"><?= View::partial('parties/_site_form', ['s' => $s, 'customerId' => $id]) ?></template>
                        <?php endif ?>
                    </div>
                    <p class="muted"><?= e(trim(($s['address'] ?? '') . ' ' . ($s['city'] ?? ''))) ?><?= $s['gps'] ? ' · GPS ' . e($s['gps']) : '' ?></p>
                    <?php if ($s['contact_name'] || $s['phone']): ?><p class="muted"><?= icon('user') ?> <?= e(trim($s['contact_name'] . ' ' . $s['phone'])) ?></p><?php endif ?>
                </li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
            <template id="tpl-site-new"><?= View::partial('parties/_site_form', ['s' => null, 'customerId' => $id]) ?></template>
        </section>
        <?php endif ?>
    </div>
</div>
