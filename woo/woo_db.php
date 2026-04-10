<?php
/**
 * PDO-подключения для WooCommerce-модуля.
 *
 * woo_db()  — SQLite data/woo.db (локальная аналитическая копия)
 * wc_db()   — MySQL WordPress (только чтение, источник данных)
 */

require_once __DIR__ . '/../config.php';

define('WOO_DB_PATH', __DIR__ . '/../data/woo.db');

/**
 * SQLite PDO-синглтон для локальных данных WooCommerce-модуля.
 */
function woo_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = dirname(WOO_DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $pdo = new PDO('sqlite:' . WOO_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    return $pdo;
}

/**
 * MySQL PDO-подключение к WordPress (только SELECT).
 */
function wc_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = woo_mysql_config();
    if (empty($cfg['dbname'])) {
        throw new RuntimeException('WooCommerce MySQL: не задано имя базы данных. Настройте подключение в «WC Настройки».');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'],
        (int)$cfg['port'],
        $cfg['dbname']
    );
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * Получить конфигурацию MySQL.
 * Приоритет: woo_settings (SQLite) → woo_config.php (файл).
 */
function woo_mysql_config(): array
{
    $defaults = [
        'host' => 'localhost', 'port' => 3306,
        'dbname' => '', 'user' => '', 'pass' => '', 'prefix' => 'wp_',
    ];

    // Попробовать из SQLite settings
    try {
        $db = woo_db();
        $check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='woo_settings'");
        if ($check->fetchColumn()) {
            $cfg = [];
            foreach (array_keys($defaults) as $k) {
                $stmt = $db->prepare("SELECT value FROM woo_settings WHERE key = ?");
                $stmt->execute(["wc_mysql_$k"]);
                $val = $stmt->fetchColumn();
                if ($k === 'pass' && $val !== false && $val !== '') {
                    $val = base64_decode($val);
                }
                $cfg[$k] = ($val !== false && $val !== '') ? $val : $defaults[$k];
            }
            if (!empty($cfg['dbname'])) return $cfg;
        }
    } catch (Throwable $e) {
        // SQLite ещё не инициализирован — пробуем файл
    }

    // Fallback на файл
    $file = __DIR__ . '/woo_config.php';
    if (file_exists($file)) {
        $fileCfg = require $file;
        if (is_array($fileCfg)) {
            return array_merge($defaults, $fileCfg);
        }
    }

    return $defaults;
}

/**
 * WordPress table prefix (по умолчанию 'wp_').
 */
function woo_table_prefix(): string
{
    $cfg = woo_mysql_config();
    return $cfg['prefix'] ?: 'wp_';
}
