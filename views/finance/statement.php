<?php
/**
 * Relevé de tiers (A4) : relevé de compte et / ou échéancier d'un client ou fournisseur, ou balance âgée de tous les tiers.
 * Un bloc par devise (aucune conversion implicite).
 * @var string $direction @var string $type full|ledger|aging|balance @var string $from @var string $to @var bool $settled
 * @var array<int,array> $parties @var array<int,array<string,array{ledger:?array,aging:array}>> $blocks
 * @var array<string,string> $filterLabels @var array $company @var string $user @var string $docTitle
 * @var string $orientation portrait|landscape
 *
 * Mise en page multi-pages : l'en-tête (société, titre, arrêté) et le pied (mentions, référence du relevé) sont dans le thead / tfoot
 * d'un tableau-cadre, répétés par le navigateur sur chaque page imprimée ; le n° de page est dans la marge (@page). Les en-têtes de
 * tableaux sont répétés et une ligne n'est jamais coupée entre deux pages.
 */
use App\Domain\Statement;

$rec = $direction === 'receivable';
$money = fn ($v) => fmt_number((float) $v);
$bucketLabels = [
    'current' => t('statement.bucket.current'), 'd30' => t('statement.bucket.d30'), 'd60' => t('statement.bucket.d60'),
    'd90' => t('statement.bucket.d90'), 'over90' => t('statement.bucket.over90'),
];
$single = count($parties) === 1 && $type !== 'balance' ? reset($parties) : null;
$agingBar = function (array $aging) use ($bucketLabels, $money): string {
    $total = max(0.01, $aging['total']);
    $html = '<div class="aging"><div class="aging__bar">';
    foreach ($aging['buckets'] as $k => $v) {
        if ($v > 0.004) {
            $html .= '<span class="aging__seg aging__seg--' . $k . '" style="width:' . round($v / $total * 100, 2) . '%"></span>';
        }
    }
    $html .= '</div><div class="aging__legend">';
    foreach ($aging['buckets'] as $k => $v) {
        $html .= '<div><span class="aging__dot aging__seg--' . $k . '"></span>' . e($bucketLabels[$k]) . '<strong>' . e($money($v)) . '</strong></div>';
    }
    return $html . '</div></div>';
};
?>
<article class="doc doc--statement doc--<?= e($orientation) ?>">
<table class="page-frame">
<thead><tr><td>
    <header class="doc__head">
        <?= \App\Core\View::partial('partials/doc_company', ['company' => $company]) ?>
        <div class="doc__title">
            <h1><?= e($docTitle) ?></h1>
            <?php if ($single): ?><p class="ref"><?= e($single['name']) ?></p><?php endif ?>
            <?php if ($type === 'ledger' || $type === 'full'): ?>
                <p><?= e(t('statement.period')) ?> : <strong><?= e(fmt_date($from)) ?> → <?= e(fmt_date($to)) ?></strong></p>
            <?php endif ?>
            <p><?= e(t('statement.as_of')) ?> : <strong><?= e(fmt_date($to)) ?></strong></p>
        </div>
    </header>
</td></tr></thead>
<tfoot><tr><td>
    <footer class="page-foot">
        <span><?= e(implode(' · ', array_filter([$company['name'], $company['rccm'] ? 'RCCM ' . $company['rccm'] : '', $company['nif'] ? 'NIF ' . $company['nif'] : '']))) ?></span>
        <span><?= e($docTitle . ($single ? ' — ' . $single['name'] : '') . ' · ' . t('statement.as_of') . ' ' . fmt_date($to)) ?></span>
        <span><?= e(t('statement.edited', ['date' => fmt_date(date('Y-m-d H:i'), true), 'user' => $user])) ?></span>
    </footer>
</td></tr></tfoot>
<tbody><tr><td>

    <?php if ($filterLabels || $settled): ?>
        <p class="statement-filters"><?= e(t('statement.filters')) ?> :
            <?= e(implode(' · ', array_map(fn ($k, $v) => "$k : $v", array_keys($filterLabels), $filterLabels))) ?><?= $settled ? ($filterLabels ? ' · ' : '') . e(t('statement.with_settled')) : '' ?></p>
    <?php endif ?>

    <?php if ($type === 'balance'): ?>
        <!-- Balance âgée : un tiers × devise par ligne, totaux par devise -->
        <?php
        $totals = [];
        foreach ($blocks as $id => $byCur) {
            foreach ($byCur as $cur => $b) {
                foreach ($b['aging']['buckets'] as $k => $v) {
                    $totals[$cur][$k] = ($totals[$cur][$k] ?? 0) + $v;
                }
                $totals[$cur]['total'] = ($totals[$cur]['total'] ?? 0) + $b['aging']['total'];
                $totals[$cur]['overdue'] = ($totals[$cur]['overdue'] ?? 0) + $b['aging']['overdue'];
            }
        }
        ?>
        <?php if (!$totals): ?><p class="notes"><?= e(t('statement.empty')) ?></p><?php endif ?>
        <?php foreach ($totals as $cur => $tot): ?>
            <div class="kpi-strip">
                <div><span><?= e(t('statement.total_due')) ?> (<?= e($cur) ?>)</span><strong><?= e($money($tot['total'])) ?></strong></div>
                <div class="kpi-strip__bad"><span><?= e(t('statement.overdue')) ?></span><strong><?= e($money($tot['overdue'])) ?></strong></div>
                <div><span><?= e(t('statement.overdue_share')) ?></span><strong><?= $tot['total'] > 0 ? e(fmt_number($tot['overdue'] / $tot['total'] * 100, 1)) . ' %' : '—' ?></strong></div>
            </div>
            <?= $agingBar(['buckets' => array_intersect_key($tot, Statement::BUCKETS), 'total' => $tot['total']]) ?>
            <table class="statement-table">
                <thead><tr><th><?= e(t($rec ? 'orders.col.customer' : 'sourcing.col.supplier')) ?></th>
                    <?php foreach ($bucketLabels as $l): ?><th class="num"><?= e($l) ?></th><?php endforeach ?>
                    <th class="num"><?= e(t('statement.total_due')) ?></th><th class="num"><?= e(t('statement.overdue')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($blocks as $id => $byCur): if (!isset($byCur[$cur]) || $byCur[$cur]['aging']['total'] <= 0.004) { continue; } $a = $byCur[$cur]['aging']; ?>
                    <tr>
                        <td><strong><?= e($parties[$id]['name']) ?></strong> <span class="muted"><?= e($parties[$id]['code']) ?></span></td>
                        <?php foreach (array_keys($bucketLabels) as $k): ?><td class="num<?= $k !== 'current' && $a['buckets'][$k] > 0 ? ' late' : '' ?>"><?= $a['buckets'][$k] > 0 ? e($money($a['buckets'][$k])) : '—' ?></td><?php endforeach ?>
                        <td class="num"><strong><?= e($money($a['total'])) ?></strong></td>
                        <td class="num<?= $a['overdue'] > 0 ? ' late' : '' ?>"><?= $a['overdue'] > 0 ? e($money($a['overdue'])) : '—' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
                <tfoot><tr><td><?= e(t('print.total')) ?> <?= e($cur) ?></td>
                    <?php foreach (array_keys($bucketLabels) as $k): ?><td class="num"><?= e($money($tot[$k] ?? 0)) ?></td><?php endforeach ?>
                    <td class="num"><?= e($money($tot['total'])) ?></td><td class="num"><?= e($money($tot['overdue'])) ?></td></tr></tfoot>
            </table>
        <?php endforeach ?>
    <?php else: ?>
        <?php foreach ($parties as $id => $p): ?>
        <div class="boxes">
            <div class="box box--accent">
                <h2><?= e(t($rec ? 'statement.customer' : 'statement.supplier')) ?></h2>
                <p><strong><?= e($p['name']) ?></strong> <span class="muted">(<?= e($p['code']) ?>)</span></p>
                <?php if (!empty($p['address'])): ?><p><?= e($p['address']) ?></p><?php endif ?>
                <?php if (!empty($p['city']) || !empty($p['country'])): ?><p><?= e(implode(', ', array_filter([$p['city'] ?? '', $p['country'] ?? '']))) ?></p><?php endif ?>
                <?php $ids = array_filter(['RCCM' => $p['rccm'] ?? null, 'ID NAT' => $p['id_nat'] ?? null, 'NIF' => $p['nif'] ?? null]); ?>
                <?php if ($ids): ?><p class="muted"><?= e(implode(' · ', array_map(fn ($k, $v) => "$k $v", array_keys($ids), $ids))) ?></p><?php endif ?>
                <?php $contact = array_filter([$p['contact_name'] ?? null, $p['phone'] ?? null, $p['email'] ?? null]); ?>
                <?php if ($contact): ?><p class="muted"><?= e(implode(' · ', $contact)) ?></p><?php endif ?>
            </div>
            <div class="box">
                <h2><?= e(t('statement.terms')) ?></h2>
                <p><?= e(t('parties.terms_short', ['n' => (int) $p['payment_terms_days']])) ?></p>
                <p class="muted"><?= e(t($rec ? 'statement.intro_customer' : 'statement.intro_supplier')) ?></p>
            </div>
        </div>

        <?php if (empty($blocks[$id])): ?><p class="notes"><?= e(t('statement.empty')) ?></p><?php endif ?>
        <?php foreach ($blocks[$id] ?? [] as $cur => $b): $l = $b['ledger']; $a = $b['aging']; ?>
            <h2 class="statement-cur"><?= e(t('statement.currency_block', ['cur' => $cur])) ?></h2>
            <div class="kpi-strip">
                <?php if ($l): ?>
                    <div><span><?= e(t('statement.opening')) ?></span><strong><?= e($money($l['opening'])) ?></strong></div>
                    <div><span><?= e(t($rec ? 'statement.invoiced' : 'statement.invoiced_supplier')) ?></span><strong><?= e($money($l['invoiced'])) ?></strong></div>
                    <div><span><?= e(t($rec ? 'statement.collected' : 'statement.paid')) ?></span><strong><?= e($money($l['paid'])) ?></strong></div>
                <?php endif ?>
                <div class="kpi-strip__main"><span><?= e(t('statement.balance_due', ['date' => fmt_date($to)])) ?></span><strong><?= e($money($a['total'])) ?> <?= e($cur) ?></strong></div>
                <div class="<?= $a['overdue'] > 0 ? 'kpi-strip__bad' : '' ?>"><span><?= e(t('statement.overdue')) ?></span><strong><?= e($money($a['overdue'])) ?></strong></div>
            </div>
            <?= $agingBar($a) ?>

            <?php if ($l): ?>
            <h3 class="statement-sub"><?= e(t('statement.ledger')) ?></h3>
            <table class="statement-table">
                <thead><tr><th><?= e(t('statement.col.date')) ?></th><th><?= e(t('statement.col.type')) ?></th><th><?= e(t('statement.col.reference')) ?></th>
                    <th><?= e(t('statement.col.label')) ?></th><th><?= e(t('invoice.col.due')) ?></th>
                    <th class="num"><?= e(t($rec ? 'statement.invoiced' : 'statement.invoiced_supplier')) ?></th><th class="num"><?= e(t($rec ? 'statement.collected' : 'statement.paid')) ?></th>
                    <th class="num"><?= e(t('statement.col.balance')) ?></th></tr></thead>
                <tbody>
                    <tr class="statement-row--opening"><td><?= e(fmt_date($from)) ?></td><td colspan="6"><?= e(t('statement.opening')) ?></td><td class="num"><strong><?= e($money($l['opening'])) ?></strong></td></tr>
                    <?php foreach ($l['lines'] as $m): ?>
                        <tr>
                            <td><?= e(fmt_date($m['date'])) ?></td>
                            <td><?= e(t($m['kind'] === 'invoice' ? 'statement.kind.invoice' : ($rec ? 'statement.kind.collection' : 'statement.kind.payment'))) ?></td>
                            <td><?= e($m['reference']) ?></td>
                            <td class="muted"><?= e($m['label']) ?></td>
                            <td><?= $m['due_date'] ? e(fmt_date($m['due_date'])) : '' ?></td>
                            <td class="num"><?= $m['debit'] > 0 ? e($money($m['debit'])) : '' ?></td>
                            <td class="num"><?= $m['credit'] > 0 ? e($money($m['credit'])) : '' ?></td>
                            <td class="num"><?= e($money($m['balance'])) ?></td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (!$l['lines']): ?><tr><td colspan="8" class="muted"><?= e(t('statement.no_move')) ?></td></tr><?php endif ?>
                </tbody>
                <tfoot><tr><td colspan="5"><?= e(t('statement.closing', ['date' => fmt_date($to)])) ?></td>
                    <td class="num"><?= e($money($l['invoiced'])) ?></td><td class="num"><?= e($money($l['paid'])) ?></td><td class="num"><?= e($money($l['closing'])) ?></td></tr></tfoot>
            </table>
            <?php endif ?>

            <?php if ($type !== 'ledger'): ?>
            <!-- Factures non soldées : sur sa propre page quand le relevé de compte la précède -->
            <section class="statement-section<?= $l ? ' statement-section--new-page' : '' ?>">
            <h3 class="statement-sub"><?= e(t($settled ? 'statement.invoices_all' : 'statement.invoices_open', ['date' => fmt_date($to)])) ?> — <?= e($cur) ?></h3>
            <table class="statement-table">
                <thead><tr><th><?= e(t('statement.col.invoice')) ?></th><th><?= e(t('invoice.col.date')) ?></th><th><?= e(t('invoice.col.due')) ?></th>
                    <th class="num"><?= e(t('orders.col.amount')) ?></th><th class="num"><?= e(t($rec ? 'statement.collected' : 'statement.paid')) ?></th>
                    <th class="num"><?= e(t('statement.col.open')) ?></th><th class="num"><?= e(t('statement.col.days_late')) ?></th><th><?= e(t('statement.col.bucket')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($a['rows'] as $r): ?>
                    <tr>
                        <td><strong><?= e($r['reference']) ?></strong><?= $r['label'] ? '<br><span class="muted">' . e($r['label']) . '</span>' : '' ?></td>
                        <td><?= e(fmt_date($r['date'])) ?></td>
                        <td><?= e(fmt_date($r['due_date'])) ?></td>
                        <td class="num"><?= e($money($r['amount'])) ?></td>
                        <td class="num"><?= $r['paid'] > 0 ? e($money($r['paid'])) : '—' ?></td>
                        <td class="num"><strong><?= $r['open'] > 0 ? e($money($r['open'])) : '—' ?></strong></td>
                        <td class="num<?= $r['open'] > 0 && $r['days_late'] > 0 ? ' late' : '' ?>"><?= $r['open'] > 0 && $r['days_late'] > 0 ? (int) $r['days_late'] : '—' ?></td>
                        <td><?= $r['bucket'] ? '<span class="aging__dot aging__seg--' . e($r['bucket']) . '"></span>' . e($bucketLabels[$r['bucket']]) : '<span class="muted">' . e(t('invoice.status.paid')) . '</span>' ?></td>
                    </tr>
                <?php endforeach ?>
                <?php if (!$a['rows']): ?><tr><td colspan="8" class="muted"><?= e(t('statement.nothing_due')) ?></td></tr><?php endif ?>
                </tbody>
                <tfoot><tr><td colspan="5"><?= e(t('statement.total_due')) ?> <?= e($cur) ?></td><td class="num"><?= e($money($a['total'])) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
            </section>
            <?php endif ?>
        <?php endforeach ?>

        <div class="boxes boxes--pay">
            <div class="box">
                <h2><?= e(t($rec ? 'statement.footer.payment' : 'statement.footer.reconciliation')) ?></h2>
                <p><?= e(t($rec ? 'statement.footer.customer_text' : 'statement.footer.supplier_text', ['date' => fmt_date($to)])) ?></p>
                <?php if ($rec && ($company['bank_details'] ?? '')): ?><p class="bank"><?= e($company['bank_details']) ?></p><?php endif ?>
            </div>
            <div class="box">
                <h2><?= e(t('statement.footer.dispute')) ?></h2>
                <p class="muted"><?= e(t('statement.footer.dispute_text')) ?></p>
            </div>
        </div>
        <div class="signatures">
            <div><?= e(t('print.issued_by')) ?><br><strong><?= e($user) ?></strong><br><span class="muted"><?= e(t('invoice.print.stamp_company')) ?></span></div>
            <div><?= e(t('statement.ack', ['date' => fmt_date($to)])) ?></div>
        </div>
        <?php endforeach ?>
    <?php endif ?>
</td></tr></tbody>
</table>
</article>
