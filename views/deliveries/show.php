<?php
/**
 * Fiche d'une expédition (sur le modèle de la fiche PO client) : en-tête, frise, bandeau « Prochaine action » + alertes,
 * indicateurs, puis onglets Documents · Produits · Inspections (import) · Transport · Historique.
 * @var array $d @var array $docs @var array{done:int,total:int} $progress @var ?string $nextStage @var array $blockers
 * @var array $avs AV qui couvrent l'expédition (+ drawn) @var ?array $licence @var array $events @var array $forwarders agences en douane
 */
use App\Core\View;
use App\Domain\Checklist;
use App\Security\Auth;

$user = Auth::require();
$id = (int) $d['id'];
$st = $d['status'];
$open = !in_array($d['order_status'], ['closed', 'cancelled'], true) && $st !== 'cancelled';
$canManage = $open && $user->can('deliveries.manage');
$today = date('Y-m-d');
$isImport = $d['fulfillment_mode'] === 'import';
$pct = $progress['total'] > 0 ? (int) floor($progress['done'] / $progress['total'] * 100) : 100;

// --- Quantités ---
$shipped = $received = $gap = 0.0;
foreach ($d['lines'] as $l) {
    $shipped += (float) $l['quantity_shipped'];
    if ($l['quantity_received'] !== null) {
        $received += (float) $l['quantity_received'];
        $gap += max(0, (float) $l['quantity_shipped'] - (float) $l['quantity_received']);
    }
}
$units = array_unique(array_column($d['lines'], 'unit'));
$unit = count($units) === 1 ? reset($units) : '';
$rejected = array_values(array_filter($docs, fn ($x) => $x['status'] === 'rejected'));
$toValidate = array_values(array_filter($docs, fn ($x) => $x['status'] === 'submitted'));

// --- Prochaine action ---
$next = null;
$cta = [];
$cta2 = null;
if ($open) {
    if (in_array($st, ['planned', 'in_transit'], true) && $blockers) {
        $next = ['code' => $st === 'planned' ? 'docs_departure' : 'docs_reception', 'tone' => 'warn'];
        $cta = ['href' => '#documents', 'label' => t('delivery.cta.docs', ['n' => count($blockers)]), 'icon' => 'file-check-2',
            'hint' => implode(' · ', array_map(fn ($b) => $b['code'] . ' (' . t('documents.status.' . $b['status']) . ')', $blockers))];
    } elseif ($st === 'planned') {
        $next = ['code' => 'depart', 'tone' => 'neutral'];
        $cta = $canManage ? ['form' => url("/deliveries/$id/depart"), 'label' => t('delivery.depart'), 'icon' => 'truck', 'confirm' => t('delivery.depart_confirm')] : [];
    } elseif ($st === 'in_transit') {
        $next = ['code' => 'receive', 'tone' => 'neutral'];
        $cta = $canManage ? ['open' => '#tpl-confirm', 'label' => t('delivery.confirm'), 'icon' => 'package-check', 'title' => t('delivery.confirm') . ' ' . $d['reference']] : [];
    } elseif ($st === 'delivered') {
        $next = ['code' => 'invoice', 'tone' => 'success'];
        $cta = $user->can('finance.manage') ? ['href' => url('/orders/' . (int) $d['order_id']) . '#finance', 'label' => t('delivery.cta.invoice'), 'icon' => 'file-up'] : [];
    }
}
$alerts = [];
if ($open) {
    if ($rejected) {
        $alerts[] = ['danger', 'file-x', t('delivery.alert.rejected', ['n' => count($rejected)]), '#documents'];
    }
    if ($toValidate) {
        $alerts[] = ['info', 'file-clock', t('delivery.alert.to_validate', ['n' => count($toValidate)]), '#documents'];
    }
    // Importation : l'expédition doit être déclarée par une agence en douane, sous son n° de dossier
    if ($isImport && $st !== 'delivered') {
        if (!$d['forwarder_name']) {
            $alerts[] = ['warn', 'landmark', t('delivery.customs.alert_none'), '#transport'];
        } elseif (!$d['forwarder_ref']) {
            $alerts[] = ['info', 'hash', t('delivery.customs.alert_ref', ['agency' => $d['forwarder_name']]), '#transport'];
        }
    }
    if ($gap > 0) {
        $alerts[] = ['warn', 'scale', t('orders.alert.gap', ['q' => fmt_qty($gap), 'u' => $unit]), '#products'];
    }
    if ($licence && !\App\Domain\ImportGate::licenceValid($licence, $today) && $st !== 'delivered') {
        $alerts[] = ['danger', 'calendar-x', t('delivery.alert.licence', ['number' => $licence['cod_number'] ?: $licence['number']]), '#inspections'];
    }
}

// --- Onglets ---
$tabs = ['documents' => ['file-check-2', t('documents.checklist'), $progress['done'] . '/' . $progress['total']], 'products' => ['package', t('orders.nav.lines'), count($d['lines'])]];
if ($isImport) {
    $tabs['inspections'] = ['search-check', t('delivery.tab.inspections'), count($avs) ?: null];
}
$tabs += ['transport' => ['route', t('delivery.tab.transport'), null], 'timeline' => ['history', t('orders.nav.timeline'), count($events) ?: null]];
$tabAlerts = [];
foreach ($alerts as $a) {
    $tabAlerts[substr($a[3], 1)] = true;
}
if ($blockers && $open) {
    $tabAlerts['documents'] = true;
}
$activeTab = ($open && $blockers) || ($open && in_array($st, ['planned', 'in_transit'], true) && $progress['done'] < $progress['total']) ? 'documents' : 'products';
$panel = fn (string $k) => '<div class="tabs__panel" role="tabpanel" id="panel-' . $k . '" aria-labelledby="tab-' . $k . '" data-tab-panel="' . $k . '" tabindex="-1"' . ($k === $activeTab ? '' : ' hidden') . '>';
$steps = ['planned', 'in_transit', 'delivered'];
$idx = array_search($st, $steps, true);
$avLabel = fn (array $a) => $a['decision_number'] ?: $a['reference'];
?>
<div data-live-region="delivery">
<?= View::partial('partials/dossier_bar', ['kind' => 'delivery', 'orderId' => (int) $d['order_id'], 'poId' => (int) $d['po_id'], 'deliveryId' => (int) $d['id']]) ?>
<section class="hero">
    <div class="hero__icon"><?= icon('truck') ?></div>
    <div class="hero__body">
        <div class="hero__line">
            <h1 class="hero__code"><?= e($d['reference']) ?></h1>
            <?= View::partial('partials/status_badge', ['key' => 'delivery.status.' . $st, 'status' => $st]) ?>
            <?php if ($isImport): ?><span class="mode-tag mode-tag--import"><?= e(t('order.mode_short.import')) ?></span><?php endif ?>
            <?php if ($d['vehicle_plate']): ?><span class="tag tag--plate"><?= e($d['vehicle_plate']) ?></span><?php endif ?>
        </div>
        <p class="hero__name"><strong><?= e($d['customer_name']) ?></strong> · <?= e($d['supplier_name']) ?> · <?= e(fmt_date($d['shipped_at'])) ?></p>
        <?php if ($d['forwarder_name']): ?><p class="hero__tags"><span class="tag"><?= icon('landmark') ?> <?= e($d['forwarder_name']) ?><?= $d['forwarder_ref'] ? ' · ' . e($d['forwarder_ref']) : '' ?></span></p><?php endif ?>
    </div>
    <div class="hero__actions">
        <a class="btn"<?= preview_attrs(url("/deliveries/$id/print"), t('delivery.print') . ' — ' . $d['reference'], null, 'html') ?>><?= icon('printer') ?> <?= e(t('delivery.print')) ?></a>
        <?php if ($canManage && in_array($st, ['planned', 'in_transit'], true)): ?>
        <div class="dropdown">
            <button type="button" class="icon-btn" data-menu-toggle aria-haspopup="true" aria-expanded="false" title="<?= e(t('common.more')) ?>" aria-label="<?= e(t('common.more')) ?>"><?= icon('ellipsis') ?></button>
            <div class="menu" data-menu role="menu" hidden>
                <form method="post" action="<?= e(url("/deliveries/$id/checklist")) ?>" data-confirm="<?= e(t('documents.refresh_confirm')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="menu__item" role="menuitem"><?= icon('refresh-cw') ?> <?= e(t('documents.refresh')) ?></button>
                </form>
                <button type="button" class="menu__item" role="menuitem" data-open="#tpl-dcancel" data-title="<?= e(t('delivery.cancel')) ?>"><?= icon('ban') ?> <?= e(t('delivery.cancel')) ?></button>
            </div>
        </div>
        <?php endif ?>
    </div>
</section>

<?php if ($st !== 'cancelled'): ?>
<ol class="stepper" aria-label="<?= e(t('orders.progress')) ?>">
    <?php foreach ($steps as $i => $s):
        $state = $idx === false ? 'todo' : ($i < $idx ? 'done' : ($i === $idx ? ($s === 'delivered' ? 'done' : 'current') : 'todo')); ?>
        <li class="stepper__step stepper__step--<?= $state ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>><span class="stepper__dot"><?= $state === 'done' ? icon('check') : $i + 1 ?></span><span><?= e(t('delivery.status.' . $s)) ?></span></li>
    <?php endforeach ?>
</ol>
<?php endif ?>

<?= View::partial('orders/_action_center', ['o' => $d, 'next' => $next, 'cta' => $cta, 'cta2' => $cta2, 'alerts' => $alerts, 'open' => $open, 'prefix' => 'delivery.next.']) ?>

<section class="vitals">
    <div class="vital"><?= icon('package', 'vital__icon') ?><span class="vital__label"><?= e(t('delivery.shipped')) ?></span><span class="vital__value"><?= e(fmt_qty($shipped)) ?> <?= e($unit) ?></span></div>
    <div class="vital"><?= icon('package-check', 'vital__icon vital__icon--' . ($st === 'delivered' ? 'good' : 'warn')) ?><span class="vital__label"><?= e(t('delivery.received')) ?></span><span class="vital__value"><?= $st === 'delivered' ? e(fmt_qty($received)) . ' ' . e($unit) : '—' ?></span></div>
    <div class="vital<?= $gap > 0 ? ' vital--alert' : '' ?>"><?= icon('scale', 'vital__icon vital__icon--' . ($gap > 0 ? 'bad' : 'good')) ?><span class="vital__label"><?= e(t('delivery.gap')) ?></span><span class="vital__value"><?= $gap > 0 ? '−' . e(fmt_qty($gap)) . ' ' . e($unit) : '—' ?></span></div>
    <div class="vital"><?= icon('file-check-2', 'vital__icon vital__icon--' . ($pct >= 100 ? 'good' : ($blockers ? 'bad' : 'warn'))) ?><span class="vital__label"><?= e(t('documents.checklist')) ?></span><span class="vital__value"><?= $progress['done'] ?>/<?= $progress['total'] ?></span>
        <span class="meter"><span class="meter__bar" style="width: <?= $pct ?>%"></span></span></div>
    <?php if ($isImport): ?>
        <div class="vital"><?= icon('badge-check', 'vital__icon vital__icon--' . ($avs ? 'good' : 'bad')) ?><span class="vital__label"><?= e(t('delivery.vital.avs')) ?></span><span class="vital__value"><?= $avs ? e(implode(' + ', array_map($avLabel, $avs))) : '—' ?></span></div>
    <?php endif ?>
    <div class="vital"><?= icon('landmark', 'vital__icon vital__icon--' . ($d['forwarder_name'] ? ($d['forwarder_ref'] ? 'good' : 'warn') : ($isImport ? 'bad' : ''))) ?><span class="vital__label"><?= e(t('delivery.customs.agency')) ?></span><span class="vital__value"><?= e($d['forwarder_name'] ?: '—') ?><?= $d['forwarder_ref'] ? '<span class="vital__hint muted">' . e(t('delivery.customs.file') . ' ' . $d['forwarder_ref']) . '</span>' : '' ?></span></div>
    <div class="vital"><?= icon('calendar-range', 'vital__icon') ?><span class="vital__label"><?= e(t('delivery.vital.dates')) ?></span><span class="vital__value"><?= e(fmt_date($d['shipped_at'])) ?><?= $d['delivered_at'] ? ' → ' . e(fmt_date($d['delivered_at'])) : '' ?></span></div>
</section>

<div class="tabs" data-tabs>
<div class="tabs__list" role="tablist" aria-label="<?= e(t('delivery.tabs_label')) ?>">
    <?php foreach ($tabs as $k => [$ic, $label, $count]): $on = $k === $activeTab; ?>
        <button type="button" class="tabs__tab" role="tab" id="tab-<?= $k ?>" aria-controls="panel-<?= $k ?>" aria-selected="<?= $on ? 'true' : 'false' ?>" tabindex="<?= $on ? '0' : '-1' ?>" data-tab="<?= $k ?>">
            <?= icon($ic) ?> <span><?= e($label) ?></span><?php if ($count !== null): ?> <span class="tabs__count"><?= e((string) $count) ?></span><?php endif ?><?php if (isset($tabAlerts[$k])): ?><span class="alert-dot" title="<?= e(t('orders.has_alert')) ?>"></span><?php endif ?>
        </button>
    <?php endforeach ?>
</div>

<?= $panel('documents') ?>
<section class="card" id="documents">
    <div class="card__title">
        <h2><?= icon('file-check-2', 'title-icon') ?> <?= e(t('documents.checklist')) ?></h2>
        <span class="doc-progress" title="<?= e(t('documents.progress_hint')) ?>"><span class="doc-progress__bar"><span style="width: <?= $pct ?>%"></span></span><strong><?= $progress['done'] ?>/<?= $progress['total'] ?></strong></span>
    </div>
    <?php if ($open && $nextStage && $blockers): ?>
        <div class="alert alert--error" role="alert"><?= icon('lock') ?> <span><strong><?= e(t('documents.blocking_title', ['stage' => t('documents.stage.' . $nextStage)])) ?></strong> <?= e(implode(' · ', array_map(fn ($b) => $b['code'] . ' (' . t('documents.status.' . $b['status']) . ')', $blockers))) ?></span></div>
    <?php elseif ($open && $nextStage): ?>
        <div class="alert alert--success" role="status"><?= icon('circle-check') ?> <span><?= e(t('documents.ready_for', ['stage' => t('documents.stage.' . $nextStage)])) ?></span></div>
    <?php endif ?>
    <?php if ($isImport && $avs): ?><p class="hint"><?= icon('badge-check') ?> <?= e(t('delivery.av_docs_hint')) ?></p><?php endif ?>
    <?= View::partial('documents/_checklist', ['docs' => $docs, 'back' => 'delivery:' . $id, 'readonly' => !$open]) ?>
</section>
</div>

<?= $panel('products') ?>
<section class="card" id="products">
    <div class="card__title"><h2><?= icon('package', 'title-icon') ?> <?= e(t('orders.nav.lines')) ?></h2></div>
    <div class="table-wrap">
    <table class="table table--compact">
        <thead><tr>
            <th><?= e(t('orders.col.product')) ?></th>
            <th class="num"><?= e(t('delivery.shipped')) ?></th>
            <th class="num"><?= e(t('delivery.received')) ?></th>
            <th class="num"><?= e(t('delivery.gap')) ?></th>
            <?php if ($isImport): ?><th><?= e(t('delivery.av.covered_by')) ?></th><?php endif ?>
        </tr></thead>
        <tbody>
        <?php foreach ($d['lines'] as $l):
            $lg = $l['quantity_received'] !== null ? (float) $l['quantity_shipped'] - (float) $l['quantity_received'] : 0; ?>
            <tr>
                <td><strong><?= e($l['item_name']) ?></strong> <span class="muted mono"><?= e($l['sku']) ?></span><?= $l['specification'] ? '<br><span class="muted">' . e($l['specification']) . '</span>' : '' ?></td>
                <td class="num"><?= e(fmt_qty($l['quantity_shipped'])) ?> <?= e($l['unit']) ?></td>
                <td class="num"><?= $l['quantity_received'] !== null ? '<strong>' . e(fmt_qty($l['quantity_received'])) . '</strong> ' . e($l['unit']) : '<span class="muted">—</span>' ?></td>
                <td class="num"><?= $lg > 0 ? '<span class="text-bad">−' . e(fmt_qty($lg)) . '</span>' : '<span class="muted">—</span>' ?></td>
                <?php if ($isImport): ?><td><?= $l['avs'] ? implode('<br>', array_map(fn ($a) => '<span class="av-cover">' . icon('badge-check') . ' ' . e(($a['decision_number'] ?: $a['reference']) . ' · ' . fmt_qty($a['quantity'])) . '</span>', $l['avs'])) : '<span class="text-warn">' . e(t('delivery.av.none')) . '</span>' ?></td><?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php if ($d['reception_notes'] ?? null): ?><p class="prewrap muted mt-2"><?= icon('message-square') ?> <?= e($d['reception_notes']) ?></p><?php endif ?>
</section>
</div>

<?php if ($isImport): ?>
<?= $panel('inspections') ?>
<section class="card" id="inspections">
    <div class="card__title">
        <h2><?= icon('search-check', 'title-icon') ?> <?= e(t('delivery.tab.inspections')) ?></h2>
        <div class="page-actions"><a class="btn btn--sm btn--ghost" href="<?= e(url('/purchase-orders/' . (int) $d['po_id'] . '#import')) ?>"><?= icon('stamp') ?> <?= e(t('delivery.import.see_import')) ?></a></div>
    </div>
    <?php if ($licence): ?>
        <p class="muted"><?= icon('stamp') ?> <?= e(t('imports.licence')) ?> <strong><?= e($licence['number']) ?></strong><?= $licence['cod_number'] ? ' · ' . e($licence['cod_number']) : '' ?> · <?= e(t('imports.lic.validity')) ?> <?= e(fmt_date($licence['expires_at'])) ?></p>
    <?php endif ?>
    <?php if (!$avs): ?>
        <p class="empty"><?= e(t('delivery.av.none_long')) ?></p>
    <?php else: ?>
    <ul class="insp-list">
        <?php foreach ($avs as $i): ?>
        <li class="insp insp--ok">
            <div class="insp__head">
                <span class="insp__title"><strong class="insp__ref"><?= e($avLabel($i)) ?></strong> <?= View::partial('partials/status_badge', ['key' => 'imports.decision.av', 'status' => 'delivered']) ?></span>
                <span class="insp__tools"><strong><?= e(t('delivery.av.drawn_here', ['q' => fmt_qty($i['drawn']), 'u' => $unit])) ?></strong></span>
            </div>
            <p class="insp__meta"><?= icon('calendar') ?> <?= e(fmt_date($i['inspection_date'])) ?> · <?= e($i['inspector']) ?><?= $i['decision_date'] ? ' · ' . e(t('imports.decided_on', ['date' => fmt_date($i['decision_date'])])) : '' ?></p>
            <p class="insp__qty"><?php foreach ($i['lines'] as $n => $il): ?><?= $n ? ' · ' : '' ?><?= e(t('imports.insp_qty', ['q' => fmt_qty($il['quantity_inspected']) . ' ' . $il['unit']])) ?> → <strong class="text-good"><?= e(t('imports.conform_qty', ['q' => fmt_qty($il['quantity_conform'])])) ?></strong><?php endforeach ?></p>
            <div class="insp__report<?= $i['attachment_id'] ? '' : ' insp__report--none' ?>">
                <?= icon($i['attachment_id'] ? 'file-text' : 'file-x') ?>
                <?php if ($i['attachment_id']): ?>
                    <span class="insp__report-name"><span class="muted"><?= e(t('imports.report')) ?> :</span> <?= e($i['file_name']) ?></span>
                    <a class="btn btn--sm"<?= preview_attrs(url('/attachments/' . (int) $i['attachment_id']), t('imports.report') . ' ' . ($i['report_number'] ?: $i['reference']), $i['file_mime']) ?>><?= icon('eye') ?> <?= e(t('imports.report_view')) ?></a>
                <?php else: ?>
                    <span class="insp__report-name muted"><?= e(t('imports.report_none')) ?></span>
                <?php endif ?>
            </div>
        </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>
</div>
<?php endif ?>

<?= $panel('transport') ?>
<div class="grid grid--2-1" id="transport">
    <div>
    <section class="card customs-card<?= $isImport && !$d['forwarder_name'] ? ' customs-card--missing' : '' ?>">
        <div class="card__title">
            <h2><?= icon('landmark', 'title-icon') ?> <?= e(t('delivery.customs.title')) ?></h2>
            <?php if ($open && $user->can('deliveries.manage')): ?>
                <div class="page-actions"><button type="button" class="btn btn--sm<?= $d['forwarder_name'] && $d['forwarder_ref'] ? '' : ' btn--primary' ?>" data-open="#tpl-customs" data-title="<?= e(t('delivery.customs.title') . ' — ' . $d['reference']) ?>"><?= icon($d['forwarder_name'] ? 'pencil' : 'plus') ?> <?= e(t($d['forwarder_name'] ? 'common.edit' : 'delivery.customs.assign')) ?></button></div>
            <?php endif ?>
        </div>
        <dl class="side-facts po-facts">
            <div><dt><?= e(t('delivery.customs.agency')) ?></dt><dd><?= $d['forwarder_name'] ? '<strong>' . e($d['forwarder_name']) . '</strong>' : '<span class="' . ($isImport ? 'text-warn' : 'muted') . '">' . e(t('delivery.customs.none')) . '</span>' ?></dd></div>
            <div><dt><?= e(t('delivery.customs.file_label')) ?></dt><dd class="mono"><?= $d['forwarder_ref'] ? e($d['forwarder_ref']) : '<span class="muted">' . e(t('delivery.customs.ref_none')) . '</span>' ?></dd></div>
        </dl>
        <p class="hint"><?= e(t('delivery.customs.portal_hint')) ?></p>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= icon('route', 'title-icon') ?> <?= e(t('delivery.tab.transport')) ?></h2></div>
        <dl class="side-facts po-facts">
            <div><dt><?= e(t('transport.mode')) ?></dt><dd><?= e(t('transport.' . $d['transport_mode'])) ?></dd></div>
            <div><dt><?= e(t('delivery.transporter')) ?></dt><dd><?= e($d['transporter'] ?: '—') ?></dd></div>
            <div><dt><?= e(t('delivery.plate')) ?></dt><dd class="mono"><?= e($d['vehicle_plate'] ?: '—') ?><?= $d['trailer_plate'] ? ' · ' . e($d['trailer_plate']) : '' ?></dd></div>
            <div><dt><?= e(t('delivery.driver')) ?></dt><dd><?= e(trim(($d['driver_name'] ?? '') . ' ' . ($d['driver_phone'] ?? '')) ?: '—') ?></dd></div>
            <div><dt><?= e(t('delivery.waybill')) ?></dt><dd class="mono"><?= e($d['supplier_waybill'] ?: '—') ?></dd></div>
            <div><dt><?= e(t('delivery.shipped_at')) ?></dt><dd><?= e(fmt_date($d['shipped_at'])) ?></dd></div>
            <?php if ($d['departed_at']): ?><div><dt><?= e(t('delivery.departed_at')) ?></dt><dd><?= e(fmt_date($d['departed_at'], true)) ?></dd></div><?php endif ?>
            <?php if ($d['delivered_at']): ?><div><dt><?= e(t('delivery.delivered_at')) ?></dt><dd><?= e(fmt_date($d['delivered_at'])) ?> · <?= e($d['received_by']) ?></dd></div><?php endif ?>
        </dl>
        <?php if ($d['notes']): ?><p class="prewrap muted mt-2"><?= e($d['notes']) ?></p><?php endif ?>
    </section>
    </div>
    <section class="card">
        <div class="card__title"><h2><?= icon('map-pinned', 'title-icon') ?> <?= e(t('print.deliver_to')) ?></h2></div>
        <p><strong><?= e($d['customer_name']) ?></strong></p>
        <p class="prewrap muted"><?= e($d['delivery_address']) ?></p>
        <?php if ($d['delivery_contact']): ?><p class="muted"><?= icon('phone') ?> <?= e($d['delivery_contact']) ?></p><?php endif ?>
    </section>
</div>
</div>

<?= $panel('timeline') ?>
<section class="card" id="timeline">
    <div class="card__title"><h2><?= icon('history', 'title-icon') ?> <?= e(t('orders.section.timeline')) ?></h2></div>
    <?php if (!$events): ?><p class="empty"><?= e(t('delivery.no_event')) ?></p><?php else: ?>
    <ol class="timeline">
        <?php foreach ($events as $ev): ?>
            <li class="timeline__item timeline__item--<?= e($ev['event']) ?>">
                <span class="timeline__date"><?= e(fmt_date($ev['created_at'], true)) ?> · <?= e($ev['user_name'] ?? '—') ?></span>
                <span><strong><?= e(t('event.' . $ev['event'])) ?></strong><?php if ($ev['detail']): ?> — <?= e($ev['detail']) ?><?php endif ?></span>
            </li>
        <?php endforeach ?>
    </ol>
    <?php endif ?>
</section>
</div>
</div>

<?php if ($canManage && $st === 'in_transit'): ?>
<template id="tpl-confirm">
    <form method="post" action="<?= e(url("/deliveries/$id/confirm")) ?>" class="form modal-form">
        <?= csrf_field() ?>
        <div class="form-grid form-grid--modal">
            <div class="field"><label class="required"><?= e(t('delivery.delivered_at')) ?></label><input name="delivered_at" type="date" required value="<?= e($today) ?>" max="<?= e($today) ?>"></div>
            <div class="field"><label class="required"><?= e(t('delivery.received_by')) ?></label><input name="received_by" maxlength="160" required placeholder="<?= e(t('delivery.received_by_placeholder')) ?>"></div>
        </div>
        <?php foreach ($d['lines'] as $l): ?>
            <div class="field field--inline"><label><?= e($l['sku']) ?> <span class="muted">· <?= e(t('delivery.shipped')) ?> <?= e(fmt_qty($l['quantity_shipped'])) ?> <?= e($l['unit']) ?></span></label>
                <input name="received[<?= (int) $l['id'] ?>]" type="number" step="0.001" min="0" max="<?= e((string) $l['quantity_shipped']) ?>" required value="<?= e((string) (float) $l['quantity_shipped']) ?>" inputmode="decimal"></div>
        <?php endforeach ?>
        <p class="hint"><?= e(t('delivery.gap_hint')) ?></p>
        <div class="field"><label><?= e(t('delivery.reception_notes')) ?></label><textarea name="reception_notes" rows="2" maxlength="500"></textarea></div>
    </form>
</template>
<?php endif ?>
<?php if ($open && $user->can('deliveries.manage')): ?>
<template id="tpl-customs">
    <form method="post" action="<?= e(url("/deliveries/$id/customs")) ?>" class="form modal-form">
        <?= csrf_field() ?>
        <div class="field"><label for="customs-agency"><?= e(t('delivery.customs.agency')) ?></label>
            <select id="customs-agency" name="forwarder_id">
                <option value=""><?= e(t('common.none')) ?></option>
                <?php foreach ($forwarders as $fw): ?><option value="<?= (int) $fw['id'] ?>" <?= $d['forwarder_name'] === $fw['name'] ? 'selected' : '' ?>><?= e($fw['name']) ?></option><?php endforeach ?>
            </select>
            <?php if (!$forwarders): ?><p class="hint"><a href="<?= e(url('/customs-agencies')) ?>"><?= e(t('delivery.customs.no_agency')) ?></a></p><?php endif ?></div>
        <div class="field"><label for="customs-ref"><?= e(t('delivery.customs.file_label')) ?></label><input id="customs-ref" name="forwarder_ref" maxlength="60" class="mono" value="<?= e($d['forwarder_ref'] ?? '') ?>" autocomplete="off"><p class="hint"><?= e(t('delivery.customs.file_hint')) ?></p></div>
    </form>
</template>
<?php endif ?>
<?php if ($canManage): ?>
<template id="tpl-dcancel">
    <form method="post" action="<?= e(url("/deliveries/$id/cancel")) ?>" class="form modal-form" data-confirm-variant="danger">
        <?= csrf_field() ?>
        <div class="field"><label class="required"><?= e(t('common.reason')) ?></label><textarea name="note" rows="2" required maxlength="300"></textarea></div>
    </form>
</template>
<?php endif ?>
</div>
