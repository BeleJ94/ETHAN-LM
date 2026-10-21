<?php
/**
 * Panneau « Licence et inspections » d'un PO fournisseur.
 * 1. Fiche licence lisible (n°, COD, validité, valeur, document) ;
 * 2. « Où en est la marchandise ? » : par article, une barre en mots simples
 *    (déjà chargé · prêt à expédier · décision en attente · non conforme · reste à inspecter) et les actions utiles ;
 * 3. Inspections compactes (le n° n'apparaît qu'une fois quand rapport et AV / ARA reprennent la référence).
 * $embedded : panneau affiché dans la fiche PO (frise et bandeau d'action déjà présents) → pas de bandeau d'état en double.
 * @var array $po (id, reference, status, order_status) @var array $lines lignes du PO (PurchaseOrderRepository::lines)
 * @var array $summary ImportRepository::summary @var array $inspections @var bool|null $embedded
 */
use App\Core\View;
use App\Domain\ImportGate;
use App\Security\Auth;

$embedded ??= false;
$user = Auth::require();
$today = date('Y-m-d');
$poId = (int) $po['id'];
$lic = $summary['licence'];
$licOk = ImportGate::licenceValid($lic, $today);
$poOpen = !in_array($po['order_status'] ?? '', ['closed', 'cancelled'], true) && ($po['status'] ?? '') !== 'cancelled';
$canManage = $user->can('imports.manage') && $poOpen;
$canShip = $user->can('deliveries.manage') && $poOpen && in_array($po['status'] ?? '', ['sent', 'partial'], true);
$openAra = array_values(array_filter($inspections, fn ($i) => $i['decision'] === 'ara' && !$i['regularized_by']));
$blocked = !$licOk || (bool) $openAra;

// Quantités par ligne de PO : en attente de décision, non conformes (écarts d'AV + ARA non régularisés)
$pending = $nonConform = [];
foreach ($inspections as $i) {
    foreach ($i['lines'] as $il) {
        $lid = (int) $il['po_line_id'];
        if ($i['decision'] === 'pending') {
            $pending[$lid] = ($pending[$lid] ?? 0) + (float) $il['quantity_inspected'];
        } elseif ($i['decision'] === 'av') {
            $nonConform[$lid] = ($nonConform[$lid] ?? 0) + max(0, (float) $il['quantity_inspected'] - (float) $il['quantity_conform']);
        } elseif (!$i['regularized_by']) {
            $nonConform[$lid] = ($nonConform[$lid] ?? 0) + (float) $il['quantity_inspected'];
        }
    }
}
// Prélèvements des expéditions sur chaque AV (traçabilité AV → camions) et soldes
$repoImp = new \App\Repositories\ImportRepository();
$avUse = $repoImp->allocationsByInspection($poId);
$avLeft = [];
foreach ($repoImp->avBalances($poId) as $b) {
    $avLeft[$b['po_line_id']][] = $b;
}
$days = $lic ? (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($lic['expires_at']))->format('%r%a') : null;
$costs = $lic ? array_filter([$lic['freight'] ?? null, $lic['insurance'] ?? null, $lic['other_costs'] ?? null], fn ($v) => $v !== null) : [];

// Bandeau d'état : seulement hors fiche PO (la fiche PO a déjà sa frise et sa prochaine action)
if (!$embedded) {
    if (!$lic) {
        [$alert, $icon, $msg] = ['info', 'hourglass', t('imports.stage.awaiting_licence')];
    } elseif (!$licOk) {
        [$alert, $icon, $msg] = ['error', 'calendar-x', t('imports.stage.licence_invalid', ['number' => $lic['number']])];
    } elseif ($openAra) {
        [$alert, $icon, $msg] = ['error', 'octagon-x', t('imports.stage.blocked', ['ref' => implode(', ', array_map(fn ($a) => $a['decision_number'] ?: $a['reference'], $openAra))])];
    } elseif ($summary['cleared_total'] <= 0) {
        [$alert, $icon, $msg] = ['info', 'search-check', t('imports.stage.inspection')];
    } else {
        [$alert, $icon, $msg] = ['success', 'badge-check', t('imports.stage.cleared')];
    }
}
$fmtQ = fn ($q, $u) => fmt_qty($q) . ' ' . $u;
?>
<section class="card import-panel" id="import">
    <div class="card__title">
        <h2><?= icon('stamp', 'title-icon') ?> <?= e(t('imports.panel_title')) ?></h2>
        <div class="page-actions">
            <?php if ($canManage && !$lic): ?>
                <a class="btn btn--sm btn--primary" href="<?= e(url('/licences/new', ['po_id' => $poId])) ?>"><?= icon('plus') ?> <?= e(t('imports.licence_new')) ?></a>
            <?php endif ?>
            <?php if ($canManage && $licOk): ?>
                <a class="btn btn--sm" href="<?= e(url("/purchase-orders/$poId/inspections/new")) ?>"><?= icon('search-check') ?> <?= e(t('imports.inspection_new')) ?></a>
            <?php endif ?>
        </div>
    </div>

    <?php if (!$embedded): ?>
        <div class="alert alert--<?= $alert ?>" role="status"><?= icon($icon) ?> <span><?= e($msg) ?></span></div>
    <?php endif ?>

    <!-- 1. La licence -->
    <?php if (!$lic): ?>
        <div class="empty-state empty-state--compact"><?= icon('stamp') ?><p><?= e(t('imports.need_licence')) ?></p></div>
    <?php else: ?>
    <dl class="lic-strip">
        <div><dt><?= e(t('imports.col.number')) ?></dt><dd><a href="<?= e(url('/licences/' . (int) $lic['id'])) ?>"><strong><?= e($lic['number']) ?></strong></a></dd></div>
        <div><dt><?= e(t('imports.cod_number')) ?></dt><dd><?= $lic['cod_number'] ? '<strong>' . e($lic['cod_number']) . '</strong>' : '<span class="text-warn">' . e(t('imports.cod_none')) . '</span>' ?></dd></div>
        <div><dt><?= e(t('imports.lic.validity')) ?></dt><dd class="<?= $licOk ? ($days <= 30 ? 'text-warn' : '') : 'text-bad' ?>"><?= e(fmt_date($lic['expires_at'])) ?> <span class="muted">· <?= e($days >= 0 ? t('imports.days_left', ['n' => $days]) : t('imports.expired_since', ['n' => -$days])) ?></span></dd></div>
        <div><dt><?= e($costs ? t('imports.cost.cif') : t('imports.cost.fob')) ?></dt><dd><?= $lic['amount'] !== null ? e(fmt_money((float) $lic['amount'] + array_sum(array_map('floatval', $costs)), $lic['currency'])) : '—' ?></dd></div>
        <div class="lic-strip__doc"><?php if ($lic['attachment_id']): ?><a class="btn btn--sm"<?= preview_attrs(url('/attachments/' . (int) $lic['attachment_id']), t('imports.licence') . ' ' . $lic['number'], $lic['file_mime']) ?>><?= icon('file-text') ?> <?= e(t('imports.lic.document')) ?></a><?php else: ?><span class="muted"><?= e(t('imports.lic.no_document')) ?></span><?php endif ?></div>
    </dl>
    <?php endif ?>

    <!-- 2. Où en est la marchandise ? -->
    <h3 class="subhead"><?= e(t('imports.flow.title')) ?></h3>
    <div class="flow-list">
    <?php foreach ($lines as $l):
        $lid = (int) $l['id'];
        $u = $l['unit'];
        $q = max(0.0, (float) $l['quantity']);
        $c = (float) ($summary['cleared'][$lid] ?? 0);
        $loaded = min($c, (float) ($summary['committed'][$lid] ?? 0));
        $ready = max(0, $c - $loaded);
        $pend = $pending[$lid] ?? 0;
        $nc = $nonConform[$lid] ?? 0;
        $rest = max(0, $q - $c - $pend - $nc);
        $w = fn ($v) => $q > 0 ? round($v / $q * 100, 2) : 0;
        $parts = [
            ['loaded', $loaded, t('imports.flow.loaded')],
            [$blocked ? 'blocked' : 'ready', $ready, t($blocked ? 'imports.flow.blocked' : 'imports.flow.ready')],
            ['pending', $pend, t('imports.flow.pending')],
            ['refused', $nc, t('imports.flow.refused')],
            ['todo', $rest, t('imports.flow.todo')],
        ]; ?>
        <div class="flow">
            <div class="flow__head">
                <span><strong><?= e($l['item_name']) ?></strong><?php if ($l['hs_code'] ?? null): ?> <span class="hs">SH <?= e($l['hs_code']) ?></span><?php endif ?></span>
                <span class="muted"><?= e(t('imports.flow.ordered', ['q' => $fmtQ($q, $u)])) ?></span>
            </div>
            <div class="flow__bar" role="img" aria-label="<?= e(implode(', ', array_map(fn ($p) => $p[2] . ' ' . $fmtQ($p[1], $u), array_filter($parts, fn ($p) => $p[1] > 0)))) ?>">
                <?php foreach ($parts as [$k, $v]): if ($v <= 0) { continue; } ?><span class="flow__seg flow__seg--<?= $k ?>" style="width: <?= $w($v) ?>%"></span><?php endforeach ?>
            </div>
            <?php $withLeft = array_values(array_filter($avLeft[$lid] ?? [], fn ($b) => $b['remaining'] > 0)); if ($withLeft && !$blocked): ?>
                <p class="flow__avs"><?= icon('badge-check') ?> <?= e(t('imports.flow.by_av')) ?> <?= e(implode(' · ', array_map(fn ($b) => ($b['decision_number'] ?: $b['reference']) . ' : ' . fmt_qty($b['remaining']) . ' ' . $u, $withLeft))) ?></p>
            <?php endif ?>
            <ul class="flow__legend">
                <?php foreach ($parts as [$k, $v, $label]): if ($v <= 0) { continue; } ?>
                    <li><span class="flow__dot flow__dot--<?= $k ?>"></span><?= e($label) ?> <strong><?= e($fmtQ($v, $u)) ?></strong></li>
                <?php endforeach ?>
            </ul>
            <div class="flow__actions">
                <?php if ($ready > 0 && !$blocked && $canShip): ?>
                    <a class="btn btn--sm btn--primary" href="<?= e(url("/purchase-orders/$poId/deliveries/new")) ?>"><?= icon('truck') ?> <?= e(t('imports.flow.ship', ['q' => $fmtQ($ready, $u)])) ?></a>
                <?php endif ?>
                <?php if ($rest > 0 && $licOk && !$openAra && $canManage): ?>
                    <a class="btn btn--sm" href="<?= e(url("/purchase-orders/$poId/inspections/new")) ?>"><?= icon('search-check') ?> <?= e(t('imports.flow.inspect', ['q' => $fmtQ($rest, $u)])) ?></a>
                <?php endif ?>
                <?php if ($rest <= 0 && $ready <= 0 && $pend <= 0 && $nc <= 0): ?><span class="text-good"><?= icon('circle-check') ?> <?= e(t('imports.flow.all_loaded')) ?></span><?php endif ?>
            </div>
        </div>
    <?php endforeach ?>
    </div>

    <!-- 3. Inspections -->
    <h3 class="subhead"><?= e(t('imports.col.inspections')) ?> <?php if ($inspections): ?><span class="count-chip"><?= count($inspections) ?></span><?php endif ?></h3>
    <?php if (!$inspections): ?>
        <p class="empty"><?= e(t($lic ? 'imports.no_inspection' : 'imports.need_licence')) ?></p>
    <?php else: ?>
    <ul class="insp-list">
        <?php foreach ($inspections as $i):
            $mod = $i['decision'] === 'av' ? 'ok' : ($i['decision'] === 'ara' ? ($i['regularized_by'] ? 'muted' : 'bad') : 'pending');
            $sameReport = !$i['report_number'] || $i['report_number'] === $i['reference'];
            $sameDecision = !$i['decision_number'] || $i['decision_number'] === $i['reference']; ?>
        <li class="insp insp--<?= $mod ?>">
            <div class="insp__head">
                <span class="insp__title">
                    <strong class="insp__ref"><?= e($i['reference']) ?></strong>
                    <?= View::partial('partials/status_badge', ['key' => 'imports.decision.' . $i['decision'], 'status' => ['av' => 'delivered', 'ara' => 'blocked', 'pending' => 'pending'][$i['decision']]]) ?>
                    <?php if ($i['decision'] === 'ara' && $i['regularized_by']): ?><span class="chip chip--c-green"><?= e(t('imports.regularized_by', ['ref' => $i['regularized_by']])) ?></span><?php endif ?>
                    <?php if ($i['regularizes_reference']): ?><span class="chip"><?= e(t('imports.regularizes', ['ref' => $i['regularizes_reference']])) ?></span><?php endif ?>
                </span>
                <span class="insp__tools">
                    <?php if ($canManage && $i['decision'] === 'pending'): ?>
                        <button type="button" class="btn btn--sm btn--primary" data-open="#tpl-decide-<?= (int) $i['id'] ?>" data-title="<?= e(t('imports.record_decision') . ' — ' . $i['reference']) ?>"><?= icon('gavel') ?> <?= e(t('imports.record_decision')) ?></button>
                    <?php endif ?>
                    <?php if ($canManage && $i['decision'] === 'ara' && !$i['regularized_by'] && $licOk): ?>
                        <a class="btn btn--sm btn--danger" href="<?= e(url("/purchase-orders/$poId/inspections/new", ['regularizes' => (int) $i['id']])) ?>"><?= icon('rotate-ccw') ?> <?= e(t('imports.regularize')) ?></a>
                    <?php endif ?>
                </span>
            </div>
            <p class="insp__meta">
                <?= icon('calendar') ?> <?= e(fmt_date($i['inspection_date'])) ?> · <?= e($i['inspector']) ?><?= $i['location'] ? ' · ' . e($i['location']) : '' ?>
                <?php if ($i['decision'] !== 'pending' && $i['decision_date']): ?> · <?= e(t('imports.decided_on', ['date' => fmt_date($i['decision_date'])])) ?><?php endif ?>
            </p>
            <?php if (!$sameReport || !$sameDecision): ?>
                <p class="insp__meta">
                    <?php if (!$sameReport): ?><?= e(t('imports.report')) ?> <strong><?= e($i['report_number']) ?></strong><?php endif ?>
                    <?php if (!$sameReport && !$sameDecision): ?> · <?php endif ?>
                    <?php if (!$sameDecision): ?><?= e(strtoupper($i['decision'])) ?> <strong><?= e($i['decision_number']) ?></strong><?php endif ?>
                </p>
            <?php endif ?>
            <?php if ($i['decision'] === 'av'):
                $conf = array_sum(array_map(fn ($il) => (float) $il['quantity_conform'], $i['lines']));
                $used = array_sum(array_column($avUse[(int) $i['id']] ?? [], 'quantity')); ?>
                <div class="insp__usage">
                    <span class="insp__usage-bar"><span style="width: <?= $conf > 0 ? min(100, round($used / $conf * 100, 1)) : 0 ?>%"></span></span>
                    <span><?= e(t('imports.av_usage', ['used' => fmt_qty($used), 'conf' => fmt_qty($conf), 'left' => fmt_qty(max(0, $conf - $used))])) ?></span>
                    <?php foreach ($avUse[(int) $i['id']] ?? [] as $u2): ?>
                        <a class="chip chip--link" href="<?= e(url('/deliveries/' . $u2['delivery_id'])) ?>"><?= icon('truck') ?> <?= e($u2['reference']) ?> · <?= e(fmt_qty($u2['quantity'])) ?></a>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
            <?php if ($i['reason']): ?><p class="<?= $i['decision'] === 'ara' ? 'text-bad' : 'muted' ?> insp__meta"><?= icon('message-square-warning') ?> <?= e($i['reason']) ?></p><?php endif ?>
            <p class="insp__qty">
                <?php foreach ($i['lines'] as $n => $il): ?>
                    <?= $n ? ' · ' : '' ?><?= count($i['lines']) > 1 ? '<span class="muted">' . e($il['sku']) . '</span> ' : '' ?>
                    <?= e(t('imports.insp_qty', ['q' => fmt_qty($il['quantity_inspected']) . ' ' . $il['unit']])) ?>
                    <?php if ($i['decision'] !== 'pending'): ?> → <strong class="<?= (float) $il['quantity_conform'] < (float) $il['quantity_inspected'] ? 'text-bad' : 'text-good' ?>"><?= e(t('imports.conform_qty', ['q' => fmt_qty($il['quantity_conform'])])) ?></strong><?php endif ?>
                <?php endforeach ?>
            </p>
            <div class="insp__report<?= $i['attachment_id'] ? '' : ' insp__report--none' ?>">
                <?= icon($i['attachment_id'] ? 'file-text' : 'file-x') ?>
                <?php if ($i['attachment_id']): ?>
                    <span class="insp__report-name"><span class="muted"><?= e(t('imports.report')) ?> :</span> <?= e($i['file_name']) ?></span>
                    <a class="btn btn--sm"<?= preview_attrs(url('/attachments/' . (int) $i['attachment_id']), t('imports.report') . ' ' . ($i['report_number'] ?: $i['reference']), $i['file_mime']) ?>><?= icon('eye') ?> <?= e(t('imports.report_view')) ?></a>
                    <a class="btn btn--sm btn--ghost" href="<?= e(url('/attachments/' . (int) $i['attachment_id'], ['download' => 1])) ?>"><?= icon('download') ?> <?= e(t('imports.report_download')) ?></a>
                <?php else: ?>
                    <span class="insp__report-name muted"><?= e(t('imports.report_none')) ?></span>
                <?php endif ?>
                <?php if ($canManage): ?>
                    <button type="button" class="btn btn--sm btn--ghost" data-open="#tpl-report-<?= (int) $i['id'] ?>" data-title="<?= e(t('imports.report_attach') . ' — ' . $i['reference']) ?>"><?= icon('upload') ?> <?= e(t($i['attachment_id'] ? 'imports.report_replace' : 'imports.report_attach')) ?></button>
                    <template id="tpl-report-<?= (int) $i['id'] ?>">
                        <form method="post" action="<?= e(url('/inspections/' . (int) $i['id'] . '/report')) ?>" enctype="multipart/form-data" class="form modal-form">
                            <?= csrf_field() ?>
                            <div class="field"><label class="required"><?= e(t('imports.report_file')) ?></label><input name="file" type="file" required accept="application/pdf,image/jpeg,image/png,image/webp"><p class="hint"><?= e(t('attachment.hint')) ?></p></div>
                            <?php if ($i['attachment_id']): ?><p class="hint"><?= e(t('imports.report_replace_hint')) ?></p><?php endif ?>
                        </form>
                    </template>
                <?php endif ?>
            </div>
            <?php if ($canManage && $i['decision'] === 'pending'): ?>
            <template id="tpl-decide-<?= (int) $i['id'] ?>">
                <form method="post" action="<?= e(url('/inspections/' . (int) $i['id'] . '/decision')) ?>" enctype="multipart/form-data" class="form modal-form">
                    <?= csrf_field() ?>
                    <?= View::partial('imports/_decision_fields', ['i' => $i]) ?>
                </form>
            </template>
            <?php endif ?>
        </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>
