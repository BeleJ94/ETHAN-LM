<?php
/** @var array $rows */
use App\Core\View;

echo View::partial('partials/page_header', ['icon' => 'history', 'heading' => t('nav.activity'), 'meta' => t('activity.meta')]);
$render = function (?string $json): string {
    if ($json === null) {
        return '';
    }
    $data = json_decode($json, true) ?: [];
    $out = '';
    foreach ($data as $k => $v) {
        $out .= '<span class="diff__k">' . e($k) . '</span> ' . e(is_scalar($v) || $v === null ? (string) $v : json_encode($v)) . '<br>';
    }
    return $out;
};
?>
<section class="card">
    <div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th><?= e(t('activity.col.date')) ?></th>
            <th><?= e(t('activity.col.user')) ?></th>
            <th><?= e(t('activity.col.action')) ?></th>
            <th><?= e(t('activity.col.before')) ?></th>
            <th><?= e(t('activity.col.after')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e(fmt_date($r['created_at'], true)) ?></td>
                <td><?= e($r['user_name'] ?? '—') ?></td>
                <td><strong><?= e($r['entity']) ?><?= $r['entity_id'] ? ' #' . (int) $r['entity_id'] : '' ?></strong><br><span class="muted"><?= e($r['action']) ?></span></td>
                <td class="diff log-action--remove"><?= $render($r['before_json']) ?></td>
                <td class="diff log-action--add"><?= $render($r['after_json']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
</section>
