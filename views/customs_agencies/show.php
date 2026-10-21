<?php
/** Fiche d'une agence en douane : coordonnées, expéditions confiées (n° de dossier), comptes du portail. @var array $a @var array $deliveries @var array $users */
use App\Core\View;
use App\Security\Auth;

$user = Auth::require();
$canManage = $user->can('parties.manage');
$aid = (int) $a['id'];
$open = array_values(array_filter($deliveries, fn ($d) => in_array($d['status'], ['planned', 'in_transit'], true)));
?>
<div data-live-region="agency">
<section class="hero">
    <div class="hero__icon"><?= icon('briefcase') ?></div>
    <div class="hero__body">
        <div class="hero__line">
            <h1 class="hero__code"><?= e($a['name']) ?></h1>
            <span class="badge badge--<?= $a['active'] ? 'available' : 'immobilized' ?>"><?= e(t($a['active'] ? 'common.active' : 'common.inactive')) ?></span>
        </div>
        <p class="hero__name"><span class="mono"><?= e($a['code']) ?></span><?= $a['approval_number'] ? ' · ' . e(t('agencies.col.approval')) . ' <span class="mono">' . e($a['approval_number']) . '</span>' : '' ?></p>
        <p class="hero__tags">
            <?php if ($a['contact_name']): ?><span class="tag"><?= icon('user') ?> <?= e($a['contact_name']) ?></span><?php endif ?>
            <?php if ($a['phone']): ?><span class="tag"><?= icon('phone') ?> <?= e($a['phone']) ?></span><?php endif ?>
            <?php if ($a['email']): ?><span class="tag"><?= icon('mail') ?> <?= e($a['email']) ?></span><?php endif ?>
            <?php if ($a['city']): ?><span class="tag"><?= icon('map-pin') ?> <?= e($a['city']) ?></span><?php endif ?>
            <?php if ($a['customs_posts']): ?><span class="tag"><?= icon('landmark') ?> <?= e($a['customs_posts']) ?></span><?php endif ?>
        </p>
    </div>
    <div class="hero__actions">
        <a class="btn" href="<?= e(url('/customs-agencies')) ?>"><?= icon('arrow-left') ?> <?= e(t('nav.customs_agencies')) ?></a>
        <?php if ($canManage): ?>
            <button type="button" class="btn" data-open="#tpl-agency-edit" data-title="<?= e(t('common.edit') . ' — ' . $a['name']) ?>"><?= icon('pencil') ?> <?= e(t('common.edit')) ?></button>
            <template id="tpl-agency-edit"><?= View::partial('customs_agencies/_form', ['a' => $a]) ?></template>
        <?php endif ?>
    </div>
</section>

<section class="vitals">
    <div class="vital"><?= icon('truck', 'vital__icon') ?><span class="vital__label"><?= e(t('agencies.col.open')) ?></span><span class="vital__value"><?= count($open) ?></span></div>
    <div class="vital<?= (int) $a['missing_ref'] ? ' vital--alert' : '' ?>"><?= icon('hash', 'vital__icon vital__icon--' . ((int) $a['missing_ref'] ? 'warn' : 'good')) ?><span class="vital__label"><?= e(t('agencies.missing_ref_title')) ?></span><span class="vital__value"><?= (int) $a['missing_ref'] ?></span></div>
    <div class="vital"><?= icon('package-check', 'vital__icon') ?><span class="vital__label"><?= e(t('agencies.col.deliveries')) ?></span><span class="vital__value"><?= (int) $a['delivery_count'] ?></span></div>
    <div class="vital"><?= icon('users', 'vital__icon') ?><span class="vital__label"><?= e(t('agencies.col.users')) ?></span><span class="vital__value"><?= (int) $a['user_count'] ?></span></div>
</section>
<?php if ($a['address'] || $a['nif'] || $a['rccm'] || $a['notes']): ?>
    <p class="muted"><?= e(implode(' · ', array_filter([$a['address'], $a['nif'] ? 'NIF ' . $a['nif'] : null, $a['rccm'] ? 'RCCM ' . $a['rccm'] : null]))) ?></p>
    <?php if ($a['notes']): ?><p class="prewrap muted"><?= e($a['notes']) ?></p><?php endif ?>
<?php endif ?>

<div class="grid grid--2-1">
    <section class="card">
        <div class="card__title"><h2><?= icon('truck', 'title-icon') ?> <?= e(t('agencies.deliveries_title')) ?> <span class="count-chip"><?= count($deliveries) ?></span></h2></div>
        <?php if (!$deliveries): ?>
            <p class="empty"><?= e(t('agencies.no_delivery')) ?></p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="table table--compact" data-dt-order='[[3,"desc"]]'>
            <thead><tr>
                <th><?= e(t('orders.col.reference')) ?></th>
                <th><?= e(t('delivery.customs.file_label')) ?></th>
                <th><?= e(t('orders.col.customer')) ?></th>
                <th><?= e(t('delivery.shipped_at')) ?></th>
                <th><?= e(t('common.status')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($deliveries as $d): ?>
                <tr class="row-link<?= $d['status'] === 'cancelled' ? ' is-muted' : '' ?>" tabindex="0" data-href="<?= e(url('/deliveries/' . (int) $d['id'])) ?>">
                    <td><strong><?= e($d['reference']) ?></strong><br><span class="muted"><?= e($d['po_reference']) ?><?= $d['fulfillment_mode'] === 'import' ? ' · IMPORT' : '' ?></span></td>
                    <td class="mono"><?= $d['forwarder_ref'] ? e($d['forwarder_ref']) : (in_array($d['status'], ['planned', 'in_transit'], true) ? '<span class="chip chip--warn">' . e(t('agencies.no_ref')) . '</span>' : '<span class="muted">—</span>') ?></td>
                    <td><?= e($d['customer_name']) ?><br><span class="muted"><?= e($d['customer_po_number']) ?></span></td>
                    <td data-order="<?= e($d['shipped_at']) ?>"><?= e(fmt_date($d['shipped_at'])) ?></td>
                    <td><?= View::partial('partials/status_badge', ['key' => 'delivery.status.' . $d['status'], 'status' => $d['status']]) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php endif ?>
    </section>

    <section class="card">
        <div class="card__title">
            <h2><?= icon('users', 'title-icon') ?> <?= e(t('agencies.users_title')) ?></h2>
            <?php if ($user->can('users.manage')): ?><div class="page-actions"><a class="btn btn--sm" href="<?= e(url('/users/new', ['role' => 'forwarder', 'forwarder_id' => $aid])) ?>"><?= icon('user-plus') ?> <?= e(t('agencies.add_user')) ?></a></div><?php endif ?>
        </div>
        <?php if (!$users): ?>
            <p class="empty"><?= e(t('agencies.no_user')) ?></p>
        <?php else: ?>
        <ul class="file-list">
            <?php foreach ($users as $u): ?>
                <li><span><?= icon('user') ?> <strong><?= e($u['name']) ?></strong> <span class="muted"><?= e($u['email']) ?></span></span>
                    <span class="muted"><?= $u['active'] ? ($u['last_login_at'] ? e(t('agencies.last_login', ['date' => fmt_date($u['last_login_at'], true)])) : e(t('agencies.never_logged'))) : e(t('common.inactive')) ?></span></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
        <p class="hint"><?= e(t('agencies.portal_hint')) ?></p>
    </section>
</div>
</div>
