<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_login();

$pdo     = db();
$msg     = '';
$msgType = 'success';

// ── POST: единый роутер ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add': {
            $name = trim($_POST['name'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') {
                $msg = 'Введите название категории.';
                $msgType = 'error';
                break;
            }
            $exists = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $exists->execute([$name]);
            if ($exists->fetch()) {
                $msg = 'Категория с таким названием уже существует.';
                $msgType = 'error';
                break;
            }
            $pdo->prepare("INSERT INTO categories (name, sort_order) VALUES (?, ?)")->execute([$name, $sort]);
            $msg = 'Категория добавлена.';
            break;
        }

        case 'edit': {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '') {
                $msg = 'Введите название категории.';
                $msgType = 'error';
                break;
            }
            $dup = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
            $dup->execute([$name, $id]);
            if ($dup->fetch()) {
                $msg = 'Другая категория с таким названием уже существует.';
                $msgType = 'error';
                break;
            }
            $pdo->prepare("UPDATE categories SET name = ?, sort_order = ? WHERE id = ?")
                ->execute([$name, $sort, $id]);
            $msg = 'Категория обновлена.';
            break;
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            // Проверка: есть ли товары в этой категории
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
            $cnt->execute([$id]);
            $linked = (int)$cnt->fetchColumn();
            if ($linked > 0) {
                $msg = "Нельзя удалить — в категории {$linked} товар(ов). Сначала перенесите их в другую категорию.";
                $msgType = 'error';
                break;
            }
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
            $msg = 'Категория удалена.';
            break;
        }
    }
}

// ── Загрузка списка ─────────────────────────────────────────
$editId = (int)($_GET['edit'] ?? 0);

$categories = $pdo->query(<<<SQL
    SELECT c.id, c.name, c.sort_order,
        (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS products_count
    FROM categories c
    ORDER BY c.sort_order ASC, c.name ASC
SQL)->fetchAll();

$noCatCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE category_id IS NULL")->fetchColumn();

layout_header('Категории');
?>
<h1 class="page-title">Категории товаров</h1>

<?php if ($msg): ?>
<div id="flash-data"
     data-msg="<?= htmlspecialchars($msg, ENT_QUOTES) ?>"
     data-type="<?= htmlspecialchars($msgType, ENT_QUOTES) ?>"
     hidden></div>
<?php endif; ?>

<!-- Форма добавления -->
<div class="card">
    <div class="card-title">Добавить категорию</div>
    <form method="post" class="form-row m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label for="new-cat-name">Название</label>
        <input type="text" id="new-cat-name" name="name" class="w-input-lg" placeholder="Например: Постельное бельё" required>
        <label for="new-cat-sort">Порядок</label>
        <input type="number" id="new-cat-sort" name="sort_order" value="0" step="1" class="cat-sort-input" title="Категории сортируются по этому полю по возрастанию">
        <button type="submit" class="btn btn-primary"><?= icon('plus', 16) ?>Добавить</button>
    </form>
</div>

<!-- Список -->
<div class="card card-flush">
    <table>
        <thead>
            <tr>
                <th class="col-w-48">#</th>
                <th>Название</th>
                <th class="num col-w-120">Порядок</th>
                <th class="num col-w-140">Товаров</th>
                <th class="col-w-240">Действия</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($categories)): ?>
            <tr><td colspan="5" class="empty-cell">Категории ещё не созданы.</td></tr>
        <?php endif; ?>
        <?php foreach ($categories as $i => $c): ?>
            <?php $isEdit = ($editId === (int)$c['id']); ?>
            <tr class="<?= $isEdit ? 'row-editing' : '' ?>">
                <?php if ($isEdit): ?>
                <td><?= $i + 1 ?></td>
                <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id"     value="<?= $c['id'] ?>">
                <td><input type="text" name="name" class="w-full" value="<?= htmlspecialchars($c['name']) ?>" required></td>
                <td class="num"><input type="number" name="sort_order" value="<?= (int)$c['sort_order'] ?>" step="1" class="cat-sort-input"></td>
                <td class="num"><?= (int)$c['products_count'] ?></td>
                <td class="actions-cell">
                    <button type="submit" class="btn btn-primary btn-sm"><?= icon('check', 14) ?>Сохранить</button>
                    <a href="categories.php" class="btn btn-secondary btn-sm">Отмена</a>
                </td>
                </form>
                <?php else: ?>
                <td><?= $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                <td class="num text-muted"><?= (int)$c['sort_order'] ?></td>
                <td class="num"><?= (int)$c['products_count'] ?></td>
                <td class="actions-cell">
                    <a href="categories.php?edit=<?= $c['id'] ?>" class="btn btn-primary btn-sm"><?= icon('edit', 14) ?>Изменить</a>
                    <form method="post" class="d-inline"
                          data-confirm="Удалить категорию «<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>»?"
                          data-confirm-variant="danger">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm"
                            <?= $c['products_count'] > 0 ? 'disabled title="Сначала перенесите товары в другую категорию"' : '' ?>>
                            <?= icon('x', 14) ?>Удалить
                        </button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if ($noCatCount > 0): ?>
            <tr class="row-editing">
                <td></td>
                <td colspan="2"><em class="text-muted">Без категории</em></td>
                <td class="num"><?= $noCatCount ?></td>
                <td class="text-muted text-sm">служебная запись</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php layout_footer(); ?>
