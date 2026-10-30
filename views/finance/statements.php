<?php
/**
 * Page des relevés de tiers : filtres (sens, tiers, contenu, période, devise, site, dossier, soldées), document affiché,
 * aperçu PDF et export Excel dans l'orientation choisie (portrait / paysage).
 * @var array $q paramètres validés @var list<array> $partyList @var list<array> $orders @var array $sites @var array $currencies
 */
use App\Core\View;

$rec = $q['direction'] === 'receivable';
$query = array_filter([
    'direction' => $q['direction'], 'party_id' => $q['party_id'], 'type' => $q['type'], 'from' => $q['from'], 'to' => $q['to'],
    'currency' => $q['currency'], 'site_id' => $q['site_id'], 'order_id' => $q['order_id'], 'settled' => $q['settled'] ? '1' : null,
    'orientation' => $q['orientation'],
], fn ($v) => $v !== null && $v !== '');
$docUrl = url('/finance/statement', $query);
$sel = fn ($a, $b) => (string) $a === (string) $b ? 'selected' : '';

echo View::partial('partials/page_header', ['icon' => 'scroll-text', 'heading' => t('statement.page_title'), 'meta' => t('statement.page_meta'),
    'actions' => '<a class="btn"' . preview_attrs($docUrl, t('statement.preview_title'), null, 'html') . ' data-statement-preview>' . icon('file-text') . ' ' . e(t('statement.preview_pdf')) . '</a>'
        . '<button type="button" class="btn" data-statement-excel="' . e(url('/finance/statement', $query + ['format' => 'json'])) . '">' . icon('file-spreadsheet') . ' Excel</button>']);
?>
<section class="card">
    <form method="get" action="<?= e(url('/finance/statements')) ?>" class="statement-filters-form" data-statement-form>
        <div class="segmented segmented--radio" role="radiogroup" aria-label="<?= e(t('statement.direction')) ?>">
            <?php foreach (['receivable' => 'nav.invoices_customers', 'payable' => 'nav.invoices_suppliers'] as $v => $k): ?>
                <label class="segmented__item"><input type="radio" name="direction" value="<?= $v ?>" <?= $q['direction'] === $v ? 'checked' : '' ?> data-autosubmit data-reset-party> <?= e(t($k)) ?></label>
            <?php endforeach ?>
        </div>
        <div class="filters filters--statement">
            <div class="field">
                <label for="st-party"><?= e(t($rec ? 'orders.col.customer' : 'sourcing.col.supplier')) ?></label>
                <select id="st-party" name="party_id" data-autosubmit>
                    <option value=""><?= e(t('statement.all_parties')) ?></option>
                    <?php foreach ($partyList as $x): ?><option value="<?= (int) $x['id'] ?>" <?= $sel($q['party_id'], $x['id']) ?>><?= e($x['name']) ?></option><?php endforeach ?>
                </select>
            </div>
            <?php if ($q['party_id'] !== null): ?>
            <div class="field">
                <label for="st-type"><?= e(t('statement.type')) ?></label>
                <select id="st-type" name="type" data-autosubmit>
                    <?php foreach (['full' => 'statement.type.full', 'ledger' => 'statement.type.ledger', 'aging' => 'statement.type.aging'] as $v => $k): ?>
                        <option value="<?= $v ?>" <?= $sel($q['type'], $v) ?>><?= e(t($k)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?php endif ?>
            <div class="field">
                <label for="st-period"><?= e(t('statement.period')) ?></label>
                <select id="st-period" data-period>
                    <option value="custom"><?= e(t('statement.preset.custom')) ?></option>
                    <?php foreach (['ytd', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'last_year', 'last_12'] as $p): ?>
                        <option value="<?= $p ?>"><?= e(t('statement.preset.' . $p)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="field field--dates">
                <label for="st-from"><?= e(t('statement.from_to')) ?></label>
                <div class="date-range">
                    <input id="st-from" type="date" name="from" value="<?= e($q['from']) ?>" aria-label="<?= e(t('delivery.filter.from')) ?>"<?= $q['type'] === 'balance' || $q['type'] === 'aging' ? ' disabled' : '' ?>>
                    <span class="muted">→</span>
                    <input id="st-to" type="date" name="to" value="<?= e($q['to']) ?>" aria-label="<?= e(t('statement.to_asof')) ?>">
                </div>
            </div>
            <div class="field">
                <label for="st-cur"><?= e(t('movements.currency')) ?></label>
                <select id="st-cur" name="currency" data-autosubmit>
                    <option value=""><?= e(t('statement.all_currencies')) ?></option>
                    <?php foreach ($currencies as $c): ?><option value="<?= e($c['code']) ?>" <?= $sel($q['currency'], $c['code']) ?>><?= e($c['code']) ?></option><?php endforeach ?>
                </select>
            </div>
            <?php if (count($sites) > 1): ?>
            <div class="field">
                <label for="st-site"><?= e(t('orders.col.site')) ?></label>
                <select id="st-site" name="site_id" data-autosubmit>
                    <option value=""><?= e(t('common.all')) ?></option>
                    <?php foreach ($sites as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sel($q['site_id'], $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach ?>
                </select>
            </div>
            <?php endif ?>
            <?php if ($orders): ?>
            <div class="field">
                <label for="st-order"><?= e(t('invoice.col.order')) ?></label>
                <select id="st-order" name="order_id" data-autosubmit>
                    <option value=""><?= e(t('common.all')) ?></option>
                    <?php foreach ($orders as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $sel($q['order_id'], $o['id']) ?>><?= e($o['reference'] . ' · ' . $o['customer_po_number']) ?></option><?php endforeach ?>
                </select>
            </div>
            <?php endif ?>
            <div class="field">
                <label for="st-orient"><?= e(t('statement.orientation')) ?></label>
                <select id="st-orient" name="orientation" data-autosubmit>
                    <option value="portrait" <?= $sel($q['orientation'], 'portrait') ?>><?= e(t('statement.orientation.portrait')) ?></option>
                    <option value="landscape" <?= $sel($q['orientation'], 'landscape') ?>><?= e(t('statement.orientation.landscape')) ?></option>
                </select>
            </div>
            <div class="field field--check">
                <label class="check"><input type="checkbox" name="settled" value="1" <?= $q['settled'] ? 'checked' : '' ?> data-autosubmit> <?= e(t('statement.include_settled')) ?></label>
            </div>
            <div class="filters__meta">
                <button class="btn btn--primary btn--sm"><?= icon('refresh-cw') ?> <?= e(t('statement.apply')) ?></button>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('/finance/statements', ['direction' => $q['direction']])) ?>"><?= icon('rotate-ccw') ?> <?= e(t('common.reset')) ?></a>
            </div>
        </div>
        <p class="hint"><?= e(t($q['party_id'] === null ? 'statement.all_parties_hint' : 'statement.form_hint')) ?></p>
    </form>
</section>

<section class="statement-view">
    <iframe class="statement-frame" src="<?= e($docUrl) ?>" title="<?= e(t('statement.preview_title')) ?>" data-autoheight></iframe>
</section>
