<?php
/** Suppression d'un client : inventaire des données liées + double saisie du mot de passe. @var array $party @var array $impact */
use App\Core\View;

$id = (int) $party['id'];
$counts = array_filter($impact['counts']);
echo View::partial('partials/page_header', [
    'icon' => 'trash-2',
    'heading' => t('customers.delete.title', ['name' => $party['name']]),
    'meta' => $party['code'],
    'actions' => '<a class="btn" href="' . e(url("/customers/$id")) . '">' . icon('arrow-left') . ' ' . e(t('common.cancel')) . '</a>',
]);
?>
<div class="alert alert--error" role="alert"><?= icon('triangle-alert') ?>
    <span><?= e(t($counts ? 'customers.delete.warning' : 'customers.delete.warning_empty')) ?></span></div>

<?php if ($counts): ?>
<div class="grid grid--2-1">
    <section class="card">
        <div class="card__title"><h2><?= icon('clipboard-list', 'title-icon') ?> <?= e(t('customers.delete.orders', ['n' => count($impact['orders'])])) ?></h2></div>
        <?php if ($impact['orders']): ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th><?= e(t('orders.col.reference')) ?></th><th><?= e(t('customers.delete.customer_po')) ?></th><th><?= e(t('orders.col.date')) ?></th><th><?= e(t('common.status')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($impact['orders'] as $o): ?>
                <tr>
                    <td class="mono"><?= e($o['reference']) ?></td>
                    <td><?= e($o['customer_po_number']) ?></td>
                    <td><?= e(fmt_date($o['po_date'])) ?></td>
                    <td><?= View::partial('partials/status_badge', ['key' => 'order.status.' . $o['status'], 'status' => $o['status']]) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php endif ?>
        <?php foreach (['purchase_orders' => 'nav.purchase_orders', 'invoices' => 'customers.delete.invoices', 'sites' => 'customers.delete.sites'] as $k => $label): ?>
            <?php if ($impact[$k]): ?>
                <p><strong><?= e(t($label)) ?> :</strong> <span class="mono"><?= e(implode(', ', $impact[$k])) ?></span></p>
            <?php endif ?>
        <?php endforeach ?>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= icon('list') ?> <?= e(t('customers.delete.summary')) ?></h2></div>
        <table class="table">
            <tbody>
            <?php foreach ($counts as $k => $n): ?>
                <tr><td><?= e(t('customers.delete.count.' . $k)) ?></td><td class="num"><strong><?= (int) $n ?></strong></td></tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </section>
</div>
<?php endif ?>

<section class="card">
    <div class="card__title"><h2><?= icon('lock') ?> <?= e(t('customers.delete.confirm_title')) ?></h2></div>
    <p class="muted"><?= e(t('customers.delete.confirm_hint')) ?></p>
    <form method="post" action="<?= e(url("/customers/$id/delete")) ?>" data-no-ajax autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field">
                <label for="password" class="required"><?= e(t('customers.delete.password')) ?></label>
                <input id="password" name="password" type="password" autocomplete="current-password" required<?= error_attr('password') ?>>
                <?= error_msg('password') ?>
            </div>
            <div class="field">
                <label for="password_confirm" class="required"><?= e(t('customers.delete.password_again')) ?></label>
                <input id="password_confirm" name="password_confirm" type="password" autocomplete="current-password" required<?= error_attr('password_confirm') ?>>
                <?= error_msg('password_confirm') ?>
            </div>
        </div>
        <div class="form-actions">
            <a class="btn" href="<?= e(url("/customers/$id")) ?>"><?= e(t('common.cancel')) ?></a>
            <button type="submit" class="btn btn--danger"><?= icon('trash-2') ?> <?= e(t('customers.delete.submit')) ?></button>
        </div>
    </form>
</section>
