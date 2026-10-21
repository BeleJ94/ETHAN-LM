<?php
/**
 * Création / modification d'une licence d'importation (1 licence = 1 PO fournisseur).
 * Colonne principale : PO → identification → validité → valeur (FOB + frais = CIF) → document.
 * Colonne latérale : marchandises du PO (codes SH) et aperçu du fichier joint.
 * @var array|null $lic @var array $pos @var int $poId @var array $currencies @var array $banks @var array $branches
 * @var array<int,list<array<string,mixed>>> $goods marchandises par PO @var string $back page de retour
 */
use App\Core\View;

$l = $lic;
$action = $l ? '/licences/' . (int) $l['id'] : '/licences';
$selected = null;
foreach ($pos as $p) {
    if ((int) $p['id'] === (int) old('po_id', (string) $poId)) {
        $selected = $p;
    }
}
$cur = old('currency', $l['currency'] ?? ($selected['currency'] ?? base_currency()));
$money = fn (string $name, $value) => e(old($name, $value === null || $value === '' ? '' : number_format((float) $value, 2, '.', '')));
echo View::partial('partials/dossier_bar', ['kind' => 'licence', 'orderId' => $l ? (int) $l['order_id'] : 0, 'poId' => $l ? (int) $l['po_id'] : ((int) old('po_id', (string) $poId) ?: null)]);
echo View::partial('partials/page_header', ['icon' => 'stamp', 'heading' => $title, 'meta' => t('imports.form_meta')]);
?>
<?php if (!$l): ?>
<div class="extract-box extract-box--compact">
    <?= icon('scan-text') ?>
    <div><strong><?= e(t('imports.extract_title')) ?></strong><span class="muted"> — <?= e(t('imports.extract_short')) ?></span></div>
    <a class="btn btn--sm" href="<?= e(url('/licences/import', $poId ? ['po_id' => $poId] : [])) ?>"><?= icon('file-up') ?> <?= e(t('imports.from_document')) ?></a>
</div>
<?php endif ?>

<form method="post" action="<?= e(url($action)) ?>" enctype="multipart/form-data" class="licence-layout" data-licence-form>
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="licence-layout__main card form form--sections">

        <fieldset class="form-section">
            <legend><?= icon('link-2') ?> <?= e(t('imports.section.chain')) ?></legend>
            <?php if ($l): ?>
                <input type="hidden" name="po_id" value="<?= (int) $l['po_id'] ?>">
                <p class="chain"><span class="chip"><?= icon('clipboard-list') ?> <?= e($l['customer_po_number']) ?> · <?= e($l['customer_name']) ?></span> → <span class="chip"><?= icon('shopping-cart') ?> <?= e($l['po_reference']) ?> · <?= e($l['supplier_name']) ?></span> → <span class="chip chip--c-purple"><?= icon('stamp') ?> <?= e(t('imports.licence')) ?></span></p>
            <?php else: ?>
                <div class="field">
                    <label for="po_id" class="required"><?= e(t('imports.po_supplier')) ?></label>
                    <select id="po_id" name="po_id" required data-po-goods-select<?= error_attr('po_id') ?>>
                        <option value=""><?= e(t('common.choose')) ?></option>
                        <?php foreach ($pos as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" data-currency="<?= e($p['currency']) ?>" data-amount="<?= e(number_format((float) $p['amount'], 2, '.', '')) ?>" <?= (string) old('po_id', (string) $poId) === (string) $p['id'] ? 'selected' : '' ?>>
                                <?= e($p['reference'] . ' — ' . $p['supplier_name'] . ' · PO client ' . $p['customer_po_number'] . ' (' . $p['customer_name'] . ') · ' . fmt_money($p['amount'], $p['currency'])) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                    <p class="hint"><?= e($pos ? t('imports.po_hint') : t('imports.no_po_waiting')) ?></p>
                    <?= error_msg('po_id') ?>
                </div>
            <?php endif ?>
        </fieldset>

        <fieldset class="form-section">
            <legend><?= icon('id-card') ?> <?= e(t('imports.section.identity')) ?></legend>
            <div class="form-grid">
                <div class="field">
                    <label for="number" class="required"><?= e(t('imports.col.number')) ?></label>
                    <input id="number" name="number" maxlength="60" required class="mono" value="<?= e(old('number', $l['number'] ?? '')) ?>" placeholder="<?= e(t('imports.number_placeholder')) ?>"<?= error_attr('number') ?>>
                    <?= error_msg('number') ?>
                </div>
                <div class="field">
                    <label for="cod_number"><?= e(t('imports.cod_number')) ?></label>
                    <input id="cod_number" name="cod_number" maxlength="30" class="mono" value="<?= e(old('cod_number', $l['cod_number'] ?? '')) ?>" placeholder="COD 2026 0012" autocapitalize="characters"<?= error_attr('cod_number') ?>>
                    <p class="hint"><?= e(t('imports.cod_hint')) ?></p>
                    <?= error_msg('cod_number') ?>
                </div>
                <div class="field">
                    <label for="origin_country"><?= e(t('imports.origin_country')) ?></label>
                    <input id="origin_country" name="origin_country" maxlength="80" value="<?= e(old('origin_country', $l['origin_country'] ?? '')) ?>" placeholder="<?= e(t('imports.origin_placeholder')) ?>">
                </div>
                <?php
                // Banque / agence actuelles conservées dans les listes même si elles ont été désactivées depuis
                $curBank = (int) old('bank_id', (string) ($l['bank_id'] ?? '')) ?: null;
                $curBranch = (int) old('branch_id', (string) ($l['branch_id'] ?? '')) ?: null;
                $bankList = $banks;
                $branchList = $branches;
                if ($l && $l['bank_id'] && !in_array((int) $l['bank_id'], array_map(fn ($b) => (int) $b['id'], $banks), true) && ($ob = (new \App\Repositories\BankRepository())->find((int) $l['bank_id']))) { $bankList[] = $ob; }
                if ($l && $l['branch_id'] && !in_array((int) $l['branch_id'], array_map(fn ($b) => (int) $b['id'], $branches), true) && ($obr = (new \App\Repositories\BankRepository())->findBranch((int) $l['branch_id']))) { $branchList[] = $obr; }
                ?>
                <?= View::partial('banks/_select', ['banks' => $bankList, 'branches' => $branchList, 'bankId' => $curBank, 'branchId' => $curBranch,
                    'legacy' => $l && !$l['bank_id'] ? $l['bank'] : old('bank', '')]) ?>
            </div>
        </fieldset>

        <fieldset class="form-section">
            <legend><?= icon('calendar-range') ?> <?= e(t('imports.section.validity')) ?></legend>
            <div class="form-grid">
                <div class="field">
                    <label for="issued_at" class="required"><?= e(t('documents.issued_at')) ?></label>
                    <input id="issued_at" name="issued_at" type="date" required value="<?= e(old('issued_at', $l['issued_at'] ?? '')) ?>" data-validity-from<?= error_attr('issued_at') ?>>
                    <?= error_msg('issued_at') ?>
                </div>
                <div class="field">
                    <label for="expires_at" class="required"><?= e(t('documents.expires_at')) ?></label>
                    <input id="expires_at" name="expires_at" type="date" required value="<?= e(old('expires_at', $l['expires_at'] ?? '')) ?>" data-validity-to<?= error_attr('expires_at') ?>>
                    <?= error_msg('expires_at') ?>
                </div>
            </div>
            <p class="validity-line" data-validity-line aria-live="polite"><?= e(t('imports.expiry_hint')) ?></p>
        </fieldset>

        <fieldset class="form-section">
            <legend><?= icon('wallet') ?> <?= e(t('imports.section.value')) ?></legend>
            <div class="field field--narrow">
                <label for="currency" class="required"><?= e(t('movements.currency')) ?></label>
                <select id="currency" name="currency" required data-fill-from="po_id:currency" data-cost-currency>
                    <?php foreach ($currencies as $c): ?>
                        <option value="<?= e($c['code']) ?>" <?= $cur === $c['code'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <table class="cost-table" data-cost-table>
                <tbody>
                    <tr>
                        <th scope="row"><label for="amount"><?= e(t('imports.cost.fob')) ?></label><span class="hint"><?= e(t('imports.cost.fob_hint')) ?></span></th>
                        <td><input id="amount" name="amount" type="number" step="0.01" min="0" inputmode="decimal" data-fill-from="po_id:amount" data-cost value="<?= $money('amount', $l['amount'] ?? ($selected ? $selected['amount'] : null)) ?>"<?= error_attr('amount') ?>><?= error_msg('amount') ?></td>
                    </tr>
                    <?php foreach (['freight' => 'imports.cost.freight', 'insurance' => 'imports.cost.insurance', 'other_costs' => 'imports.cost.other'] as $k => $label): ?>
                        <tr>
                            <th scope="row"><label for="<?= $k ?>"><?= e(t($label)) ?></label></th>
                            <td><input id="<?= $k ?>" name="<?= $k ?>" type="number" step="0.01" min="0" inputmode="decimal" data-cost value="<?= $money($k, $l[$k] ?? null) ?>" placeholder="0.00"<?= error_attr($k) ?>><?= error_msg($k) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row"><?= e(t('imports.cost.cif')) ?><span class="hint"><?= e(t('imports.cost.cif_hint')) ?></span></th>
                        <td class="cost-table__total"><output data-cif aria-live="polite">—</output> <span class="muted" data-cif-currency><?= e($cur) ?></span></td>
                    </tr>
                </tfoot>
            </table>
            <p class="hint" data-po-amount-compare hidden></p>
        </fieldset>

        <fieldset class="form-section">
            <legend><?= icon('paperclip') ?> <?= e(t('imports.section.document')) ?></legend>
            <label class="file-drop" data-file-drop>
                <input id="file" name="file" type="file" accept="application/pdf,image/jpeg,image/png,image/webp" class="sr-only" data-file-preview-input>
                <?= icon('file-up') ?>
                <span><strong data-file-name><?= e($l && $l['file_name'] ? $l['file_name'] : t('imports.file_choose')) ?></strong>
                    <span class="muted"><?= e($l && $l['file_name'] ? t('imports.file_replace') : t('attachment.hint')) ?></span></span>
            </label>
            <div class="field mt-2">
                <label for="notes"><?= e(t('movements.note')) ?></label>
                <textarea id="notes" name="notes" rows="2" maxlength="500" data-autogrow><?= e(old('notes', $l['notes'] ?? '')) ?></textarea>
            </div>
        </fieldset>

        <div class="form-actions">
            <a class="link-btn" href="<?= e(url($back)) ?>"><?= e(t('common.cancel')) ?></a>
            <button type="submit" class="btn btn--primary" data-once><?= icon('save') ?> <?= e(t('common.save')) ?></button>
        </div>
    </div>

    <aside class="licence-layout__side">
        <section class="card side-card">
            <h2 class="side-card__title"><?= icon('package') ?> <?= e(t('imports.goods_title')) ?></h2>
            <p class="muted" data-goods-empty <?= $selected || $l ? 'hidden' : '' ?>><?= e(t('imports.goods_pick_po')) ?></p>
            <?php foreach ($goods as $gPo => $gLines):
                $visible = $l || ($selected && (int) $selected['id'] === (int) $gPo);
                $gCur = $l ? $l['po_currency'] : (array_values(array_filter($pos, fn ($p) => (int) $p['id'] === (int) $gPo))[0]['currency'] ?? '');
                $gTotal = array_sum(array_map(fn ($g) => (float) $g['quantity'] * (float) $g['unit_price'], $gLines)); ?>
                <div data-po-goods="<?= (int) $gPo ?>" <?= $visible ? '' : 'hidden' ?>>
                    <ul class="goods-list">
                        <?php foreach ($gLines as $g): ?>
                            <li>
                                <div class="goods-list__head"><strong><?= e($g['item_name']) ?></strong><span class="hs<?= $g['hs_code'] ? '' : ' hs--missing' ?>" title="<?= e(t('items.hs_code')) ?>"><?= e($g['hs_code'] ? 'SH ' . $g['hs_code'] : t('imports.hs_missing')) ?></span></div>
                                <div class="goods-list__meta muted"><span class="mono"><?= e($g['sku']) ?></span><?= $g['specification'] ? ' · ' . e($g['specification']) : '' ?></div>
                                <div class="goods-list__nums"><span><?= e(fmt_qty($g['quantity'])) ?> <?= e($g['unit']) ?> × <?= e(fmt_number($g['unit_price'])) ?></span><strong><?= e(fmt_money((float) $g['quantity'] * (float) $g['unit_price'], $gCur)) ?></strong></div>
                            </li>
                        <?php endforeach ?>
                    </ul>
                    <p class="goods-total"><span><?= e(t('imports.goods_total')) ?></span><strong><?= e(fmt_money($gTotal, $gCur)) ?></strong></p>
                </div>
            <?php endforeach ?>
        </section>

        <section class="card side-card">
            <h2 class="side-card__title"><?= icon('eye') ?> <?= e(t('imports.preview_title')) ?></h2>
            <div class="file-preview" data-file-preview
                 data-existing-src="<?= $l && $l['attachment_id'] ? e(url('/attachments/' . (int) $l['attachment_id'])) : '' ?>"
                 data-existing-mime="<?= e($l['file_mime'] ?? '') ?>">
                <p class="muted file-preview__empty" data-file-preview-empty><?= icon('file-search') ?> <?= e(t('imports.preview_empty')) ?></p>
            </div>
        </section>
    </aside>
</form>
<?= json_script('licence-form-i18n', [
    'days' => t('imports.validity.days'), 'expired' => t('imports.validity.expired'), 'invalid' => t('imports.validity.invalid'),
    'compare_equal' => t('imports.cost.compare_equal'), 'compare_diff' => t('imports.cost.compare_diff'), 'file_choose' => t('imports.file_choose'),
    'too_big' => t('licence_import.error.too_big', ['max' => 10]), 'bad_type' => t('imports.file_bad_type'),
]) ?>
