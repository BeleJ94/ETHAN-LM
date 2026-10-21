<?php
/**
 * Règle de check-list : critères, exigence en un seul choix, résumé en clair et alerte de chevauchement en direct.
 * @var array|null $r @var array $types @var array $families
 */
use App\Domain\Checklist;

$req = $r ? Checklist::requirement($r) : 'blocking';
$uid = $r ? 'r' . (int) $r['id'] : 'new';
?>
<form method="post" action="<?= e(url($r ? '/documents/rules/' . (int) $r['id'] : '/documents/rules')) ?>" class="form modal-form rule-form" data-rule-form data-rule-id="<?= $r ? (int) $r['id'] : '' ?>">
    <?= csrf_field() ?>
    <div class="field"><label class="required" for="doc-<?= $uid ?>"><?= e(t('documents.col.document')) ?></label>
        <select id="doc-<?= $uid ?>" name="document_type_id" required data-rule-doc>
            <option value=""><?= e(t('common.choose')) ?></option>
            <?php foreach ($types as $tp): if (!$tp['active'] && (int) ($r['document_type_id'] ?? 0) !== (int) $tp['id']) { continue; } ?>
                <option value="<?= (int) $tp['id'] ?>" <?= (int) ($r['document_type_id'] ?? 0) === (int) $tp['id'] ? 'selected' : '' ?>><?= e($tp['code'] . ' — ' . $tp['name']) ?></option>
            <?php endforeach ?>
        </select></div>

    <fieldset class="rule-form__group">
        <legend><?= e(t('documents.criteria')) ?> <span class="muted"><?= e(t('documents.criteria_hint')) ?></span></legend>
        <div class="form-grid form-grid--modal rule-form__criteria">
            <div class="field"><label for="mode-<?= $uid ?>"><?= e(t('orders.mode')) ?></label>
                <select id="mode-<?= $uid ?>" name="fulfillment_mode" data-rule-crit="mode">
                    <option value=""><?= e(t('documents.any')) ?></option>
                    <?php foreach (Checklist::MODES as $m): ?><option value="<?= e($m) ?>" <?= ($r['fulfillment_mode'] ?? '') === $m ? 'selected' : '' ?>><?= e(t('order.mode.' . $m)) ?></option><?php endforeach ?>
                </select></div>
            <div class="field"><label for="fam-<?= $uid ?>"><?= e(t('item.family')) ?></label>
                <select id="fam-<?= $uid ?>" name="family_id" data-rule-crit="family">
                    <option value=""><?= e(t('documents.any')) ?></option>
                    <?php foreach ($families as $f): ?><option value="<?= (int) $f['id'] ?>" <?= (int) ($r['family_id'] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option><?php endforeach ?>
                </select></div>
            <div class="field"><label for="tr-<?= $uid ?>"><?= e(t('transport.mode')) ?></label>
                <select id="tr-<?= $uid ?>" name="transport_mode" data-rule-crit="transport">
                    <option value=""><?= e(t('documents.any')) ?></option>
                    <?php foreach (Checklist::TRANSPORTS as $tm): ?><option value="<?= e($tm) ?>" <?= ($r['transport_mode'] ?? '') === $tm ? 'selected' : '' ?>><?= e(t('transport.' . $tm)) ?></option><?php endforeach ?>
                </select></div>
        </div>
    </fieldset>

    <fieldset class="rule-form__group">
        <legend><?= e(t('documents.col.requirement')) ?></legend>
        <div class="req-choice" role="radiogroup">
            <?php foreach (['optional' => 'file', 'required' => 'file-check', 'blocking' => 'lock'] as $k => $ic): ?>
                <label class="req-choice__item">
                    <input type="radio" name="requirement" value="<?= $k ?>" <?= $req === $k ? 'checked' : '' ?> data-rule-req>
                    <span><?= icon($ic) ?> <strong><?= e(t('documents.req.' . $k)) ?></strong><small><?= e(t('documents.req.' . $k . '_hint')) ?></small></span>
                </label>
            <?php endforeach ?>
        </div>
        <div class="field" data-rule-stage <?= $req === 'blocking' ? '' : 'hidden' ?>><label for="stage-<?= $uid ?>" class="required"><?= e(t('documents.col.blocking')) ?></label>
            <select id="stage-<?= $uid ?>" name="blocking_stage" <?= $req === 'blocking' ? 'required' : '' ?>>
                <?php foreach (Checklist::STAGES as $s): ?><option value="<?= e($s) ?>" <?= ($r['blocking_stage'] ?? 'departure') === $s ? 'selected' : '' ?>><?= e(t('documents.stage.' . $s)) ?></option><?php endforeach ?>
            </select></div>
    </fieldset>

    <p class="rule-summary" data-rule-summary aria-live="polite"></p>
    <div class="rule-overlaps" data-rule-overlaps aria-live="polite" hidden></div>

    <?php if ($r): ?><label class="switch"><input type="checkbox" name="active" value="1" <?= $r['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label><?php endif ?>
    <div class="field"><label for="notes-<?= $uid ?>"><?= e(t('movements.note')) ?></label><input id="notes-<?= $uid ?>" name="notes" maxlength="255" value="<?= e($r['notes'] ?? '') ?>"></div>
    <p class="hint"><?= e(t('documents.frozen_hint')) ?></p>
</form>
