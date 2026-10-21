<?php
/** @var array $types @var array $rules @var array $families @var array $overlaps @var array $sim @var string $tab */
use App\Core\View;
use App\Domain\Checklist;
use App\Repositories\DocumentRepository;

$tab = $tab === 'types' ? 'types' : 'rules';
$actions = $tab === 'rules'
    ? '<button type="button" class="btn btn--primary" data-open="#tpl-rule" data-title="' . e(t('documents.rule_new')) . '">' . icon('plus') . ' ' . e(t('documents.rule_new')) . '</button>'
    : '<button type="button" class="btn btn--primary" data-open="#tpl-type" data-title="' . e(t('documents.type_new')) . '">' . icon('plus') . ' ' . e(t('documents.type_new')) . '</button>';
echo '<div data-live-region="documents-settings">';
echo View::partial('partials/page_header', ['icon' => 'file-check-2', 'heading' => t('nav.documents_settings'), 'meta' => t('documents.settings_meta'), 'actions' => $actions]);
$any = fn (?string $v, string $prefix) => $v === null || $v === '' ? '<span class="muted">' . e(t('documents.any')) . '</span>' : e(t($prefix . $v));
?>
<nav class="segmented" aria-label="<?= e(t('nav.documents_settings')) ?>">
    <a class="segmented__item" href="<?= e(url('/documents/settings')) ?>" <?= $tab === 'rules' ? 'aria-current="page"' : '' ?>><?= icon('list-checks') ?> <?= e(t('documents.tab.rules')) ?> (<?= count($rules) ?>)</a>
    <a class="segmented__item" href="<?= e(url('/documents/settings', ['tab' => 'types'])) ?>" <?= $tab === 'types' ? 'aria-current="page"' : '' ?>><?= icon('files') ?> <?= e(t('documents.tab.types')) ?> (<?= count($types) ?>)</a>
</nav>

<?php if ($tab === 'rules'): ?>
<section class="card sim" aria-labelledby="sim-title">
    <div class="card__title"><h2 id="sim-title"><?= icon('flask-conical', 'title-icon') ?> <?= e(t('documents.sim.title')) ?></h2></div>
    <p class="muted"><?= e(t('documents.sim.hint')) ?></p>
    <form method="get" action="<?= e(url('/documents/settings')) ?>" class="sim-form" data-no-ajax data-sim-form data-url="<?= e(url('/documents/settings/simulate')) ?>">
        <div class="field"><label for="sim-mode"><?= e(t('orders.mode')) ?></label>
            <select id="sim-mode" name="sim_mode">
                <?php foreach (Checklist::MODES as $m): ?><option value="<?= e($m) ?>" <?= $sim['mode'] === $m ? 'selected' : '' ?>><?= e(t('order.mode.' . $m)) ?></option><?php endforeach ?>
            </select></div>
        <div class="field"><label for="sim-family"><?= e(t('item.family')) ?></label>
            <select id="sim-family" name="sim_family">
                <option value=""><?= e(t('documents.sim.no_family')) ?></option>
                <?php foreach ($families as $f): ?><option value="<?= (int) $f['id'] ?>" <?= $sim['family_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option><?php endforeach ?>
            </select></div>
        <div class="field"><label for="sim-transport"><?= e(t('transport.mode')) ?></label>
            <select id="sim-transport" name="sim_transport">
                <option value=""><?= e(t('documents.sim.no_transport')) ?></option>
                <?php foreach (Checklist::TRANSPORTS as $tm): ?><option value="<?= e($tm) ?>" <?= $sim['transport'] === $tm ? 'selected' : '' ?>><?= e(t('transport.' . $tm)) ?></option><?php endforeach ?>
            </select></div>
        <noscript><button type="submit" class="btn btn--sm"><?= e(t('documents.sim.run')) ?></button></noscript>
    </form>
    <div data-sim-target><?= View::partial('documents/_simulation', ['sim' => $sim]) ?></div>
</section>

<section class="card" data-bulk>
    <p class="muted"><?= e(t('documents.rules_hint')) ?></p>
    <form method="post" action="<?= e(url('/documents/rules/bulk-delete')) ?>" id="bulk-rules" class="bulk-bar" data-bulk-bar hidden
          data-confirm="<?= e(t('documents.bulk_confirm')) ?>" data-confirm-template="<?= e(t('documents.bulk_confirm')) ?>" data-confirm-variant="danger">
        <?= csrf_field() ?>
        <span class="bulk-bar__count" data-bulk-count aria-live="polite"></span>
        <button type="button" class="btn btn--ghost btn--sm" data-bulk-clear><?= icon('x') ?> <?= e(t('documents.bulk_clear')) ?></button>
        <button type="submit" class="btn btn--danger btn--sm"><?= icon('trash-2') ?> <?= e(t('documents.bulk_delete')) ?></button>
    </form>
    <div class="table-wrap">
    <table class="table" data-no-dt>
        <thead><tr>
            <th class="check-col"><input type="checkbox" data-bulk-all aria-label="<?= e(t('documents.bulk_select_all')) ?>" title="<?= e(t('documents.bulk_select_all')) ?>"></th>
            <th><?= e(t('documents.col.document')) ?></th>
            <th><?= e(t('orders.mode')) ?></th>
            <th><?= e(t('item.family')) ?></th>
            <th><?= e(t('transport.mode')) ?></th>
            <th><?= e(t('documents.col.requirement')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th class="actions-col"><span class="sr-only"><?= e(t('common.actions')) ?></span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rules as $r): $rid = (int) $r['id']; ?>
            <tr class="<?= $r['active'] ? '' : 'is-muted' ?>">
                <td class="check-col"><input type="checkbox" name="ids[]" value="<?= $rid ?>" form="bulk-rules" data-bulk-item aria-label="<?= e(t('documents.bulk_select', ['rule' => '#' . $rid . ' ' . $r['type_code']])) ?>"></td>
                <td><strong><?= e($r['type_name']) ?></strong><br><span class="muted mono"><?= e($r['type_code']) ?></span> <span class="chip"><?= e(t('documents.level.' . $r['level'])) ?></span>
                    <?php $ov = $overlaps[$rid] ?? []; if ($ov): ?>
                        <span class="chip chip--warn" title="<?= e(t('documents.overlap_title', ['ids' => implode(', ', array_map(fn ($o) => '#' . $o['id'], $ov))])) ?>"><?= icon('layers') ?> <?= e(t('documents.overlap_chip', ['n' => count($ov)])) ?></span>
                    <?php endif ?></td>
                <td><?= $any($r['fulfillment_mode'], 'order.mode.') ?></td>
                <td><?= $r['family_name'] ? e($r['family_name']) : '<span class="muted">' . e(t('documents.any')) . '</span>' ?></td>
                <td><?= $any($r['transport_mode'], 'transport.') ?></td>
                <td><?php $lv = Checklist::requirement($r); ?><?= $lv === 'blocking' ? '<span class="chip chip--warn">' . icon('lock') . ' ' . e(t('documents.blocks', ['stage' => t('documents.stage.' . $r['blocking_stage'])])) . '</span>' : ($lv === 'required' ? e(t('documents.req.required')) : '<span class="muted">' . e(t('documents.req.optional')) . '</span>') ?></td>
                <td><span class="badge badge--<?= $r['active'] ? 'available' : 'immobilized' ?>"><?= e(t($r['active'] ? 'common.active' : 'common.inactive')) ?></span></td>
                <td class="actions-col">
                    <button type="button" class="icon-btn" data-open="#tpl-rule-<?= $rid ?>" data-title="<?= e(t('common.edit')) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
                    <form method="post" action="<?= e(url("/documents/rules/$rid/delete")) ?>" class="inline" data-confirm="<?= e(t('documents.rule_delete_confirm')) ?>" data-confirm-variant="danger">
                        <?= csrf_field() ?>
                        <button type="submit" class="icon-btn icon-btn--danger" title="<?= e(t('common.delete')) ?>" aria-label="<?= e(t('common.delete')) ?>"><?= icon('trash-2') ?></button>
                    </form>
                    <template id="tpl-rule-<?= $rid ?>"><?= View::partial('documents/_rule_form', ['r' => $r, 'types' => $types, 'families' => $families]) ?></template>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
</section>
<template id="tpl-rule"><?= View::partial('documents/_rule_form', ['r' => null, 'types' => $types, 'families' => $families]) ?></template>
<?= json_script('doc-rules', array_values(array_map(fn ($r) => [
    'id' => (int) $r['id'], 'type' => (int) $r['document_type_id'], 'mode' => $r['fulfillment_mode'], 'family' => $r['family_id'] !== null ? (string) $r['family_id'] : null,
    'transport' => $r['transport_mode'], 'active' => (bool) $r['active'],
    'label' => '#' . $r['id'] . ' · ' . ($r['fulfillment_mode'] ? t('order.mode.' . $r['fulfillment_mode']) : t('documents.any')) . ' / ' . ($r['family_name'] ?: t('documents.any')) . ' / ' . ($r['transport_mode'] ? t('transport.' . $r['transport_mode']) : t('documents.any'))
        . ' → ' . t('documents.req.' . Checklist::requirement($r)) . ($r['blocking_stage'] && $r['required'] ? ' (' . t('documents.stage.' . $r['blocking_stage']) . ')' : ''),
], $rules))) ?>
<?= json_script('rule-form-i18n', [
    'sentence' => t('documents.sentence'),
    'mode_any' => t('documents.sentence_mode_any'), 'mode' => t('documents.sentence_mode'),
    'family_any' => t('documents.sentence_family_any'), 'family' => t('documents.sentence_family'),
    'transport_any' => t('documents.sentence_transport_any'), 'transport' => t('documents.sentence_transport'),
    'req_optional' => t('documents.sentence_req_optional'), 'req_required' => t('documents.sentence_req_required'), 'req_blocking' => t('documents.sentence_req_blocking'),
    'doc_none' => t('documents.sentence_doc_none'),
    'duplicate' => t('documents.live_duplicate'), 'overlap' => t('documents.live_overlap'), 'overlap_hint' => t('documents.live_overlap_hint'),
]) ?>

<?php else: ?>
<section class="card">
    <div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th><?= e(t('documents.col.document')) ?></th>
            <th><?= e(t('documents.col.provider')) ?></th>
            <th><?= e(t('documents.col.level')) ?></th>
            <th><?= e(t('documents.col.requires')) ?></th>
            <th class="num"><?= e(t('documents.col.rules')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th class="actions-col"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($types as $tp): $tid = (int) $tp['id']; ?>
            <tr class="<?= $tp['active'] ? '' : 'is-muted' ?>">
                <td><strong><?= e($tp['name']) ?></strong><br><span class="muted mono"><?= e($tp['code']) ?></span></td>
                <td><?= e(t('documents.provider.' . $tp['provider'])) ?></td>
                <td><span class="chip"><?= e(t('documents.level.' . $tp['level'])) ?></span></td>
                <td class="muted"><?= e(implode(' · ', array_filter([$tp['requires_number'] ? t('documents.number') : null, $tp['requires_file'] ? t('attachment.file') : null, $tp['requires_issue_date'] ? t('documents.issued_at') : null, $tp['has_expiry'] ? t('documents.expires_at') : null]))) ?: '—' ?></td>
                <td class="num"><?= (int) $tp['rule_count'] ?></td>
                <td><span class="badge badge--<?= $tp['active'] ? 'available' : 'immobilized' ?>"><?= e(t($tp['active'] ? 'common.active' : 'common.inactive')) ?></span></td>
                <td class="actions-col">
                    <button type="button" class="icon-btn" data-open="#tpl-type-<?= $tid ?>" data-title="<?= e(t('common.edit') . ' — ' . $tp['code']) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
                    <template id="tpl-type-<?= $tid ?>"><?= View::partial('documents/_type_form', ['tp' => $tp]) ?></template>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
</section>
<template id="tpl-type"><?= View::partial('documents/_type_form', ['tp' => null]) ?></template>
<?php endif ?>
</div>
