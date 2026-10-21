<?php
/**
 * Champs du rapport d'inspection et de la décision AV / ARA (formulaire et modale).
 * Le n° du rapport et le n° d'AV / ARA reprennent par défaut la référence de l'inspection.
 * @var array|null $i @var bool|null $allowPending @var ?string $defaultNumber @var ?string $defaultReport @var bool|null $withFile
 */
$i ??= null;
$allowPending ??= false;
$withFile ??= true;
$defaultReport ??= $i['reference'] ?? null;
// N° d'AV / ARA proposé : celui de l'inspection quand il est numéroté sur le COD (« COD … / 0001 »)
$defaultNumber ??= ($i && str_starts_with((string) ($i['reference'] ?? ''), 'COD ') ? $i['reference'] : null);
$dec = old('decision', $i['decision'] ?? ($allowPending ? 'pending' : 'av'));
if (!$allowPending && $dec === 'pending') {
    $dec = 'av';
}
?>
<div class="form-grid form-grid--modal">
    <div class="field"><label><?= e(t('imports.report_number')) ?></label><input name="report_number" maxlength="80" class="mono" value="<?= e(old('report_number', $i['report_number'] ?? $defaultReport ?? '')) ?>" data-report-number></div>
    <div class="field"><label><?= e(t('imports.report_date')) ?></label><input name="report_date" type="date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('report_date', $i['report_date'] ?? '')) ?>"></div>
</div>
<div class="choice-cards<?= $allowPending ? ' choice-cards--3' : '' ?>" role="radiogroup" data-decision-cards>
    <?php if ($allowPending): ?>
    <label class="choice-card"><input type="radio" name="decision" value="pending" <?= $dec === 'pending' ? 'checked' : '' ?>><span class="choice-card__body"><?= icon('hourglass') ?><strong><?= e(t('imports.decision.pending')) ?></strong><span class="muted"><?= e(t('imports.decision.pending_hint')) ?></span></span></label>
    <?php endif ?>
    <label class="choice-card choice-card--ok"><input type="radio" name="decision" value="av" <?= $dec === 'av' ? 'checked' : '' ?>><span class="choice-card__body"><?= icon('badge-check') ?><strong><?= e(t('imports.decision.av')) ?></strong><span class="muted"><?= e(t('imports.decision.av_hint')) ?></span></span></label>
    <label class="choice-card choice-card--bad"><input type="radio" name="decision" value="ara" <?= $dec === 'ara' ? 'checked' : '' ?>><span class="choice-card__body"><?= icon('octagon-x') ?><strong><?= e(t('imports.decision.ara')) ?></strong><span class="muted"><?= e(t('imports.decision.ara_hint')) ?></span></span></label>
</div>
<div class="form-grid form-grid--modal" data-decision-fields>
    <div class="field"><label class="required"><?= e(t('imports.decision_number')) ?></label><input name="decision_number" maxlength="80" class="mono" value="<?= e(old('decision_number', $i['decision_number'] ?? $defaultNumber ?? '')) ?>"></div>
    <div class="field"><label class="required"><?= e(t('imports.decision_date')) ?></label><input name="decision_date" type="date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('decision_date', $i['decision_date'] ?? date('Y-m-d'))) ?>"></div>
</div>
<div class="field" data-ara-reason><label class="required"><?= e(t('imports.ara_reason')) ?></label><textarea name="reason" rows="2" maxlength="500" placeholder="<?= e(t('imports.ara_reason_placeholder')) ?>"><?= e(old('reason', $i['reason'] ?? '')) ?></textarea></div>
<?php if ($withFile): ?><div class="field"><label><?= e(t('imports.report_file')) ?></label><input name="file" type="file" accept="application/pdf,image/jpeg,image/png,image/webp"><p class="hint"><?= e(t('attachment.hint')) ?></p></div><?php endif ?>
