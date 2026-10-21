<?php
/** @var array $outstanding @var string $direction @var string $status */
use App\Core\View;

$actions = '<button type="button" class="btn" data-export="excel" data-export-table="invoices" data-export-title="' . e(t('nav.finance')) . '">' . icon('file-spreadsheet') . ' Excel</button>'
    . '<button type="button" class="btn" data-export="pdf" data-export-table="invoices" data-export-title="' . e(t('nav.finance')) . '">' . icon('file-text') . ' PDF</button>';
echo View::partial('partials/page_header', ['icon' => 'banknote', 'heading' => t('nav.finance'), 'meta' => t('finance.meta'), 'actions' => $actions]);
$fmt = function (array $byCur, string $k): string {
    $parts = [];
    foreach ($byCur as $c => $v) {
        if ($v[$k] > 0.004) {
            $parts[] = fmt_money($v[$k], $c);
        }
    }
    return $parts ? implode(' · ', $parts) : '0';
};
$hasOverdue = fn (array $byCur) => (bool) array_filter($byCur, fn ($v) => $v['overdue'] > 0.004);
?>
<section class="kpis kpis--compact">
    <button type="button" class="kpi kpi--button" data-set-filters='{"direction":"receivable","status":"open"}'>
        <span class="kpi__label"><?= e(t('finance.kpi.receivable')) ?></span><span class="kpi__value kpi__value--sm"><?= e($fmt($outstanding['receivable'], 'open')) ?></span><span class="kpi__hint"><?= e(t('finance.kpi.receivable_hint')) ?></span></button>
    <button type="button" class="kpi kpi--button<?= $hasOverdue($outstanding['receivable']) ? ' kpi--alert' : '' ?>" data-set-filters='{"direction":"receivable","status":"overdue"}'>
        <span class="kpi__label"><?= e(t('finance.kpi.receivable_overdue')) ?></span><span class="kpi__value kpi__value--sm"><?= e($fmt($outstanding['receivable'], 'overdue')) ?></span><span class="kpi__hint"><?= e(t('finance.kpi.overdue_hint')) ?></span></button>
    <button type="button" class="kpi kpi--button" data-set-filters='{"direction":"payable","status":"open"}'>
        <span class="kpi__label"><?= e(t('finance.kpi.payable')) ?></span><span class="kpi__value kpi__value--sm"><?= e($fmt($outstanding['payable'], 'open')) ?></span><span class="kpi__hint"><?= e(t('finance.kpi.payable_hint')) ?></span></button>
    <button type="button" class="kpi kpi--button<?= $hasOverdue($outstanding['payable']) ? ' kpi--warn' : '' ?>" data-set-filters='{"direction":"payable","status":"overdue"}'>
        <span class="kpi__label"><?= e(t('finance.kpi.payable_overdue')) ?></span><span class="kpi__value kpi__value--sm"><?= e($fmt($outstanding['payable'], 'overdue')) ?></span><span class="kpi__hint"><?= e(t('finance.kpi.overdue_hint')) ?></span></button>
</section>

<section class="card">
    <form class="filters" data-filters-for="invoices">
        <div class="field">
            <label for="f-dir"><?= e(t('finance.direction')) ?></label>
            <select id="f-dir" data-filter="direction">
                <?php foreach (['' => 'common.all', 'receivable' => 'invoice.dir.receivable', 'payable' => 'invoice.dir.payable'] as $v => $k): ?>
                    <option value="<?= e($v) ?>" <?= $direction === $v ? 'selected' : '' ?>><?= e(t($k)) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-st"><?= e(t('common.status')) ?></label>
            <select id="f-st" data-filter="status">
                <?php foreach (['open' => 'invoice.status.open', 'overdue' => 'invoice.status.overdue', 'due_soon' => 'finance.due_soon', 'paid' => 'invoice.status.paid', 'cancelled' => 'invoice.status.cancelled', '' => 'common.all'] as $v => $k): ?>
                    <option value="<?= e($v) ?>" <?= $status === $v ? 'selected' : '' ?>><?= e(t($k)) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="filters__meta">
            <span class="chip" data-filters-count hidden></span>
            <button type="button" class="btn btn--ghost btn--sm" data-filters-reset hidden><?= icon('rotate-ccw') ?> <?= e(t('common.reset')) ?></button>
        </div>
    </form>
    <div class="table-wrap">
        <table class="table table--data" data-table="invoices" data-source="<?= e(url('/finance/data')) ?>" data-row-link="<?= e(url('/invoices/{id}')) ?>"></table>
    </div>
</section>
