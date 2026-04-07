<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_login();

$pdo = db();
$me  = current_user();
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

// Добавление пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '') {
        $msg = 'Введите логин.';
        $msgType = 'error';
    } elseif (mb_strlen($password) < 6) {
        $msg = 'Пароль должен быть не менее 6 символов.';
        $msgType = 'error';
    } else {
        try {
            $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)")
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $msg = "Пользователь «{$username}» добавлен.";
        } catch (PDOException $e) {
            $msg = 'Пользователь с таким логином уже существует.';
            $msgType = 'error';
        }
    }
}

// Смена пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'passwd') {
    $uid      = (int) ($_POST['id'] ?? 0);
    $password = $_POST['password'] ?? '';
    if (mb_strlen($password) < 6) {
        $msg = 'Пароль должен быть не менее 6 символов.';
        $msgType = 'error';
    } else {
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
        $msg = 'Пароль изменён.';
    }
}

// Удаление пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $uid = (int) ($_POST['id'] ?? 0);
    if ($uid === (int)$me['id']) {
        $msg = 'Нельзя удалить самого себя.';
        $msgType = 'error';
    } else {
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        $msg = 'Пользователь удалён.';
    }
}

$users   = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll();
$editPwd = (int)($_GET['passwd'] ?? 0);

layout_header('Пользователи');
?>
<h1 class="page-title">Пользователи</h1>

<?php if ($msg): ?>
<div id="flash-data"
     data-msg="<?= htmlspecialchars($msg, ENT_QUOTES) ?>"
     data-type="<?= htmlspecialchars($msgType, ENT_QUOTES) ?>"
     hidden></div>
<?php endif; ?>

<!-- Добавление -->
<div class="card">
    <div class="card-title">Добавить пользователя</div>
    <form method="post" class="form-row m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label for="new-user">Логин</label>
        <input type="text" id="new-user" name="username" class="w-input-md" placeholder="Логин" required autocomplete="off">
        <label for="new-pass">Пароль</label>
        <input type="password" id="new-pass" name="password" class="w-input-md" placeholder="Минимум 6 символов" required autocomplete="new-password">
        <button type="submit" class="btn btn-success"><?= icon('plus', 16) ?>Добавить</button>
    </form>
</div>

<!-- Список -->
<div class="card card-flush">
    <table>
        <thead>
            <tr>
                <th style="width:48px">#</th>
                <th>Логин</th>
                <th style="width:280px">Действия</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $i => $u): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td>
                    <strong><?= htmlspecialchars($u['username']) ?></strong>
                    <?php if ((int)$u['id'] === (int)$me['id']): ?>
                        <span class="badge badge-primary badge-inline">это вы</span>
                    <?php endif; ?>
                </td>
                <td class="actions-cell">
                    <a href="users.php?passwd=<?= $u['id'] ?>" class="btn btn-primary btn-sm"><?= icon('edit', 14) ?>Сменить пароль</a>
                    <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                    <form method="post" class="d-inline"
                          data-confirm="Удалить пользователя «<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>»?"
                          data-confirm-variant="danger">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <?= icon('x', 14) ?>Удалить
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($editPwd === (int)$u['id']): ?>
            <tr class="row-editing">
                <td colspan="3">
                    <form method="post" class="form-row m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="passwd">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <label for="pw-<?= $u['id'] ?>">Новый пароль для «<?= htmlspecialchars($u['username']) ?>»:</label>
                        <input type="password" id="pw-<?= $u['id'] ?>" name="password" class="w-input-md" placeholder="Минимум 6 символов" required autocomplete="new-password">
                        <button type="submit" class="btn btn-primary btn-sm"><?= icon('check', 14) ?>Сохранить</button>
                        <a href="users.php" class="btn btn-secondary btn-sm">Отмена</a>
                    </form>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php layout_footer(); ?>
