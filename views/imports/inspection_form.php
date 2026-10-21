<?php
/**
 * Inspection BIVAC (pour l'OCC) d'un PO fournisseur : partielle possible, rapport et décision AV / ARA.
 * Colonne principale : inspection → quantités → rapport et décision → fichier du rapport.
 * Colonne latérale : licence (COD, validité, valeur), marchandises du PO (codes SH), aperçu du rapport.
 * Le n° du rapport reprend la référence de l'inspection (modifiable).
 * @var ?string $codRef @var array $po @var array $licence @var array $lines @var array $summary @var array|null $regularizes @var array $regularizesLines
 */
use App\Core\Session;
use App\Core\View;
use App\Domain\ImportGate;
use App\Services\ImportService;
use App\Services\ReferenceGenerator;

$old = Session::getFlash('_old', []);
$oldLines = (array) ($old['lines'] ?? []);
$fromAra = [];
foreach ($regularizesLines as $rl) {
    $fromAra[(int) $rl['po_line_id']] = (float) $rl['quantity_inspected'];
}
$poId = (int) $po['id'];
$today = date('Y-m-d');
// Référence proposée (COD … / 0001, ou provisoire sans COD) : reprise comme n° de rapport et n° d'AV / ARA
$proposedRef = $codRef ?? ReferenceGenerator::suggest('inspection');
$refValue = old('reference', $proposedRef);
$days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($licence['expires_at']))->format('%r%a');
$costs = array_filter([$licence['freight'] ?? null, $licence['insurance'] ?? null, $licence['other_costs'] ?? null], fn ($v) => $v !== null);
echo View::partial('partials/dossier_bar', ['kind' => 'inspection', 'orderId' => (int) $po['order_id'], 'poId' => (int) $po['id']]);
echo View::partial('partials/page_header', [
    'icon' => 'search-check',
    'heading' => $title,
    'meta' => t('imports.inspection_meta', ['licence' => $licence['number'], 'supplier' => $po['supplier_name']]),
]);
?>
<?php if ($regularizes): ?>
    <div class="alert alert--error"><?= icon('octagon-x') ?> <span><?= e(t('imports.regularize_hint', ['ref' => $regularizes['reference'], 'ara' => $regularizes['decision_number'] ?? ''])) ?><?= $regularizes['reason'] ? ' — « ' . e($regularizes['reason']) . ' »' : '' ?></span></div>
<?php endif ?>

<form method="post" action="<?= e(url("/purchase-orders/$poId/inspections")) ?>" enctype="multipart/form-data" class="licence-layout" data-inspection-form>
    <?= csrf_field() ?>
    <?php if ($regularizes): ?><input type="hidden" name="regularizes_id" value="<?= (int) $regularizes['id'] ?>"><?php endif ?>
    <div class="licence-layout__main card form form--sections">

        <fieldset class="form-section">
            <legend><?= icon('search-check') ?> <?= e(t('imports.section.inspection')) ?></legend>
            <div class="form-grid">
                <div class="field">
                    <label for="reference"><?= e(t('imports.inspection_ref')) ?></label>
                    <input id="reference" name="reference" maxlength="30" class="mono" value="<?= e($refValue) ?>" data-inspection-ref<?= error_attr('reference') ?>>
                    <input type="hidden" name="reference_suggested" value="<?= e($proposedRef) ?>">
                    <p class="hint"><?= e($codRef ? t('imports.cod_ref_hint', ['cod' => $licence['cod_number']]) : t('imports.ref_followers_hint')) ?></p>
                    <?= error_msg('reference') ?>
                </div>
                <div class="field">
                    <label for="inspector" class="required"><?= e(t('imports.inspector')) ?></label>
                    <input id="inspector" name="inspector" maxlength="120" required value="<?= e(old('inspector', ImportService::DEFAULT_INSPECTOR)) ?>">
                </div>
                <div class="field">
                    <label for="inspection_date" class="required"><?= e(t('imports.inspection_date')) ?></label>
                    <input id="inspection_date" name="inspection_date" type="date" required max="<?= e($today) ?>" value="<?= e(old('inspection_date', $today)) ?>"<?= error_attr('inspection_date') ?>>
                    <?= error_msg('inspection_date') ?>
                </div>
                <div class="field">
                    <label for="location"><?= e(t('imports.location')) ?></label>
                    <input id="location" name="location" maxlength="160" value="<?= e(old('location')) ?>" placeholder="<?= e(t('imports.location_placeholder')) ?>">
                </div>
            </div>
            <?php if (!$codRef): ?>
                <p class="hint text-warn"><?= icon('info') ?> <?= e(t('imports.cod_missing_hint')) ?> <a href="<?= e(url('/licences/' . (int) $licence['id'] . '/edit')) ?>"><?= e(t('imports.cod_add')) ?></a></p>
            <?php endif ?>
        </fieldset>

        <fieldset class="form-section">
            <legend><?= icon('scale') ?> <?= e(t('imports.section.items')) ?></legend>
            <?= error_msg('lines') ?>
            <div class="table-wrap">
            <table class="table table--compact insp-lines" data-no-dt>
                <thead><tr>
                    <th><?= e(t('orders.col.product')) ?></th>
                    <th class="num"><?= e(t('po.col.ordered_po')) ?></th>
                    <th class="num"><?= e(t('imports.col.cleared')) ?></th>
                    <th class="num"><?= e(t('imports.col.remaining')) ?></th>
                    <th class="lines__qty"><?= e(t('imports.col.inspected')) ?></th>
                    <th class="lines__qty"><?= e(t('imports.col.conform')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($lines as $l):
                    $lid = (int) $l['id'];
                    $q = (float) $l['quantity'];
                    $cleared = (float) ($summary['cleared'][$lid] ?? 0);
                    $rest = max(0, $q - $cleared);
                    $default = $fromAra[$lid] ?? ''; ?>
                    <tr data-insp-line data-rest="<?= e((string) $rest) ?>">
                        <td><strong><?= e($l['item_name']) ?></strong>
                            <br><span class="muted mono"><?= e($l['sku']) ?></span> <span class="hs<?= $l['hs_code'] ? '' : ' hs--missing' ?>"><?= e($l['hs_code'] ? 'SH ' . $l['hs_code'] : t('imports.hs_missing')) ?></span></td>
                        <td class="num"><?= e(fmt_qty($q)) ?> <?= e($l['unit']) ?></td>
                        <td class="num"><?= $cleared > 0 ? e(fmt_qty($cleared)) : '<span class="muted">—</span>' ?></td>
                        <td class="num<?= $rest > 0 ? ' text-warn' : '' ?>"><?= $rest > 0 ? e(fmt_qty($rest)) : '✓' ?></td>
                        <td><div class="qty-input"><input name="lines[<?= $lid ?>][inspected]" type="number" step="0.001" min="0" max="<?= e((string) $q) ?>" inputmode="decimal" placeholder="0" value="<?= e($oldLines[$lid]['inspected'] ?? (string) $default) ?>" data-inspected aria-label="<?= e(t('imports.col.inspected') . ' ' . $l['sku']) ?>"><span class="qty-input__unit"><?= e($l['unit']) ?></span></div></td>
                        <td><div class="qty-input"><input name="lines[<?= $lid ?>][conform]" type="number" step="0.001" min="0" inputmode="decimal" placeholder="<?= e(t('imports.same_as_inspected')) ?>" value="<?= e($oldLines[$lid]['conform'] ?? '') ?>" data-conform aria-label="<?= e(t('imports.col.conform') . ' ' . $l['sku']) ?>"><span class="qty-input__unit"><?= e($l['unit']) ?></span></div></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
            </div>
            <div class="insp-lines__actions">
                <button type="button" class="btn btn--sm btn--ghost" data-fill-rest><?= icon('arrow-down-to-line') ?> <?= e(t('imports.fill_rest')) ?></button>
                <span class="hint"><?= e(t('imports.partial_hint')) ?></span>
            </div>
        </fieldset>

        <fieldset class="form-section">
            <legend><?= icon('badge-check') ?> <?= e(t('imports.section.decision')) ?></legend>
            <?= View::partial('imports/_decision_fields', ['i' => null, 'allowPending' => !$regularizes, 'defaultNumber' => $refValue, 'defaultReport' => $refValue, 'withFile' => false]) ?>
        </fieldset>

        <fieldset class="form-section">
            <legend><?= icon('paperclip') ?> <?= e(t('imports.section.report_file')) ?></legend>
            <label class="file-drop" data-file-drop>
                <input name="file" type="file" accept="application/pdf,image/jpeg,image/png,image/webp" class="sr-only" data-file-preview-input>
                <?= icon('file-up') ?>
                <span><strong data-file-name><?= e(t('imports.report_choose')) ?></strong><span class="muted"><?= e(t('attachment.hint')) ?></span></span>
            </label>
            <div class="field mt-2"><label for="notes"><?= e(t('movements.note')) ?></label><textarea id="notes" name="notes" rows="2" maxlength="500" data-autogrow><?= e(old('notes')) ?></textarea></div>
        </fieldset>

        <div class="form-actions">
            <a class="link-btn" href="<?= e(url("/purchase-orders/$poId#import")) ?>"><?= e(t('common.cancel')) ?></a>
            <button type="submit" class="btn btn--primary" data-once><?= icon('save') ?> <?= e(t('imports.save_inspection')) ?></button>
        </div>
    </div>

    <aside class="licence-layout__side">
        <section class="card side-card">
            <h2 class="side-card__title"><?= icon('stamp') ?> <?= e(t('imports.licence')) ?></h2>
            <dl class="side-facts">
                <div><dt><?= e(t('imports.col.number')) ?></dt><dd class="mono"><a href="<?= e(url('/licences/' . (int) $licence['id'])) ?>"><?= e($licence['number']) ?></a></dd></div>
                <div><dt><?= e(t('imports.cod_number')) ?></dt><dd><?= $licence['cod_number'] ? '<span class="cod-ref cod-ref--sm mono">' . e($licence['cod_number']) . '</span>' : '<span class="text-warn">' . e(t('imports.cod_none')) . '</span>' ?></dd></div>
                <div><dt><?= e(t('documents.expires_at')) ?></dt><dd class="<?= ImportGate::licenceValid($licence, $today) ? ($days <= 30 ? 'text-warn' : '') : 'text-bad' ?>"><?= e(fmt_date($licence['expires_at'])) ?> · <?= e($days >= 0 ? t('imports.days_left', ['n' => $days]) : t('imports.expired_since', ['n' => -$days])) ?></dd></div>
                <div><dt><?= e(t('imports.cost.fob')) ?></dt><dd><?= $licence['amount'] !== null ? e(fmt_money($licence['amount'], $licence['currency'])) : '—' ?></dd></div>
                <?php if ($licence['amount'] !== null && $costs): ?><div><dt><?= e(t('imports.cost.cif')) ?></dt><dd><?= e(fmt_money((float) $licence['amount'] + array_sum(array_map('floatval', $costs)), $licence['currency'])) ?></dd></div><?php endif ?>
                <?php if ($licence['bank']): ?><div><dt><?= e(t('imports.bank')) ?></dt><dd><?= e($licence['bank']) ?></dd></div><?php endif ?>
                <div><dt><?= e(t('imports.col.cleared')) ?></dt><dd><?= e(fmt_qty($summary['cleared_total'])) ?><?= $summary['open_ara'] ? ' · <span class="text-bad">' . e(t('imports.open_ara', ['n' => (int) $summary['open_ara']])) . '</span>' : '' ?></dd></div>
            </dl>
        </section>

        <section class="card side-card">
            <h2 class="side-card__title"><?= icon('eye') ?> <?= e(t('imports.report_preview')) ?></h2>
            <div class="file-preview" data-file-preview data-existing-src="" data-existing-mime="">
                <p class="muted file-preview__empty" data-file-preview-empty><?= icon('file-search') ?> <?= e(t('imports.report_preview_empty')) ?></p>
            </div>
        </section>
    </aside>
</form>
<?= json_script('file-preview-i18n', ['too_big' => t('licence_import.error.too_big', ['max' => 10]), 'bad_type' => t('imports.file_bad_type')]) ?>
