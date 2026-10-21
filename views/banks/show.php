<?php
/** Fiche d'une banque et de ses agences. @var array $bank @var array $branches */
use App\Core\View;
use App\Security\Auth;

$canManage = Auth::require()->can('banks.manage');
$bid = (int) $bank['id'];
?>
<div data-live-region="bank">
<section class="hero">
    <div class="hero__icon"><?= icon('landmark') ?></div>
    <div class="hero__body">
        <div class="hero__line">
            <h1 class="hero__code"><?= e($bank['name']) ?></h1>
            <span class="badge badge--<?= $bank['active'] ? 'available' : 'immobilized' ?>"><?= e(t($bank['active'] ? 'common.active' : 'common.inactive')) ?></span>
        </div>
        <p class="hero__name"><span class="mono"><?= e($bank['code']) ?></span><?= $bank['short_name'] ? ' · ' . e($bank['short_name']) : '' ?><?= $bank['swift'] ? ' · SWIFT <span class="mono">' . e($bank['swift']) . '</span>' : '' ?></p>
        <p class="hero__tags">
            <?php if ($bank['country']): ?><span class="tag"><?= icon('globe') ?> <?= e($bank['country']) ?></span><?php endif ?>
            <?php if ($bank['phone']): ?><span class="tag"><?= icon('phone') ?> <?= e($bank['phone']) ?></span><?php endif ?>
            <?php if ($bank['email']): ?><span class="tag"><?= icon('mail') ?> <?= e($bank['email']) ?></span><?php endif ?>
            <?php if ($bank['website']): ?><span class="tag"><?= icon('link') ?> <?= e($bank['website']) ?></span><?php endif ?>
            <span class="tag"><?= icon('stamp') ?> <?= e(t('banks.used_by', ['n' => (int) $bank['usage_count']])) ?></span>
        </p>
    </div>
    <div class="hero__actions">
        <a class="btn" href="<?= e(url('/banks')) ?>"><?= icon('arrow-left') ?> <?= e(t('nav.banks')) ?></a>
        <?php if ($canManage): ?>
            <button type="button" class="btn" data-open="#tpl-bank-edit" data-title="<?= e(t('common.edit') . ' — ' . $bank['name']) ?>"><?= icon('pencil') ?> <?= e(t('common.edit')) ?></button>
            <template id="tpl-bank-edit"><?= View::partial('banks/_form', ['b' => $bank]) ?></template>
        <?php endif ?>
    </div>
</section>
<?php if ($bank['notes']): ?><p class="prewrap muted"><?= e($bank['notes']) ?></p><?php endif ?>

<section class="card">
    <div class="card__title">
        <h2><?= icon('git-branch', 'title-icon') ?> <?= e(t('banks.branches')) ?> <span class="count-chip"><?= count($branches) ?></span></h2>
        <?php if ($canManage): ?>
            <div class="page-actions"><button type="button" class="btn btn--sm btn--primary" data-open="#tpl-branch" data-title="<?= e(t('banks.branch.new')) ?>"><?= icon('plus') ?> <?= e(t('banks.branch.new')) ?></button></div>
        <?php endif ?>
    </div>
    <?php if (!$branches): ?>
        <div class="empty-state"><?= icon('git-branch') ?><p><?= e(t('banks.branch.none')) ?></p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table table--compact">
        <thead><tr>
            <th><?= e(t('banks.branch.name')) ?></th>
            <th><?= e(t('banks.branch.city')) ?></th>
            <th><?= e(t('banks.branch.contact')) ?></th>
            <th class="num"><?= e(t('banks.col.licences')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <?php if ($canManage): ?><th class="actions-col" data-orderable="false"><span class="sr-only"><?= e(t('common.actions')) ?></span></th><?php endif ?>
        </tr></thead>
        <tbody>
        <?php foreach ($branches as $br): $rid = (int) $br['id']; ?>
            <tr class="<?= $br['active'] ? '' : 'is-muted' ?>">
                <td><strong><?= e($br['name']) ?></strong><?= $br['code'] ? '<br><span class="muted mono">' . e($br['code']) . '</span>' : '' ?></td>
                <td><?= e($br['city'] ?: '—') ?><?= $br['address'] ? '<br><span class="muted">' . e($br['address']) . '</span>' : '' ?></td>
                <td class="muted"><?= e(implode(' · ', array_filter([$br['contact_name'], $br['phone'], $br['email']])) ?: '—') ?></td>
                <td class="num"><?= (int) $br['usage_count'] ?: '<span class="muted">—</span>' ?></td>
                <td><span class="badge badge--<?= $br['active'] ? 'available' : 'immobilized' ?>"><?= e(t($br['active'] ? 'common.active' : 'common.inactive')) ?></span></td>
                <?php if ($canManage): ?>
                <td class="actions-col">
                    <button type="button" class="icon-btn" data-open="#tpl-branch-<?= $rid ?>" data-title="<?= e(t('common.edit') . ' — ' . $br['name']) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
                    <?php if ((int) $br['usage_count'] === 0): ?>
                        <form method="post" action="<?= e(url("/banks/$bid/branches/$rid/delete")) ?>" class="inline" data-confirm="<?= e(t('banks.branch.delete_confirm', ['name' => $br['name']])) ?>" data-confirm-variant="danger">
                            <?= csrf_field() ?>
                            <button type="submit" class="icon-btn icon-btn--danger" title="<?= e(t('common.delete')) ?>" aria-label="<?= e(t('common.delete')) ?>"><?= icon('trash-2') ?></button>
                        </form>
                    <?php else: ?>
                        <span class="icon-btn is-disabled" title="<?= e(t('banks.branch.cannot_delete')) ?>" aria-hidden="true"><?= icon('trash-2') ?></span>
                    <?php endif ?>
                    <template id="tpl-branch-<?= $rid ?>"><?= View::partial('banks/_branch_form', ['bank' => $bank, 'br' => $br]) ?></template>
                </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
<?php if ($canManage): ?><template id="tpl-branch"><?= View::partial('banks/_branch_form', ['bank' => $bank, 'br' => null]) ?></template><?php endif ?>
</div>
