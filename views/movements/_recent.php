<?php /** @var array $movements @var bool $showItem */ ?>
<?php if (!$movements): ?>
    <p class="empty"><?= e(t('movements.none')) ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
    <thead><tr>
        <th><?= e(t('movements.col.date')) ?></th>
        <th><?= e(t('movements.col.reference')) ?></th>
        <th><?= e(t('movements.col.type')) ?></th>
        <?php if ($showItem): ?><th><?= e(t('movements.col.item')) ?></th><?php endif ?>
        <th><?= e(t('movements.col.warehouse')) ?></th>
        <th class="num"><?= e(t('movements.col.quantity')) ?></th>
        <th class="num"><?= e(t('movements.col.balance')) ?></th>
        <th><?= e(t('movements.col.user')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($movements as $m):
        $sign = in_array($m['type'], ['in', 'transfer_in', 'adjust_in'], true) ? '+' : '−'; ?>
        <tr>
            <td><?= e(fmt_date($m['moved_at'], true)) ?></td>
            <td><strong><?= e($m['reference']) ?></strong><?php if ($m['note']): ?><br><span class="muted"><?= e($m['note']) ?></span><?php endif ?></td>
            <td><span class="badge badge--mv-<?= e($m['type']) ?>"><?= e(t('mv.type.' . $m['type'])) ?></span></td>
            <?php if ($showItem): ?>
                <td><a href="<?= e(url('/items/' . (int) $m['item_id'])) ?>"><strong><?= e($m['item_sku']) ?></strong></a><br><span class="muted"><?= e($m['item_name']) ?></span></td>
            <?php endif ?>
            <td><?= e($m['warehouse_code']) ?></td>
            <td class="num qty qty--<?= $sign === '+' ? 'in' : 'out' ?>"><?= $sign ?><?= e(fmt_qty($m['quantity'])) ?> <?= e($m['unit']) ?></td>
            <td class="num"><?= e(fmt_qty($m['balance_after'])) ?></td>
            <td><?= e($m['user_name']) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>
