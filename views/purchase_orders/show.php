<?php
/**
 * Fiche PO fournisseur (sur le modèle de la fiche PO client) : en-tête, frise, bandeau « Prochaine action » + alertes,
 * indicateurs, lignes toujours visibles, puis onglets Import · Livraisons · Finance · Détails · Historique.
 * @var array $po @var array $lines @var array $deliveries @var array $invoices @var ?array $import @var array $docSummaries @var array $events
 */
use App\Core\View;
use App\Domain\ImportGate;
use App\Domain\OrderProgress;
use App\Security\Auth;

$user = Auth::require();
$id = (int) $po['id'];
$oid = (int) $po['order_id'];
$cur = $po['currency'];
$today = date('Y-m-d');
$st = $po['status'];
$open = $st !== 'cancelled' && !in_array($po['order_status'], ['closed', 'cancelled'], true);

// --- Quantités et montants ---
$total = $ordered = $received = $loaded = $inTransit = 0.0;
foreach ($lines as $l) {
    $total += (float) $l['quantity'] * (float) $l['unit_price'];
    $ordered += (float) $l['quantity'];
    $received += (float) $l['received'];
    $loaded += (float) $l['shipped'];
    $inTransit += (float) $l['in_transit'];
}
$pct = OrderProgress::percent(min($received, $ordered), $ordered);
$toShip = max(0, $ordered - $loaded - $inTransit);
$liveInv = array_values(array_filter($invoices, fn ($i) => $i['status'] !== 'cancelled'));
$invoiced = array_sum(array_map(fn ($i) => (float) $i['amount'], $liveInv));
$paid = array_sum(array_map(fn ($i) => (float) $i['paid'], $liveInv));
$openInv = array_values(array_filter($liveInv, fn ($i) => $i['status'] === 'open'));
$overdueInv = array_values(array_filter($openInv, fn ($i) => $i['due_date'] < $today));
$transit = array_values(array_filter($deliveries, fn ($d) => $d['status'] === 'in_transit'));
$planned = array_values(array_filter($deliveries, fn ($d) => $d['status'] === 'planned'));
$gap = 0.0;
foreach ($deliveries as $d) {
    if ($d['status'] === 'delivered') {
        foreach ($d['lines'] as $dl) {
            $gap += max(0, (float) $dl['quantity_shipped'] - (float) $dl['quantity_received']);
        }
    }
}
$unit = count(array_unique(array_column($lines, 'unit'))) === 1 ? $lines[0]['unit'] : '';

// --- Importation ---
$isImport = $import !== null;
$sum = $import['summary'] ?? null;
$lic = $sum['licence'] ?? null;
$licOk = $lic && ImportGate::licenceValid($lic, $today);
$openAra = $isImport ? array_values(array_filter($import['inspections'], fn ($i) => $i['decision'] === 'ara' && !$i['regularized_by'])) : [];
$cleared = (float) ($sum['cleared_total'] ?? 0);
$shippable = $isImport ? array_sum($sum['shippable']) : $toShip;
$licDays = $lic ? (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($lic['expires_at']))->format('%r%a') : null;

// --- Frise ---
$steps = $isImport ? ['draft', 'sent', 'licence', 'inspection', 'deliveries', 'delivered'] : ['draft', 'sent', 'deliveries', 'delivered'];
$stepLabels = ['draft' => t('po.status.draft'), 'sent' => t('po.status.sent'), 'licence' => t('imports.licence'), 'inspection' => t('imports.step.inspection'),
    'deliveries' => t('nav.deliveries'), 'delivered' => t('po.status.delivered')];
$current = match (true) {
    $st === 'draft' => 'draft',
    $st === 'delivered' => 'delivered',
    (bool) $deliveries => 'deliveries',
    $isImport && !$licOk => 'licence',
    $isImport && ($openAra || $cleared <= 0) => 'inspection',
    $isImport => 'deliveries',
    default => 'sent',
};
$stepIdx = array_search($current, $steps, true);

// --- Prochaine action (code, tonalité) et boutons ---
$next = null;
$cta = [];
$cta2 = null;
$invoiceCta = ['href' => url("/orders/$oid") . '#finance', 'label' => t('po.cta.invoice'), 'icon' => 'file-down'];
if ($open) {
    if ($st === 'draft') {
        $next = ['code' => 'send', 'tone' => 'warn'];
        if ($user->can('sourcing.manage')) {
            $cta = ['form' => url("/purchase-orders/$id/send"), 'label' => t('po.send'), 'icon' => 'send', 'confirm' => t('po.send_confirm')];
            $cta2 = ['href' => url("/purchase-orders/$id/edit"), 'label' => t('common.edit'), 'icon' => 'pencil'];
        }
    } elseif ($isImport && !$lic) {
        $next = ['code' => 'licence', 'tone' => 'warn'];
        if ($user->can('imports.manage')) {
            $cta = ['href' => url('/licences/new', ['po_id' => $id]), 'label' => t('imports.licence_new'), 'icon' => 'stamp'];
            $cta2 = ['href' => url('/licences/import', ['po_id' => $id]), 'label' => t('imports.from_document'), 'icon' => 'scan-text'];
        }
    } elseif ($isImport && !$licOk) {
        $next = ['code' => 'licence_invalid', 'tone' => 'danger'];
        $cta = ['href' => url('/licences/' . (int) $lic['id']), 'label' => t('po.cta.view_licence'), 'icon' => 'stamp'];
    } elseif ($openAra) {
        $next = ['code' => 'ara', 'tone' => 'danger'];
        if ($user->can('imports.manage')) {
            $cta = ['href' => url("/purchase-orders/$id/inspections/new", ['regularizes' => (int) $openAra[0]['id']]), 'label' => t('po.cta.regularize', ['ref' => $openAra[0]['decision_number'] ?: $openAra[0]['reference']]), 'icon' => 'badge-check'];
        }
    } elseif ($transit) {
        $next = ['code' => 'receive', 'tone' => 'neutral'];
        $cta = ['href' => url('/deliveries/' . (int) $transit[0]['id']), 'label' => t('po.cta.receive', ['ref' => $transit[0]['reference']]), 'icon' => 'package-check'];
    } elseif ($st !== 'delivered' && $isImport && $shippable <= 0 && $toShip > 0) {
        $next = ['code' => 'inspection', 'tone' => 'neutral'];
        if ($user->can('imports.manage')) {
            $cta = ['href' => url("/purchase-orders/$id/inspections/new"), 'label' => t('imports.inspection_new'), 'icon' => 'search-check'];
        }
    } elseif ($st !== 'delivered' && $toShip > 0) {
        $next = ['code' => 'ship', 'tone' => 'neutral'];
        if ($user->can('deliveries.manage')) {
            $cta = ['href' => url("/purchase-orders/$id/deliveries/new"), 'label' => t('delivery.new'), 'icon' => 'truck',
                'hint' => t('po.cta_hint.ship', ['q' => fmt_qty($isImport ? min($shippable, $toShip) : $toShip), 'u' => $unit])];
        }
    } elseif (!$liveInv) {
        $next = ['code' => 'invoice', 'tone' => 'warn'];
        $cta = $user->can('finance.manage') ? $invoiceCta : [];
    } elseif ($openInv) {
        $next = ['code' => 'pay', 'tone' => $overdueInv ? 'danger' : 'warn'];
        $cta = $user->can('finance.manage') ? ['href' => url("/orders/$oid") . '#finance', 'label' => t('po.cta.pay', ['amount' => fmt_money($invoiced - $paid, $cur)]), 'icon' => 'hand-coins'] : [];
    } elseif ($st !== 'delivered') {
        $next = ['code' => 'follow', 'tone' => 'neutral'];
        $cta = ['href' => '#deliveries', 'label' => t('orders.cta.view_deliveries'), 'icon' => 'truck'];
    } else {
        $next = ['code' => 'done', 'tone' => 'success'];
    }
    // Livré sans facture fournisseur : la saisir est proposé à côté
    if (!$liveInv && $received > 0 && $user->can('finance.manage') && ($next['code'] ?? '') !== 'invoice' && !$cta2) {
        $cta2 = $invoiceCta;
    }
}

// --- Alertes : [tonalité, icône, texte, ancre] ---
$alerts = [];
if ($open) {
    if ($po['expected_date'] && $po['expected_date'] < $today && in_array($st, ['sent', 'partial'], true)) {
        $late = (int) (new DateTimeImmutable($po['expected_date']))->diff(new DateTimeImmutable($today))->format('%a');
        $alerts[] = ['danger', 'calendar-x', t('po.alert.late', ['n' => $late]), '#details'];
    }
    if ($lic && $licOk && $licDays !== null && $licDays <= 30 && $st !== 'delivered') {
        $alerts[] = ['warn', 'calendar-clock', t('po.alert.licence_expiring', ['n' => $licDays]), '#import'];
    }
    if ($openAra) {
        $alerts[] = ['danger', 'octagon-x', t('po.alert.ara', ['n' => count($openAra)]), '#import'];
    }
    if ($planned) {
        $alerts[] = ['info', 'clipboard-list', t('po.alert.planned', ['n' => count($planned)]), '#deliveries'];
    }
    if ($transit) {
        $alerts[] = ['info', 'truck', t('orders.alert.transit', ['n' => count($transit)]), '#deliveries'];
    }
    if ($gap > 0) {
        $alerts[] = ['warn', 'scale', t('orders.alert.gap', ['q' => fmt_qty($gap), 'u' => $unit]), '#deliveries'];
    }
    if ($overdueInv) {
        $alerts[] = ['danger', 'wallet', t('orders.alert.pay_overdue', ['n' => count($overdueInv)]), '#finance'];
    }
}

// --- Onglets ---
$tabs = [];
if ($isImport) {
    $tabs['import'] = ['stamp', t('po.tab.import'), $lic ? count($import['inspections']) : null];
}
$tabs += [
    'deliveries' => ['truck', t('nav.deliveries'), count($deliveries) ?: null],
    'finance' => ['banknote', t('orders.nav.finance'), count($invoices) ?: null],
    'details' => ['info', t('po.tab.details'), null],
    'timeline' => ['history', t('orders.nav.timeline'), count($events) ?: null],
];
$tabOf = ['import' => 'import', 'deliveries' => 'deliveries', 'finance' => 'finance', 'details' => 'details', 'timeline' => 'timeline'];
$tabAlerts = [];
foreach ($alerts as $a) {
    if ($tk = $tabOf[substr($a[3], 1)] ?? null) {
        $tabAlerts[$tk] = true;
    }
}
$activeTab = match (true) {
    $st === 'draft' => 'details',
    $isImport && in_array($current, ['licence', 'inspection'], true) => 'import',
    $st === 'delivered' => 'finance',
    default => 'deliveries',
};
$panel = fn (string $k) => '<div class="tabs__panel" role="tabpanel" id="panel-' . $k . '" aria-labelledby="tab-' . $k . '" data-tab-panel="' . $k . '" tabindex="-1"' . ($k === $activeTab ? '' : ' hidden') . '>';
?>
<div data-live-region="po">
<?= View::partial('partials/dossier_bar', ['kind' => 'po', 'orderId' => (int) $po['order_id'], 'poId' => (int) $po['id']]) ?>
<section class="hero">
    <div class="hero__icon"><?= icon('shopping-cart') ?></div>
    <div class="hero__body">
        <div class="hero__line">
            <h1 class="hero__code"><?= e($po['reference']) ?></h1>
            <?= View::partial('partials/status_badge', ['key' => 'po.status.' . $st, 'status' => $st]) ?>
            <?php if ($isImport): ?><span class="mode-tag mode-tag--import"><?= e(t('order.mode_short.import')) ?></span><?php endif ?>
        </div>
        <p class="hero__name"><a href="<?= e(url('/suppliers/' . (int) $po['supplier_id'])) ?>"><strong><?= e($po['supplier_name']) ?></strong></a> · <?= e(fmt_money($total, $cur)) ?></p>
    </div>
    <div class="hero__actions">
        <a class="btn"<?= preview_attrs(url("/purchase-orders/$id/print"), t('print.po_title') . ' — ' . $po['reference'], null, 'html') ?>><?= icon('printer') ?> <?= e(t('po.print')) ?></a>
        <?php if ($user->can('sourcing.manage') && $st !== 'cancelled' && !$deliveries && !$invoices): ?>
        <div class="dropdown">
            <button type="button" class="icon-btn" data-menu-toggle aria-haspopup="true" aria-expanded="false" title="<?= e(t('common.more')) ?>" aria-label="<?= e(t('common.more')) ?>"><?= icon('ellipsis') ?></button>
            <div class="menu" data-menu role="menu" hidden>
                <button type="button" class="menu__item" role="menuitem" data-open="#tpl-po-cancel" data-title="<?= e(t('po.cancel')) ?>"><?= icon('ban') ?> <?= e(t('po.cancel')) ?></button>
            </div>
        </div>
        <template id="tpl-po-cancel">
            <form method="post" action="<?= e(url("/purchase-orders/$id/cancel")) ?>" class="form modal-form" data-confirm-variant="danger">
                <?= csrf_field() ?>
                <div class="field"><label class="required"><?= e(t('common.reason')) ?></label><textarea name="note" rows="2" required maxlength="400"></textarea></div>
            </form>
        </template>
        <?php endif ?>
    </div>
</section>

<?php if ($st !== 'cancelled'): ?>
<ol class="stepper" aria-label="<?= e(t('orders.progress')) ?>">
    <?php foreach ($steps as $i => $s):
        $state = $stepIdx === false ? 'todo' : ($i < $stepIdx ? 'done' : ($i === $stepIdx ? ($s === 'delivered' ? 'done' : 'current') : 'todo'));
        if ($s === 'licence' && $lic && !$licOk) { $state = 'current'; } ?>
        <li class="stepper__step stepper__step--<?= $state ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>><span class="stepper__dot"><?= $state === 'done' ? icon('check') : $i + 1 ?></span><span><?= e($stepLabels[$s]) ?></span></li>
    <?php endforeach ?>
</ol>
<?php endif ?>

<?= View::partial('orders/_action_center', ['o' => $po, 'next' => $next, 'cta' => $cta, 'cta2' => $cta2, 'alerts' => $alerts, 'open' => $open, 'prefix' => 'po.next.']) ?>

<section class="vitals">
    <div class="vital"><?= icon('receipt', 'vital__icon') ?><span class="vital__label"><?= e(t('po.vital.amount')) ?></span><span class="vital__value"><?= e(fmt_money($total, $cur)) ?></span></div>
    <div class="vital"><?= icon('truck', 'vital__icon vital__icon--' . ($pct >= 100 ? 'good' : ($pct > 0 ? 'warn' : 'bad'))) ?><span class="vital__label"><?= e(t('po.vital.received')) ?></span><span class="vital__value"><?= $pct ?> %</span>
        <span class="meter"><span class="meter__bar" style="width: <?= $pct ?>%"></span></span></div>
    <?php if ($isImport): ?>
        <div class="vital"><?= icon('badge-check', 'vital__icon vital__icon--' . ($openAra ? 'bad' : ($cleared > 0 ? 'good' : 'warn'))) ?><span class="vital__label"><?= e(t('imports.col.cleared')) ?></span><span class="vital__value"><?= e(fmt_qty($cleared)) ?> <?= e($unit) ?></span></div>
    <?php endif ?>
    <div class="vital"><?= icon('route', 'vital__icon') ?><span class="vital__label"><?= e(t('orders.col.in_transit')) ?></span><span class="vital__value"><?= $inTransit > 0 ? e(fmt_qty($inTransit)) . ' ' . e($unit) : '—' ?></span></div>
    <div class="vital"><?= icon('file-down', 'vital__icon') ?><span class="vital__label"><?= e(t('po.vital.invoiced')) ?></span><span class="vital__value"><?= e(fmt_money($invoiced, $cur)) ?></span></div>
    <div class="vital<?= $overdueInv ? ' vital--alert' : '' ?>"><?= icon('hand-coins', 'vital__icon vital__icon--' . ($invoiced > 0 && $paid >= $invoiced - 0.005 ? 'good' : ($overdueInv ? 'bad' : 'warn'))) ?><span class="vital__label"><?= e(t('po.vital.paid')) ?></span><span class="vital__value"><?= e(fmt_money($paid, $cur)) ?></span></div>
</section>

<!-- Lignes : toujours visibles -->
<section class="card" id="lines">
    <div class="card__title"><h2><?= icon('list', 'title-icon') ?> <?= e(t('orders.section.lines')) ?></h2></div>
    <div class="table-wrap">
    <table class="table table--compact">
        <thead><tr>
            <th><?= e(t('orders.col.product')) ?></th>
            <th class="num"><?= e(t('movements.col.quantity')) ?></th>
            <th class="num"><?= e(t('po.col.purchase_price')) ?></th>
            <th class="num"><?= e(t('orders.col.total')) ?></th>
            <?php if ($isImport): ?><th class="num"><?= e(t('imports.col.cleared')) ?></th><?php endif ?>
            <th class="num"><?= e(t('po.col.loaded')) ?></th>
            <th class="num"><?= e(t('orders.col.in_transit')) ?></th>
            <th class="num"><?= e(t('orders.col.delivered')) ?></th>
            <th><?= e(t('orders.progress')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $l):
            $q = (float) $l['quantity'];
            $lp = OrderProgress::percent(min((float) $l['received'], $q), $q); ?>
            <tr>
                <td><strong><?= e($l['item_name']) ?></strong> <?php if ($l['hs_code']): ?><span class="hs">SH <?= e($l['hs_code']) ?></span><?php endif ?>
                    <br><span class="muted mono"><?= e($l['sku']) ?></span><?= $l['specification'] ? ' <span class="muted">· ' . e($l['specification']) . '</span>' : '' ?></td>
                <td class="num"><?= e(fmt_qty($q)) ?> <?= e($l['unit']) ?></td>
                <td class="num"><?= e(fmt_number($l['unit_price'])) ?></td>
                <td class="num"><?= e(fmt_number($q * (float) $l['unit_price'])) ?></td>
                <?php if ($isImport): ?><td class="num"><?= ($c = (float) ($sum['cleared'][(int) $l['id']] ?? 0)) > 0 ? e(fmt_qty($c)) : '<span class="muted">—</span>' ?></td><?php endif ?>
                <td class="num"><?= (float) $l['shipped'] > 0 ? e(fmt_qty($l['shipped'])) : '<span class="muted">—</span>' ?></td>
                <td class="num"><?= (float) $l['in_transit'] > 0 ? e(fmt_qty($l['in_transit'])) : '<span class="muted">—</span>' ?></td>
                <td class="num"><strong><?= e(fmt_qty($l['received'])) ?></strong></td>
                <td><span class="meter meter--inline"><span class="meter__bar" style="width: <?= $lp ?>%"></span></span> <span class="muted"><?= $lp ?> %</span></td>
            </tr>
        <?php endforeach ?>
        </tbody>
        <tfoot><tr><th colspan="3"><?= e(t('common.total')) ?></th><th class="num"><?= e(fmt_money($total, $cur)) ?></th><th colspan="<?= $isImport ? 5 : 4 ?>"></th></tr></tfoot>
    </table>
    </div>
</section>

<div class="tabs" data-tabs>
<div class="tabs__list" role="tablist" aria-label="<?= e(t('po.tabs_label')) ?>">
    <?php foreach ($tabs as $k => [$ic, $label, $count]): $on = $k === $activeTab; ?>
        <button type="button" class="tabs__tab" role="tab" id="tab-<?= $k ?>" aria-controls="panel-<?= $k ?>" aria-selected="<?= $on ? 'true' : 'false' ?>" tabindex="<?= $on ? '0' : '-1' ?>" data-tab="<?= $k ?>">
            <?= icon($ic) ?> <span><?= e($label) ?></span><?php if ($count !== null): ?> <span class="tabs__count"><?= (int) $count ?></span><?php endif ?><?php if (isset($tabAlerts[$k])): ?><span class="alert-dot" title="<?= e(t('orders.has_alert')) ?>"></span><?php endif ?>
        </button>
    <?php endforeach ?>
</div>

<?php if ($isImport): ?>
<?= $panel('import') ?>
    <?= View::partial('imports/_panel', ['po' => $po, 'lines' => $lines, 'summary' => $sum, 'inspections' => $import['inspections'], 'embedded' => true]) ?>
</div>
<?php endif ?>

<?= $panel('deliveries') ?>
<section class="card" id="deliveries">
    <div class="card__title">
        <h2><?= icon('truck', 'title-icon') ?> <?= e(t('nav.deliveries')) ?></h2>
        <?php if ($open && in_array($st, ['sent', 'partial'], true) && $user->can('deliveries.manage')): ?>
            <div class="page-actions"><a class="btn btn--sm btn--primary" href="<?= e(url("/purchase-orders/$id/deliveries/new")) ?>"><?= icon('plus') ?> <?= e(t('delivery.new')) ?></a></div>
        <?php endif ?>
    </div>
    <?php if (!$deliveries): ?>
        <p class="empty"><?= e($st === 'draft' ? t('po.deliveries_after_send') : t('delivery.none_yet')) ?></p>
    <?php else: ?>
    <div class="dl-summary">
        <span><strong><?= count($deliveries) ?></strong> <?= e(t('delivery.kpi.shipments')) ?></span>
        <span><?= e(t('delivery.kpi.loaded')) ?> <strong><?= e(fmt_qty($loaded + $inTransit)) ?> <?= e($unit) ?></strong></span>
        <span><?= e(t('delivery.kpi.received')) ?> <strong><?= e(fmt_qty($received)) ?> <?= e($unit) ?></strong></span>
        <?php if ($gap > 0): ?><span class="text-bad"><?= e(t('delivery.gap')) ?> <strong>−<?= e(fmt_qty($gap)) ?> <?= e($unit) ?></strong></span><?php endif ?>
        <?php if ($toShip > 0 && $st !== 'draft'): ?><span class="text-warn"><?= e(t('po.to_ship')) ?> <strong><?= e(fmt_qty($toShip)) ?> <?= e($unit) ?></strong></span><?php endif ?>
    </div>
    <div class="table-wrap">
    <table class="table table--compact" data-dt-client data-dt-length="10" data-dt-order='[[1,"desc"]]'>
        <thead><tr>
            <th><?= e(t('orders.col.reference')) ?></th>
            <th><?= e(t('delivery.shipped_at')) ?></th>
            <th><?= e(t('delivery.vehicle')) ?></th>
            <th class="num"><?= e(t('delivery.shipped')) ?></th>
            <th class="num"><?= e(t('delivery.received')) ?></th>
            <th class="num"><?= e(t('delivery.gap')) ?></th>
            <th><?= e(t('documents.checklist')) ?></th>
            <th><?= e(t('common.status')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($deliveries as $d):
            $did = (int) $d['id'];
            $sh = array_sum(array_map(fn ($x) => (float) $x['quantity_shipped'], $d['lines']));
            $rc = $d['status'] === 'delivered' ? array_sum(array_map(fn ($x) => (float) $x['quantity_received'], $d['lines'])) : null;
            $ds = $docSummaries[$did] ?? null; ?>
            <tr class="row-link<?= $d['status'] === 'cancelled' ? ' is-muted' : '' ?>" tabindex="0" data-href="<?= e(url('/deliveries/' . $did)) ?>">
                <td data-order="<?= e($d['reference']) ?>"><strong><?= e($d['reference']) ?></strong></td>
                <td data-order="<?= e($d['shipped_at']) ?>"><?= e(fmt_date($d['shipped_at'])) ?><?= $d['delivered_at'] ? ' <span class="muted">→ ' . e(fmt_date($d['delivered_at'])) . '</span>' : '' ?></td>
                <td><span class="mono"><?= e($d['vehicle_plate'] ?: '—') ?></span><?= $d['driver_name'] ? ' <span class="muted">· ' . e($d['driver_name']) . '</span>' : '' ?></td>
                <td class="num" data-order="<?= e((string) $sh) ?>"><?= e(fmt_qty($sh)) ?></td>
                <td class="num" data-order="<?= e((string) ($rc ?? -1)) ?>"><?= $rc !== null ? '<strong>' . e(fmt_qty($rc)) . '</strong>' : '—' ?></td>
                <td class="num"><?= $rc !== null && $sh - $rc > 0 ? '<span class="text-bad">−' . e(fmt_qty($sh - $rc)) . '</span>' : '—' ?></td>
                <td><?php if ($ds && $ds['total']): ?><span class="chip<?= $ds['done'] < $ds['total'] ? ' chip--warn' : ' chip--c-green' ?>"><?= e($ds['done'] . '/' . $ds['total']) ?></span><?php else: ?><span class="muted">—</span><?php endif ?></td>
                <td><?= View::partial('partials/status_badge', ['key' => 'delivery.status.' . $d['status'], 'status' => $d['status']]) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
</div>

<?= $panel('finance') ?>
<section class="card" id="finance">
    <div class="card__title">
        <h2><?= icon('file-down', 'title-icon') ?> <?= e(t('invoice.dir.payable')) ?></h2>
        <?php if ($open && $user->can('finance.manage')): ?>
            <div class="page-actions"><a class="btn btn--sm" href="<?= e(url("/orders/$oid") . '#finance') ?>"><?= icon('external-link') ?> <?= e(t('po.manage_in_order')) ?></a></div>
        <?php endif ?>
    </div>
    <?php if (!$invoices): ?>
        <p class="empty"><?= e(t('invoice.none')) ?></p>
    <?php else: ?>
    <div class="dl-summary">
        <span><?= e(t('po.vital.invoiced')) ?> <strong><?= e(fmt_money($invoiced, $cur)) ?></strong> <span class="muted">/ <?= e(fmt_money($total, $cur)) ?></span></span>
        <span><?= e(t('po.vital.paid')) ?> <strong><?= e(fmt_money($paid, $cur)) ?></strong></span>
        <?php if ($invoiced - $paid > 0.005): ?><span class="text-warn"><?= e(t('invoice.col.balance')) ?> <strong><?= e(fmt_money($invoiced - $paid, $cur)) ?></strong></span><?php endif ?>
    </div>
    <div class="table-wrap">
    <table class="table table--compact">
        <thead><tr><th><?= e(t('orders.col.reference')) ?></th><th><?= e(t('invoice.col.due')) ?></th><th class="num"><?= e(t('orders.col.amount')) ?></th><th class="num"><?= e(t('invoice.col.paid')) ?></th><th class="num"><?= e(t('invoice.col.balance')) ?></th><th><?= e(t('common.status')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $inv):
            $bal = (float) $inv['amount'] - (float) $inv['paid'];
            $overdue = $inv['status'] === 'open' && $inv['due_date'] < $today; ?>
            <tr class="row-link" tabindex="0" data-href="<?= e(url('/invoices/' . (int) $inv['id'])) ?>">
                <td><strong><?= e($inv['reference']) ?></strong><?= $inv['external_number'] ? ' <span class="muted">· N° ' . e($inv['external_number']) . '</span>' : '' ?></td>
                <td class="<?= $overdue ? 'overdue' : '' ?>"><?= e(fmt_date($inv['due_date'])) ?></td>
                <td class="num"><?= e(fmt_money($inv['amount'], $inv['currency'])) ?></td>
                <td class="num" data-order="<?= e((string) (float) $inv['paid']) ?>"><?= e(fmt_number($inv['paid'])) ?>
                        <?php foreach ($inv['payments'] as $py): ?><span class="pay-line" title="<?= e(t('payment.method.' . $py['method']) . ($py['bank_ref'] ? ' · ' . $py['bank_ref'] : '')) ?>"><?= e(fmt_date($py['paid_at'])) ?> · <?= e(fmt_number($py['amount'])) ?><?= $py['bank_ref'] ? ' · ' . e($py['bank_ref']) : '' ?></span><?php endforeach ?></td>
                <td class="num"><strong><?= $inv['status'] === 'cancelled' ? '—' : e(fmt_number($bal)) ?></strong></td>
                <td><?= View::partial('partials/status_badge', ['key' => $overdue ? 'invoice.status.overdue' : 'invoice.status.' . $inv['status'], 'status' => $overdue ? 'overdue' : $inv['status']]) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
</div>

<?= $panel('details') ?>
<div class="grid grid--2-1" id="details">
    <section class="card">
        <div class="card__title"><h2><?= icon('info', 'title-icon') ?> <?= e(t('po.tab.details')) ?></h2></div>
        <dl class="side-facts po-facts">
            <div><dt><?= e(t('orders.col.date')) ?></dt><dd><?= e(fmt_date($po['order_date'])) ?></dd></div>
            <div><dt><?= e(t('po.expected')) ?></dt><dd><?= $po['expected_date'] ? e(fmt_date($po['expected_date'])) : '—' ?></dd></div>
            <div><dt><?= e(t('po.sent_at')) ?></dt><dd><?= $po['sent_at'] ? e(fmt_date($po['sent_at'], true)) : '—' ?></dd></div>
            <div><dt><?= e(t('transport.mode')) ?></dt><dd><?= e(t('transport.' . ($po['transport_mode'] ?? 'road'))) ?></dd></div>
            <div><dt><?= e(t('parties.terms')) ?></dt><dd><?= e(t('parties.terms_short', ['n' => (int) $po['payment_terms_days']])) ?></dd></div>
            <div><dt><?= e(t('movements.currency')) ?></dt><dd><?= e($cur) ?></dd></div>
            <div><dt><?= e(t('po.created_by')) ?></dt><dd><?= e($po['created_by_name']) ?></dd></div>
        </dl>
        <?php if ($po['notes']): ?><p class="prewrap muted mt-2"><?= e($po['notes']) ?></p><?php endif ?>
    </section>
    <div>
        <section class="card">
            <div class="card__title"><h2><?= icon('map-pinned', 'title-icon') ?> <?= e(t('po.delivery_address')) ?></h2></div>
            <p class="prewrap"><?= e($po['delivery_address']) ?></p>
            <?php if ($po['delivery_contact']): ?><p class="muted"><?= icon('user') ?> <?= e($po['delivery_contact']) ?></p><?php endif ?>
        </section>
        <section class="card">
            <div class="card__title"><h2><?= icon('factory', 'title-icon') ?> <?= e(t('sourcing.col.supplier')) ?></h2></div>
            <p><a href="<?= e(url('/suppliers/' . (int) $po['supplier_id'])) ?>"><strong><?= e($po['supplier_name']) ?></strong></a> <span class="muted mono"><?= e($po['supplier_code']) ?></span></p>
            <p class="muted"><?= e(implode(' · ', array_filter([$po['supplier_contact'], $po['supplier_phone'], $po['supplier_email']])) ?: '—') ?></p>
        </section>
    </div>
</div>
</div>

<?= $panel('timeline') ?>
<section class="card" id="timeline">
    <div class="card__title"><h2><?= icon('history', 'title-icon') ?> <?= e(t('orders.section.timeline')) ?></h2></div>
    <?php if (!$events): ?><p class="empty"><?= e(t('po.no_event')) ?></p><?php else: ?>
    <ol class="timeline">
        <?php foreach ($events as $ev): ?>
            <li class="timeline__item timeline__item--<?= e($ev['event']) ?>">
                <span class="timeline__date"><?= e(fmt_date($ev['created_at'], true)) ?> · <?= e($ev['user_name'] ?? '—') ?></span>
                <span><strong><?= e(t('event.' . $ev['event'])) ?></strong><?php if ($ev['detail']): ?> — <?= e($ev['event'] === 'status' ? t('order.status.' . $ev['detail']) : $ev['detail']) ?><?php endif ?></span>
            </li>
        <?php endforeach ?>
    </ol>
    <?php endif ?>
</section>
</div>
</div>
</div>
