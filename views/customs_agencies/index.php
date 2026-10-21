<?php
/** Référentiel des agences en douane / transitaires. @var array $agencies */
use App\Core\View;
use App\Security\Auth;

$canManage = Auth::require()->can('parties.manage');
$actions = $canManage ? '<button type="button" class="btn btn--primary" data-open="#tpl-agency" data-title="' . e(t('agencies.new')) . '">' . icon('plus') . ' ' . e(t('agencies.new')) . '</button>' : '';
?>
<div data-live-region="agencies">
<?= View::partial('partials/page_header', ['icon' => 'briefcase', 'heading' => t('nav.customs_agencies'), 'meta' => t('agencies.meta'), 'actions' => $actions]) ?>
<section class="card">
    <?php if (!$agencies): ?>
        <div class="empty-state"><?= icon('briefcase') ?><p><?= e(t('agencies.none')) ?></p>
            <?php if ($canManage): ?><button type="button" class="btn btn--primary" data-open="#tpl-agency" data-title="<?= e(t('agencies.new')) ?>"><?= icon('plus') ?> <?= e(t('agencies.new')) ?></button><?php endif ?>
        </div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table table--compact">
        <thead><tr>
            <th><?= e(t('agencies.col.agency')) ?></th>
            <th><?= e(t('agencies.col.approval')) ?></th>
            <th><?= e(t('agencies.col.contact')) ?></th>
            <th><?= e(t('agencies.col.posts')) ?></th>
            <th class="num"><?= e(t('agencies.col.open')) ?></th>
            <th class="num"><?= e(t('agencies.col.deliveries')) ?></th>
            <th class="num"><?= e(t('agencies.col.users')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <?php if ($canManage): ?><th class="actions-col" data-orderable="false"><span class="sr-only"><?= e(t('common.actions')) ?></span></th><?php endif ?>
        </tr></thead>
        <tbody>
        <?php foreach ($agencies as $a): $aid = (int) $a['id']; ?>
            <tr class="row-link<?= $a['active'] ? '' : ' is-muted' ?>" tabindex="0" data-href="<?= e(url('/customs-agencies/' . $aid)) ?>">
                <td><strong><?= e($a['name']) ?></strong><br><span class="muted mono"><?= e($a['code']) ?></span><?= $a['city'] ? ' <span class="muted">· ' . e($a['city']) . '</span>' : '' ?></td>
                <td class="mono"><?= e($a['approval_number'] ?: '—') ?></td>
                <td class="muted"><?= e(implode(' · ', array_filter([$a['contact_name'], $a['phone'], $a['email']])) ?: '—') ?></td>
                <td class="muted"><?= e($a['customs_posts'] ?: '—') ?></td>
                <td class="num" data-order="<?= (int) $a['open_count'] ?>"><?= (int) $a['open_count'] ?: '<span class="muted">—</span>' ?><?= (int) $a['missing_ref'] ? ' <span class="chip chip--warn" title="' . e(t('agencies.missing_ref_title')) . '">' . (int) $a['missing_ref'] . ' ' . e(t('agencies.no_ref')) . '</span>' : '' ?></td>
                <td class="num"><?= (int) $a['delivery_count'] ?: '<span class="muted">—</span>' ?></td>
                <td class="num"><?= (int) $a['user_count'] ?: '<span class="muted">—</span>' ?></td>
                <td><span class="badge badge--<?= $a['active'] ? 'available' : 'immobilized' ?>"><?= e(t($a['active'] ? 'common.active' : 'common.inactive')) ?></span></td>
                <?php if ($canManage): ?>
                <td class="actions-col">
                    <button type="button" class="icon-btn" data-open="#tpl-agency-<?= $aid ?>" data-title="<?= e(t('common.edit') . ' — ' . $a['name']) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
                    <template id="tpl-agency-<?= $aid ?>"><?= View::partial('customs_agencies/_form', ['a' => $a, 'back' => 'list']) ?></template>
                </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
<?php if ($canManage): ?><template id="tpl-agency"><?= View::partial('customs_agencies/_form', ['a' => null]) ?></template><?php endif ?>
</div>
