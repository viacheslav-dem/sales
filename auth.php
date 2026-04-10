<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Сессия живёт 8 часов бездействия (рабочая смена)
    ini_set('session.gc_maxlifetime', 28800);
    // Харднутые cookie-параметры: HttpOnly + SameSite=Strict + Secure если HTTPS
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || ($_SERVER['SERVER_PORT'] ?? null) == 443;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function current_user(): array {
    return [
        'id'       => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? '',
        'role'     => $_SESSION['role']     ?? 'manager',
    ];
}

/** Доступные роли с человеческими названиями. */
function user_roles(): array {
    return [
        'admin'   => 'Администратор',
        'manager' => 'Менеджер',
    ];
}

function is_admin(): bool {
    return (current_user()['role'] ?? 'manager') === 'admin';
}

/** Прерывает запрос с 403, если у пользователя нет прав администратора. */
function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('403 — Доступ запрещён. Требуются права администратора.');
    }
}

function login(string $username, string $password): bool {
    $stmt = db()->prepare("SELECT id, password_hash, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $username;
        $_SESSION['role']     = $user['role'] ?? 'manager';
        session_regenerate_id(true);
        return true;
    }
    return false;
}

function logout(): void {
    $_SESSION = [];
    session_destroy();
}

/* ============================================================
   CSRF: токен сессии + проверка POST-запросов
   ============================================================ */

/** Возвращает CSRF-токен текущей сессии (создаёт при необходимости). */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/** Скрытое поле формы с CSRF-токеном. */
function csrf_field(): string {
    return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Проверка CSRF-токена для POST-запросов.
 * Вызывать в начале каждого POST-обработчика (или глобально в require_login).
 */
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $sent = $_POST['_token'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('CSRF token mismatch. Обновите страницу.');
    }
}
