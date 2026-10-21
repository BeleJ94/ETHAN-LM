<?php
/** @var array $item @var array $levels @var float $total @var array $movements */
use App\Core\View;
use App\Security\Auth;

$user = Auth::require();
$id = (int) $item['id'];
$min = (float) $item['min_stock'];
$state = !$item['active'] ? 'immobilized' : ($total <= 0 ? 'breakdown' : ($min > 0 && $total <= $min ? 'waiting_part' : 'available'));
$stateKey = !$item['active'] ? 'items.state.inactive' : ($total <= 0 ? 'items.state.out' : ($min > 0 && $total <= $min ? 'items.state.low' : 'items.state.ok'));
?>
<section class="hero">
    <div class="hero__icon"><?= icon('package') ?></div>
    <div class="hero__body">
        <div class="hero__line">
            <h1 class="hero__code"><?= e($item['sku']) ?></h1>
            <span class="badge badge--<?= e($state) ?>"><?= e(t($stateKey)) ?></span>
        </div>
        <p class="hero__name"><?= e($item['name']) ?></p>
        <p class="hero__tags">
            <span class="chip chip--c-<?= e($item['family_color']) ?>"><?= e($item['family_name']) ?></span>
            <?php if ($item['category']): ?><span class="tag"><?= icon('tag') ?> <?= e($item['category']) ?></span><?php endif ?>
            <span class="tag"><?= icon('ruler') ?> <?= e($item['unit_label']) ?></span>
            <?php if ($item['hs_code']): ?><span class="tag tag--plate">HS <?= e($item['hs_code']) ?></span><?php endif ?>
        </p>
    </div>
    <div class="hero__actions">
        <?php if ($user->can('stock.move')): ?>
            <a class="btn btn--primary" href="<?= e(url('/movements/new', ['type' => 'in', 'item_id' => $id])) ?>"><?= icon('package-plus') ?> <?= e(t('movements.new.in')) ?></a>
            <a class="btn" href="<?= e(url('/movements/new', ['type' => 'out', 'item_id' => $id])) ?>"><?= icon('package-minus') ?> <?= e(t('movements.new.out')) ?></a>
        <?php endif ?>
        <div class="dropdown">
            <button type="button" class="icon-btn" data-menu-toggle aria-haspopup="true" aria-expanded="false" title="<?= e(t('common.more')) ?>" aria-label="<?= e(t('common.more')) ?>"><?= icon('ellipsis') ?></button>
            <div class="menu" data-menu role="menu" hidden>
                <?php if ($user->can('stock.move')): ?>
                    <a class="menu__item" role="menuitem" href="<?= e(url('/movements/new', ['type' => 'transfer', 'item_id' => $id])) ?>"><?= icon('arrow-left-right') ?> <?= e(t('movements.new.transfer')) ?></a>
                <?php endif ?>
                <?php if ($user->can('stock.adjust')): ?>
                    <a class="menu__item" role="menuitem" href="<?= e(url('/movements/new', ['type' => 'adjust', 'item_id' => $id])) ?>"><?= icon('clipboard-check') ?> <?= e(t('movements.new.adjust')) ?></a>
                <?php endif ?>
                <?php if ($user->can('items.manage')): ?>
                    <a class="menu__item" role="menuitem" href="<?= e(url("/items/$id/edit")) ?>"><?= icon('pencil') ?> <?= e(t('common.edit')) ?></a>
                <?php endif ?>
                <a class="menu__item" role="menuitem" href="<?= e(url('/movements', ['item_id' => $id])) ?>"><?= icon('list') ?> <?= e(t('items.all_movements')) ?></a>
            </div>
        </div>
    </div>
</section>

<section class="vitals">
    <div class="vital<?= $state === 'breakdown' ? ' vital--alert' : '' ?>">
        <?= icon('boxes', 'vital__icon vital__icon--' . ($state === 'available' ? 'good' : ($state === 'waiting_part' ? 'warn' : 'bad'))) ?>
        <span class="vital__label"><?= e(t('items.vital.stock')) ?></span>
        <span class="vital__value"><?= e(fmt_qty($total)) ?> <?= e($item['unit']) ?></span>
    </div>
    <div class="vital"><?= icon('gauge', 'vital__icon') ?><span class="vital__label"><?= e(t('items.col.min_stock')) ?></span><span class="vital__value"><?= e(fmt_qty($min)) ?> <?= e($item['unit']) ?></span></div>
    <div class="vital"><?= icon('calculator', 'vital__icon') ?><span class="vital__label"><?= e(t('items.col.avg_cost')) ?></span><span class="vital__value"><?= e(fmt_money($item['avg_cost'])) ?></span></div>
    <div class="vital"><?= icon('wallet', 'vital__icon') ?><span class="vital__label"><?= e(t('items.col.value')) ?></span><span class="vital__value"><?= e(fmt_money($total * (float) $item['avg_cost'])) ?></span></div>
</section>

<div class="grid grid--2-1">
    <div>
        <section class="card">
            <div class="card__title">
                <h2><?= icon('history', 'title-icon') ?> <?= e(t('items.last_movements')) ?></h2>
                <a class="link-btn" href="<?= e(url('/movements', ['item_id' => $id])) ?>"><?= e(t('common.see_all')) ?></a>
            </div>
            <?= View::partial('movements/_recent', ['movements' => $movements, 'showItem' => false]) ?>
        </section>
        <?php if ($item['description']): ?>
        <section class="card">
            <div class="card__title"><h2><?= icon('file-text', 'title-icon') ?> <?= e(t('items.description')) ?></h2></div>
            <p class="prewrap"><?= e($item['description']) ?></p>
        </section>
        <?php endif ?>
    </div>
    <section class="card">
        <div class="card__title"><h2><?= icon('warehouse', 'title-icon') ?> <?= e(t('items.by_warehouse')) ?></h2></div>
        <?php if (!$levels): ?>
            <p class="empty"><?= e(t('warehouses.none')) ?></p>
        <?php else: ?>
            <ul class="stock-list">
                <?php foreach ($levels as $l): $q = (float) $l['quantity']; ?>
                    <li class="stock-list__item<?= $q <= 0 ? ' stock-list__item--empty' : '' ?>">
                        <span><strong><?= e($l['code']) ?></strong> <span class="muted"><?= e($l['name']) ?> · <?= e($l['site_name']) ?></span></span>
                        <span class="num"><?= e(fmt_qty($q)) ?> <?= e($item['unit']) ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>
</div>
