<?php
/**
 * Dossier PO client — traçabilité complète : lignes → sourcing → PO fournisseurs → livraisons → factures/paiements → clôture.
 * @var array $order @var array $lines @var array $quotes @var array $pos @var array $deliveries @var array $invoices
 * @var array $events @var array $attachments @var array $facts @var array $checks @var array $suppliers @var array $currencies
 */
use App\Core\View;
use App\Security\Auth;

$user = Auth::require();
$o = $order;
$id = (int) $o['id'];
$cur = $o['currency'];
$open = !in_array($o['status'], ['closed', 'cancelled'], true);
$editable = $open && in_array($o['status'], ['received', 'sourcing'], true) && !$pos;

$total = $ordered = $delivered = 0.0;
foreach ($lines as $l) {
    $total += (float) $l['quantity'] * (float) $l['unit_price'];
    $ordered += (float) $l['quantity'];
    $delivered += min((float) $l['quantity'], (float) $l['delivered']);
}
$pct = \App\Domain\OrderProgress::percent($delivered, $ordered);
$receivables = array_values(array_filter($invoices, fn ($i) => $i['direction'] === 'receivable'));
$payables = array_values(array_filter($invoices, fn ($i) => $i['direction'] === 'payable'));
$sum = fn (array $list, string $k) => array_sum(array_map(fn ($i) => $i['status'] === 'cancelled' ? 0 : (float) $i[$k], $list));
$costByCur = [];
foreach ($pos as $p) {
    if ($p['status'] !== 'cancelled') {
        $costByCur[$p['currency']] = ($costByCur[$p['currency']] ?? 0) + (float) $p['amount'];
    }
}
$activePos = array_values(array_filter($pos, fn ($p) => in_array($p['status'], ['sent', 'partial'], true)));
$invoicablePos = array_values(array_filter($pos, fn ($p) => in_array($p['status'], ['sent', 'partial', 'delivered'], true)));
$toInvoice = max(0, round($facts['delivered_value'] - $facts['invoiced_value'], 2));
// Facturation client par expédition : expéditions livrées sans facture client active
$invoicableDeliveries = array_values(array_filter($deliveries, fn ($d) => $d['status'] === 'delivered' && $d['invoice_id'] === null));
// Importation : étapes licence et inspection ; un ARA (« bloqué ») se place sur l'étape inspection.
$steps = $o['fulfillment_mode'] === 'import'
    ? ['received', 'sourcing', 'awaiting_licence', 'inspection', 'ordered', 'in_delivery', 'delivered', 'closed']
    : ['received', 'sourcing', 'ordered', 'in_delivery', 'delivered', 'closed'];
$stepIdx = array_search($o['status'] === 'blocked' ? 'inspection' : $o['status'], $steps, true);
$today = date('Y-m-d');

// --- Livraisons : totaux (section, alertes et export) ---
$dlRows = [];
$dlTot = ['count' => 0, 'shipped' => 0.0, 'received' => 0.0, 'gap' => 0.0, 'transit' => 0];
$dlUnits = [];
foreach ($deliveries as $d) {
    $shippedQ = array_sum(array_map(fn ($l) => (float) $l['quantity_shipped'], $d['lines']));
    $receivedQ = $d['status'] === 'delivered' ? array_sum(array_map(fn ($l) => (float) $l['quantity_received'], $d['lines'])) : null;
    $units = array_unique(array_column($d['lines'], 'unit'));
    $dlUnits = array_merge($dlUnits, $units);
    $docSum = $docSummaries[(int) $d['id']] ?? null;
    $dlRows[] = [
        'id' => (int) $d['id'], 'reference' => $d['reference'], 'status' => $d['status'], 'status_label' => t('delivery.status.' . $d['status']),
        'po_reference' => $d['po_reference'], 'supplier_name' => $d['supplier_name'],
        'products' => implode(', ', array_unique(array_column($d['lines'], 'sku'))), 'unit' => implode('/', $units),
        'transport_mode' => t('transport.' . ($d['transport_mode'] ?? 'road')),
        'transporter' => $d['transporter'], 'vehicle_plate' => $d['vehicle_plate'], 'trailer_plate' => $d['trailer_plate'],
        'driver_name' => $d['driver_name'], 'driver_phone' => $d['driver_phone'], 'supplier_waybill' => $d['supplier_waybill'],
        'shipped_at' => $d['shipped_at'], 'delivered_at' => $d['delivered_at'], 'received_by' => $d['received_by'],
        'shipped' => $shippedQ, 'received' => $receivedQ, 'gap' => $receivedQ !== null ? $shippedQ - $receivedQ : null,
        'docs_done' => $docSum['done'] ?? null, 'docs_total' => $docSum['total'] ?? null,
        'docs' => $docSum ? $docSum['done'] . '/' . $docSum['total'] : '',
    ];
    if ($d['status'] !== 'cancelled') {
        $dlTot['count']++;
        $dlTot['shipped'] += $shippedQ;
        $dlTot['received'] += (float) $receivedQ;
        $dlTot['gap'] += (float) ($receivedQ !== null ? $shippedQ - $receivedQ : 0);
        $dlTot['transit'] += in_array($d['status'], ['planned', 'in_transit'], true) ? 1 : 0;
    }
}
$dlUnit = count(array_unique($dlUnits)) === 1 ? reset($dlUnits) : '';
$dlExport = [
    'title' => t('delivery.export_title', ['ref' => $o['reference']]),
    'context' => $o['customer_name'] . ' · ' . t('orders.col.customer_po') . ' ' . $o['customer_po_number'],
    'summary' => [
        [t('delivery.kpi.shipments'), $dlTot['count']],
        [t('delivery.kpi.loaded') . ($dlUnit ? ' (' . $dlUnit . ')' : ''), $dlTot['shipped']],
        [t('delivery.kpi.received') . ($dlUnit ? ' (' . $dlUnit . ')' : ''), $dlTot['received']],
        [t('delivery.gap') . ($dlUnit ? ' (' . $dlUnit . ')' : ''), $dlTot['gap']],
        [t('orders.vital.delivered'), $pct / 100, 'pct'],
        [t('delivery.kpi.open'), $dlTot['transit']],
    ],
];
// --- Prochaine action, échéance, alertes ---
$draftPos = array_values(array_filter($pos, fn ($p) => $p['status'] === 'draft'));
$next = \App\Domain\OrderNextAction::for($o['status'], count($draftPos));
$daysLeft = $o['required_date'] ? (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($o['required_date']))->format('%r%a') : null;
$pending = $open && $o['status'] !== 'delivered';
$overdueRec = count(array_filter($receivables, fn ($i) => $i['status'] === 'open' && $i['due_date'] < $today));
$overduePay = count(array_filter($payables, fn ($i) => $i['status'] === 'open' && $i['due_date'] < $today));
$openInv = count(array_filter($invoices, fn ($i) => $i['status'] === 'open'));
$failedChecks = array_values(array_filter($checks, fn ($c) => !$c['ok']));
$docsMissing = (int) ($facts['documents_missing'] ?? 0);
$showChecklist = $user->can('documents.view') && $dossierDocs;
$poWhere = function (callable $test) use ($pos, $importStates) {
    foreach ($pos as $p) {
        if ($p['status'] !== 'cancelled' && $test($p, $importStates[(int) $p['id']] ?? null)) {
            return $p;
        }
    }
    return null;
};
$inTransit = array_values(array_filter($deliveries, fn ($d) => $d['status'] === 'in_transit'));

// Bouton principal selon l'action (null : information seule, faute de droit ou de cible)
$cta = [];
$cta2 = null;
if ($next && $open) {
    switch ($next['code']) {
        case 'source':
            if ($user->can('sourcing.manage')) {
                $cta = ['open' => '#tpl-quote', 'label' => t('sourcing.add_quote'), 'icon' => 'plus', 'hint' => t('orders.cta_hint.source')];
                $cta2 = ['href' => url("/orders/$id/purchase-orders/new"), 'label' => t('sourcing.po_without_quote'), 'icon' => 'file-plus'];
            }
            break;
        case 'issue_po':
            $best = null;
            foreach ($quotes as $q) {
                if ($q['status'] === 'pending') { $best = $q; break; }
            }
            if ($best && $user->can('sourcing.manage')) {
                $cta = ['href' => url("/orders/$id/purchase-orders/new", ['quote_id' => (int) $best['id']]), 'label' => t('orders.cta.select_quote', ['supplier' => $best['supplier_name']]), 'icon' => 'check',
                    'hint' => $best['margin_base'] !== null ? t('orders.cta_hint.best_quote', ['margin' => fmt_number($best['margin_pct'], 1)]) : null];
                $cta2 = ['href' => '#sourcing', 'label' => t('orders.cta.compare'), 'icon' => 'scale'];
            } else {
                $cta = ['href' => '#sourcing', 'label' => t('orders.cta.compare'), 'icon' => 'scale'];
            }
            break;
        case 'send_po':
            $d0 = $draftPos[0];
            $cta = $user->can('sourcing.manage')
                ? ['form' => url('/purchase-orders/' . (int) $d0['id'] . '/send'), 'label' => t('orders.cta.send_po', ['ref' => $d0['reference']]), 'icon' => 'send', 'confirm' => t('orders.cta.send_po_confirm', ['ref' => $d0['reference'], 'supplier' => $d0['supplier_name']])]
                : [];
            $cta2 = ['href' => url('/purchase-orders/' . (int) $d0['id']), 'label' => t('orders.cta.view_po'), 'icon' => 'eye'];
            break;
        case 'licence':
            $p = $poWhere(fn ($p, $st) => $st !== null && !$st['licence']);
            if ($p && $user->can('imports.manage')) {
                $cta = ['href' => url('/licences/new', ['po_id' => (int) $p['id'], 'back' => '/orders/' . (int) $order['id'] . '#purchasing']), 'label' => t('orders.cta.licence', ['ref' => $p['reference']]), 'icon' => 'stamp'];
                $cta2 = ['href' => url('/licences/import', ['po_id' => (int) $p['id']]), 'label' => t('imports.from_document'), 'icon' => 'scan-text'];
            }
            break;
        case 'inspection':
            $p = $poWhere(fn ($p, $st) => $st !== null && $st['valid'] && !$st['open_ara']);
            if ($p && $user->can('imports.manage')) {
                $cta = ['href' => url('/purchase-orders/' . (int) $p['id'] . '/inspections/new'), 'label' => t('orders.cta.inspection', ['ref' => $p['reference']]), 'icon' => 'search-check'];
            }
            break;
        case 'ara':
            $p = $poWhere(fn ($p, $st) => $st !== null && $st['open_ara']);
            if ($p) {
                $cta = ['href' => url('/purchase-orders/' . (int) $p['id']) . '#import', 'label' => t('orders.cta.ara', ['ref' => $p['reference']]), 'icon' => 'octagon-x'];
            }
            break;
        case 'ship':
            if ($activePos && $user->can('deliveries.manage')) {
                $cta = ['href' => url('/purchase-orders/' . (int) $activePos[0]['id'] . '/deliveries/new'), 'label' => t('orders.cta.ship', ['ref' => $activePos[0]['reference']]), 'icon' => 'truck'];
            }
            break;
        case 'follow':
            if ($inTransit && $user->can('deliveries.manage')) {
                $cta = ['open' => '#tpl-confirm-' . (int) $inTransit[0]['id'], 'label' => t('orders.cta.confirm', ['ref' => $inTransit[0]['reference']]), 'icon' => 'package-check', 'title' => t('delivery.confirm') . ' ' . $inTransit[0]['reference']];
            } elseif ($activePos && $user->can('deliveries.manage')) {
                $cta = ['href' => url('/purchase-orders/' . (int) $activePos[0]['id'] . '/deliveries/new'), 'label' => t('orders.cta.ship', ['ref' => $activePos[0]['reference']]), 'icon' => 'truck'];
            } else {
                $cta = ['href' => '#deliveries', 'label' => t('orders.cta.view_deliveries'), 'icon' => 'truck'];
            }
            break;
        case 'close':
            $blocking = array_values(array_filter($failedChecks, fn ($c) => $c['blocking']));
            $cta = ['href' => '#closure', 'label' => t($blocking ? 'orders.cta.closure_points' : 'closure.close'), 'icon' => 'flag',
                'hint' => $blocking ? t('orders.cta_hint.closure', ['n' => count($blocking)]) : null];
            break;
    }
    // Livré non facturé : facturer le client est toujours proposé à côté
    if ($toInvoice > 0 && $invoicableDeliveries && $user->can('finance.manage') && !in_array($next['code'], ['source', 'issue_po', 'send_po'], true)) {
        $inv = ['open' => '#tpl-inv-dl-' . (int) $invoicableDeliveries[0]['id'], 'label' => t('orders.cta.invoice', ['amount' => fmt_money($toInvoice, $cur)]), 'icon' => 'file-up', 'title' => t('invoice.new_receivable') . ' ' . $invoicableDeliveries[0]['reference']];
        if (empty($cta['label']) || $next['code'] === 'close') {
            [$cta, $cta2] = [$inv, empty($cta['label']) ? null : $cta];
        } else {
            $cta2 = $inv;
        }
    }
}

// Alertes : [tonalité, icône, texte, ancre]
$alerts = [];
if ($open) {
    if ($pending && $daysLeft !== null && $daysLeft < 0) {
        $alerts[] = ['danger', 'calendar-x', t('orders.alert.late', ['n' => -$daysLeft]), '#lines'];
    } elseif ($pending && $daysLeft !== null && $daysLeft <= 7) {
        $alerts[] = ['warn', 'calendar-clock', $daysLeft === 0 ? t('orders.alert.due_today') : t('orders.alert.due_soon', ['n' => $daysLeft]), '#lines'];
    }
    if ($o['status'] === 'blocked') {
        $alerts[] = ['danger', 'octagon-x', t('orders.alert.ara'), '#purchase-orders'];
    }
    if ($draftPos && ($next['code'] ?? '') !== 'send_po') {
        $alerts[] = ['warn', 'send', t('orders.alert.draft_po', ['n' => count($draftPos)]), '#purchase-orders'];
    }
    if ($dlTot['transit']) {
        $alerts[] = ['info', 'truck', t('orders.alert.transit', ['n' => $dlTot['transit']]), '#deliveries'];
    }
    if ($dlTot['gap'] > 0) {
        $alerts[] = ['warn', 'scale', t('orders.alert.gap', ['q' => fmt_qty($dlTot['gap']), 'u' => $dlUnit]), '#deliveries'];
    }
    if ($toInvoice > 0) {
        $alerts[] = ['warn', 'file-up', t('orders.alert.to_invoice', ['amount' => fmt_money($toInvoice, $cur)]), '#finance'];
    }
    if ($overdueRec) {
        $alerts[] = ['danger', 'hand-coins', t('orders.alert.rec_overdue', ['n' => $overdueRec]), '#finance'];
    }
    if ($overduePay) {
        $alerts[] = ['warn', 'wallet', t('orders.alert.pay_overdue', ['n' => $overduePay]), '#finance'];
    }
    if ($docsMissing) {
        $alerts[] = ['warn', 'file-warning', t('orders.alert.docs', ['n' => $docsMissing]), $showChecklist ? '#checklist' : '#deliveries'];
    }
}
$alertIn = array_count_values(array_map(fn ($a) => substr($a[3], 1), $alerts));

// --- Onglets : détail du dossier regroupé ; l'onglet de l'étape en cours s'ouvre par défaut ---
$st = $o['status'];
$tabOf = ['lines' => 'products', 'sourcing' => 'purchasing', 'purchase-orders' => 'purchasing', 'deliveries' => 'deliveries', 'finance' => 'finance',
    'checklist' => 'documents', 'documents' => 'documents', 'closure' => 'closure', 'timeline' => 'timeline'];
$tabAlerts = [];
foreach ($alerts as $a) {
    if ($tk = $tabOf[substr($a[3], 1)] ?? null) {
        $tabAlerts[$tk] = ($tabAlerts[$tk] ?? 0) + 1;
    }
}
$tabs = [
    'products' => ['package', t('orders.nav.lines'), count($lines) ?: null],
    'purchasing' => ['shopping-cart', t('orders.tab.purchasing'), count($pos) ?: (count($quotes) ?: null)],
    'deliveries' => ['truck', t('orders.nav.deliveries'), $dlTot['count'] ?: null],
    'finance' => ['banknote', t('orders.nav.finance'), count($invoices) ?: null],
    'documents' => ['files', t('orders.tab.documents'), (count($attachments) + ($showChecklist ? count($dossierDocs) : 0)) ?: null],
    'closure' => ['flag', t('orders.nav.closure'), $open ? (count($checks) - count($failedChecks)) . '/' . count($checks) : null],
    'timeline' => ['history', t('orders.nav.timeline'), count($events) ?: null],
];
$activeTab = match (true) {
    in_array($st, ['ordered', 'in_delivery'], true) => 'deliveries',
    $st === 'delivered' => 'finance',
    in_array($st, ['closed', 'cancelled'], true) => 'closure',
    default => 'purchasing',
};
$panel = fn (string $k) => '<div class="tabs__panel" role="tabpanel" id="panel-' . $k . '" aria-labelledby="tab-' . $k . '" data-tab-panel="' . $k . '" tabindex="-1"' . ($k === $activeTab ? '' : ' hidden') . '>';
?>
<div data-live-region="order">
<?= View::partial('partials/dossier_bar', ['kind' => 'order', 'orderId' => (int) $order['id']]) ?>
<section class="hero">
    <div class="hero__icon"><?= icon('clipboard-list') ?></div>
    <div class="hero__body">
        <div class="hero__line">
            <h1 class="hero__code"><?= e($o['reference']) ?></h1>
            <?= View::partial('partials/status_badge', ['key' => 'order.status.' . $o['status'], 'status' => $o['status']]) ?>
            <?php if ($open && $o['required_date'] && $o['required_date'] < $today && $o['status'] !== 'delivered'): ?>
                <?= View::partial('partials/status_badge', ['key' => 'orders.filter.late', 'status' => 'late']) ?>
            <?php endif ?>
        </div>
        <p class="hero__name"><a href="<?= e(url('/customers/' . (int) $o['customer_id'])) ?>"><strong><?= e($o['customer_name']) ?></strong></a> · <?= e(t('orders.col.customer_po')) ?> <strong><?= e($o['customer_po_number']) ?></strong> <?= e(t('common.of')) ?> <?= e(fmt_date($o['po_date'])) ?></p>
        <p class="hero__tags">
            <span class="tag"><?= icon('store') ?> <?= e(t('order.mode.' . $o['fulfillment_mode'])) ?></span>
            <span class="tag"><?= icon('map-pin') ?> <?= e($o['site_name']) ?></span>
            <?php if ($o['delivery_site_name']): ?><span class="tag"><?= icon('map-pinned') ?> <?= e($o['delivery_site_name']) ?></span><?php endif ?>
            <?php if ($o['required_date']): ?><span class="tag"><?= icon('calendar') ?> <?= e(t('orders.col.required_date')) ?> <?= e(fmt_date($o['required_date'])) ?></span><?php endif ?>
            <span class="tag"><?= icon('calendar-clock') ?> <?= e(t('parties.terms_short', ['n' => (int) $o['payment_terms_days']])) ?></span>
        </p>
    </div>
    <div class="hero__actions">
        <?php if ($editable && $user->can('orders.manage')): ?>
            <a class="btn" href="<?= e(url("/orders/$id/edit")) ?>"><?= icon('pencil') ?> <?= e(t('common.edit')) ?></a>
        <?php endif ?>
        <?php if ($open && $user->can('orders.manage')): ?>
        <div class="dropdown">
            <button type="button" class="icon-btn" data-menu-toggle aria-haspopup="true" aria-expanded="false" title="<?= e(t('common.more')) ?>" aria-label="<?= e(t('common.more')) ?>"><?= icon('ellipsis') ?></button>
            <div class="menu" data-menu role="menu" hidden>
                <button type="button" class="menu__item" role="menuitem" data-open="#tpl-cancel-order" data-title="<?= e(t('orders.cancel')) ?>"><?= icon('ban') ?> <?= e(t('orders.cancel')) ?></button>
            </div>
        </div>
        <template id="tpl-cancel-order">
            <form method="post" action="<?= e(url("/orders/$id/cancel")) ?>" class="form modal-form" data-confirm-variant="danger">
                <?= csrf_field() ?>
                <p class="muted"><?= e(t('orders.cancel_hint')) ?></p>
                <div class="field"><label class="required"><?= e(t('common.reason')) ?></label><textarea name="note" rows="2" required maxlength="500"></textarea></div>
            </form>
        </template>
        <?php endif ?>
    </div>
</section>

<ol class="stepper" aria-label="<?= e(t('orders.progress')) ?>">
    <?php foreach ($steps as $i => $s):
        $state = $o['status'] === 'cancelled' ? 'todo' : ($stepIdx === false ? 'todo' : ($i < $stepIdx ? 'done' : ($i === $stepIdx ? 'current' : 'todo'))); ?>
        <li class="stepper__step stepper__step--<?= $state ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>><span class="stepper__dot"><?= $state === 'done' ? icon('check') : $i + 1 ?></span><span><?= e(t('order.status.' . $s)) ?></span></li>
    <?php endforeach ?>
</ol>

<?= View::partial('orders/_action_center', ['o' => $o, 'next' => $next, 'cta' => $cta, 'cta2' => $cta2, 'alerts' => $alerts, 'open' => $open]) ?>

<section class="vitals">
    <div class="vital"><?= icon('receipt', 'vital__icon') ?><span class="vital__label"><?= e(t('orders.vital.amount')) ?></span><span class="vital__value"><?= e(fmt_money($total, $cur)) ?></span></div>
    <div class="vital"><?= icon('truck', 'vital__icon vital__icon--' . ($pct >= 100 ? 'good' : ($pct > 0 ? 'warn' : 'bad'))) ?><span class="vital__label"><?= e(t('orders.vital.delivered')) ?></span><span class="vital__value"><?= $pct ?> %</span>
        <span class="meter"><span class="meter__bar" style="width: <?= $pct ?>%"></span></span></div>
    <div class="vital"><?= icon('file-text', 'vital__icon') ?><span class="vital__label"><?= e(t('orders.vital.invoiced')) ?></span><span class="vital__value"><?= e(fmt_money($facts['invoiced_value'], $cur)) ?></span></div>
    <div class="vital<?= $facts['receivable_open'] > 0 ? '' : '' ?>"><?= icon('hand-coins', 'vital__icon vital__icon--' . ($facts['receivable_open'] > 0 ? 'warn' : 'good')) ?><span class="vital__label"><?= e(t('orders.vital.collected')) ?></span><span class="vital__value"><?= e(fmt_money($sum($receivables, 'paid'), $cur)) ?></span></div>
    <div class="vital"><?= icon('shopping-cart', 'vital__icon') ?><span class="vital__label"><?= e(t('orders.vital.cost')) ?></span><span class="vital__value"><?= $costByCur ? e(implode(' + ', array_map(fn ($c, $v) => fmt_money($v, $c), array_keys($costByCur), $costByCur))) : '—' ?></span></div>
    <?php if (count($costByCur) === 1 && isset($costByCur[$cur])):
        $margin = $total - $costByCur[$cur]; ?>
        <div class="vital<?= $margin < 0 ? ' vital--alert' : '' ?>"><?= icon('trending-up', 'vital__icon vital__icon--' . ($margin >= 0 ? 'good' : 'bad')) ?><span class="vital__label"><?= e(t('orders.vital.margin')) ?></span><span class="vital__value"><?= e(fmt_money($margin, $cur)) ?><?= $total > 0 ? ' · ' . e(fmt_number($margin / $total * 100, 1)) . ' %' : '' ?></span></div>
    <?php endif ?>
</section>


<div class="tabs" data-tabs>
<div class="tabs__list" role="tablist" aria-label="<?= e(t('orders.nav.label')) ?>">
    <?php foreach ($tabs as $k => [$ic, $label, $count]): $on = $k === $activeTab; ?>
        <button type="button" class="tabs__tab" role="tab" id="tab-<?= $k ?>" aria-controls="panel-<?= $k ?>" aria-selected="<?= $on ? 'true' : 'false' ?>" tabindex="<?= $on ? '0' : '-1' ?>" data-tab="<?= $k ?>">
            <?= icon($ic) ?> <span><?= e($label) ?></span><?php if ($count !== null): ?> <span class="tabs__count"><?= e((string) $count) ?></span><?php endif ?><?php if (isset($tabAlerts[$k])): ?><span class="alert-dot" title="<?= e(t('orders.has_alert')) ?>"></span><?php endif ?>
        </button>
    <?php endforeach ?>
</div>
<?= $panel('products') ?>
<!-- 1. Lignes et avancement -->
<section class="card" id="lines">
    <div class="card__title"><h2><?= icon('list', 'title-icon') ?> <?= e(t('orders.section.lines')) ?></h2></div>
    <div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th><?= e(t('orders.col.product')) ?></th>
            <th class="num"><?= e(t('orders.col.ordered')) ?></th>
            <th class="num"><?= e(t('orders.col.unit_price')) ?></th>
            <th class="num"><?= e(t('orders.col.purchased')) ?></th>
            <th class="num"><?= e(t('orders.col.in_transit')) ?></th>
            <th class="num"><?= e(t('orders.col.delivered')) ?></th>
            <th class="num"><?= e(t('orders.col.remaining')) ?></th>
            <th><?= e(t('orders.progress')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $l):
            $q = (float) $l['quantity'];
            $rem = max(0, $q - (float) $l['delivered']);
            $lp = \App\Domain\OrderProgress::percent((float) $l['delivered'], $q); ?>
            <tr>
                <td><strong><?= e($l['sku']) ?></strong> <span class="chip chip--c-<?= e($l['family_color']) ?>"><?= e($l['family_name']) ?></span><br><span class="muted"><?= e($l['item_name']) ?><?= $l['specification'] ? ' · ' . e($l['specification']) : '' ?></span></td>
                <td class="num"><?= e(fmt_qty($q)) ?> <?= e($l['unit']) ?></td>
                <td class="num"><?= e(fmt_number($l['unit_price'])) ?></td>
                <td class="num<?= (float) $l['purchased'] + 1e-9 < $q && $open ? ' text-warn' : '' ?>"><?= e(fmt_qty($l['purchased'])) ?></td>
                <td class="num"><?= (float) $l['in_transit'] > 0 ? e(fmt_qty($l['in_transit'])) : '—' ?></td>
                <td class="num"><strong><?= e(fmt_qty($l['delivered'])) ?></strong></td>
                <td class="num<?= $rem > 0 ? ' text-warn' : '' ?>"><?= $rem > 0 ? e(fmt_qty($rem)) : '✓' ?></td>
                <td><span class="meter meter--inline"><span class="meter__bar" style="width: <?= $lp ?>%"></span></span> <span class="muted"><?= $lp ?> %</span></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php if ($o['notes']): ?><p class="prewrap muted mt-2"><?= e($o['notes']) ?></p><?php endif ?>
</section>
</div>

<?= $panel('purchasing') ?>
<!-- 2. Sourcing -->
<section class="card" id="sourcing">
    <div class="card__title">
        <h2><?= icon('search', 'title-icon') ?> <?= e(t('orders.section.sourcing')) ?></h2>
        <?php if ($open && $user->can('sourcing.manage')): ?>
            <div class="page-actions">
                <button type="button" class="btn btn--sm" data-open="#tpl-quote" data-title="<?= e(t('sourcing.add_quote')) ?>"><?= icon('plus') ?> <?= e(t('sourcing.add_quote')) ?></button>
                <a class="btn btn--sm btn--ghost" href="<?= e(url("/orders/$id/purchase-orders/new")) ?>"><?= icon('file-plus') ?> <?= e(t('sourcing.po_without_quote')) ?></a>
            </div>
        <?php endif ?>
    </div>
    <?php if (!$quotes): ?>
        <div class="empty-state"><?= icon('search') ?><p><?= e(t('sourcing.none')) ?></p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th><?= e(t('sourcing.col.supplier')) ?></th>
            <?php foreach ($lines as $l): ?><th class="num"><?= e($l['sku']) ?></th><?php endforeach ?>
            <th class="num"><?= e(t('sourcing.col.cost')) ?></th>
            <th class="num"><?= e(t('sourcing.col.margin')) ?></th>
            <th><?= e(t('sourcing.col.lead')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th class="actions-col"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($quotes as $i => $q): ?>
            <tr class="<?= $q['status'] === 'rejected' ? 'is-muted' : ($i === 0 && $q['margin_base'] !== null ? 'is-best' : '') ?>">
                <td><strong><?= e($q['supplier_name']) ?></strong><br><span class="muted"><?= e($q['quote_ref'] ?: '—') ?> · <?= e(fmt_date($q['quote_date'])) ?></span></td>
                <?php foreach ($lines as $l): ?>
                    <td class="num"><?php if (isset($q['prices'][(int) $l['id']])):
                        $qq = $q['quantities'][(int) $l['id']] ?? null; ?>
                        <?= e(fmt_number($q['prices'][(int) $l['id']])) ?>
                        <?php if ($qq !== null && $qq + 1e-9 < (float) $l['quantity']): ?><br><span class="chip chip--warn"><?= e(fmt_qty($qq)) ?> <?= e($l['unit']) ?></span><?php endif ?>
                    <?php else: ?><span class="muted">—</span><?php endif ?></td>
                <?php endforeach ?>
                <td class="num"><?= e(fmt_money($q['cost'], $q['currency'])) ?><?= $q['complete'] ? '' : '<br><span class="chip chip--warn">' . e(t('sourcing.partial')) . '</span>' ?></td>
                <td class="num"><?php if ($q['margin_base'] !== null): ?>
                    <strong class="<?= $q['margin_base'] < 0 ? 'text-bad' : 'text-good' ?>"><?= e(fmt_money($q['margin_base'])) ?></strong><br><span class="muted"><?= e(fmt_number($q['margin_pct'], 1)) ?> %</span>
                <?php else: ?><span class="muted" title="<?= e(t('sourcing.no_rate')) ?>">?</span><?php endif ?></td>
                <td><?= $q['lead_time_days'] !== null ? e(t('sourcing.days', ['n' => (int) $q['lead_time_days']])) : '—' ?><?php if ($q['valid_until']): ?><br><span class="muted<?= $q['expired'] ? ' text-bad' : '' ?>"><?= e(t('sourcing.valid_until')) ?> <?= e(fmt_date($q['valid_until'])) ?></span><?php endif ?></td>
                <td><?= View::partial('partials/status_badge', ['key' => 'quote.status.' . $q['status'], 'status' => $q['status']]) ?></td>
                <td class="actions-col">
                    <?php if ($open && $q['status'] === 'pending' && $user->can('sourcing.manage')): ?>
                        <a class="btn btn--sm btn--primary" href="<?= e(url("/orders/$id/purchase-orders/new", ['quote_id' => (int) $q['id']])) ?>"><?= icon('check') ?> <?= e(t('sourcing.select')) ?></a>
                        <form method="post" action="<?= e(url("/orders/$id/quotes/" . (int) $q['id'] . '/reject')) ?>" class="inline" data-confirm-variant="danger" data-confirm="<?= e(t('sourcing.reject_confirm')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="icon-btn" title="<?= e(t('sourcing.reject')) ?>" aria-label="<?= e(t('sourcing.reject')) ?>"><?= icon('x') ?></button>
                        </form>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
        <tfoot><tr><td class="muted"><?= e(t('sourcing.sale_price')) ?> (<?= e($cur) ?>)</td>
            <?php foreach ($lines as $l): ?><td class="num muted"><?= e(fmt_number($l['unit_price'])) ?></td><?php endforeach ?>
            <td class="num muted"><?= e(fmt_money($total, $cur)) ?></td><td colspan="4"></td></tr></tfoot>
    </table>
    </div>
    <p class="hint"><?= e(t('sourcing.margin_hint', ['base' => base_currency()])) ?></p>
    <?php endif ?>
    <template id="tpl-quote">
        <form method="post" action="<?= e(url("/orders/$id/quotes")) ?>" class="form modal-form quote-form">
            <?= csrf_field() ?>
            <div class="quote-form__head">
                <div class="field quote-form__supplier"><label class="required"><?= e(t('sourcing.col.supplier')) ?></label>
                    <select name="supplier_id" required>
                        <option value=""><?= e(t('common.choose')) ?></option>
                        <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" data-currency="<?= e($s['currency']) ?>"><?= e($s['name']) ?></option><?php endforeach ?>
                    </select>
                    <?php if (!$suppliers): ?><p class="hint"><a href="<?= e(url('/suppliers/new')) ?>"><?= e(t('sourcing.create_supplier_first')) ?></a></p><?php endif ?>
                </div>
                <div class="field"><label><?= e(t('sourcing.quote_ref')) ?></label><input name="quote_ref" maxlength="80"></div>
                <div class="field"><label class="required"><?= e(t('orders.col.date')) ?></label><input name="quote_date" type="date" required value="<?= e($today) ?>"></div>
                <div class="field"><label class="required"><?= e(t('movements.currency')) ?></label>
                    <select name="currency" required data-fill-from="supplier_id:currency" data-quote-currency>
                        <?php foreach ($currencies as $c): ?><option value="<?= e($c['code']) ?>" <?= $c['code'] === $cur ? 'selected' : '' ?>><?= e($c['code']) ?></option><?php endforeach ?>
                    </select></div>
                <div class="field"><label><?= e(t('sourcing.col.lead')) ?></label><input name="lead_time_days" type="number" min="0" step="1" placeholder="<?= e(t('sourcing.days_placeholder')) ?>"></div>
                <div class="field"><label><?= e(t('sourcing.valid_until')) ?></label><input name="valid_until" type="date"></div>
            </div>

            <table class="table quote-table" data-quote-table data-sale-currency="<?= e($cur) ?>">
                <thead><tr>
                    <th><?= e(t('orders.col.product')) ?></th>
                    <th class="num"><?= e(t('orders.col.ordered')) ?></th>
                    <th class="num"><?= e(t('sourcing.sale_price')) ?></th>
                    <th class="num quote-table__input"><?= e(t('sourcing.quoted_qty')) ?></th>
                    <th class="num quote-table__input"><?= e(t('sourcing.quoted_price')) ?></th>
                    <th class="num"><?= e(t('orders.col.total')) ?></th>
                    <th class="num"><?= e(t('sourcing.col.margin')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($lines as $l): ?>
                    <tr data-qty="<?= e((string) (float) $l['quantity']) ?>" data-sale="<?= e((string) (float) $l['unit_price']) ?>">
                        <td><strong><?= e($l['sku']) ?></strong><span class="muted quote-table__name"><?= e($l['item_name']) ?></span></td>
                        <td class="num"><?= e(fmt_qty($l['quantity'])) ?> <span class="muted"><?= e($l['unit']) ?></span></td>
                        <td class="num muted"><?= e(fmt_number($l['unit_price'])) ?></td>
                        <td class="quote-table__input"><input name="quantities[<?= (int) $l['id'] ?>]" type="number" step="0.001" min="0.001" inputmode="decimal" value="<?= e((string) (float) $l['quantity']) ?>" aria-label="<?= e(t('sourcing.quoted_qty') . ' ' . $l['sku']) ?>" data-quote-qty></td>
                        <td class="quote-table__input"><input name="prices[<?= (int) $l['id'] ?>]" type="number" step="0.000001" min="0" inputmode="decimal" aria-label="<?= e(t('sourcing.quoted_price') . ' ' . $l['sku']) ?>" data-quote-price></td>
                        <td class="num" data-quote-total>—</td>
                        <td class="num" data-quote-margin>—</td>
                    </tr>
                <?php endforeach ?>
                </tbody>
                <tfoot><tr>
                    <th colspan="5"><?= e(t('common.total')) ?> <span class="muted" data-quote-cur><?= e($cur) ?></span></th>
                    <th class="num" data-quote-sum>—</th>
                    <th class="num" data-quote-sum-margin>—</th>
                </tr></tfoot>
            </table>
            <p class="hint"><?= e(t('sourcing.prices_hint')) ?></p>

            <div class="field"><label><?= e(t('movements.note')) ?></label><textarea name="notes" rows="2" maxlength="500"></textarea></div>
        </form>
    </template>
</section>

<!-- 3. PO fournisseurs -->
<section class="card" id="purchase-orders">
    <div class="card__title"><h2><?= icon('shopping-cart', 'title-icon') ?> <?= e(t('nav.purchase_orders')) ?></h2></div>
    <?php if (!$pos): ?>
        <p class="empty"><?= e(t('po.none')) ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th><?= e(t('orders.col.reference')) ?></th><th><?= e(t('sourcing.col.supplier')) ?></th><th><?= e(t('orders.col.date')) ?></th><th class="num"><?= e(t('orders.col.amount')) ?></th><th><?= e(t('common.status')) ?></th><th class="actions-col"></th></tr></thead>
        <tbody>
        <?php foreach ($pos as $p): ?>
            <tr class="row-link" tabindex="0" data-href="<?= e(url('/purchase-orders/' . (int) $p['id'])) ?>">
                <td><strong><?= e($p['reference']) ?></strong></td>
                <td><?= e($p['supplier_name']) ?></td>
                <td><?= e(fmt_date($p['order_date'])) ?><?= $p['expected_date'] ? '<br><span class="muted">' . e(t('po.expected')) . ' ' . e(fmt_date($p['expected_date'])) . '</span>' : '' ?></td>
                <td class="num"><?= e(fmt_money($p['amount'], $p['currency'])) ?></td>
                <td><?= View::partial('partials/status_badge', ['key' => 'po.status.' . $p['status'], 'status' => $p['status']]) ?>
                    <?php if ($st = $importStates[(int) $p['id']] ?? null): ?><br>
                        <?php if (!$st['licence']): ?><a class="chip chip--warn" href="<?= e(url('/purchase-orders/' . (int) $p['id'] . '#import')) ?>"><?= icon('stamp') ?> <?= e(t('imports.chip.no_licence')) ?></a>
                        <?php elseif (!$st['valid']): ?><span class="chip chip--warn"><?= icon('calendar-x') ?> <?= e(t('imports.chip.licence_expired')) ?></span>
                        <?php elseif ($st['open_ara']): ?><span class="chip chip--c-red"><?= icon('octagon-x') ?> ARA</span>
                        <?php elseif ($st['cleared'] <= 0): ?><span class="chip"><?= icon('search-check') ?> <?= e(t('imports.chip.inspection')) ?></span>
                        <?php else: ?><span class="chip chip--c-green"><?= icon('badge-check') ?> AV</span><?php endif ?>
                    <?php endif ?></td>
                <td class="actions-col">
                    <?php if ($open && in_array($p['status'], ['sent', 'partial'], true) && $user->can('deliveries.manage')): ?>
                        <a class="btn btn--sm" href="<?= e(url('/purchase-orders/' . (int) $p['id'] . '/deliveries/new')) ?>"><?= icon('truck') ?> <?= e(t('delivery.new')) ?></a>
                    <?php endif ?>
                    <a class="icon-btn"<?= preview_attrs(url('/purchase-orders/' . (int) $p['id'] . '/print'), t('print.po_title') . ' — ' . $p['reference'], null, 'html') ?> title="<?= e(t('print.print')) ?>" aria-label="<?= e(t('print.print')) ?>"><?= icon('printer') ?></a>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
</div>

<?= $panel('deliveries') ?>
<!-- 4. Livraisons : tableau compact (DataTables côté client, 5 lignes) + export Excel exécutif -->
<section class="card" id="deliveries">
    <div class="card__title">
        <h2><?= icon('truck', 'title-icon') ?> <?= e(t('nav.deliveries')) ?></h2>
        <?php if ($deliveries): ?>
            <div class="page-actions">
                <button type="button" class="btn btn--sm" data-export="excel" data-export-table="order_deliveries" data-export-json="order-deliveries-data" data-export-title="<?= e($dlExport['title']) ?>"><?= icon('file-spreadsheet') ?> <?= e(t('delivery.export_excel')) ?></button>
            </div>
        <?php endif ?>
    </div>
    <?php if (!$deliveries): ?>
        <p class="empty"><?= e($activePos ? t('delivery.none_yet') : t('delivery.need_po')) ?></p>
    <?php else: ?>
    <div class="dl-summary">
        <span><strong><?= $dlTot['count'] ?></strong> <?= e(t('delivery.kpi.shipments')) ?></span>
        <span><?= e(t('delivery.kpi.loaded')) ?> <strong><?= e(fmt_qty($dlTot['shipped'])) ?> <?= e($dlUnit) ?></strong></span>
        <span><?= e(t('delivery.kpi.received')) ?> <strong><?= e(fmt_qty($dlTot['received'])) ?> <?= e($dlUnit) ?></strong></span>
        <?php if ($dlTot['gap'] > 0): ?><span class="text-bad"><?= e(t('delivery.gap')) ?> <strong>−<?= e(fmt_qty($dlTot['gap'])) ?> <?= e($dlUnit) ?></strong></span><?php endif ?>
        <?php if ($dlTot['transit']): ?><span class="text-warn"><?= e(t('delivery.kpi.open')) ?> <strong><?= $dlTot['transit'] ?></strong></span><?php endif ?>
    </div>
    <div class="table-wrap">
    <table class="table table--compact" data-dt-client data-dt-length="5" data-dt-order='[[2,"desc"]]'>
        <thead><tr>
            <th><?= e(t('orders.col.reference')) ?></th>
            <th><?= e(t('sourcing.col.supplier')) ?></th>
            <th><?= e(t('delivery.shipped_at')) ?></th>
            <th><?= e(t('delivery.vehicle')) ?></th>
            <th class="num"><?= e(t('delivery.shipped')) ?></th>
            <th class="num"><?= e(t('delivery.received')) ?></th>
            <th class="num"><?= e(t('delivery.gap')) ?></th>
            <th><?= e(t('documents.checklist')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th class="actions-col" data-orderable="false"><span class="sr-only"><?= e(t('common.actions')) ?></span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($deliveries as $i => $d):
            $r = $dlRows[$i];
            $did = (int) $d['id']; ?>
            <tr class="row-link<?= $d['status'] === 'cancelled' ? ' is-muted' : '' ?>" tabindex="0" data-href="<?= e(url('/deliveries/' . $did)) ?>">
                <td data-order="<?= e($d['reference']) ?>"><strong><?= e($d['reference']) ?></strong><br><span class="muted"><?= e($r['products']) ?></span></td>
                <td><?= e($d['supplier_name']) ?><br><span class="muted"><?= e($d['po_reference']) ?></span></td>
                <td data-order="<?= e($d['shipped_at']) ?>"><?= e(fmt_date($d['shipped_at'])) ?><?= $d['delivered_at'] ? '<br><span class="muted">→ ' . e(fmt_date($d['delivered_at'])) . '</span>' : '' ?></td>
                <td><span class="mono"><?= e($d['vehicle_plate'] ?: '—') ?></span><?= $d['driver_name'] ? '<br><span class="muted">' . e($d['driver_name']) . '</span>' : '' ?></td>
                <td class="num" data-order="<?= e((string) $r['shipped']) ?>"><?= e(fmt_qty($r['shipped'])) ?> <span class="muted"><?= e($r['unit']) ?></span></td>
                <td class="num" data-order="<?= e((string) ($r['received'] ?? -1)) ?>"><?= $r['received'] !== null ? '<strong>' . e(fmt_qty($r['received'])) . '</strong>' : '—' ?></td>
                <td class="num" data-order="<?= e((string) ($r['gap'] ?? 0)) ?>"><?= $r['gap'] > 0 ? '<span class="text-bad">−' . e(fmt_qty($r['gap'])) . '</span>' : '—' ?></td>
                <td data-order="<?= e((string) ($r['docs_total'] ? $r['docs_done'] / $r['docs_total'] : 1)) ?>"><?php if ($r['docs_total']): ?><a class="chip<?= $r['docs_done'] < $r['docs_total'] ? ' chip--warn' : ' chip--c-green' ?>" href="<?= e(url('/deliveries/' . $did . '#documents')) ?>"><?= icon('file-check-2') ?> <?= e($r['docs']) ?></a><?php else: ?><span class="muted">—</span><?php endif ?></td>
                <td data-order="<?= e((string) array_search($d['status'], ['planned', 'in_transit', 'delivered', 'cancelled'], true)) ?>"><?= View::partial('partials/status_badge', ['key' => 'delivery.status.' . $d['status'], 'status' => $d['status']]) ?></td>
                <td class="actions-col">
                    <?php if ($open && $d['status'] === 'in_transit' && $user->can('deliveries.manage')): ?>
                        <button type="button" class="icon-btn" data-open="#tpl-confirm-<?= $did ?>" data-title="<?= e(t('delivery.confirm') . ' ' . $d['reference']) ?>" title="<?= e(t('delivery.confirm')) ?>" aria-label="<?= e(t('delivery.confirm')) ?>"><?= icon('package-check') ?></button>
                    <?php endif ?>
                    <?php if ($open && in_array($d['status'], ['planned', 'in_transit'], true) && $user->can('deliveries.manage')): ?>
                        <button type="button" class="icon-btn icon-btn--danger" data-open="#tpl-dcancel-<?= $did ?>" data-title="<?= e(t('delivery.cancel')) ?>" title="<?= e(t('delivery.cancel')) ?>" aria-label="<?= e(t('delivery.cancel')) ?>"><?= icon('ban') ?></button>
                    <?php endif ?>
                    <a class="icon-btn"<?= preview_attrs(url('/deliveries/' . $did . '/print'), t('delivery.print') . ' — ' . $d['reference'], null, 'html') ?> title="<?= e(t('delivery.print')) ?>" aria-label="<?= e(t('delivery.print')) ?>"><?= icon('printer') ?></a>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?= json_script('order-deliveries-data', ['meta' => $dlExport, 'rows' => $dlRows]) ?>

    <?php foreach ($deliveries as $d): $did = (int) $d['id']; ?>
        <?php if ($open && $d['status'] === 'in_transit'): ?>
        <template id="tpl-confirm-<?= $did ?>">
            <form method="post" action="<?= e(url('/deliveries/' . $did . '/confirm')) ?>" class="form modal-form">
                <?= csrf_field() ?>
                <div class="form-grid form-grid--modal">
                    <div class="field"><label class="required"><?= e(t('delivery.delivered_at')) ?></label><input name="delivered_at" type="date" required value="<?= e($today) ?>" max="<?= e($today) ?>"></div>
                    <div class="field"><label class="required"><?= e(t('delivery.received_by')) ?></label><input name="received_by" maxlength="160" required placeholder="<?= e(t('delivery.received_by_placeholder')) ?>"></div>
                </div>
                <?php foreach ($d['lines'] as $dl): ?>
                    <div class="field field--inline"><label><?= e($dl['sku']) ?> <span class="muted">· <?= e(t('delivery.shipped')) ?> <?= e(fmt_qty($dl['quantity_shipped'])) ?> <?= e($dl['unit']) ?></span></label>
                        <input name="received[<?= (int) $dl['id'] ?>]" type="number" step="0.001" min="0" max="<?= e((string) $dl['quantity_shipped']) ?>" required value="<?= e((string) (float) $dl['quantity_shipped']) ?>" inputmode="decimal"></div>
                <?php endforeach ?>
                <p class="hint"><?= e(t('delivery.gap_hint')) ?></p>
                <div class="field"><label><?= e(t('delivery.reception_notes')) ?></label><textarea name="reception_notes" rows="2" maxlength="500"></textarea></div>
            </form>
        </template>
        <?php endif ?>
        <?php if ($open && in_array($d['status'], ['planned', 'in_transit'], true)): ?>
        <template id="tpl-dcancel-<?= $did ?>">
            <form method="post" action="<?= e(url('/deliveries/' . $did . '/cancel')) ?>" class="form modal-form" data-confirm-variant="danger">
                <?= csrf_field() ?>
                <div class="field"><label class="required"><?= e(t('common.reason')) ?></label><textarea name="note" rows="2" required maxlength="300"></textarea></div>
            </form>
        </template>
        <?php endif ?>
    <?php endforeach ?>
    <?php endif ?>
</section>
</div>

<?= $panel('finance') ?>
<!-- 5. Factures et paiements -->
<section class="card" id="finance">
    <div class="card__title">
        <h2><?= icon('banknote', 'title-icon') ?> <?= e(t('orders.section.finance')) ?></h2>
        <?php if ($open && $user->can('finance.manage')): ?>
            <div class="page-actions">
                <?php if ($invoicablePos): ?><button type="button" class="btn btn--sm" data-open="#tpl-inv-payable" data-title="<?= e(t('invoice.new_payable')) ?>"><?= icon('file-down') ?> <?= e(t('invoice.new_payable')) ?></button><?php endif ?>
            </div>
        <?php endif ?>
    </div>
    <?php if ($toInvoice > 0 && $open): ?>
        <div class="alert alert--info"><?= icon('info') ?> <span><?= e(t('invoice.to_invoice', ['amount' => fmt_money($toInvoice, $cur)])) ?></span></div>
    <?php endif ?>
    <?php if ($open && $invoicableDeliveries): ?>
        <!-- Facturation client par expédition : une facture par expédition livrée -->
        <h3 class="subhead"><?= e(t('invoice.deliveries_to_invoice')) ?></h3>
        <div class="table-wrap">
        <table class="table table--compact">
            <thead><tr><th><?= e(t('orders.col.reference')) ?></th><th><?= e(t('delivery.delivered_at')) ?></th><th class="num"><?= e(t('invoice.ctx.delivered')) ?></th><th class="actions-col"></th></tr></thead>
            <tbody>
            <?php foreach ($invoicableDeliveries as $dl): ?>
                <tr>
                    <td><a href="<?= e(url('/deliveries/' . (int) $dl['id'])) ?>"><strong><?= e($dl['reference']) ?></strong></a><br><span class="muted"><?= e($dl['po_reference'] . ' · ' . $dl['supplier_name']) ?></span></td>
                    <td><?= e(fmt_date($dl['delivered_at'])) ?></td>
                    <td class="num"><?= e(fmt_money($dl['invoice_value'], $cur)) ?></td>
                    <td class="actions-col"><?php if ($user->can('finance.manage')): ?><button type="button" class="btn btn--sm btn--primary" data-open="#tpl-inv-dl-<?= (int) $dl['id'] ?>" data-title="<?= e(t('invoice.new_receivable') . ' ' . $dl['reference']) ?>"><?= icon('file-up') ?> <?= e(t('invoice.invoice_delivery')) ?></button><?php endif ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
    <?php endif ?>
    <?php foreach (['receivable' => $receivables, 'payable' => $payables] as $dir => $list): ?>
        <h3 class="subhead"><?= e(t('invoice.dir.' . $dir)) ?></h3>
        <?php if (!$list): ?>
            <p class="empty"><?= e(t('invoice.none')) ?></p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th><?= e(t('orders.col.reference')) ?></th><th><?= e(t('invoice.col.party')) ?></th><th><?= e(t('invoice.col.due')) ?></th><th class="num"><?= e(t('orders.col.amount')) ?></th><th class="num"><?= e(t('invoice.col.paid')) ?></th><th class="num"><?= e(t('invoice.col.balance')) ?></th><th><?= e(t('common.status')) ?></th><th class="actions-col"></th></tr></thead>
            <tbody>
            <?php foreach ($list as $inv):
                $bal = (float) $inv['amount'] - (float) $inv['paid'];
                $overdue = $inv['status'] === 'open' && $inv['due_date'] < $today; ?>
                <tr>
                    <td><strong><?= e($inv['reference']) ?></strong><?= $inv['external_number'] ? '<br><span class="muted">N° ' . e($inv['external_number']) . '</span>' : '' ?><?= $inv['po_reference'] ? '<br><span class="muted">' . e($inv['po_reference']) . '</span>' : '' ?><?= $inv['delivery_reference'] ? '<br><a class="muted" href="' . e(url('/deliveries/' . (int) $inv['delivery_id'])) . '">' . e($inv['delivery_reference']) . '</a>' : '' ?></td>
                    <td><?= e($inv['party_name']) ?></td>
                    <td class="<?= $overdue ? 'overdue' : '' ?>"><?= e(fmt_date($inv['due_date'])) ?><br><span class="muted"><?= e(t('invoice.col.date')) ?> <?= e(fmt_date($inv['invoice_date'])) ?></span></td>
                    <td class="num"><?= e(fmt_money($inv['amount'], $inv['currency'])) ?><?= ($inv['vat_rate'] ?? null) !== null ? '<br><span class="muted">' . e(t('invoice.vat_included', ['rate' => fmt_number($inv['vat_rate']), 'amount' => fmt_number($inv['vat_amount'])])) . '</span>' : '' ?></td>
                    <td class="num" data-order="<?= e((string) (float) $inv['paid']) ?>"><?= e(fmt_number($inv['paid'])) ?>
                        <?php foreach ($inv['payments'] as $py): ?><span class="pay-line" title="<?= e(t('payment.method.' . $py['method']) . ($py['bank_ref'] ? ' · ' . $py['bank_ref'] : '')) ?>"><?= e(fmt_date($py['paid_at'])) ?> · <?= e(fmt_number($py['amount'])) ?><?= $py['bank_ref'] ? ' · ' . e($py['bank_ref']) : '' ?></span><?php endforeach ?></td>
                    <td class="num"><strong><?= $inv['status'] === 'cancelled' ? '—' : e(fmt_number($bal)) ?></strong></td>
                    <td><?= View::partial('partials/status_badge', ['key' => $overdue ? 'invoice.status.overdue' : 'invoice.status.' . $inv['status'], 'status' => $overdue ? 'overdue' : $inv['status']]) ?></td>
                    <td class="actions-col">
                        <?php if ($dir === 'receivable'): ?><a class="icon-btn"<?= preview_attrs(url('/invoices/' . (int) $inv['id'] . '/print'), t('invoice.print.title') . ' — ' . $inv['reference'], null, 'html') ?> title="<?= e(t('invoice.print_btn')) ?>" aria-label="<?= e(t('invoice.print_btn')) ?>"><?= icon('printer') ?></a><?php endif ?>
                        <?php if ($open && $inv['status'] === 'draft'): ?>
                            <!-- En attente d'approbation : modification sur la fiche facture, puis approbation -->
                            <?php if ($user->can('finance.manage')): ?><a class="icon-btn" href="<?= e(url('/invoices/' . (int) $inv['id'])) ?>" title="<?= e(t('invoice.edit')) ?>" aria-label="<?= e(t('invoice.edit')) ?>"><?= icon('pencil') ?></a><?php endif ?>
                            <?php if ($user->canApproveInvoiceOf((int) $inv['created_by'])): ?>
                                <form method="post" action="<?= e(url('/invoices/' . (int) $inv['id'] . '/approve')) ?>" class="inline" data-confirm="<?= e(t('invoice.approve_confirm', ['ref' => $inv['reference'], 'amount' => fmt_money($inv['amount'], $inv['currency'])])) ?>">
                                    <?= csrf_field() ?><button class="btn btn--sm btn--primary"><?= icon('badge-check') ?> <?= e(t('invoice.approve')) ?></button>
                                </form>
                            <?php endif ?>
                        <?php endif ?>
                        <?php if ($open && $inv['status'] === 'open' && $user->can('finance.manage')): ?>
                            <button type="button" class="btn btn--sm" data-open="#tpl-pay-<?= (int) $inv['id'] ?>" data-title="<?= e(t($dir === 'payable' ? 'invoice.pay' : 'invoice.collect') . ' ' . $inv['reference']) ?>"><?= icon('hand-coins') ?> <?= e(t($dir === 'payable' ? 'invoice.pay' : 'invoice.collect')) ?></button>
                            <?php if (!$inv['payments']): ?>
                                <button type="button" class="icon-btn" data-open="#tpl-icancel-<?= (int) $inv['id'] ?>" data-title="<?= e(t('invoice.cancel')) ?>" title="<?= e(t('invoice.cancel')) ?>" aria-label="<?= e(t('invoice.cancel')) ?>"><?= icon('ban') ?></button>
                            <?php endif ?>
                            <template id="tpl-pay-<?= (int) $inv['id'] ?>"><?= View::partial('finance/_payment_form', ['inv' => $inv, 'balance' => $bal, 'back' => 'order']) ?></template>
                            <template id="tpl-icancel-<?= (int) $inv['id'] ?>">
                                <form method="post" action="<?= e(url('/invoices/' . (int) $inv['id'] . '/cancel')) ?>" class="form modal-form" data-confirm-variant="danger">
                                    <?= csrf_field() ?>
                                    <div class="field"><label class="required"><?= e(t('common.reason')) ?></label><textarea name="note" rows="2" required maxlength="300"></textarea></div>
                                </form>
                            </template>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php endif ?>
    <?php endforeach ?>

    <?php if ($open && $user->can('finance.manage')): ?>
    <?php foreach ($invoicableDeliveries as $dl): ?>
    <template id="tpl-inv-dl-<?= (int) $dl['id'] ?>">
        <?= View::partial('finance/_receivable_form', ['orderId' => $id, 'delivery' => $dl, 'currency' => $cur,
            'terms' => (int) $o['payment_terms_days'], 'back' => '/orders/' . $id . '#finance']) ?>
    </template>
    <?php endforeach ?>
    <?php if ($invoicablePos): ?>
    <template id="tpl-inv-payable">
        <form method="post" action="<?= e(url("/orders/$id/invoices")) ?>" class="form modal-form">
            <?= csrf_field() ?>
            <input type="hidden" name="direction" value="payable">
            <?= View::partial('partials/ref_field', ['type' => 'invoice_payable', 'name' => 'reference', 'label' => t('reference.internal')]) ?>
            <div class="field"><label class="required"><?= e(t('invoice.po')) ?></label>
                <select name="po_id" required data-fill-source>
                    <?php foreach ($invoicablePos as $p):
                        $invoicedPo = array_sum(array_map(fn ($i) => (int) $i['po_id'] === (int) $p['id'] && $i['status'] !== 'cancelled' ? (float) $i['amount'] : 0, $payables)); ?>
                        <option value="<?= (int) $p['id'] ?>" data-amount="<?= e(number_format(max(0, (float) $p['amount'] - $invoicedPo), 2, '.', '')) ?>"><?= e($p['reference'] . ' — ' . $p['supplier_name'] . ' (' . fmt_money($p['amount'], $p['currency']) . ')') ?></option>
                    <?php endforeach ?>
                </select></div>
            <div class="form-grid form-grid--modal">
                <div class="field"><label class="required"><?= e(t('invoice.external_number')) ?></label><input name="external_number" maxlength="80" required></div>
                <div class="field"><label class="required"><?= e(t('invoice.col.date')) ?></label><input name="invoice_date" type="date" required value="<?= e($today) ?>"></div>
                <div class="field"><label><?= e(t('invoice.col.due')) ?></label><input name="due_date" type="date"></div>
                <div class="field"><label class="required"><?= e(t('orders.col.amount')) ?></label><input name="amount" type="number" step="0.01" min="0.01" required inputmode="decimal" data-fill-from="po_id:amount" value="<?= e(number_format(max(0, (float) $invoicablePos[0]['amount'] - array_sum(array_map(fn ($i) => (int) $i['po_id'] === (int) $invoicablePos[0]['id'] && $i['status'] !== 'cancelled' ? (float) $i['amount'] : 0, $payables))), 2, '.', '')) ?>"></div>
            </div>
            <p class="hint"><?= e(t('invoice.payable_hint')) ?></p>
            <div class="field"><label><?= e(t('movements.note')) ?></label><textarea name="notes" rows="2" maxlength="500"></textarea></div>
        </form>
    </template>
    <?php endif ?>
    <?php endif ?>
</section>
</div>

<?= $panel('documents') ?>
<?php if ($showChecklist): ?>
<!-- Documents de niveau dossier (licence, assurance, FERI…) -->
<section class="card" id="checklist">
    <div class="card__title"><h2><?= icon('file-check-2', 'title-icon') ?> <?= e(t('documents.dossier_title')) ?></h2></div>
    <p class="muted"><?= e(t('documents.dossier_hint')) ?></p>
    <?= View::partial('documents/_checklist', ['docs' => $dossierDocs, 'back' => 'order', 'readonly' => !$open]) ?>
</section>
<?php endif ?>

<!-- 7. Documents -->
<section class="card" id="documents">
    <div class="card__title"><h2><?= icon('paperclip', 'title-icon') ?> <?= e(t('orders.section.documents')) ?></h2></div>
    <?php if ($attachments): ?>
        <ul class="file-list">
            <?php foreach ($attachments as $a): ?>
                <li><a<?= preview_attrs(url('/attachments/' . (int) $a['id']), $a['label'] ?: $a['original_name'], $a['mime']) ?>><?= icon($a['mime'] === 'application/pdf' ? 'file-text' : 'image') ?> <?= e($a['label'] ?: $a['original_name']) ?></a>
                    <span class="muted"><?= e(t('attachment.entity.' . $a['entity'])) ?> · <?= e(fmt_date($a['created_at'])) ?> · <?= e($a['user_name']) ?></span></li>
            <?php endforeach ?>
        </ul>
    <?php else: ?><p class="empty"><?= e(t('attachment.none')) ?></p><?php endif ?>
    <form method="post" action="<?= e(url("/orders/$id/attachments")) ?>" enctype="multipart/form-data" class="form upload-form">
        <?= csrf_field() ?>
        <div class="field"><label for="att-entity"><?= e(t('attachment.attach_to')) ?></label>
            <select id="att-entity" name="entity_ref" data-attach-target>
                <option value="customer_order:<?= $id ?>"><?= e(t('attachment.entity.customer_order')) ?> <?= e($o['customer_po_number']) ?></option>
                <?php foreach ($pos as $p): ?><option value="purchase_order:<?= (int) $p['id'] ?>"><?= e($p['reference']) ?></option><?php endforeach ?>
                <?php foreach ($deliveries as $d): ?><option value="delivery:<?= (int) $d['id'] ?>"><?= e($d['reference']) ?></option><?php endforeach ?>
                <?php foreach ($invoices as $inv): ?><option value="invoice:<?= (int) $inv['id'] ?>"><?= e($inv['reference']) ?></option><?php endforeach ?>
            </select>
            <input type="hidden" name="entity" value="customer_order"><input type="hidden" name="entity_id" value="<?= $id ?>">
        </div>
        <div class="field"><label for="att-label"><?= e(t('attachment.label')) ?></label><input id="att-label" name="label" maxlength="160" placeholder="<?= e(t('attachment.label_placeholder')) ?>"></div>
        <div class="field"><label for="att-file" class="required"><?= e(t('attachment.file')) ?></label><input id="att-file" name="file" type="file" required accept="application/pdf,image/jpeg,image/png,image/webp"><p class="hint"><?= e(t('attachment.hint')) ?></p></div>
        <button type="submit" class="btn btn--sm" data-once><?= icon('upload') ?> <?= e(t('attachment.upload')) ?></button>
    </form>
</section>
</div>

<?= $panel('closure') ?>
<!-- 6. Clôture -->
<section class="card" id="closure">
    <div class="card__title"><h2><?= icon('flag', 'title-icon') ?> <?= e(t('orders.section.closure')) ?></h2></div>
    <?php if (!$open): ?>
        <div class="empty-state empty-state--ok"><?= icon($o['status'] === 'closed' ? 'circle-check' : 'ban') ?>
            <p><?= e(t('order.status.' . $o['status'])) ?> — <?= e(fmt_date($o['closed_at'], true)) ?> · <?= e($o['closed_by_name'] ?? '') ?></p>
            <?php if ($o['closing_note']): ?><p class="prewrap"><?= e($o['closing_note']) ?></p><?php endif ?>
        </div>
    <?php else: ?>
        <ul class="checklist">
            <?php foreach ($checks as $c): ?>
                <li class="checklist__item checklist__item--<?= $c['ok'] ? 'ok' : ($c['blocking'] ? 'bad' : 'warn') ?>">
                    <?= icon($c['ok'] ? 'circle-check' : ($c['blocking'] ? 'circle-x' : 'circle-alert')) ?>
                    <span><?= e(t('closure.' . $c['key'])) ?><?= !$c['ok'] && !$c['blocking'] ? ' <span class="muted">(' . e(t('closure.needs_note')) . ')</span>' : '' ?></span>
                </li>
            <?php endforeach ?>
        </ul>
        <?php if ($user->can('orders.close')):
            $blocked = (bool) array_filter($checks, fn ($c) => !$c['ok'] && $c['blocking']);
            $needsNote = (bool) array_filter($checks, fn ($c) => !$c['ok'] && !$c['blocking']); ?>
            <form method="post" action="<?= e(url("/orders/$id/close")) ?>" class="form" data-confirm="<?= e(t('closure.confirm')) ?>">
                <?= csrf_field() ?>
                <div class="field"><label for="close-note"<?= $needsNote ? ' class="required"' : '' ?>><?= e(t('closure.note')) ?></label>
                    <textarea id="close-note" name="note" rows="2" maxlength="500" <?= $needsNote ? 'required' : '' ?> placeholder="<?= e(t($needsNote ? 'closure.note_short' : 'closure.note_optional')) ?>"></textarea></div>
                <button type="submit" class="btn btn--primary" <?= $blocked ? 'disabled' : '' ?>><?= icon('flag') ?> <?= e(t('closure.close')) ?></button>
                <?php if ($blocked): ?><p class="hint"><?= e(t('closure.blocked')) ?></p><?php endif ?>
            </form>
        <?php endif ?>
    <?php endif ?>
</section>
</div>

<?= $panel('timeline') ?>
<!-- 8. Chronologie -->
<section class="card" id="timeline">
    <div class="card__title"><h2><?= icon('history', 'title-icon') ?> <?= e(t('orders.section.timeline')) ?></h2></div>
    <ol class="timeline">
        <?php foreach ($events as $ev): ?>
            <li class="timeline__item timeline__item--<?= e($ev['event']) ?>">
                <span class="timeline__date"><?= e(fmt_date($ev['created_at'], true)) ?> · <?= e($ev['user_name'] ?? '—') ?></span>
                <span><strong><?= e(t('event.' . $ev['event'])) ?></strong><?php if ($ev['detail']): ?> — <?= e($ev['event'] === 'status' ? t('order.status.' . $ev['detail']) : $ev['detail']) ?><?php endif ?></span>
            </li>
        <?php endforeach ?>
    </ol>
</section>
</div>

</div>
</div>
