<?php
/**
 * Expédition d'un PO fournisseur — parcours guidé en 4 étapes (quantités → transport → réception → vérification)
 * avec synthèse permanente. Sans JavaScript, toutes les étapes s'affichent à la suite (même formulaire).
 * @var array $po @var array $lines @var ?array $importShippable @var array $inspections (importation)
 */
use App\Core\Session;
use App\Core\View;

$old = Session::getFlash('_old', []);
$oldLines = (array) ($old['lines'] ?? []);
$today = date('Y-m-d');
$poId = (int) $po['id'];
$importShippable ??= null;
$inspections ??= [];
// Importation : quantités inspectées par ligne (toutes décisions) et ARA ouverts
$inspectedBy = [];
foreach ($inspections as $i) {
    foreach ($i['lines'] as $il) {
        $inspectedBy[(int) $il['po_line_id']] = ($inspectedBy[(int) $il['po_line_id']] ?? 0) + (float) $il['quantity_inspected'];
    }
}
$openAra = array_values(array_filter($inspections, fn ($i) => $i['decision'] === 'ara' && !$i['regularized_by']));
// Soldes des AV par ligne (plus ancienne d'abord) : base de la répartition des quantités chargées
$avBalances ??= [];
$avByLine = [];
foreach ($avBalances as $b) {
    if ($b['remaining'] > 0) {
        $avByLine[$b['po_line_id']][] = $b;
    }
}

// Synthèse du PO (cumul uniquement si toutes les lignes ont la même unité)
$units = array_unique(array_column($lines, 'unit'));
$unit = count($units) === 1 ? reset($units) : null;
$sum = fn (string $k) => array_sum(array_map(fn ($l) => (float) $l[$k], $lines));
$ordered = $sum('quantity');
$shippedDone = $sum('shipped');
$inTransit = $sum('in_transit');
$remaining = max(0, $ordered - $shippedDone - $inTransit);
$late = $po['expected_date'] && $po['expected_date'] < $today;
$qtyLabel = fn (float $v) => $unit !== null ? fmt_qty($v) . ' ' . $unit : (string) count($lines);

echo View::partial('partials/dossier_bar', ['kind' => 'delivery', 'orderId' => (int) $po['order_id'], 'poId' => (int) $po['id']]);
echo View::partial('partials/page_header', [
    'icon' => 'truck',
    'heading' => t('delivery.new') . ' — ' . $po['reference'],
    'meta' => t('delivery.form_meta', ['supplier' => $po['supplier_name'], 'customer' => $po['customer_name']]),
]);
?>
<section class="kpis kpis--compact ship-kpis" aria-label="<?= e(t('delivery.kpi.title')) ?>">
    <div class="kpi"><span class="kpi__label"><?= e(t('delivery.kpi.ordered')) ?></span><span class="kpi__value kpi__value--sm"><?= e($qtyLabel($ordered)) ?></span><span class="kpi__hint"><?= e(t('delivery.kpi.lines', ['n' => count($lines)])) ?></span></div>
    <div class="kpi"><span class="kpi__label"><?= e(t('delivery.kpi.delivered')) ?></span><span class="kpi__value kpi__value--sm"><?= e($qtyLabel($shippedDone)) ?></span><span class="kpi__hint"><?= \App\Domain\OrderProgress::percent($shippedDone, $ordered) ?> %</span></div>
    <div class="kpi"><span class="kpi__label"><?= e(t('delivery.kpi.in_transit')) ?></span><span class="kpi__value kpi__value--sm"><?= e($qtyLabel($inTransit)) ?></span><span class="kpi__hint"><?= e(t('delivery.kpi.in_transit_hint')) ?></span></div>
    <div class="kpi kpi--hero"><span class="kpi__label"><?= e(t('delivery.kpi.remaining')) ?></span><span class="kpi__value kpi__value--sm"><?= e($qtyLabel($remaining)) ?></span><span class="kpi__hint"><?= e(t('delivery.kpi.remaining_hint')) ?></span></div>
</section>

<div class="ship-layout">
<form method="post" action="<?= e(url("/purchase-orders/$poId/deliveries")) ?>" class="card form wizard" data-wizard data-unit="<?= e((string) $unit) ?>">
    <?= csrf_field() ?>

    <ol class="wizard-steps" aria-label="<?= e(t('delivery.wizard.label')) ?>">
        <?php foreach (['qty' => 'package', 'transport' => 'truck', 'reception' => 'package-check', 'review' => 'clipboard-check'] as $i => $ic): ?>
            <li><button type="button" class="wizard-steps__item" data-wizard-tab><span class="wizard-steps__dot"><?= icon($ic) ?></span><span><?= e(t('delivery.wizard.' . $i)) ?></span></button></li>
        <?php endforeach ?>
    </ol>

    <!-- Étape 1 : quantités -->
    <section class="wizard-panel" data-step aria-labelledby="step-qty">
        <header class="wizard-panel__head">
            <h2 id="step-qty"><?= e(t('delivery.wizard.qty_title')) ?></h2>
            <button type="button" class="btn btn--sm" data-ship-all><?= icon('arrow-down-to-line') ?> <?= e(t('delivery.load_all')) ?></button>
        </header>
        <?= error_msg('lines') ?>
        <div class="ship-lines">
            <?php foreach ($lines as $l):
                $lid = (int) $l['id'];
                $q = (float) $l['quantity'];
                $open = max(0, $q - (float) $l['shipped'] - (float) $l['in_transit']);
                if ($importShippable !== null) {
                    // Importation : on ne charge que ce qu'une AV a libéré
                    $open = $importShippable['open_ara'] > 0 ? 0 : min($open, $importShippable['shippable'][$lid] ?? 0);
                }
                $pDone = $q > 0 ? (float) $l['shipped'] / $q * 100 : 0;
                $pTransit = $q > 0 ? (float) $l['in_transit'] / $q * 100 : 0; ?>
                <div class="ship-line<?= $open <= 0 ? ' is-complete' : '' ?>" data-ship-line data-open="<?= e((string) $open) ?>" data-qty="<?= e((string) $q) ?>" data-done="<?= e((string) ((float) $l['shipped'] + (float) $l['in_transit'])) ?>" data-sku="<?= e($l['sku']) ?>" data-unit="<?= e($l['unit']) ?>">
                    <div class="ship-line__product">
                        <strong><?= e($l['sku']) ?></strong>
                        <span class="muted"><?= e($l['item_name']) ?><?= $l['specification'] ? ' · ' . e($l['specification']) : '' ?></span>
                    </div>
                    <div class="ship-line__bar" aria-hidden="true">
                        <span class="seg seg--done" style="width: <?= round($pDone, 2) ?>%"></span><span class="seg seg--transit" style="width: <?= round($pTransit, 2) ?>%"></span><span class="seg seg--now" data-seg-now style="width: 0%"></span>
                    </div>
                    <div class="ship-line__facts">
                        <span><?= e(t('po.col.ordered_po')) ?> <strong><?= e(fmt_qty($q)) ?></strong></span>
                        <span><?= e(t('orders.col.delivered')) ?> <strong><?= e(fmt_qty($l['shipped'])) ?></strong></span>
                        <?php if ((float) $l['in_transit'] > 0): ?><span><?= e(t('orders.col.in_transit')) ?> <strong><?= e(fmt_qty($l['in_transit'])) ?></strong></span><?php endif ?>
                        <span class="ship-line__open"><?= e(t('delivery.kpi.remaining')) ?> <strong><?= e(fmt_qty($open)) ?> <?= e($l['unit']) ?></strong></span>
                    </div>
                    <?php if ($importShippable !== null):
                        $cl = (float) ($importShippable['cleared'][$lid] ?? 0);
                        $cm = (float) ($importShippable['committed'][$lid] ?? 0); ?>
                        <div class="ship-import" aria-label="<?= e(t('delivery.import.chain_label')) ?>">
                            <span><?= icon('search-check') ?> <?= e(t('delivery.import.inspected')) ?> <strong><?= e(fmt_qty($inspectedBy[$lid] ?? 0)) ?></strong></span>
                            <span class="ship-import__arrow">→</span>
                            <span><?= icon('badge-check') ?> <?= e(t('delivery.import.cleared')) ?> <strong class="<?= $cl > 0 ? 'text-good' : '' ?>"><?= e(fmt_qty($cl)) ?></strong></span>
                            <span class="ship-import__arrow">−</span>
                            <span><?= icon('truck') ?> <?= e(t('delivery.import.committed')) ?> <strong><?= e(fmt_qty($cm)) ?></strong></span>
                            <span class="ship-import__arrow">=</span>
                            <span class="ship-import__now<?= $open > 0 ? '' : ' is-zero' ?>"><?= e(t('delivery.import.available')) ?> <strong><?= e(fmt_qty($open)) ?> <?= e($l['unit']) ?></strong></span>
                        </div>
                        <?php if ($openAra): ?><p class="ship-import__block text-bad"><?= icon('octagon-x') ?> <?= e(t('delivery.import.blocked', ['ref' => $openAra[0]['decision_number'] ?: $openAra[0]['reference']])) ?></p>
                        <?php elseif ($open <= 0 && max(0, $q - (float) $l['shipped'] - (float) $l['in_transit']) > 0): ?><p class="ship-import__block text-warn"><?= icon('info') ?> <?= e(t('delivery.import.need_inspection')) ?></p><?php endif ?>
                        <?php if ($open > 0 && !$openAra && ($lineAvs = $avByLine[$lid] ?? [])): ?>
                            <div class="ship-av" data-av-alloc data-manual="<?= !empty($oldLines[$lid]['av']) ? '1' : '0' ?>">
                                <div class="ship-av__head">
                                    <span><?= icon('badge-check') ?> <?= e(t('delivery.av.title')) ?></span>
                                    <button type="button" class="link-btn" data-av-auto><?= icon('wand-sparkles') ?> <?= e(t('delivery.av.auto')) ?></button>
                                </div>
                                <table class="ship-av__table">
                                    <?php foreach ($lineAvs as $b): $iid = (int) $b['inspection_id']; ?>
                                        <tr>
                                            <td><strong><?= e($b['decision_number'] ?: $b['reference']) ?></strong><?= $b['decision_date'] ? ' <span class="muted">· ' . e(fmt_date($b['decision_date'])) . '</span>' : '' ?></td>
                                            <td class="num muted"><?= e(t('delivery.av.remaining', ['q' => fmt_qty($b['remaining']), 'u' => $l['unit']])) ?></td>
                                            <td class="ship-av__input"><div class="qty-input"><input name="lines[<?= $lid ?>][av][<?= $iid ?>]" type="number" step="0.001" min="0" max="<?= e((string) $b['remaining']) ?>" inputmode="decimal" placeholder="0" value="<?= e($oldLines[$lid]['av'][$iid] ?? '') ?>" data-av-input data-av-label="<?= e($b['decision_number'] ?: $b['reference']) ?>" aria-label="<?= e(t('delivery.av.qty_from', ['av' => $b['decision_number'] ?: $b['reference']])) ?>"><span class="qty-input__unit"><?= e($l['unit']) ?></span></div></td>
                                        </tr>
                                    <?php endforeach ?>
                                </table>
                                <p class="ship-av__status" data-av-status aria-live="polite"></p>
                            </div>
                        <?php endif ?>
                    <?php endif ?>
                    <div class="ship-line__input">
                        <?php if ($open > 0): ?>
                            <label class="sr-only" for="ship-<?= $lid ?>"><?= e(t('delivery.shipped') . ' ' . $l['sku']) ?></label>
                            <div class="qty-input">
                                <input id="ship-<?= $lid ?>" name="lines[<?= $lid ?>][shipped]" type="number" step="0.001" min="0" max="<?= e((string) $open) ?>" inputmode="decimal" placeholder="0" value="<?= e($oldLines[$lid]['shipped'] ?? '') ?>" data-ship-qty>
                                <span class="qty-input__unit"><?= e($l['unit']) ?></span>
                            </div>
                            <button type="button" class="btn btn--sm btn--ghost" data-ship-max><?= e(t('delivery.load_rest')) ?></button>
                        <?php else: ?>
                            <span class="badge badge--available"><?= e(t('delivery.line_complete')) ?></span>
                        <?php endif ?>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
        <?php if ($importShippable !== null): ?><p class="hint"><?= icon('stamp') ?> <?= e(t('imports.wizard_hint')) ?></p><?php endif ?>
        <p class="hint"><?= icon('info') ?> <?= e(t('delivery.partial_hint')) ?></p>
        <p class="error" data-ship-error hidden><?= e(t('delivery.error.no_qty')) ?></p>
    </section>

    <!-- Étape 2 : transport -->
    <section class="wizard-panel" data-step aria-labelledby="step-transport">
        <header class="wizard-panel__head"><h2 id="step-transport"><?= e(t('delivery.wizard.transport_title')) ?></h2></header>
        <div class="form-grid">
            <?= View::partial('partials/ref_field', ['type' => 'delivery', 'name' => 'reference', 'label' => t('reference.delivery')]) ?>
            <div class="field">
                <label for="shipped_at" class="required"><?= e(t('delivery.shipped_at')) ?></label>
                <input id="shipped_at" name="shipped_at" type="date" required max="<?= e($today) ?>" value="<?= e(old('shipped_at', $today)) ?>"<?= error_attr('shipped_at') ?> data-recap-src="shipped_at">
                <?= error_msg('shipped_at') ?>
            </div>
            <div class="field">
                <label for="transport_mode" class="required"><?= e(t('transport.mode')) ?></label>
                <select id="transport_mode" name="transport_mode" required>
                    <?php $cur = old('transport_mode', $po['transport_mode'] ?? 'road'); foreach (\App\Domain\Checklist::TRANSPORTS as $tm): ?>
                        <option value="<?= e($tm) ?>" <?= $cur === $tm ? 'selected' : '' ?>><?= e(t('transport.' . $tm)) ?></option>
                    <?php endforeach ?>
                </select>
                <p class="hint"><?= e(t('transport.hint')) ?></p>
            </div>
            <div class="field">
                <label for="forwarder_id"><?= e(t('delivery.forwarder')) ?></label>
                <select id="forwarder_id" name="forwarder_id">
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php $cur = old('forwarder_id', $po['forwarder_id'] ?? ''); foreach ($forwarders as $fw): ?>
                        <option value="<?= (int) $fw['id'] ?>" <?= (string) $cur === (string) $fw['id'] ? 'selected' : '' ?>><?= e($fw['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <p class="hint"><?= e(t('delivery.forwarder_hint')) ?></p>
            </div>
            <div class="field">
                <label for="forwarder_ref"><?= e(t('delivery.customs.file_label')) ?></label>
                <input id="forwarder_ref" name="forwarder_ref" maxlength="60" class="mono" autocomplete="off" value="<?= e(old('forwarder_ref')) ?>" data-uppercase>
                <p class="hint"><?= e(t('delivery.customs.file_hint')) ?></p>
            </div>
            <div class="field"><label for="vehicle_plate"><?= e(t('delivery.plate')) ?></label><input id="vehicle_plate" name="vehicle_plate" maxlength="60" class="mono" autocomplete="off" placeholder="1234AB01" value="<?= e(old('vehicle_plate')) ?>" data-uppercase></div>
            <div class="field"><label for="trailer_plate"><?= e(t('delivery.trailer')) ?></label><input id="trailer_plate" name="trailer_plate" maxlength="60" class="mono" autocomplete="off" value="<?= e(old('trailer_plate')) ?>" data-uppercase></div>
            <div class="field"><label for="driver_name"><?= e(t('delivery.driver')) ?></label><input id="driver_name" name="driver_name" maxlength="120" autocomplete="off" value="<?= e(old('driver_name')) ?>"></div>
            <div class="field"><label for="driver_phone"><?= e(t('delivery.driver_phone')) ?></label><input id="driver_phone" name="driver_phone" type="tel" maxlength="60" placeholder="+243…" value="<?= e(old('driver_phone')) ?>"></div>
            <div class="field"><label for="transporter"><?= e(t('delivery.transporter')) ?></label><input id="transporter" name="transporter" maxlength="160" value="<?= e(old('transporter')) ?>"></div>
            <div class="field">
                <label for="supplier_waybill"><?= e(t('delivery.waybill')) ?></label>
                <input id="supplier_waybill" name="supplier_waybill" maxlength="80" class="mono" value="<?= e(old('supplier_waybill')) ?>">
                <p class="hint"><?= e(t('delivery.waybill_hint')) ?></p>
            </div>
        </div>
    </section>

    <!-- Étape 3 : réception (facultative) -->
    <section class="wizard-panel" data-step aria-labelledby="step-reception">
        <header class="wizard-panel__head"><h2 id="step-reception"><?= e(t('delivery.wizard.reception_title')) ?></h2></header>
        <?php $mode = in_array($old['reception_mode'] ?? '', ['planned', 'in_transit', 'delivered'], true) ? $old['reception_mode'] : 'planned'; ?>
        <div class="choice-cards choice-cards--3" role="radiogroup">
            <label class="choice-card">
                <input type="radio" name="reception_mode" value="planned" <?= $mode === 'planned' ? 'checked' : '' ?> data-reception-mode>
                <span class="choice-card__body"><?= icon('file-check-2') ?><strong><?= e(t('delivery.mode.planned')) ?></strong><span class="muted"><?= e(t('delivery.mode.planned_hint')) ?></span></span>
            </label>
            <label class="choice-card">
                <input type="radio" name="reception_mode" value="in_transit" <?= $mode === 'in_transit' ? 'checked' : '' ?> data-reception-mode>
                <span class="choice-card__body"><?= icon('truck') ?><strong><?= e(t('delivery.mode.transit')) ?></strong><span class="muted"><?= e(t('delivery.mode.transit_hint')) ?></span></span>
            </label>
            <label class="choice-card">
                <input type="radio" name="reception_mode" value="delivered" <?= $mode === 'delivered' ? 'checked' : '' ?> data-reception-mode>
                <span class="choice-card__body"><?= icon('package-check') ?><strong><?= e(t('delivery.mode.delivered')) ?></strong><span class="muted"><?= e(t('delivery.mode.delivered_hint')) ?></span></span>
            </label>
        </div>
        <p class="hint"><?= icon('lock') ?> <?= e(t('delivery.mode.documents_hint')) ?></p>
        <div id="reception-fields" class="reception-fields" data-reception-fields <?= $mode === 'delivered' ? '' : 'hidden' ?>>
            <div class="form-grid">
                <div class="field"><label for="delivered_at" class="required"><?= e(t('delivery.delivered_at')) ?></label><input id="delivered_at" name="delivered_at" type="date" max="<?= e($today) ?>" value="<?= e(old('delivered_at', $today)) ?>"<?= error_attr('delivered_at') ?>><?= error_msg('delivered_at') ?></div>
                <div class="field"><label for="received_by" class="required"><?= e(t('delivery.received_by')) ?></label><input id="received_by" name="received_by" maxlength="160" placeholder="<?= e(t('delivery.received_by_placeholder')) ?>" value="<?= e(old('received_by')) ?>"<?= error_attr('received_by') ?>><?= error_msg('received_by') ?></div>
            </div>
            <table class="table table--compact" data-no-dt>
                <thead><tr><th><?= e(t('orders.col.product')) ?></th><th class="num"><?= e(t('delivery.shipped')) ?></th><th class="lines__qty"><?= e(t('delivery.received')) ?></th><th class="num"><?= e(t('delivery.gap')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($lines as $l): $lid = (int) $l['id']; ?>
                    <tr data-receive-row="<?= $lid ?>" hidden>
                        <td><strong><?= e($l['sku']) ?></strong></td>
                        <td class="num" data-receive-shipped>—</td>
                        <td><div class="qty-input"><input name="lines[<?= $lid ?>][received]" type="number" step="0.001" min="0" inputmode="decimal" placeholder="<?= e(t('delivery.same_as_shipped')) ?>" value="<?= e($oldLines[$lid]['received'] ?? '') ?>" data-receive-qty><span class="qty-input__unit"><?= e($l['unit']) ?></span></div></td>
                        <td class="num" data-receive-gap>—</td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
            <p class="hint"><?= e(t('delivery.gap_hint')) ?></p>
        </div>
        <div class="field mt-2">
            <label for="notes"><?= e(t('movements.note')) ?></label>
            <textarea id="notes" name="notes" rows="2" maxlength="500" data-autogrow placeholder="<?= e(t('delivery.notes_placeholder')) ?>"><?= e(old('notes')) ?></textarea>
        </div>
    </section>

    <!-- Étape 4 : vérification -->
    <section class="wizard-panel" data-step aria-labelledby="step-review">
        <header class="wizard-panel__head"><h2 id="step-review"><?= e(t('delivery.wizard.review_title')) ?></h2></header>
        <p class="muted"><?= e(t('delivery.review_hint')) ?></p>
        <div class="review" data-review>
            <div class="review__block">
                <h3><?= icon('package') ?> <?= e(t('delivery.wizard.qty')) ?> <button type="button" class="link-btn" data-wizard-goto="0"><?= e(t('common.edit')) ?></button></h3>
                <ul class="review__list" data-review-lines></ul>
            </div>
            <div class="review__block">
                <h3><?= icon('truck') ?> <?= e(t('delivery.wizard.transport')) ?> <button type="button" class="link-btn" data-wizard-goto="1"><?= e(t('common.edit')) ?></button></h3>
                <dl class="dl" data-review-transport></dl>
            </div>
            <div class="review__block">
                <h3><?= icon('package-check') ?> <?= e(t('delivery.wizard.reception')) ?> <button type="button" class="link-btn" data-wizard-goto="2"><?= e(t('common.edit')) ?></button></h3>
                <p data-review-reception></p>
            </div>
        </div>
    </section>

    <div class="wizard-nav">
        <a class="link-btn" href="<?= e(url('/orders/' . (int) $po['order_id'] . '#deliveries')) ?>"><?= e(t('common.cancel')) ?></a>
        <span class="wizard-nav__spacer"></span>
        <button type="button" class="btn" data-wizard-prev><?= icon('arrow-left') ?> <?= e(t('delivery.wizard.prev')) ?></button>
        <button type="button" class="btn btn--primary" data-wizard-next><?= e(t('delivery.wizard.next')) ?> <?= icon('arrow-right') ?></button>
        <button type="submit" class="btn btn--primary" data-wizard-submit data-once><?= icon('check') ?> <?= e(t('delivery.save')) ?></button>
    </div>
</form>

<aside class="ship-aside">
    <section class="card ship-aside__card">
        <h2 class="ship-aside__title"><?= icon('map-pinned') ?> <?= e(t('print.deliver_to')) ?></h2>
        <p><strong><?= e($po['customer_name']) ?></strong></p>
        <p class="prewrap muted"><?= e($po['delivery_address']) ?></p>
        <?php if ($po['delivery_contact']): ?><p><?= icon('phone') ?> <?= e($po['delivery_contact']) ?></p><?php endif ?>
        <?php if ($po['expected_date']): ?>
            <p class="<?= $late ? 'overdue' : '' ?>"><?= icon('calendar-clock') ?> <?= e(t('po.expected')) ?> <?= e(fmt_date($po['expected_date'])) ?><?= $late ? ' · ' . e(t('orders.filter.late')) : '' ?></p>
        <?php endif ?>
        <p class="ship-aside__links">
            <a href="<?= e(url("/purchase-orders/$poId")) ?>"><?= icon('shopping-cart') ?> <?= e($po['reference']) ?></a>
            <a href="<?= e(url('/orders/' . (int) $po['order_id'])) ?>"><?= icon('clipboard-list') ?> <?= e($po['order_reference']) ?></a>
        </p>
    </section>
    <?php if ($importShippable !== null): ?>
    <section class="card ship-aside__card">
        <h2 class="ship-aside__title"><?= icon('search-check') ?> <?= e(t('delivery.import.inspections')) ?> <span class="count-chip"><?= count($inspections) ?></span></h2>
        <?php if (!$inspections): ?>
            <p class="muted"><?= e(t('delivery.import.no_inspection')) ?></p>
        <?php else: ?>
        <ul class="ship-insp">
            <?php foreach ($inspections as $i):
                $mod = $i['decision'] === 'av' ? 'ok' : ($i['decision'] === 'ara' ? ($i['regularized_by'] ? 'muted' : 'bad') : 'pending'); ?>
                <li class="ship-insp__item ship-insp__item--<?= $mod ?>">
                    <div class="ship-insp__head">
                        <strong><?= e($i['reference']) ?></strong>
                        <?= View::partial('partials/status_badge', ['key' => 'imports.decision.' . $i['decision'], 'status' => ['av' => 'delivered', 'ara' => 'blocked', 'pending' => 'pending'][$i['decision']]]) ?>
                    </div>
                    <p class="muted"><?= e(fmt_date($i['inspection_date'])) ?><?php foreach ($i['lines'] as $il): ?> · <?= e(fmt_qty($il['quantity_inspected'])) ?> <?= e($il['unit']) ?> <?= e(t('delivery.import.inspected_short')) ?><?php if ($i['decision'] !== 'pending'): ?> → <strong class="<?= (float) $il['quantity_conform'] > 0 ? 'text-good' : 'text-bad' ?>"><?= e(fmt_qty($il['quantity_conform'])) ?> <?= e(t('delivery.import.cleared_short')) ?></strong><?php endif ?><?php endforeach ?></p>
                    <?php if ($i['decision'] === 'ara' && $i['regularized_by']): ?><p class="muted"><?= e(t('imports.regularized_by', ['ref' => $i['regularized_by']])) ?></p><?php endif ?>
                    <?php if ($i['attachment_id']): ?><a class="ship-insp__report"<?= preview_attrs(url('/attachments/' . (int) $i['attachment_id']), t('imports.report') . ' ' . ($i['report_number'] ?: $i['reference']), $i['file_mime']) ?>><?= icon('file-text') ?> <?= e(t('imports.report_view_full')) ?></a><?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
        <p class="ship-aside__links"><a href="<?= e(url("/purchase-orders/$poId#import")) ?>"><?= icon('stamp') ?> <?= e(t('delivery.import.see_import')) ?></a></p>
    </section>
    <?php endif ?>
    <section class="card ship-aside__card ship-live" aria-live="polite">
        <h2 class="ship-aside__title"><?= icon('gauge') ?> <?= e(t('delivery.this_shipment')) ?></h2>
        <p class="ship-live__qty" data-live-qty>—</p>
        <div class="ship-live__bar"><span data-live-bar style="width: <?= \App\Domain\OrderProgress::percent($shippedDone + $inTransit, $ordered) ?>%"></span></div>
        <p class="muted" data-live-after data-base="<?= \App\Domain\OrderProgress::percent($shippedDone + $inTransit, $ordered) ?>"><?= e(t('delivery.after_shipment', ['pct' => \App\Domain\OrderProgress::percent($shippedDone + $inTransit, $ordered)])) ?></p>
        <p class="muted" data-live-vehicle></p>
    </section>
</aside>
</div>
<?= json_script('ship-i18n', [
    'after' => t('delivery.after_shipment', ['pct' => '__pct__']),
    'transit' => t('delivery.mode.transit'),
    'planned' => t('delivery.mode.planned'),
    'delivered' => t('delivery.review_delivered', ['date' => '__date__', 'by' => '__by__']),
    'none' => '—',
    'labels' => [
        'reference' => t('reference.delivery'), 'shipped_at' => t('delivery.shipped_at'), 'vehicle_plate' => t('delivery.plate'),
        'forwarder_ref' => t('delivery.customs.file_label'), 'trailer_plate' => t('delivery.trailer'), 'driver_name' => t('delivery.driver'), 'driver_phone' => t('delivery.driver_phone'),
        'transporter' => t('delivery.transporter'), 'supplier_waybill' => t('delivery.waybill'),
    ],
    'gap' => t('delivery.gap'),
    'av_ok' => t('delivery.av.status_ok', ['q' => '__q__']),
    'av_missing' => t('delivery.av.status_missing', ['q' => '__q__']),
    'av_over' => t('delivery.av.status_over', ['q' => '__q__']),
    'av_invalid' => t('delivery.av.error.step'),
]) ?>
