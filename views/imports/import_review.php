<?php
/**
 * Étapes 2-3-4 : résultat de l'extraction, vérification / correction à côté du document, confirmation explicite.
 * @var array $draft @var array $p proposition (data, lines, status, source, supplier, pos…) @var array $issues @var array $currencies @var array $banks @var array $branches
 */
use App\Controllers\LicenceImportController;
use App\Core\Session;
use App\Core\View;

$token = $draft['token'];
$failed = $draft['error_code'] !== null;
$old = Session::getFlash('_old', []);
$d = $p['data'];
foreach ($d as $k => $v) {
    if (array_key_exists($k, $old)) {
        $d[$k] = $old[$k];
    }
}
$lines = isset($old['lines']) ? array_values((array) $old['lines']) : $p['lines'];
$extracted = $draft['extraction']['fields'] ?? [];
$counts = ['extracted' => 0, 'uncertain' => 0, 'missing' => 0];
foreach (['number', 'issued_at', 'expires_at', 'origin_country', 'currency', 'amount', 'bank', 'po_id'] as $f) {
    $counts[$p['status'][$f] ?? 'missing']++;
}
$chip = function (string $field) use ($p): string {
    $s = $p['status'][$field] ?? 'missing';
    return '<span class="xstate xstate--' . e($s) . '" data-xstate>' . e(t('licence_import.state.' . $s)) . '</span>';
};
$src = function (string $field) use ($p): string {
    $s = $p['source'][$field] ?? null;
    return $s ? '<p class="xsource" title="' . e(t('licence_import.source_hint')) . '">' . icon('quote') . ' « ' . e(mb_substr($s, 0, 140)) . ' »</p>' : '';
};
$field = function (string $name, string $label, string $type = 'text', array $attrs = []) use ($d, $extracted, $chip, $src): string {
    $ex = $extracted[$name]['value'] ?? null;
    $attr = '';
    foreach ($attrs as $k => $v) {
        $attr .= ' ' . $k . '="' . e((string) $v) . '"';
    }
    return '<div class="field xfield" data-xfield="' . e($name) . '">'
        . '<label for="x-' . e($name) . '"' . (in_array($name, ['number', 'issued_at', 'expires_at'], true) ? ' class="required"' : '') . '>' . e($label) . ' ' . $chip($name) . '</label>'
        . '<input id="x-' . e($name) . '" name="' . e($name) . '" type="' . $type . '" value="' . e((string) ($d[$name] ?? '')) . '" data-extracted="' . e((string) ($ex ?? '')) . '"' . $attr . error_attr($name) . '>'
        . $src($name) . error_msg($name) . '</div>';
};
echo View::partial('partials/page_header', [
    'icon' => 'scan-text',
    'heading' => t('licence_import.review_title'),
    'meta' => $draft['original_name'] . ' · ' . t('licence_import.method.' . $draft['method']) . ($draft['page_count'] ? ' · ' . t('licence_import.pages', ['n' => (int) $draft['page_count']]) : ''),
]);
?>
<ol class="import-steps" aria-label="<?= e(t('licence_import.steps')) ?>">
    <li class="is-done"><span>✓</span> <?= e(t('licence_import.step.upload')) ?></li>
    <li class="<?= $failed ? 'is-bad' : 'is-done' ?>"><span><?= $failed ? '!' : '✓' ?></span> <?= e(t('licence_import.step.extract')) ?></li>
    <li class="is-current"><span>3</span> <?= e(t('licence_import.step.review')) ?></li>
    <li><span>4</span> <?= e(t('licence_import.step.confirm')) ?></li>
</ol>

<?php if ($failed): ?>
    <div class="alert alert--error" role="alert"><?= icon('file-x') ?> <span><strong><?= e(t($draft['error_code'])) ?></strong> <?= e(t('licence_import.manual_fallback')) ?></span></div>
<?php else: ?>
    <div class="xsummary" role="status">
        <span class="xstate xstate--extracted"><?= $counts['extracted'] ?> <?= e(t('licence_import.state.extracted')) ?></span>
        <span class="xstate xstate--uncertain"><?= $counts['uncertain'] ?> <?= e(t('licence_import.state.uncertain')) ?></span>
        <span class="xstate xstate--missing"><?= $counts['missing'] ?> <?= e(t('licence_import.state.missing')) ?></span>
        <?php if (!empty($p['model'])): ?><span class="tag"><?= icon('badge-check') ?> <?= e(t('licence_import.model.' . $p['model'])) ?></span><?php endif ?>
        <span class="muted"><?= e(t('licence_import.summary_hint')) ?></span>
    </div>
<?php endif ?>
<?php foreach (array_intersect($p['warnings'], ['validation_several', 'line_amount_computed', 'several_currencies']) as $w): ?>
    <p class="xissue xissue--warning"><?= icon('triangle-alert') ?> <span><?= e(t('licence_import.warning.' . $w)) ?></span></p>
<?php endforeach ?>

<div class="xreview">
<form method="post" action="<?= e(url("/licences/import/$token/confirm")) ?>" class="card form form--sections xreview__form" data-licence-review
      data-save-url="<?= e(url("/licences/import/$token/save")) ?>" data-confirm="<?= e(t('licence_import.confirm_question')) ?>">
    <?= csrf_field() ?>

    <fieldset class="form-section">
        <legend><?= e(t('licence_import.section.partner')) ?></legend>
        <?php $sup = $p['supplier']; ?>
        <p class="xpartner">
            <?= icon('factory') ?> <?= e(t('licence_import.read_supplier')) ?> :
            <strong><?= $p['supplier_name'] ? e($p['supplier_name']) : '<span class="muted">' . e(t('licence_import.state.missing')) . '</span>' ?></strong>
            <?php if ($sup['status'] === 'matched'): ?><span class="xstate xstate--extracted"><?= e(t('licence_import.match.matched')) ?></span>
            <?php elseif ($sup['status'] === 'ambiguous'): ?><span class="xstate xstate--uncertain"><?= e(t('licence_import.match.ambiguous')) ?></span>
            <?php elseif ($p['supplier_name']): ?><span class="xstate xstate--missing"><?= e(t('licence_import.match.none')) ?></span><?php endif ?>
        </p>
        <div class="form-grid">
            <div class="field">
                <label for="x-supplier"><?= e(t('sourcing.col.supplier')) ?></label>
                <select id="x-supplier" data-supplier-filter>
                    <option value=""><?= e(t('licence_import.all_suppliers')) ?></option>
                    <?php foreach ($sup['candidates'] as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $sup['best'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= (int) $c['score'] ?> %)</option>
                    <?php endforeach ?>
                </select>
                <p class="hint"><?= e(t($sup['status'] === 'ambiguous' ? 'licence_import.choose_supplier' : 'licence_import.supplier_hint')) ?></p>
            </div>
            <div class="field xfield" data-xfield="po_id">
                <label for="x-po_id" class="required"><?= e(t('imports.po_supplier')) ?> <?= $chip('po_id') ?></label>
                <select id="x-po_id" name="po_id" required data-po-select<?= error_attr('po_id') ?>>
                    <option value=""><?= e(t('common.choose')) ?></option>
                    <?php foreach ($p['pos'] as $po): ?>
                        <option value="<?= (int) $po['id'] ?>" data-supplier="<?= (int) $po['supplier_id'] ?>" data-currency="<?= e($po['currency']) ?>" data-amount="<?= e(number_format((float) $po['amount'], 2, '.', '')) ?>" <?= (string) ($d['po_id'] ?? '') === (string) $po['id'] ? 'selected' : '' ?>>
                            <?= e($po['reference'] . ' — ' . $po['supplier_name'] . ' · PO client ' . $po['customer_po_number'] . ' · ' . fmt_money($po['amount'], $po['currency'])) ?>
                        </option>
                    <?php endforeach ?>
                </select>
                <?= $src('po_reference') ?>
                <p class="hint"><?= e($p['pos'] ? t('licence_import.po_hint') : t('imports.no_po_waiting')) ?></p>
                <?= error_msg('po_id') ?>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><?= e(t('imports.section.licence')) ?></legend>
        <div class="form-grid">
            <?= $field('number', t('imports.col.number'), 'text', ['maxlength' => 60, 'class' => 'mono', 'required' => 'required']) ?>
            <?= $field('cod_number', t('imports.cod_number'), 'text', ['maxlength' => 30, 'class' => 'mono', 'placeholder' => 'COD 2026 0012']) ?>
            <?= $field('origin_country', t('imports.origin_country'), 'text', ['maxlength' => 80]) ?>
            <?= $field('issued_at', t('documents.issued_at'), 'date', ['required' => 'required']) ?>
            <?= $field('expires_at', t('documents.expires_at'), 'date', ['required' => 'required']) ?>
            <?= $field('amount', t('imports.cost.fob'), 'number', ['step' => '0.01', 'min' => 0, 'inputmode' => 'decimal']) ?>
            <?= $field('freight', t('imports.cost.freight'), 'number', ['step' => '0.01', 'min' => 0, 'inputmode' => 'decimal']) ?>
            <?= $field('insurance', t('imports.cost.insurance'), 'number', ['step' => '0.01', 'min' => 0, 'inputmode' => 'decimal']) ?>
            <?= $field('other_costs', t('imports.cost.other'), 'number', ['step' => '0.01', 'min' => 0, 'inputmode' => 'decimal']) ?>
            <div class="field xfield" data-xfield="currency">
                <label for="x-currency" class="required"><?= e(t('movements.currency')) ?> <?= $chip('currency') ?></label>
                <select id="x-currency" name="currency" required data-extracted="<?= e((string) ($extracted['currency']['value'] ?? '')) ?>"<?= error_attr('currency') ?>>
                    <option value=""><?= e(t('common.choose')) ?></option>
                    <?php foreach ($currencies as $c): ?><option value="<?= e($c['code']) ?>" <?= ($d['currency'] ?? '') === $c['code'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option><?php endforeach ?>
                </select>
                <?= $src('currency') ?><?= error_msg('currency') ?>
            </div>
            <?= View::partial('banks/_select', ['banks' => $banks, 'branches' => $branches, 'bankId' => $d['bank_id'] ?? null, 'branchId' => $d['branch_id'] ?? null,
                'legacy' => $d['bank'] ?? null, 'chip' => $chip('bank_id'), 'source' => $src('bank')]) ?>
            <div class="field"><label for="x-notes"><?= e(t('movements.note')) ?></label><input id="x-notes" name="notes" maxlength="500" value="<?= e((string) ($d['notes'] ?? '')) ?>"></div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend><?= e(t('licence_import.section.lines')) ?> <span class="muted">(<?= count($lines) ?>)</span></legend>
        <?= error_msg('lines') ?>
        <div class="table-wrap">
        <table class="table table--compact xlines" data-no-dt data-xlines>
            <thead><tr>
                <th><?= e(t('licence_import.col.description')) ?></th><th><?= e(t('licence_import.col.hs')) ?></th>
                <th class="num"><?= e(t('movements.col.quantity')) ?></th><th><?= e(t('items.col.unit')) ?></th>
                <th class="num"><?= e(t('orders.col.unit_price')) ?></th><th class="num"><?= e(t('orders.col.amount')) ?></th><th></th>
            </tr></thead>
            <tbody data-xlines-body>
            <?php foreach ($lines as $i => $l): ?>
                <?= View::partial('imports/_import_line', ['i' => $i, 'l' => (array) $l]) ?>
            <?php endforeach ?>
            </tbody>
            <tfoot><tr><th colspan="5"><?= e(t('licence_import.lines_total')) ?></th><th class="num" data-xlines-total>—</th><th></th></tr></tfoot>
        </table>
        </div>
        <template data-xline-template><?= View::partial('imports/_import_line', ['i' => '__i__', 'l' => []]) ?></template>
        <button type="button" class="btn btn--sm mt-2" data-xline-add><?= icon('plus') ?> <?= e(t('orders.add_line')) ?></button>
        <?php if (!$lines && !$failed): ?><p class="hint"><?= e(t('licence_import.no_lines_found')) ?></p><?php endif ?>
    </fieldset>

    <?php if (!empty($p['extras'])): ?>
    <details class="xextras" open>
        <summary><?= icon('info') ?> <?= e(t('licence_import.extras_title')) ?></summary>
        <dl>
            <?php foreach ($p['extras'] as $k => $v): ?>
                <div><dt><?= e(t('licence_import.extra.' . $k)) ?></dt><dd><?= e($k === 'invoice_date' ? fmt_date($v) : $v) ?></dd></div>
            <?php endforeach ?>
        </dl>
        <p class="hint"><?= e(t('licence_import.extras_hint')) ?></p>
    </details>
    <?php endif ?>

    <section class="xissues" aria-live="polite" data-xissues>
        <?= View::partial('imports/_import_issues', ['issues' => LicenceImportController::presentIssues($issues)]) ?>
    </section>

    <label class="check xack"><input type="checkbox" name="confirm_ack" value="1" required<?= error_attr('confirm_ack') ?>> <span><?= e(t('licence_import.ack')) ?></span></label>
    <?= error_msg('confirm_ack') ?>

    <div class="form-actions">
        <span class="xsave muted" data-xsave aria-live="polite"></span>
        <button type="submit" class="btn btn--primary" data-once><?= icon('check') ?> <?= e(t('licence_import.confirm')) ?></button>
    </div>
</form>

<aside class="xreview__doc">
    <div class="xreview__doc-bar">
        <strong><?= icon('file-text') ?> <?= e(t('licence_import.source_doc')) ?></strong>
        <a class="icon-btn" href="<?= e(url("/licences/import/$token/document")) ?>" target="_blank" rel="noopener" title="<?= e(t('licence_import.open_doc')) ?>" aria-label="<?= e(t('licence_import.open_doc')) ?>"><?= icon('external-link') ?></a>
    </div>
    <iframe src="<?= e(url("/licences/import/$token/document")) ?>" title="<?= e($draft['original_name']) ?>"></iframe>
    <?php if (!$failed && !empty($draft['extraction']['text_excerpt'])): ?>
        <details class="xreview__text"><summary><?= e(t('licence_import.raw_text')) ?></summary><pre><?= e($draft['extraction']['text_excerpt']) ?></pre></details>
    <?php endif ?>
    <form method="post" action="<?= e(url("/licences/import/$token/discard")) ?>" data-confirm="<?= e(t('licence_import.discard_confirm')) ?>" data-confirm-variant="danger" class="xreview__discard">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--ghost btn--sm"><?= icon('trash-2') ?> <?= e(t('licence_import.discard')) ?></button>
    </form>
</aside>
</div>
