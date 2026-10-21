<?php
/**
 * Check-list documentaire (expédition, dossier ou portail transitaire).
 * @var list<array<string,mixed>> $docs
 * @var string $back   retour après action : 'delivery:ID' | 'order' | ''
 * @var bool|null $readonly dossier clos / annulé
 */
use App\Domain\Checklist;
use App\Security\Auth;

$user = Auth::require();
$readonly ??= false;
$today = date('Y-m-d');
$canUpload = !$readonly && $user->can('documents.upload');
$canValidate = !$readonly && $user->can('documents.validate');
$canWaive = !$readonly && $user->can('documents.waive');
$hidden = fn () => csrf_field() . '<input type="hidden" name="back" value="' . e($back) . '">';
?>
<?php if (!$docs): ?>
    <div class="empty-state empty-state--ok"><?= icon('file-check-2') ?><p><?= e(t('documents.none_required')) ?></p></div>
<?php else: ?>
<ul class="checklist-docs">
<?php foreach ($docs as $doc):
    $id = (int) $doc['id'];
    $expired = $doc['status'] === 'validated' && Checklist::isExpired($doc, $today);
    $state = $expired ? 'expired' : $doc['status'];
    $soon = $doc['status'] === 'validated' && !$expired && $doc['expires_at'] && $doc['expires_at'] <= date('Y-m-d', strtotime('+30 days'));
    // Le déposant ne valide pas son propre document, sauf l'administrateur (auto-validation tracée).
    $mine = (int) $doc['submitted_by'] === $user->id && !\App\Services\ChecklistService::canSelfValidate($user);
    $selfValidation = (int) $doc['submitted_by'] === $user->id && !$mine; ?>
    <li class="checklist-doc checklist-doc--<?= e($state) ?>">
        <div class="checklist-doc__main">
            <div class="checklist-doc__title">
                <strong><?= e($doc['name']) ?></strong>
                <span class="muted mono"><?= e($doc['code']) ?></span>
                <?= \App\Core\View::partial('partials/status_badge', ['key' => 'documents.status.' . $state, 'status' => 'doc_' . $state]) ?>
            </div>
            <p class="checklist-doc__tags">
                <span class="chip"><?= e(t('documents.level.' . ($doc['delivery_id'] ? 'delivery' : 'order'))) ?></span>
                <span class="chip chip--c-gray"><?= icon('user-round') ?> <?= e(t('documents.provider.' . $doc['provider'])) ?></span>
                <?php if (!$doc['required']): ?>
                    <span class="chip chip--c-gray"><?= e(t('documents.optional')) ?></span>
                <?php elseif ($doc['blocking_stage']): ?>
                    <span class="chip chip--warn"><?= icon('lock') ?> <?= e(t('documents.blocks', ['stage' => t('documents.stage.' . $doc['blocking_stage'])])) ?></span>
                <?php endif ?>
            </p>
            <?php if ($doc['number'] || $doc['attachment_id'] || $doc['issued_at'] || $doc['expires_at']): ?>
            <p class="checklist-doc__facts">
                <?php if ($doc['number']): ?><span>N° <strong class="mono"><?= e($doc['number']) ?></strong></span><?php endif ?>
                <?php if ($doc['issued_at']): ?><span><?= e(t('documents.issued_at')) ?> <?= e(fmt_date($doc['issued_at'])) ?></span><?php endif ?>
                <?php if ($doc['expires_at']): ?><span class="<?= $expired ? 'text-bad' : ($soon ? 'text-warn' : '') ?>"><?= e(t('documents.expires_at')) ?> <?= e(fmt_date($doc['expires_at'])) ?></span><?php endif ?>
                <?php if ($doc['attachment_id']): ?><a<?= preview_attrs(url('/shipment-documents/' . $id . '/file'), $doc['name'] . ($doc['number'] ? ' — ' . $doc['number'] : ''), $doc['file_mime']) ?>><?= icon('paperclip') ?> <?= e($doc['file_name']) ?></a><?php endif ?>
            </p>
            <?php endif ?>
            <p class="checklist-doc__trail muted">
                <?php if ($doc['submitted_at']): ?><?= e(t('documents.trail.submitted', ['by' => $doc['submitted_by_name'], 'at' => fmt_date($doc['submitted_at'], true)])) ?><?php endif ?>
                <?php if ($doc['decided_at']): ?> · <?= e(t('documents.trail.' . $doc['status'], ['by' => $doc['decided_by_name'], 'at' => fmt_date($doc['decided_at'], true)])) ?><?php endif ?>
                <?php if ($doc['decision_note']): ?> — « <?= e($doc['decision_note']) ?> »<?php endif ?>
            </p>
        </div>
        <div class="checklist-doc__actions">
            <?php if ($canUpload && in_array($doc['status'], ['pending', 'rejected', 'submitted'], true)): ?>
                <?php if (Checklist::needsAgency($doc)): ?>
                    <?php // Document du transitaire : l'agence en douane doit d'abord être désignée ?>
                    <a class="agency-lock" href="<?= e($doc['delivery_id'] !== null ? url('/deliveries/' . (int) $doc['delivery_id']) . '#transport' : url('/orders/' . (int) $doc['order_id']) . '#deliveries') ?>"
                       title="<?= e(t($doc['delivery_id'] !== null ? 'documents.error.agency_required' : 'documents.error.agency_required_order')) ?>"><?= icon('lock') ?> <?= e(t('documents.agency_first')) ?></a>
                <?php else: ?>
                    <button type="button" class="btn btn--sm<?= $doc['status'] === 'submitted' ? '' : ' btn--primary' ?>" data-open="#tpl-doc-submit-<?= $id ?>" data-title="<?= e($doc['name']) ?>"><?= icon('upload') ?> <?= e(t($doc['status'] === 'submitted' ? 'documents.replace' : 'documents.submit')) ?></button>
                <?php endif ?>
            <?php endif ?>
            <?php if ($canValidate && $doc['status'] === 'submitted'): ?>
                <?php if ($mine): ?>
                    <span class="hint" title="<?= e(t('documents.error.same_user')) ?>"><?= icon('user-check') ?> <?= e(t('documents.other_validator')) ?></span>
                <?php else: ?>
                    <form method="post" action="<?= e(url('/shipment-documents/' . $id . '/validate')) ?>" class="inline" data-confirm="<?= e(t($selfValidation ? 'documents.self_validate_confirm' : 'documents.validate_confirm', ['doc' => $doc['code']])) ?>">
                        <?= $hidden() ?>
                        <button type="submit" class="btn btn--sm btn--primary"><?= icon('check-check') ?> <?= e(t('documents.validate')) ?></button>
                    </form>
                <?php endif ?>
            <?php endif ?>
            <?php if (($canValidate && in_array($doc['status'], ['submitted', 'validated'], true)) || ($canWaive && !in_array($doc['status'], ['validated', 'waived', 'not_applicable'], true)) || ($canWaive && in_array($doc['status'], ['validated', 'waived', 'not_applicable'], true))): ?>
            <div class="dropdown">
                <button type="button" class="icon-btn" data-menu-toggle aria-haspopup="true" aria-expanded="false" title="<?= e(t('common.more')) ?>" aria-label="<?= e(t('common.more')) ?>"><?= icon('ellipsis') ?></button>
                <div class="menu" data-menu role="menu" hidden>
                    <?php if ($canValidate && in_array($doc['status'], ['submitted', 'validated'], true)): ?>
                        <button type="button" class="menu__item" role="menuitem" data-open="#tpl-doc-reject-<?= $id ?>" data-title="<?= e(t('documents.reject') . ' — ' . $doc['code']) ?>"><?= icon('x-circle') ?> <?= e(t('documents.reject')) ?></button>
                    <?php endif ?>
                    <?php if ($canWaive && !in_array($doc['status'], ['validated', 'waived', 'not_applicable'], true)): ?>
                        <button type="button" class="menu__item" role="menuitem" data-open="#tpl-doc-waive-<?= $id ?>" data-title="<?= e(t('documents.waive') . ' — ' . $doc['code']) ?>"><?= icon('shield-alert') ?> <?= e(t('documents.waive')) ?></button>
                    <?php endif ?>
                    <?php if ($canWaive && in_array($doc['status'], ['validated', 'waived', 'not_applicable'], true)): ?>
                        <button type="button" class="menu__item" role="menuitem" data-open="#tpl-doc-reset-<?= $id ?>" data-title="<?= e(t('documents.reset') . ' — ' . $doc['code']) ?>"><?= icon('rotate-ccw') ?> <?= e(t('documents.reset')) ?></button>
                    <?php endif ?>
                </div>
            </div>
            <?php endif ?>
        </div>

        <?php if ($canUpload): ?>
        <template id="tpl-doc-submit-<?= $id ?>">
            <form method="post" action="<?= e(url('/shipment-documents/' . $id . '/submit')) ?>" enctype="multipart/form-data" class="form modal-form">
                <?= $hidden() ?>
                <p class="muted"><?= e(t('documents.submit_hint')) ?></p>
                <div class="form-grid form-grid--modal">
                    <div class="field"><label<?= $doc['requires_number'] ? ' class="required"' : '' ?>><?= e(t('documents.number')) ?></label><input name="number" maxlength="80" class="mono" value="<?= e($doc['number'] ?? '') ?>" <?= $doc['requires_number'] ? 'required' : '' ?>></div>
                    <div class="field"><label<?= $doc['requires_issue_date'] ? ' class="required"' : '' ?>><?= e(t('documents.issued_at')) ?></label><input name="issued_at" type="date" max="<?= e($today) ?>" value="<?= e($doc['issued_at'] ?? '') ?>" <?= $doc['requires_issue_date'] ? 'required' : '' ?>></div>
                    <?php if ($doc['has_expiry']): ?>
                        <div class="field"><label class="required"><?= e(t('documents.expires_at')) ?></label><input name="expires_at" type="date" required value="<?= e($doc['expires_at'] ?? '') ?>"></div>
                    <?php endif ?>
                </div>
                <div class="field">
                    <label<?= $doc['requires_file'] && !$doc['attachment_id'] ? ' class="required"' : '' ?>><?= e(t('attachment.file')) ?></label>
                    <input name="file" type="file" accept="application/pdf,image/jpeg,image/png,image/webp" <?= $doc['requires_file'] && !$doc['attachment_id'] ? 'required' : '' ?>>
                    <p class="hint"><?= e($doc['attachment_id'] ? t('documents.keep_file', ['name' => $doc['file_name']]) : t('attachment.hint')) ?></p>
                </div>
            </form>
        </template>
        <?php endif ?>
        <?php if ($canValidate): ?>
        <template id="tpl-doc-reject-<?= $id ?>">
            <form method="post" action="<?= e(url('/shipment-documents/' . $id . '/reject')) ?>" class="form modal-form" data-confirm-variant="danger">
                <?= $hidden() ?>
                <div class="field"><label class="required"><?= e(t('documents.reject_reason')) ?></label><textarea name="note" rows="2" required maxlength="500" placeholder="<?= e(t('documents.reject_placeholder')) ?>"></textarea></div>
            </form>
        </template>
        <?php endif ?>
        <?php if ($canWaive): ?>
        <template id="tpl-doc-waive-<?= $id ?>">
            <form method="post" action="<?= e(url('/shipment-documents/' . $id . '/waive')) ?>" class="form modal-form" data-confirm-variant="danger">
                <?= $hidden() ?>
                <div class="choice-cards">
                    <label class="choice-card"><input type="radio" name="kind" value="waived" checked><span class="choice-card__body"><?= icon('shield-alert') ?><strong><?= e(t('documents.status.waived')) ?></strong><span class="muted"><?= e(t('documents.waived_hint')) ?></span></span></label>
                    <label class="choice-card"><input type="radio" name="kind" value="not_applicable"><span class="choice-card__body"><?= icon('circle-slash') ?><strong><?= e(t('documents.status.not_applicable')) ?></strong><span class="muted"><?= e(t('documents.na_hint')) ?></span></span></label>
                </div>
                <div class="field"><label class="required"><?= e(t('common.reason')) ?></label><textarea name="note" rows="2" required maxlength="500"></textarea><p class="hint"><?= e(t('documents.waive_trace')) ?></p></div>
            </form>
        </template>
        <template id="tpl-doc-reset-<?= $id ?>">
            <form method="post" action="<?= e(url('/shipment-documents/' . $id . '/reset')) ?>" class="form modal-form" data-confirm-variant="danger">
                <?= $hidden() ?>
                <p class="muted"><?= e(t('documents.reset_hint')) ?></p>
                <div class="field"><label><?= e(t('common.reason')) ?></label><textarea name="note" rows="2" maxlength="500"></textarea></div>
            </form>
        </template>
        <?php endif ?>
    </li>
<?php endforeach ?>
</ul>
<?php endif ?>
