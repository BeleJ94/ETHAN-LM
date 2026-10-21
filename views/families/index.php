<?php
/** @var array $families */
use App\Core\View;
use App\Security\Auth;

$canManage = Auth::require()->can('items.manage');
$actions = $canManage ? '<button type="button" class="btn btn--primary" data-open="#tpl-family" data-title="' . e(t('families.new')) . '">' . icon('plus') . ' ' . e(t('families.new')) . '</button>' : '';
echo View::partial('partials/page_header', ['icon' => 'shapes', 'heading' => t('nav.families'), 'meta' => t('families.meta'), 'actions' => $actions]);
?>
<section class="card">
    <?php if (!$families): ?>
        <div class="empty-state"><?= icon('shapes') ?><p><?= e(t('families.none')) ?></p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th><?= e(t('families.col.family')) ?></th>
            <th><?= e(t('families.col.description')) ?></th>
            <th class="num"><?= e(t('families.col.items')) ?></th>
            <th class="num"><?= e(t('families.col.order')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <?php if ($canManage): ?><th class="actions-col"><span class="sr-only"><?= e(t('common.actions')) ?></span></th><?php endif ?>
        </tr></thead>
        <tbody>
        <?php foreach ($families as $f): $fid = (int) $f['id']; ?>
            <tr>
                <td><span class="chip chip--c-<?= e($f['color']) ?>"><?= e($f['name']) ?></span><br><span class="muted mono"><?= e($f['code']) ?></span></td>
                <td class="muted"><?= e($f['description'] ?? '—') ?></td>
                <td class="num"><a href="<?= e(url('/items', ['family_id' => $fid])) ?>"><?= (int) $f['active_item_count'] ?></a><?= (int) $f['item_count'] > (int) $f['active_item_count'] ? ' <span class="muted">/ ' . (int) $f['item_count'] . '</span>' : '' ?></td>
                <td class="num"><?= (int) $f['sort_order'] ?></td>
                <td><span class="badge badge--<?= $f['active'] ? 'available' : 'immobilized' ?>"><?= e(t($f['active'] ? 'common.active' : 'common.inactive')) ?></span></td>
                <?php if ($canManage): ?>
                <td class="actions-col">
                    <button type="button" class="icon-btn" data-open="#tpl-family-<?= $fid ?>" data-title="<?= e(t('common.edit') . ' — ' . $f['name']) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
                    <?php if ((int) $f['item_count'] === 0): ?>
                        <form method="post" action="<?= e(url("/families/$fid/delete")) ?>" class="inline" data-confirm="<?= e(t('families.delete_confirm', ['name' => $f['name']])) ?>" data-confirm-variant="danger">
                            <?= csrf_field() ?>
                            <button type="submit" class="icon-btn icon-btn--danger" title="<?= e(t('common.delete')) ?>" aria-label="<?= e(t('common.delete')) ?>"><?= icon('trash-2') ?></button>
                        </form>
                    <?php else: ?>
                        <span class="icon-btn is-disabled" title="<?= e(t('families.cannot_delete')) ?>" aria-hidden="true"><?= icon('trash-2') ?></span>
                    <?php endif ?>
                    <template id="tpl-family-<?= $fid ?>"><?= View::partial('families/_form', ['f' => $f]) ?></template>
                </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
<?php if ($canManage): ?>
<template id="tpl-family"><?= View::partial('families/_form', ['f' => null]) ?></template>
<?php endif ?>
