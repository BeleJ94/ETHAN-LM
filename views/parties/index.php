<?php
/** @var string $type @var string $base @var array $parties */
use App\Core\View;
use App\Security\Auth;

$actions = Auth::require()->can('parties.manage')
    ? '<a class="btn btn--primary" href="' . e(url("$base/new")) . '">' . icon('plus') . ' ' . e(t('parties.new.' . $type)) . '</a>' : '';
echo View::partial('partials/page_header', [
    'icon' => $type === 'customer' ? 'building-2' : 'factory',
    'heading' => t('nav.' . $type . 's'),
    'meta' => t('parties.count', ['n' => count($parties)]),
    'actions' => $actions,
]);
?>
<section class="card">
    <?php if (!$parties): ?>
        <div class="empty-state"><?= icon($type === 'customer' ? 'building-2' : 'factory') ?><p><?= e(t('parties.none')) ?></p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th><?= e(t('parties.col.name')) ?></th>
            <th><?= e(t('parties.col.contact')) ?></th>
            <th><?= e(t('parties.col.city')) ?></th>
            <th class="num"><?= e(t('parties.col.open_orders')) ?></th>
            <th class="num"><?= e(t($type === 'customer' ? 'parties.col.receivable' : 'parties.col.payable')) ?></th>
            <th><?= e(t('common.status')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($parties as $p): ?>
            <tr class="row-link" tabindex="0" data-href="<?= e(url("$base/" . (int) $p['id'])) ?>">
                <td><strong><?= e($p['name']) ?></strong><br><span class="muted"><?= e($p['code']) ?></span></td>
                <td><?= e($p['contact_name'] ?? '—') ?><?php if ($p['phone']): ?><br><span class="muted"><?= e($p['phone']) ?></span><?php endif ?></td>
                <td><?= e($p['city'] ?? '—') ?></td>
                <td class="num"><?= (int) $p['open_orders'] ?></td>
                <td class="num"><?= (float) $p['balance'] > 0 ? '<strong>' . e(fmt_number($p['balance'])) . '</strong>' : '—' ?></td>
                <td><span class="badge badge--<?= $p['active'] ? 'available' : 'immobilized' ?>"><?= e(t($p['active'] ? 'common.active' : 'common.inactive')) ?></span></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</section>
