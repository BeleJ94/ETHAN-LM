<?php
/**
 * Cockpit d'accueil : à faire (replié), PO clients / PO fournisseurs / licences / livraisons, graphiques, pipeline,
 * livraisons en cours, licences, activité. Chaque indicateur [data-drill] ouvre son détail en modale (tableau + export).
 * @var array $todo @var array $tiles @var array $pipeline @var array $charts @var array $deliveries @var array $licences
 * @var array $activity @var list<string> $missingRates
 */
use App\Core\View;
use App\Security\Auth;

$user = Auth::require();
$o = $tiles['orders'];
$p = $tiles['pos'];
$l = $tiles['licences'];
$d = $tiles['deliveries'];
$hour = (int) date('G');
// Indicateur cliquable : ouvre le détail en modale
$drill = fn (string $key, string $title, string $param = '') => ' data-drill="' . e($key) . '"' . ($param !== '' ? ' data-drill-param="' . e($param) . '"' : '')
    . ' data-drill-title="' . e($title) . '"';
$actions = '';
if ($user->can('imports.manage')) {
    $actions .= '<a class="btn" href="' . e(url('/licences/import')) . '">' . icon('scan-text') . ' ' . e(t('cockpit.import_licence')) . '</a>';
}
if ($user->can('orders.manage')) {
    $actions .= '<a class="btn btn--primary" href="' . e(url('/orders/new')) . '">' . icon('plus') . ' ' . e(t('cockpit.new_order')) . '</a>';
}
echo View::partial('partials/page_header', [
    'icon' => 'layout-dashboard',
    'heading' => t($hour < 12 ? 'cockpit.hello_morning' : ($hour < 18 ? 'cockpit.hello_afternoon' : 'cockpit.hello_evening'), ['name' => explode(' ', $user->name)[0]]),
    'meta' => t('cockpit.meta', ['date' => fmt_date(date('Y-m-d'))]),
    'actions' => $actions,
]);
$levels = array_count_values(array_column($todo, 'level'));
?>
<div class="cockpit">

<!-- ① À faire aujourd'hui : replié par défaut, compteurs par urgence -->
<details class="card cockpit-todo">
    <summary class="cockpit-todo__head">
        <span class="cockpit-todo__title"><?= icon('list-checks') ?> <?= e(t('cockpit.todo_title')) ?></span>
        <?php if (!$todo): ?>
            <span class="text-good"><?= icon('circle-check') ?> <?= e(t('cockpit.todo_none')) ?></span>
        <?php else: ?>
            <span class="todo-chips">
                <?php foreach (['bad', 'warn', 'info'] as $lv): if (!empty($levels[$lv])): ?>
                    <span class="todo-chip todo-chip--<?= $lv ?>"><?= (int) $levels[$lv] ?> <?= e(t('cockpit.level.' . $lv)) ?></span>
                <?php endif; endforeach ?>
            </span>
            <span class="muted cockpit-todo__first"><?= e($todo[0]['text']) ?><?= $todo[0]['ref'] !== '' ? ' — ' . e($todo[0]['ref']) : '' ?></span>
        <?php endif ?>
        <span class="cockpit-todo__toggle" aria-hidden="true"><?= icon('chevron-down') ?></span>
    </summary>
    <?php if ($todo): ?>
        <ul class="todo-list">
            <?php foreach (array_slice($todo, 0, 12) as $a): ?>
                <li class="todo todo--<?= e($a['level']) ?>">
                    <span class="todo__icon"><?= icon($a['icon']) ?></span>
                    <span class="todo__text"><strong><?= e($a['text']) ?></strong><?php if ($a['ref'] !== ''): ?> <span class="muted">— <?= e($a['ref']) ?></span><?php endif ?></span>
                    <a class="todo__cta" href="<?= e($a['href']) ?>"><?= e($a['cta']) ?> <?= icon('arrow-right') ?></a>
                </li>
            <?php endforeach ?>
        </ul>
        <div class="cockpit-todo__foot"><button type="button" class="link-btn"<?= $drill('todo', t('cockpit.todo_title')) ?>><?= icon('table') ?> <?= e(t('cockpit.see_all', ['n' => count($todo)])) ?></button></div>
    <?php endif ?>
</details>

<!-- ② Tuiles -->
<section class="cockpit-tiles" aria-label="<?= e(t('cockpit.tiles')) ?>">
    <article class="tile tile--orders">
        <a class="tile__head" href="<?= e(url('/orders')) ?>"><?= icon('clipboard-list') ?> <?= e(t('nav.orders')) ?></a>
        <button type="button" class="tile__value"<?= $drill('orders_open', t('cockpit.drill.orders_open')) ?>><?= (int) $o['open'] ?> <small><?= e(t('cockpit.open')) ?></small></button>
        <ul class="tile__facts">
            <li><button type="button" class="drill-link <?= $o['late'] ? 'text-bad' : 'muted' ?>"<?= $drill('orders_late', t('cockpit.drill.orders_late')) ?>><?= icon('clock-alert') ?> <?= e(t('cockpit.orders_late', ['n' => (int) $o['late']])) ?></button></li>
            <li><button type="button" class="drill-link <?= $o['to_source'] ? 'text-warn' : 'muted' ?>"<?= $drill('orders_to_source', t('cockpit.drill.orders_to_source')) ?>><?= icon('search') ?> <?= e(t('cockpit.orders_to_source', ['n' => (int) $o['to_source']])) ?></button></li>
        </ul>
        <button type="button" class="tile__money"<?= $drill('orders_open', t('cockpit.drill.orders_open')) ?>><span class="muted"><?= e(t('cockpit.backlog')) ?></span> <strong><?= e(fmt_money($o['backlog'])) ?></strong></button>
    </article>
    <article class="tile tile--pos">
        <a class="tile__head" href="<?= e(url('/purchase-orders')) ?>"><?= icon('shopping-cart') ?> <?= e(t('nav.purchase_orders')) ?></a>
        <button type="button" class="tile__value"<?= $drill('pos_open', t('cockpit.drill.pos_open')) ?>><?= (int) $p['open'] ?> <small><?= e(t('cockpit.in_progress')) ?></small></button>
        <ul class="tile__facts">
            <li><button type="button" class="drill-link <?= $p['drafts'] ? 'text-warn' : 'muted' ?>"<?= $drill('pos_drafts', t('cockpit.drill.pos_drafts')) ?>><?= icon('send') ?> <?= e(t('cockpit.pos_drafts', ['n' => (int) $p['drafts']])) ?></button></li>
            <li><button type="button" class="drill-link <?= $p['late'] ? 'text-bad' : 'muted' ?>"<?= $drill('pos_late', t('cockpit.drill.pos_late')) ?>><?= icon('clock-alert') ?> <?= e(t('cockpit.pos_late', ['n' => (int) $p['late']])) ?></button></li>
        </ul>
        <button type="button" class="tile__money"<?= $drill('pos_open', t('cockpit.drill.pos_open')) ?>><span class="muted"><?= e(t('cockpit.committed')) ?></span> <strong><?= e(fmt_money($p['committed'])) ?></strong></button>
    </article>
    <article class="tile tile--licences">
        <a class="tile__head" href="<?= e(url('/licences')) ?>"><?= icon('stamp') ?> <?= e(t('nav.licences')) ?></a>
        <button type="button" class="tile__value"<?= $drill('licences_active', t('cockpit.drill.licences_active')) ?>><?= (int) $l['active'] ?> <small><?= e(t('cockpit.active')) ?></small></button>
        <ul class="tile__facts">
            <li><button type="button" class="drill-link <?= $l['expired'] ? 'text-bad' : ($l['expiring'] ? 'text-warn' : 'muted') ?>"<?= $drill('licences_expiring', t('cockpit.drill.licences_expiring')) ?>><?= icon('calendar-clock') ?> <?= e(t('cockpit.licences_expiring', ['n' => (int) $l['expiring']])) ?><?= $l['expired'] ? ' · ' . e(t('cockpit.licences_expired', ['n' => (int) $l['expired']])) : '' ?></button></li>
            <li><button type="button" class="drill-link <?= $l['missing'] ? 'text-warn' : 'muted' ?>"<?= $drill('licences_missing', t('cockpit.drill.licences_missing')) ?>><?= icon('file-plus') ?> <?= e(t('cockpit.licences_missing', ['n' => (int) $l['missing']])) ?></button></li>
        </ul>
        <button type="button" class="tile__money"<?= $drill('licences_active', t('cockpit.drill.licences_active')) ?>><span class="muted"><?= e(t('cockpit.authorized')) ?></span> <strong><?= e(fmt_money($l['authorized'])) ?></strong></button>
    </article>
    <article class="tile tile--deliveries">
        <a class="tile__head" href="<?= e(url('/deliveries')) ?>"><?= icon('truck') ?> <?= e(t('nav.deliveries')) ?></a>
        <button type="button" class="tile__value"<?= $drill('deliveries_in_transit', t('cockpit.drill.deliveries_in_transit')) ?>><?= (int) $d['in_transit'] ?> <small><?= e(t('cockpit.in_transit')) ?></small></button>
        <ul class="tile__facts">
            <li><button type="button" class="drill-link <?= $d['planned'] ? 'text-warn' : 'muted' ?>"<?= $drill('deliveries_planned', t('cockpit.drill.deliveries_planned')) ?>><?= icon('calendar') ?> <?= e(t('cockpit.deliveries_planned', ['n' => (int) $d['planned']])) ?></button></li>
            <li><button type="button" class="drill-link muted"<?= $drill('deliveries_month', t('cockpit.drill.deliveries_month', ['month' => \App\Repositories\CockpitDrill::monthLabel(date('Y-m'))]), date('Y-m')) ?>><?= icon('package-check') ?> <?= e(t('cockpit.deliveries_month', ['n' => (int) $d['delivered_month']])) ?></button></li>
        </ul>
        <button type="button" class="tile__money"<?= $drill('deliveries_active', t('cockpit.drill.deliveries_active')) ?>><span class="muted"><?= e(t('cockpit.deliveries_hint')) ?></span> <strong><?= (int) $d['planned'] + (int) $d['in_transit'] ?></strong></button>
    </article>
</section>
<?php if ($missingRates): ?><p class="hint text-warn"><?= icon('info') ?> <?= e(t('cockpit.missing_rates', ['list' => implode(', ', $missingRates)])) ?></p><?php endif ?>

<!-- ③ Graphiques -->
<section class="cockpit-charts" aria-label="<?= e(t('cockpit.charts')) ?>">
    <figure class="card chart-card">
        <figcaption class="chart-card__title"><?= icon('chart-column') ?> <?= e(t('cockpit.chart.deliveries')) ?> <span class="muted"><?= e(t('cockpit.chart.deliveries_hint')) ?></span></figcaption>
        <div class="chart-card__box"><canvas id="ck-chart-deliveries" role="img" aria-label="<?= e(t('cockpit.chart.deliveries')) ?>"></canvas></div>
    </figure>
    <figure class="card chart-card">
        <figcaption class="chart-card__title"><?= icon('chart-bar') ?> <?= e(t('cockpit.chart.suppliers')) ?> <span class="muted"><?= e(base_currency()) ?></span></figcaption>
        <div class="chart-card__box"><canvas id="ck-chart-suppliers" role="img" aria-label="<?= e(t('cockpit.chart.suppliers')) ?>"></canvas></div>
    </figure>
    <figure class="card chart-card">
        <figcaption class="chart-card__title"><?= icon('gauge') ?> <?= e(t('cockpit.chart.licences')) ?> <span class="muted"><?= e(base_currency()) ?></span></figcaption>
        <div class="chart-card__box"><canvas id="ck-chart-licences" role="img" aria-label="<?= e(t('cockpit.chart.licences')) ?>"></canvas></div>
    </figure>
</section>
<?= json_script('cockpit-charts', $charts + ['i18n' => [
    'count' => t('cockpit.chart.count'), 'value' => t('cockpit.chart.value'), 'used' => t('cockpit.col.used'), 'remaining' => t('cockpit.col.remaining'),
    'empty' => t('cockpit.chart.empty'), 'supplier_title' => t('cockpit.drill.pos_supplier'), 'month_title' => t('cockpit.drill.deliveries_month', ['month' => '']),
    'licences_title' => t('cockpit.drill.licences_active'),
]]) ?>

<!-- ④ Pipeline -->
<section class="card cockpit-pipe" aria-labelledby="cockpit-pipe-title">
    <div class="card__title"><h2 id="cockpit-pipe-title"><?= icon('workflow', 'title-icon') ?> <?= e(t('cockpit.pipeline')) ?></h2>
        <span class="muted"><?= e(t('cockpit.pipeline_hint')) ?></span></div>
    <?php
    $icons = ['to_source' => 'search', 'licence' => 'stamp', 'inspection' => 'search-check', 'ordered' => 'shopping-cart', 'in_delivery' => 'truck', 'to_close' => 'flag'];
    $max = max(1, ...array_map(fn ($k) => $pipeline[$k], array_keys($icons)));
    ?>
    <ol class="pipeline">
        <?php foreach ($icons as $k => $ic): $n = $pipeline[$k]; ?>
            <li class="pipeline__step<?= $n ? '' : ' is-empty' ?>">
                <button type="button"<?= $drill('pipe', t('cockpit.pipe.' . $k), $k) ?>>
                    <span class="pipeline__label"><?= icon($ic) ?> <?= e(t('cockpit.pipe.' . $k)) ?></span>
                    <span class="pipeline__value"><?= $n ?></span>
                    <span class="pipeline__bar"><span style="width: <?= round($n / $max * 100) ?>%"></span></span>
                    <?php if ($k === 'inspection' && $pipeline['blocked']): ?><span class="pipeline__note text-bad"><?= e(t('cockpit.pipe.blocked', ['n' => $pipeline['blocked']])) ?></span><?php endif ?>
                </button>
            </li>
        <?php endforeach ?>
    </ol>
</section>

<div class="grid grid--2">
    <!-- ⑤ Livraisons en cours -->
    <section class="card" aria-labelledby="cockpit-del-title">
        <div class="card__title">
            <h2 id="cockpit-del-title"><?= icon('truck', 'title-icon') ?> <?= e(t('cockpit.deliveries_title')) ?> <span class="count-chip"><?= count($deliveries) ?></span></h2>
            <button type="button" class="link-btn"<?= $drill('deliveries_active', t('cockpit.drill.deliveries_active')) ?>><?= icon('table') ?> <?= e(t('cockpit.details')) ?></button>
        </div>
        <?php if (!$deliveries): ?>
            <p class="empty"><?= e(t('cockpit.no_delivery')) ?></p>
        <?php else: ?>
            <ul class="cockpit-list">
                <?php foreach ($deliveries as $r): $days = max(0, (int) $r['days']); ?>
                    <li>
                        <a class="cockpit-list__main" href="<?= e(url('/deliveries/' . (int) $r['id'])) ?>">
                            <strong><?= e($r['reference']) ?></strong>
                            <?= View::partial('partials/status_badge', ['key' => 'delivery.status.' . $r['status'], 'status' => $r['status']]) ?>
                            <span class="muted"><?= e($r['customer']) ?> · <?= e($r['supplier']) ?></span>
                        </a>
                        <span class="cockpit-list__meta">
                            <?php if ($r['vehicle_plate']): ?><span class="tag tag--plate"><?= e($r['vehicle_plate']) ?></span><?php endif ?>
                            <?php if ($r['fulfillment_mode'] === 'import'): ?>
                                <span class="<?= $r['agency'] ? ($r['forwarder_ref'] ? 'muted' : 'text-warn') : 'text-bad' ?>"><?= icon('landmark') ?> <?= e($r['agency'] ? $r['agency'] . ($r['forwarder_ref'] ? ' · ' . $r['forwarder_ref'] : '') : t('cockpit.no_agency')) ?></span>
                            <?php endif ?>
                            <span class="<?= $r['status'] === 'in_transit' && $days > 10 ? 'text-bad' : 'muted' ?>"><?= icon('calendar') ?> <?= e($r['status'] === 'in_transit' ? t('cockpit.days_on_road', ['n' => $days]) : t('cockpit.planned_on', ['date' => fmt_date($r['shipped_at'])])) ?></span>
                        </span>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>

    <!-- ⑥ Licences -->
    <section class="card" aria-labelledby="cockpit-lic-title">
        <div class="card__title">
            <h2 id="cockpit-lic-title"><?= icon('stamp', 'title-icon') ?> <?= e(t('cockpit.licences_title')) ?> <span class="count-chip"><?= count($licences) ?></span></h2>
            <button type="button" class="link-btn"<?= $drill('licences_active', t('cockpit.drill.licences_active')) ?>><?= icon('table') ?> <?= e(t('cockpit.details')) ?></button>
        </div>
        <?php if (!$licences): ?>
            <p class="empty"><?= e(t('cockpit.no_licence')) ?></p>
        <?php else: ?>
            <ul class="cockpit-list">
                <?php foreach ($licences as $r):
                    $pct = (float) $r['amount'] > 0 ? min(100, round((float) $r['used'] / (float) $r['amount'] * 100)) : 0;
                    $days = (int) $r['days'];
                    $dTone = $days < 0 ? 'bad' : ($days <= 30 ? 'warn' : 'good'); ?>
                    <li>
                        <a class="cockpit-list__main" href="<?= e(url('/licences/' . (int) $r['id'])) ?>">
                            <strong class="mono"><?= e($r['cod_number'] ?: $r['number']) ?></strong>
                            <span class="muted"><?= e($r['po_ref']) ?> · <?= e($r['supplier']) ?></span>
                        </a>
                        <span class="cockpit-list__meta">
                            <span class="gauge" title="<?= e(t('cockpit.used_title', ['used' => fmt_money($r['used'], $r['currency']), 'amount' => fmt_money($r['amount'], $r['currency'])])) ?>">
                                <span class="gauge__bar"><span class="gauge__fill<?= $pct >= 90 ? ' gauge__fill--warn' : '' ?>" style="width: <?= $pct ?>%"></span></span>
                                <span class="gauge__pct"><?= $pct ?> %</span>
                            </span>
                            <span class="text-<?= $dTone ?>"><?= icon('calendar-clock') ?> <?= e($days < 0 ? t('cockpit.expired_since', ['n' => -$days]) : t('cockpit.expires_in', ['date' => fmt_date($r['expires_at']), 'n' => $days])) ?></span>
                        </span>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>
</div>

<!-- ⑦ Activité -->
<section class="card" aria-labelledby="cockpit-act-title">
    <div class="card__title"><h2 id="cockpit-act-title"><?= icon('history', 'title-icon') ?> <?= e(t('cockpit.activity')) ?></h2>
        <button type="button" class="link-btn"<?= $drill('activity', t('cockpit.drill.activity')) ?>><?= icon('table') ?> <?= e(t('cockpit.activity_30')) ?></button></div>
    <?php if (!$activity): ?>
        <p class="empty"><?= e(t('cockpit.no_activity')) ?></p>
    <?php else: ?>
        <ul class="activity">
            <?php foreach ($activity as $ev): ?>
                <li>
                    <span class="activity__time muted"><?= e(fmt_date($ev['created_at'], true)) ?></span>
                    <a href="<?= e(url('/orders/' . (int) $ev['order_id']) . '#timeline') ?>" class="activity__ref"><?= e($ev['customer_po_number']) ?></a>
                    <span><strong><?= e(t('event.' . $ev['event'])) ?></strong><?php if ($ev['detail']): ?> — <?= e($ev['event'] === 'status' ? t('order.status.' . $ev['detail']) : $ev['detail']) ?><?php endif ?></span>
                    <?php if ($ev['user_name']): ?><span class="muted activity__user"><?= e($ev['user_name']) ?></span><?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>

</div>
