<?php
/**
 * WC Настройки — конфигурация подключения к MySQL WordPress.
 * Только для администратора.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/woo_db.php';
require_once __DIR__ . '/../helpers.php';

require_login();
require_admin();

// Убедиться, что схема woo.db создана
$wooDB = woo_db();
$check = $wooDB->query("SELECT name FROM sqlite_master WHERE type='table' AND name='woo_settings'");
if (!$check->fetchColumn()) {
    require_once __DIR__ . '/woo_install.php';
    exit;
}

$msg     = '';
$msgType = 'success';

// ── Обработка POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'save_settings':
            $fields = [
                'host'   => trim($_POST['wc_host']   ?? 'localhost'),
                'port'   => (int)($_POST['wc_port']   ?? 3306),
                'dbname' => trim($_POST['wc_dbname']  ?? ''),
                'user'   => trim($_POST['wc_user']    ?? ''),
                'pass'   => $_POST['wc_pass']         ?? '',
                'prefix' => trim($_POST['wc_prefix']  ?? 'wp_'),
            ];

            // Статусы для синхронизации
            $statuses = $_POST['wc_statuses'] ?? ['wc-completed', 'wc-processing'];
            if (!is_array($statuses)) $statuses = ['wc-completed', 'wc-processing'];
            $statuses = array_intersect($statuses, [
                'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded', 'wc-cancelled',
            ]);
            if (empty($statuses)) $statuses = ['wc-completed'];

            $wooDB->beginTransaction();
            try {
                $stmt = $wooDB->prepare("INSERT INTO woo_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
                foreach ($fields as $k => $v) {
                    $val = ($k === 'pass') ? base64_encode($v) : (string)$v;
                    $stmt->execute(["wc_mysql_$k", $val]);
                }
                $stmt->execute(['wc_sync_statuses', implode(',', $statuses)]);
                $wooDB->commit();
                $msg     = 'Настройки сохранены.';
            } catch (Throwable $e) {
                $wooDB->rollBack();
                $msg     = 'Ошибка сохранения: ' . $e->getMessage();
                $msgType = 'error';
            }
            break;

        case 'test_connection':
            try {
                $wc = wc_db();
                $prefix = woo_table_prefix();

                // Проверить, что это WordPress
                $st = $wc->prepare("SELECT option_value FROM {$prefix}options WHERE option_name = 'blogname' LIMIT 1");
                $st->execute();
                $blogName = $st->fetchColumn();

                // Проверить WooCommerce
                $st = $wc->prepare("SELECT option_value FROM {$prefix}options WHERE option_name = 'woocommerce_version' LIMIT 1");
                $st->execute();
                $wcVersion = $st->fetchColumn();

                if ($wcVersion) {
                    // Проверить HPOS
                    $st = $wc->prepare("SELECT option_value FROM {$prefix}options WHERE option_name = 'woocommerce_custom_orders_table_enabled' LIMIT 1");
                    $st->execute();
                    $hpos = $st->fetchColumn();
                    $mode = ($hpos === 'yes') ? 'HPOS' : 'Legacy (wp_posts)';

                    // Посчитать заказы
                    if ($hpos === 'yes') {
                        $cnt = $wc->query("SELECT COUNT(*) FROM {$prefix}wc_orders WHERE type='shop_order'")->fetchColumn();
                    } else {
                        $cnt = $wc->query("SELECT COUNT(*) FROM {$prefix}posts WHERE post_type='shop_order'")->fetchColumn();
                    }

                    $msg = "Подключение успешно! Сайт: {$blogName}, WooCommerce {$wcVersion}, режим: {$mode}, заказов: {$cnt}.";
                } else {
                    $msg     = "Подключение к MySQL успешно (сайт: {$blogName}), но WooCommerce не найден.";
                    $msgType = 'error';
                }
            } catch (Throwable $e) {
                $msg     = 'Ошибка подключения: ' . $e->getMessage();
                $msgType = 'error';
            }
            break;
    }
}

// ── Загрузка текущих значений ─────────────────────────────

function _woo_setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        global $wooDB;
        $all = $wooDB->query("SELECT key, value FROM woo_settings")->fetchAll();
        $cache = array_column($all, 'value', 'key');
    }
    $val = $cache[$key] ?? $default;
    return $val;
}

$cfg = [
    'host'   => _woo_setting('wc_mysql_host', 'localhost'),
    'port'   => _woo_setting('wc_mysql_port', '3306'),
    'dbname' => _woo_setting('wc_mysql_dbname', ''),
    'user'   => _woo_setting('wc_mysql_user', ''),
    'pass'   => _woo_setting('wc_mysql_pass', ''),
    'prefix' => _woo_setting('wc_mysql_prefix', 'wp_'),
];
// Декодировать пароль для показа
if ($cfg['pass'] !== '') {
    $cfg['pass'] = base64_decode($cfg['pass']);
}

$syncStatuses = array_filter(explode(',', _woo_setting('wc_sync_statuses', 'wc-completed,wc-processing')));

$allStatuses = [
    'wc-completed'  => 'Выполнен (completed)',
    'wc-processing' => 'В обработке (processing)',
    'wc-on-hold'    => 'На удержании (on-hold)',
    'wc-refunded'   => 'Возвращён (refunded)',
    'wc-cancelled'  => 'Отменён (cancelled)',
];

// ── HTML ───────────────────────────────────────────────────

require_once __DIR__ . '/../layout.php';
layout_header('WC — Настройки');
?>
<h1 class="page-title">WooCommerce — Настройки</h1>

<?php if ($msg): ?>
<div id="flash-data"
     data-msg="<?= htmlspecialchars($msg, ENT_QUOTES) ?>"
     data-type="<?= htmlspecialchars($msgType, ENT_QUOTES) ?>"
     hidden></div>
<?php endif; ?>

<div class="card">
    <div class="card-title">Подключение к MySQL WordPress</div>

    <form method="post" style="max-width:560px;">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="wc-host">Хост MySQL</label>
            <input type="text" id="wc-host" name="wc_host"
                   value="<?= htmlspecialchars($cfg['host']) ?>"
                   placeholder="localhost" style="width:100%;">
        </div>

        <div class="form-group">
            <label for="wc-port">Порт</label>
            <input type="number" id="wc-port" name="wc_port"
                   value="<?= (int)$cfg['port'] ?>"
                   min="1" max="65535" style="width:120px;">
        </div>

        <div class="form-group">
            <label for="wc-dbname">Имя базы данных</label>
            <input type="text" id="wc-dbname" name="wc_dbname"
                   value="<?= htmlspecialchars($cfg['dbname']) ?>"
                   placeholder="wordpress_db" required style="width:100%;">
        </div>

        <div class="form-group">
            <label for="wc-user">Пользователь MySQL</label>
            <input type="text" id="wc-user" name="wc_user"
                   value="<?= htmlspecialchars($cfg['user']) ?>"
                   placeholder="db_user" required style="width:100%;">
        </div>

        <div class="form-group">
            <label for="wc-pass">Пароль MySQL</label>
            <input type="password" id="wc-pass" name="wc_pass"
                   value="<?= htmlspecialchars($cfg['pass']) ?>"
                   style="width:100%;" autocomplete="off">
        </div>

        <div class="form-group">
            <label for="wc-prefix">Префикс таблиц WordPress</label>
            <input type="text" id="wc-prefix" name="wc_prefix"
                   value="<?= htmlspecialchars($cfg['prefix']) ?>"
                   placeholder="wp_" style="width:140px;">
        </div>

        <div class="form-group" style="margin-top:var(--sp-5);">
            <label style="font-weight:600;margin-bottom:var(--sp-2);display:block;">Синхронизировать заказы со статусами:</label>
            <div class="woo-check-list">
                <?php foreach ($allStatuses as $val => $label): ?>
                <label>
                    <input type="checkbox" name="wc_statuses[]" value="<?= $val ?>"
                           <?= in_array($val, $syncStatuses) ? 'checked' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-row" style="margin-top:var(--sp-5);">
            <button type="submit" name="action" value="save_settings" class="btn btn-primary">
                <?= icon('save', 16) ?> Сохранить
            </button>
            <button type="submit" name="action" value="test_connection" class="btn btn-secondary">
                <?= icon('play', 16) ?> Тест подключения
            </button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-title">Информация</div>
    <div class="alert alert-info alert-mb">
        Модуль подключается к MySQL базе данных WordPress <strong>только для чтения</strong>.
        Никакие данные в WooCommerce не изменяются.
    </div>
    <p class="text-muted" style="line-height:1.6;margin:0;">
        Заказы синхронизируются в локальную SQLite-базу <code>data/woo.db</code> и аналитика работает по ней.<br>
        Как альтернативу, вы можете указать креденшелы в файле <code>woo/woo_config.php</code> —
        он имеет приоритет, если настройки здесь не заполнены.
    </p>
</div>

<?php
layout_footer();
