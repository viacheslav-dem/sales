<?php
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

// CSRF: на странице логина сессия уже стартована (auth.php), токен доступен
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF-токена
    $sentToken = $_POST['_token'] ?? '';
    if (!is_string($sentToken) || !hash_equals(csrf_token(), $sentToken)) {
        $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } else {
        // Защита от брутфорса: максимум 5 попыток, затем задержка
        $maxAttempts = 5;
        $lockoutSeconds = 60;
        $attempts = $_SESSION['login_attempts'] ?? 0;
        $lastAttempt = $_SESSION['login_last_attempt'] ?? 0;

        // Сброс счётчика после периода блокировки
        if ($attempts >= $maxAttempts && (time() - $lastAttempt) >= $lockoutSeconds) {
            $attempts = 0;
            $_SESSION['login_attempts'] = 0;
        }

        if ($attempts >= $maxAttempts) {
            $remaining = $lockoutSeconds - (time() - $lastAttempt);
            $error = "Слишком много попыток. Подождите {$remaining} сек.";
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            if (login($username, $password)) {
                $_SESSION['login_attempts'] = 0;
                header('Location: dashboard.php');
                exit;
            }
            $_SESSION['login_attempts'] = $attempts + 1;
            $_SESSION['login_last_attempt'] = time();
            $error = 'Неверный логин или пароль.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>Вход — <?= htmlspecialchars(APP_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%233b82f6' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z'/><line x1='3' y1='6' x2='21' y2='6'/><path d='M16 10a4 4 0 0 1-8 0'/></svg>">
<style>
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 400 700;
    font-display: swap;
    src: url('assets/fonts/inter-cyrillic-ext.woff2') format('woff2');
    unicode-range: U+0460-052F, U+1C80-1C8A, U+20B4, U+2DE0-2DFF, U+A640-A69F, U+FE2E-FE2F;
}
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 400 700;
    font-display: swap;
    src: url('assets/fonts/inter-cyrillic.woff2') format('woff2');
    unicode-range: U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116;
}
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 400 700;
    font-display: swap;
    src: url('assets/fonts/inter-latin.woff2') format('woff2');
    unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}

:root {
    --primary:    #2563eb;
    --primary-h:  #1d4ed8;
    --primary-50: #eff6ff;
    --border:     #e2e8f0;
    --border-h:   #cbd5e1;
    --bg:         #f8fafc;
    --bg-2:       #f1f5f9;
    --text:       #0f172a;
    --text-2:     #475569;
    --muted:      #64748b;
    --muted-2:    #94a3b8;
    --danger:     #dc2626;
    --danger-bg:  #fef2f2;
    --danger-bd:  #fecaca;
    --radius:     8px;
    --radius-lg:  16px;
    --font:       'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: var(--font);
    background:
        radial-gradient(1200px circle at 10% -10%, rgba(59,130,246,.12), transparent 50%),
        radial-gradient(900px circle at 110% 110%, rgba(99,102,241,.10), transparent 55%),
        var(--bg);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    color: var(--text);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    font-feature-settings: 'cv11', 'ss01';
}

.login-wrap {
    width: 100%;
    max-width: 400px;
}

.login-brand {
    text-align: center;
    margin-bottom: 32px;
}
.login-brand .logo {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-h) 100%);
    color: #fff;
    box-shadow: 0 10px 30px rgba(37, 99, 235, .35), 0 0 0 1px rgba(37,99,235,.1);
    margin-bottom: 16px;
}
.login-brand h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -.025em;
    line-height: 1.2;
}
.login-brand p {
    font-size: .875rem;
    color: var(--muted);
    margin-top: 6px;
}

.card {
    background: #fff;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    box-shadow:
        0 1px 2px rgba(15, 23, 42, .04),
        0 12px 32px rgba(15, 23, 42, .08),
        0 30px 60px rgba(15, 23, 42, .04);
    padding: 36px;
}

.form-group { margin-bottom: 18px; }
.form-group label {
    display: block;
    font-size: .8125rem;
    font-weight: 600;
    color: var(--text-2);
    margin-bottom: 7px;
    letter-spacing: .01em;
}

.input-wrap {
    position: relative;
}
.input-wrap svg {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    width: 18px;
    height: 18px;
    color: var(--muted-2);
    pointer-events: none;
    transition: color .15s ease;
}
.input-wrap input:focus + svg,
.input-wrap input:hover + svg { color: var(--primary); }

input[type=text], input[type=password] {
    width: 100%;
    padding: 11px 40px 11px 42px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    font-size: .9375rem;
    font-family: var(--font);
    color: var(--text);
    background: #fff;
    transition: border-color .15s ease, box-shadow .15s ease;
}

/* Кнопка «показать/скрыть пароль» */
.pwd-toggle {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 28px;
    height: 28px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: transparent;
    color: var(--muted-2);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color .15s ease, background .15s ease;
}
.pwd-toggle:hover { color: var(--primary); background: rgba(37,99,235,.08); }
.pwd-toggle:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.pwd-toggle svg { width: 18px; height: 18px; pointer-events: none; }
.pwd-toggle__hide { display: none; }
.pwd-toggle.is-visible .pwd-toggle__show { display: none; }
.pwd-toggle.is-visible .pwd-toggle__hide { display: block; }
input[type=text]::placeholder, input[type=password]::placeholder { color: var(--muted-2); }
input:hover { border-color: var(--border-h); }
input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37,99,235,.18);
}

.btn-login {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    margin-top: 28px;
    padding: 12px;
    background: linear-gradient(180deg, var(--primary) 0%, var(--primary-h) 100%);
    color: #fff;
    border: 1px solid var(--primary-h);
    border-radius: var(--radius);
    font-size: .9375rem;
    font-family: var(--font);
    font-weight: 600;
    cursor: pointer;
    transition: transform .12s ease, box-shadow .15s ease, filter .15s ease;
    letter-spacing: .005em;
    box-shadow: 0 1px 2px rgba(37,99,235,.2), 0 4px 12px rgba(37,99,235,.18);
}
.btn-login:hover {
    filter: brightness(1.05);
    box-shadow: 0 6px 20px rgba(37,99,235,.3), 0 2px 4px rgba(37,99,235,.15);
}
.btn-login:active { transform: translateY(1px); }
.btn-login:focus-visible {
    outline: 2px solid #fff;
    outline-offset: 2px;
    box-shadow: 0 0 0 4px rgba(37,99,235,.45);
}

.btn-login svg { width: 16px; height: 16px; }

.error-msg {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--danger-bg);
    color: var(--danger);
    border: 1px solid var(--danger-bd);
    border-left: 3px solid var(--danger);
    border-radius: var(--radius);
    padding: 11px 14px;
    font-size: .8125rem;
    font-weight: 500;
    margin-top: 18px;
}
.error-msg svg { width: 16px; height: 16px; flex-shrink: 0; }

.login-footer {
    text-align: center;
    margin-top: 24px;
    font-size: .75rem;
    color: var(--muted-2);
    letter-spacing: .01em;
}
</style>
</head>
<body>
<main class="login-wrap" role="main">
    <div class="login-brand">
        <div class="logo" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
        </div>
        <h1><?= htmlspecialchars(APP_NAME) ?></h1>
        <p>Система учёта розничных продаж</p>
    </div>

    <div class="card">
        <form method="post" novalidate aria-label="Форма входа">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="username">Логин</label>
                <div class="input-wrap">
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           required autofocus autocomplete="username"
                           placeholder="Введите логин">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                </div>
            </div>
            <div class="form-group">
                <label for="password">Пароль</label>
                <div class="input-wrap">
                    <input type="password" id="password" name="password"
                           required autocomplete="current-password"
                           placeholder="••••••••">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    <button type="button" class="pwd-toggle" aria-label="Показать пароль" title="Показать пароль">
                        <svg class="pwd-toggle__show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                        <svg class="pwd-toggle__hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-login">
                Войти
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                </svg>
            </button>
            <?php if ($error): ?>
            <div class="error-msg" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
        </form>
    </div>
    <div class="login-footer">© <?= date('Y') ?> · <?= htmlspecialchars(APP_NAME) ?></div>
</main>
<script>
document.querySelector('.pwd-toggle')?.addEventListener('click', function () {
    const input = document.getElementById('password');
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    this.classList.toggle('is-visible', !visible);
    this.setAttribute('aria-label', visible ? 'Показать пароль' : 'Скрыть пароль');
    this.title = visible ? 'Показать пароль' : 'Скрыть пароль';
    input.focus();
});
</script>
</body>
</html>
