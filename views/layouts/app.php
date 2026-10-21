<?php
/** @var string $content @var string $title */

use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Session;
use App\Repositories\SiteRepository;
use App\Security\Auth;

$user = Auth::require();
$path = $_SERVER['REQUEST_URI'] ?? '/';
$current = '/' . trim(substr((string) parse_url($path, PHP_URL_PATH), strlen(rtrim((string) \App\Core\Env::get('APP_BASE_PATH', ''), '/'))), '/');
$nav = require dirname(__DIR__, 2) . '/app/navigation.php';
$sites = (new SiteRepository())->allowed();
$workingSite = Session::get('working_site_id');
// Pages d'un dossier (barre Dossier) : le menu reste sur la rubrique du dossier.
$current = $GLOBALS['elm_nav_active'] ?? $current;
$isActive = fn (string $p) => $p === '/' ? $current === '/' : ($current === $p || str_starts_with($current, $p . '/'));
?>
<!doctype html>
<html lang="<?= e(I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($title ?? '') ?> · ETHAN LM</title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('vendor/datatables/dataTables.dataTables.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/sweetalert2/sweetalert2.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="layout-app">
<a class="skip-link" href="#main"><?= e(t('a11y.skip')) ?></a>
<div class="app">
    <aside class="sidebar" id="sidebar" aria-label="<?= e(t('a11y.sidebar')) ?>">
        <div class="sidebar__brand">
            <span class="brand-mark" aria-hidden="true">E</span>
            <span class="brand-name">ETHAN <strong>LM</strong></span>
            <button type="button" class="sidebar__mini-btn" data-sidebar-mini title="<?= e(t('nav.collapse')) ?>" aria-label="<?= e(t('nav.collapse')) ?>"><?= icon('panel-left-close') ?></button>
        </div>
        <?php if (count($sites) > 1 || $user->role->isGlobal()): ?>
        <form class="site-switch" data-no-ajax method="post" action="<?= e(url('/context/site')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="<?= e($current) ?>">
            <label class="site-switch__label" for="site-switch"><?= icon('map-pin') ?> <span><?= e(t('nav.working_site')) ?></span></label>
            <select id="site-switch" name="site_id" data-autosubmit>
                <option value="0"><?= e(t('nav.all_sites')) ?></option>
                <?php foreach ($sites as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $workingSite === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach ?>
            </select>
        </form>
        <?php endif ?>
        <nav class="nav" aria-label="<?= e(t('a11y.main_nav')) ?>">
            <?php foreach ($nav as $section):
                $entries = array_values(array_filter($section['items'], fn ($i) => $user->can($i['perm'])));
                if (!$entries) { continue; }
                $hasCurrent = (bool) array_filter($entries, fn ($i) => $isActive($i['path']));
                $sectionBadges = 0;
                $inner = '';
                foreach ($entries as $i) {
                    $active = $isActive($i['path']);
                    $badge = isset($i['badge']) ? \App\Services\NavBadges::get($i['badge'], $user) : null;
                    $sectionBadges += $badge['count'] ?? 0;
                    $label = t($i['label']);
                    // Pastille : infobulle = actions les plus urgentes ; clic = détail de la rubrique en modale (le libellé ouvre la liste)
                    $tip = $badge ? $label . ' — ' . $badge['title'] . "\n" . implode("\n", $badge['lines']) : $label;
                    $inner .= '<a class="nav__link' . ($active ? ' is-active' : '') . '" href="' . e(url($i['path'])) . '"' . ($active ? ' aria-current="page"' : '')
                        . ' title="' . e($tip) . '">'
                        . icon($i['icon']) . '<span class="nav__label">' . e($label) . '</span>'
                        . ($badge ? '<span class="nav__badge nav__badge--' . e($badge['level']) . '" role="button" tabindex="0" aria-label="' . e($badge['title']) . '"'
                            . ($i['badge'] !== 'portal' ? ' data-drill="nav" data-drill-param="' . e($i['badge']) . '" data-drill-title="' . e($label . ' — ' . $badge['title']) . '"' : '')
                            . '>' . ($badge['count'] > 99 ? '99+' : (int) $badge['count']) . '</span>' : '')
                        . '</a>';
                }
                $id = $section['id'] ?? md5($section['section']);
            ?>
                <?php if (!empty($section['collapsible'])): ?>
                    <details class="nav__section nav__section--collapsible" data-nav-section="<?= e($id) ?>" <?= $hasCurrent ? 'open data-nav-force-open' : '' ?>>
                        <summary class="nav__heading">
                            <span><?= e(t($section['section'])) ?></span>
                            <?php if ($sectionBadges && !$hasCurrent): ?><span class="nav__dot" aria-hidden="true"></span><?php endif ?>
                            <?= icon('chevron-down', 'nav__chevron') ?>
                        </summary>
                        <div class="nav__items"><?= $inner ?></div>
                    </details>
                <?php else: ?>
                    <div class="nav__section">
                        <p class="nav__heading"><span><?= e(t($section['section'])) ?></span></p>
                        <div class="nav__items"><?= $inner ?></div>
                    </div>
                <?php endif ?>
            <?php endforeach ?>
            <?php if (!$user->isForwarder()): ?>
            <div class="nav__section nav__section--soon">
                <p class="nav__heading"><span><?= e(t('nav.section.coming')) ?></span></p>
                <div class="nav__items">
                    <span class="nav__link is-disabled" title="<?= e(t('order.mode.stock') . ' — ' . t('nav.coming_soon')) ?>"><?= icon('warehouse') ?><span class="nav__label"><?= e(t('order.mode.stock')) ?></span><span class="nav__soon"><?= e(t('nav.soon')) ?></span></span>
                </div>
            </div>
            <?php endif ?>
        </nav>
        <div class="sidebar__footer">
            <span class="sidebar__avatar" aria-hidden="true"><?= e($user->initials()) ?></span>
            <span class="sidebar__user">
                <strong><?= e($user->name) ?></strong>
                <span><?= e(t('role.' . $user->role->value)) ?></span>
            </span>
            <span class="sidebar__version" title="ETHAN LM">v<?= e(\App\Core\Env::get('APP_VERSION', '1.0')) ?></span>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-toggle-sidebar></div>

    <div class="main-col">
        <header class="topbar">
            <button type="button" class="icon-btn topbar__menu" data-toggle-sidebar aria-controls="sidebar" aria-label="<?= e(t('nav.menu')) ?>" title="<?= e(t('nav.menu')) ?>"><?= icon('menu') ?></button>
            <span class="topbar__title"><?= e($title ?? '') ?></span>
            <div class="topbar__right">
                <form method="post" action="<?= e(url("/context/locale")) ?>" class="lang-switch" data-no-ajax>
                    <?= csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e($current) ?>">
                    <?php foreach (I18n::LOCALES as $loc): ?>
                        <button type="submit" name="locale" value="<?= e($loc) ?>" class="lang-switch__btn" aria-pressed="<?= I18n::locale() === $loc ? 'true' : 'false' ?>"><?= e(strtoupper($loc)) ?></button>
                    <?php endforeach ?>
                </form>
                <div class="user-menu">
                    <button type="button" class="avatar" data-menu-toggle aria-haspopup="true" aria-expanded="false" title="<?= e($user->name) ?>"><?= e($user->initials()) ?></button>
                    <div class="menu" data-menu role="menu" hidden>
                        <p class="menu__head"><strong><?= e($user->name) ?></strong><br><span class="muted"><?= e(t('role.' . $user->role->value)) ?></span></p>
                        <form method="post" action="<?= e(url("/logout")) ?>" data-no-ajax>
                            <?= csrf_field() ?>
                            <button type="submit" class="menu__item" role="menuitem"><?= icon('log-out') ?> <?= e(t('auth.logout')) ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main id="main" class="content" tabindex="-1">
            <?php foreach (['success' => 'circle-check', 'error' => 'circle-alert', 'info' => 'info'] as $kind => $ic):
                $msg = Session::getFlash($kind);
                if ($msg): ?>
                <div class="alert alert--<?= e($kind) ?>" role="<?= $kind === 'error' ? 'alert' : 'status' ?>"><?= icon($ic) ?> <span><?= e($msg) ?></span>
                    <?php if ($kind === 'success' && ($next = Session::getFlash('next'))): ?>
                        <a class="btn btn--sm btn--primary alert__next" href="<?= e($next['href']) ?>"><?= e(t('dossier.next_step')) ?> <?= e($next['label']) ?> <?= icon('arrow-right') ?></a>
                    <?php endif ?></div>
            <?php endif; endforeach ?>
            <?= $content ?>
        </main>
    </div>
</div>

<?= json_script('app-config', [
    'base' => url('/'),
    'locale' => I18n::locale(),
    'currency' => base_currency(),
    'i18n' => I18n::catalog(['js.', 'mv.type.', 'dt.', 'items.col.', 'items.state.', 'movements.col.', 'orders.col.', 'orders.vital.',
        'orders.filter.', 'order.status.', 'order.mode.', 'order.mode_short.', 'orders.next.', 'invoice.col.', 'invoice.dir.', 'invoice.status.', 'common.status']),
]) ?>
<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/datatables/dataTables.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/lucide/lucide.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script src="<?= e(asset('js/elm-core.js')) ?>"></script>
<script src="<?= e(asset('js/elm-tables.js')) ?>"></script>
<script src="<?= e(asset('js/elm-export.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
