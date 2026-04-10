<?php
require_once __DIR__ . '/config.php';

/**
 * Возвращает версию статического файла для busting кеша.
 * Меняется только при изменении файла на диске.
 */
function asset_v(string $path): string {
    static $cache = [];
    if (isset($cache[$path])) return $cache[$path];
    $mt = @filemtime(__DIR__ . '/' . $path);
    return $cache[$path] = $mt ? (string)$mt : '1';
}

/**
 * SVG-иконки в стиле Lucide (24x24, stroke-based).
 * Используются в навигации, кнопках, empty-state, модалках.
 *
 * Рендеринг через <use href="#icon-name"> из SVG-спрайта, объявленного в layout_header().
 * Экономит DOM и парсинг при большом количестве иконок.
 */
function icon(string $name, int $size = 18, string $extraClass = ''): string {
    $cls  = 'icon icon-' . $name . ($extraClass ? ' ' . $extraClass : '');
    $safe = htmlspecialchars($name, ENT_QUOTES);
    return "<svg width=\"$size\" height=\"$size\" class=\"$cls\" aria-hidden=\"true\" focusable=\"false\"><use href=\"#icon-$safe\"/></svg>";
}

/**
 * SVG-спрайт со всеми используемыми иконками.
 * Выводится один раз в начале <body>.
 */
function icon_sprite(): string {
    $icons = [
        // Навигация
        'cart'      => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
        'clipboard' => '<rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6"/><path d="M9 16h4"/>',
        'chart'     => '<path d="M3 3v18h18"/><rect x="7" y="13" width="3" height="5"/><rect x="12" y="9" width="3" height="9"/><rect x="17" y="5" width="3" height="13"/>',
        'package'   => '<path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.27 6.96 8.73 5.05 8.73-5.05"/><path d="M12 22.08V12"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'user'      => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',

        // Действия
        'plus'      => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'minus'     => '<line x1="5" y1="12" x2="19" y2="12"/>',
        'x'         => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'check'     => '<polyline points="20 6 9 17 4 12"/>',
        'save'      => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'play'      => '<polygon points="5 3 19 12 5 21 5 3"/>',
        'edit'      => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'shopping-bag' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'home'      => '<path d="M3 9 12 2l9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'history'   => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'tag'       => '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
        'refresh-ccw' => '<polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4-4.64 4.36A9 9 0 0 1 3.51 15"/>',
        'spinner'   => '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
        'arrow-up'  => '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>',
    ];

    $attrs = 'xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true"';
    $defs  = '';
    foreach ($icons as $name => $body) {
        $defs .= "<symbol id=\"icon-$name\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\">$body</symbol>";
    }
    return "<svg $attrs><defs>$defs</defs></svg>";
}

function layout_header(string $title = '', bool $wide = false, ?string $filterKey = null): void {
    require_once __DIR__ . '/auth.php';

    $user      = current_user();
    $pageTitle = $title ? $title . ' — ' . APP_NAME : APP_NAME;
    $cur       = basename($_SERVER['PHP_SELF']);
    $mainCls   = 'container' . ($wide ? ' container--wide' : '');

    // Для поддержки страниц в подпапках (woo/): вычисляем относительный путь к корню приложения
    $_selfDir  = trim(dirname($_SERVER['PHP_SELF']), '/\\');
    $_appBase  = $_selfDir === '' ? '' : str_repeat('../', substr_count($_selfDir, '/') + 1);

    $nav = [
        'dashboard.php' => ['Дашборд',          'home',      false],
        'daily.php'     => ['Продажи за день',  'clipboard', false],
        'report.php'    => ['Отчёт',            'chart',     false],
        'history.php'   => ['История',          'history',   false],
        'products.php'  => ['Товары',           'package',   false],
        'categories.php'=> ['Категории',        'tag',       false],
        'users.php'     => ['Пользователи',     'users',     true],   // только admin
        'import.php'    => ['Импорт',           'upload',    true],   // только admin
    ];

    // WooCommerce — dropdown-подменю; 4-й элемент = adminOnly
    $wooNav = [
        'woo/woo_dashboard.php' => ['Дашборд',   'shopping-bag', false],
        'woo/woo_orders.php'    => ['Заказы',     'cart',         false],
        'woo/woo_report.php'    => ['Отчёт',      'chart',        false],
        'woo/woo_settings.php'  => ['Настройки',  'refresh-ccw',  true],
    ];
    $wooActive = false;
    foreach ($wooNav as $f => $_) {
        if ($cur === basename($f)) { $wooActive = true; break; }
    }
    $userIsAdmin = is_admin();
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="color-scheme" content="light">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
<title><?= htmlspecialchars($pageTitle) ?></title>

<link rel="stylesheet" href="<?= $_appBase ?>style.css?v=<?= asset_v('style.css') ?>">

<!-- Общие фронтенд-утилиты (App.openModal, debounce, escHtml, confirmDialog).
     defer: выполняется после парсинга DOM, но до DOMContentLoaded, и
     ПЕРЕД страничными скриптами (сохраняется порядок документа). -->
<script src="<?= $_appBase ?>assets/app.js?v=<?= asset_v('assets/app.js') ?>" defer></script>
<?php if (in_array($cur, ['dashboard.php', 'report.php', 'woo_dashboard.php', 'woo_report.php'], true)): ?>
<script src="<?= $_appBase ?>assets/chart.umd.min.js?v=<?= asset_v('assets/chart.umd.min.js') ?>" defer></script>
<script src="<?= $_appBase ?>assets/charts.js?v=<?= asset_v('assets/charts.js') ?>" defer></script>
<?php endif; ?>

<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%2322c55e'/%3E%3Cpath d='M5 24 L11 14 L16 19 L27 6' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/%3E%3Ccircle cx='27' cy='6' r='3' fill='white'/%3E%3C/svg%3E">
<?php if ($filterKey !== null): ?>
<meta name="filter-key" content="<?= htmlspecialchars($filterKey, ENT_QUOTES) ?>">
<?php endif; ?>
</head>
<body>
<?= icon_sprite() ?>
<a href="#main-content" class="skip-link">Перейти к содержимому</a>
<nav role="navigation" aria-label="Основная навигация">
<div class="nav-inner">
    <button type="button" class="nav-burger" aria-label="Открыть меню" aria-expanded="false">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <a href="<?= $_appBase ?>dashboard.php" class="brand" aria-label="<?= htmlspecialchars(APP_NAME) ?> — на главную">
        <svg width="32" height="32" viewBox="0 0 32 32" class="brand-logo" aria-hidden="true">
            <rect width="32" height="32" rx="8" fill="#16a34a"/>
            <path d="M7 22 L12 14 L17 18 L25 8" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="25" cy="8" r="2.5" fill="white"/>
        </svg>
        <span class="brand-text"><?= htmlspecialchars(APP_NAME) ?></span>
    </a>
    <div class="nav-links">
    <?php
    $adminInserted = false;
    foreach ($nav as $file => [$label, $iconName, $adminOnly]):
        if ($adminOnly && !$userIsAdmin) continue;
        if ($adminOnly && !$adminInserted):
            $adminInserted = true; ?>
            <span class="nav-divider" aria-hidden="true"></span>
        <?php endif; ?>
    <a href="<?= $_appBase . $file ?>" class="<?= $cur === basename($file) ? 'active' : '' ?>"<?= $cur === basename($file) ? ' aria-current="page"' : '' ?>>
        <?= icon($iconName, 16) ?>
        <span><?= $label ?></span>
    </a>
    <?php endforeach; ?>
    <span class="nav-divider" aria-hidden="true"></span>
    <div class="nav-dropdown<?= $wooActive ? ' nav-dropdown--active' : '' ?>">
        <button type="button" class="nav-dropdown__toggle<?= $wooActive ? ' active' : '' ?>">
            <?= icon('shopping-bag', 16) ?>
            <span>WooCommerce</span>
            <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-left:2px;opacity:.5;"><path d="M2.5 4 5 6.5 7.5 4"/></svg>
        </button>
        <div class="nav-dropdown__menu">
            <?php foreach ($wooNav as $wFile => [$wLabel, $wIcon, $wAdminOnly]):
                if ($wAdminOnly && !$userIsAdmin) continue;
            ?>
            <a href="<?= $_appBase . $wFile ?>" class="<?= $cur === basename($wFile) ? 'active' : '' ?>">
                <?= icon($wIcon, 15) ?> <?= $wLabel ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="spacer"></div>
    <span class="user" title="<?= htmlspecialchars($user['username']) ?>">
        <span class="user-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></span>
        <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
    </span>
    <a href="<?= $_appBase ?>logout.php" class="nav-logout" title="Выйти" aria-label="Выйти из системы">
        <?= icon('logout', 16) ?>
        <span>Выйти</span>
    </a>
    </div>
</div>
</nav>
<main id="main-content" class="<?= $mainCls ?>">
    <?php
}

function layout_footer(): void { ?>
</main>
<button type="button" id="scroll-top-btn" class="scroll-top-btn" aria-label="Наверх" title="Наверх">
    <?= icon('arrow-up', 20) ?>
</button>
<script>
(() => {
    const burger = document.querySelector('.nav-burger');
    const links = document.querySelector('.nav-links');
    if (burger && links) {
        burger.addEventListener('click', () => {
            const open = links.classList.toggle('nav-open');
            burger.setAttribute('aria-expanded', String(open));
        });
        // Закрыть при клике на ссылку
        links.querySelectorAll('a').forEach(a => {
            a.addEventListener('click', () => {
                links.classList.remove('nav-open');
                burger.setAttribute('aria-expanded', 'false');
            });
        });
    }
})();
(() => {
    const btn = document.getElementById('scroll-top-btn');
    if (!btn) return;
    const toggle = () => btn.classList.toggle('is-visible', window.scrollY > 400);
    window.addEventListener('scroll', toggle, { passive: true });
    btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    toggle();
})();
</script>
</body>
</html>
<?php }
