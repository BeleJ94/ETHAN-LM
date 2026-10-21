<?php
/** @var array $warehouses @var array $sites */
use App\Core\View;
use App\Security\Auth;

$canManage = Auth::require()->can('warehouses.manage');
$actions = $canManage && $sites ? '<button type="button" class="btn btn--primary" data-open="#tpl-warehouse" data-title="' . e(t('warehouses.new')) . '">' . icon('plus') . ' ' . e(t('warehouses.new')) . '</button>' : '';
echo View::partial('partials/page_header', ['icon' => 'warehouse', 'heading' => t('nav.warehouses'), 'meta' => t('warehouses.count', ['n' => count($warehouses)]), 'actions' => $actions]);

$total = array_sum(array_map(fn ($w) => (float) $w['stock_value'], $warehouses));
?>
<?php if (!$sites): ?>
    <div class="alert alert--info"><?= icon('info') ?> <span><?= e(t('warehouses.need_site')) ?></span></div>
<?php endif ?>

<section class="card">
    <?php if (!$warehouses): ?>
        <div class="empty-state"><?= icon('warehouse') ?><p><?= e(t('warehouses.none')) ?></p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th><?= e(t('warehouses.col.code')) ?></th>
            <th><?= e(t('warehouses.col.site')) ?></th>
            <th class="num"><?= e(t('warehouses.col.items')) ?></th>
            <th class="num"><?= e(t('warehouses.col.value')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <?php if ($canManage): ?><th class="actions-col"><span class="sr-only"><?= e(t('common.actions')) ?></span></th><?php endif ?>
        </tr></thead>
        <tbody>
        <?php foreach ($warehouses as $w): ?>
            <tr>
                <td><a href="<?= e(url('/items')) ?>"><strong><?= e($w['code']) ?></strong></a><br><span class="muted"><?= e($w['name']) ?></span></td>
                <td><?= e($w['site_name']) ?></td>
                <td class="num"><?= (int) $w['item_count'] ?></td>
                <td class="num"><?= e(fmt_money($w['stock_value'])) ?></td>
                <td><span class="badge badge--<?= $w['active'] ? 'available' : 'immobilized' ?>"><?= e(t($w['active'] ? 'common.active' : 'common.inactive')) ?></span></td>
                <?php if ($canManage): ?>
                <td class="actions-col">
                    <button type="button" class="icon-btn" data-open="#tpl-warehouse-<?= (int) $w['id'] ?>" data-title="<?= e(t('common.edit') . ' ' . $w['code']) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
                    <template id="tpl-warehouse-<?= (int) $w['id'] ?>"><?= View::partial('warehouses/_form', ['w' => $w, 'sites' => $sites]) ?></template>
                </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
        <tfoot><tr><th colspan="3"><?= e(t('common.total')) ?></th><th class="num"><?= e(fmt_money($total)) ?></th><th colspan="<?= $canManage ? 2 : 1 ?>"></th></tr></tfoot>
    </table>
    </div>
    <?php endif ?>
</section>
<?php if ($canManage): ?>
<template id="tpl-warehouse"><?= View::partial('warehouses/_form', ['w' => null, 'sites' => $sites]) ?></template>
<?php endif ?>
