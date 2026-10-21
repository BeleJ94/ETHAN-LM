<?php
/** Référentiel des banques. @var array $banks */
use App\Core\View;
use App\Security\Auth;

$canManage = Auth::require()->can('banks.manage');
$actions = $canManage ? '<button type="button" class="btn btn--primary" data-open="#tpl-bank" data-title="' . e(t('banks.new')) . '">' . icon('plus') . ' ' . e(t('banks.new')) . '</button>' : '';
?>
<div data-live-region="banks">
<?= View::partial('partials/page_header', ['icon' => 'landmark', 'heading' => t('nav.banks'), 'meta' => t('banks.meta'), 'actions' => $actions]) ?>
<section class="card">
    <?php if (!$banks): ?>
        <div class="empty-state"><?= icon('landmark') ?><p><?= e(t('banks.none')) ?></p>
            <?php if ($canManage): ?><button type="button" class="btn btn--primary" data-open="#tpl-bank" data-title="<?= e(t('banks.new')) ?>"><?= icon('plus') ?> <?= e(t('banks.new')) ?></button><?php endif ?>
        </div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table table--compact">
        <thead><tr>
            <th><?= e(t('banks.col.bank')) ?></th>
            <th><?= e(t('banks.col.swift')) ?></th>
            <th><?= e(t('banks.col.country')) ?></th>
            <th><?= e(t('banks.col.contact')) ?></th>
            <th class="num"><?= e(t('banks.col.branches')) ?></th>
            <th class="num"><?= e(t('banks.col.licences')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <?php if ($canManage): ?><th class="actions-col" data-orderable="false"><span class="sr-only"><?= e(t('common.actions')) ?></span></th><?php endif ?>
        </tr></thead>
        <tbody>
        <?php foreach ($banks as $b): $bid = (int) $b['id']; ?>
            <tr class="row-link<?= $b['active'] ? '' : ' is-muted' ?>" tabindex="0" data-href="<?= e(url('/banks/' . $bid)) ?>">
                <td><strong><?= e($b['name']) ?></strong><?= $b['short_name'] ? ' <span class="muted">(' . e($b['short_name']) . ')</span>' : '' ?><br><span class="muted mono"><?= e($b['code']) ?></span></td>
                <td class="mono"><?= e($b['swift'] ?: '—') ?></td>
                <td><?= e($b['country'] ?: '—') ?></td>
                <td class="muted"><?= e(implode(' · ', array_filter([$b['phone'], $b['email']])) ?: '—') ?></td>
                <td class="num"><a href="<?= e(url('/banks/' . $bid)) ?>"><?= (int) $b['active_branch_count'] ?></a><?= (int) $b['branch_count'] > (int) $b['active_branch_count'] ? ' <span class="muted">/ ' . (int) $b['branch_count'] . '</span>' : '' ?></td>
                <td class="num"><?= (int) $b['usage_count'] ?: '<span class="muted">—</span>' ?></td>
                <td><span class="badge badge--<?= $b['active'] ? 'available' : 'immobilized' ?>"><?= e(t($b['active'] ? 'common.active' : 'common.inactive')) ?></span></td>
                <?php if ($canManage): ?>
                <td class="actions-col">
                    <button type="button" class="icon-btn" data-open="#tpl-bank-<?= $bid ?>" data-title="<?= e(t('common.edit') . ' — ' . $b['name']) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
                    <?php if ((int) $b['branch_count'] === 0 && (int) $b['usage_count'] === 0): ?>
                        <form method="post" action="<?= e(url("/banks/$bid/delete")) ?>" class="inline" data-confirm="<?= e(t('banks.delete_confirm', ['name' => $b['name']])) ?>" data-confirm-variant="danger">
                            <?= csrf_field() ?>
                            <button type="submit" class="icon-btn icon-btn--danger" title="<?= e(t('common.delete')) ?>" aria-label="<?= e(t('common.delete')) ?>"><?= icon('trash-2') ?></button>
                        </form>
                    <?php else: ?>
                        <span class="icon-btn is-disabled" title="<?= e(t('banks.cannot_delete')) ?>" aria-hidden="true"><?= icon('trash-2') ?></span>
                    <?php endif ?>
                    <template id="tpl-bank-<?= $bid ?>"><?= View::partial('banks/_form', ['b' => $b, 'back' => 'list']) ?></template>
                </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
<?php if ($canManage): ?><template id="tpl-bank"><?= View::partial('banks/_form', ['b' => null]) ?></template><?php endif ?>
</div>
