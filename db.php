<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Регистрируем функцию mb_lower для регистро-независимого поиска
        // по кириллице. SQLite-овский LOWER работает только для ASCII;
        // встроенный LIKE без расширения ICU тоже игнорирует кириллицу.
        // Использование: WHERE mb_lower(p.name) LIKE mb_lower(:q)
        $pdo->sqliteCreateFunction('mb_lower', static function ($s) {
            return $s === null ? null : mb_strtolower((string)$s, 'UTF-8');
        }, 1);
    }
    return $pdo;
}
