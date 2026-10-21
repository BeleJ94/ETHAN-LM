<?php
/** @var array $sites */
use App\Core\View;

$actions = '<button type="button" class="btn btn--primary" data-open="#tpl-site" data-title="' . e(t('sites.new')) . '">' . icon('plus') . ' ' . e(t('sites.new')) . '</button>';
echo View::partial('partials/page_header', ['icon' => 'map-pin', 'heading' => t('nav.sites'), 'meta' => t('sites.meta'), 'actions' => $actions]);
?>
<section class="card">
    <?php if (!$sites): ?>
        <div class="empty-state"><?= icon('map-pin') ?><p><?= e(t('sites.none')) ?></p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th><?= e(t('sites.col.code')) ?></th>
            <th><?= e(t('sites.col.city')) ?></th>
            <th class="num"><?= e(t('sites.col.warehouses')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th class="actions-col"><span class="sr-only"><?= e(t('common.actions')) ?></span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($sites as $s): ?>
            <tr>
                <td><strong><?= e($s['code']) ?></strong><br><span class="muted"><?= e($s['name']) ?></span></td>
                <td><?= e($s['city'] ?? '—') ?></td>
                <td class="num"><?= (int) $s['warehouse_count'] ?></td>
                <td><span class="badge badge--<?= $s['active'] ? 'available' : 'immobilized' ?>"><?= e(t($s['active'] ? 'common.active' : 'common.inactive')) ?></span></td>
                <td class="actions-col">
                    <button type="button" class="icon-btn" data-open="#tpl-site-<?= (int) $s['id'] ?>" data-title="<?= e(t('common.edit') . ' ' . $s['code']) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
                    <template id="tpl-site-<?= (int) $s['id'] ?>"><?= View::partial('sites/_form', ['s' => $s]) ?></template>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
<template id="tpl-site"><?= View::partial('sites/_form', ['s' => null]) ?></template>
