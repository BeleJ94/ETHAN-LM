<?php
/** @var array $users */
use App\Core\View;

$actions = '<a class="btn btn--primary" href="' . e(url('/users/new')) . '">' . icon('user-plus') . ' ' . e(t('users.new')) . '</a>';
echo View::partial('partials/page_header', ['icon' => 'users', 'heading' => t('nav.users'), 'meta' => t('users.count', ['n' => count($users)]), 'actions' => $actions]);
?>
<section class="card">
    <div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th><?= e(t('users.col.name')) ?></th>
            <th><?= e(t('users.col.role')) ?></th>
            <th><?= e(t('users.col.sites')) ?></th>
            <th><?= e(t('users.col.last_login')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th class="actions-col"><span class="sr-only"><?= e(t('common.actions')) ?></span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><strong><?= e($u['name']) ?></strong><br><span class="muted"><?= e($u['email']) ?></span></td>
                <td><?= e(t('role.' . $u['role'])) ?></td>
                <td><?= e($u['role'] === 'admin' ? t('users.all_sites') : ($u['sites'] ?: '—')) ?></td>
                <td><?= e(fmt_date($u['last_login_at'], true)) ?></td>
                <td><span class="badge badge--<?= $u['active'] ? 'available' : 'immobilized' ?>"><?= e(t($u['active'] ? 'common.active' : 'common.inactive')) ?></span></td>
                <td class="actions-col"><a class="icon-btn" href="<?= e(url('/users/' . (int) $u['id'] . '/edit')) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></a></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
</section>
