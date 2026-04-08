<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';
require_login();

$pdo     = db();
$msg     = '';
$msgType = 'success';
$today   = date('Y-m-d');

/** URL для пагинации с сохранением фильтров. */
function pageUrl(int $p, string $q, string $cat = '', string $status = 'active'): string {
    $params = ['page' => $p];
    if ($q !== '')   $params['q']   = $q;
    if ($cat !== '') $params['cat'] = $cat;
    if ($status !== 'active') $params['status'] = $status;
    return 'products.php?' . http_build_query($params);
}

// ── POST: единый роутер ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add': {
            $name       = trim($_POST['name'] ?? '');
            $price      = (float)str_replace(',', '.', $_POST['price'] ?? '0');
            $validFrom  = $_POST['valid_from'] ?? $today;
            $categoryId = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) $validFrom = $today;

            if ($name === '') {
                $msg = 'Введите наименование товара.';
                $msgType = 'error';
                break;
            }
            if ($price < 0) {
                $msg = 'Цена не может быть отрицательной.';
                $msgType = 'error';
                break;
            }

            $exists = $pdo->prepare("SELECT id FROM products WHERE name = ?");
            $exists->execute([$name]);
            if ($exists->fetch()) {
                $msg = 'Товар с таким наименованием уже существует.';
                $msgType = 'error';
                break;
            }

            $pdo->prepare("INSERT INTO products (name, category_id) VALUES (?, ?)")
                ->execute([$name, $categoryId]);
            $pid = (int)$pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO product_prices (product_id, price, valid_from) VALUES (?, ?, ?)"
            )->execute([$pid, $price, $validFrom]);
            $msg = 'Товар «' . $name . '» добавлен.';
            break;
        }

        case 'edit': {
            $id         = (int)($_POST['id'] ?? 0);
            $name       = trim($_POST['name'] ?? '');
            $newPrice   = trim($_POST['price'] ?? '');
            $validFrom  = $_POST['valid_from'] ?? $today;
            $categoryId = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) $validFrom = $today;

            if ($name === '') {
                $msg = 'Введите наименование товара.';
                $msgType = 'error';
                break;
            }

            $dup = $pdo->prepare("SELECT id FROM products WHERE name = ? AND id != ?");
            $dup->execute([$name, $id]);
            if ($dup->fetch()) {
                $msg = 'Другой товар с таким наименованием уже существует.';
                $msgType = 'error';
                break;
            }

            $pdo->prepare("UPDATE products SET name = ?, category_id = ? WHERE id = ?")
                ->execute([$name, $categoryId, $id]);

            if ($newPrice !== '') {
                $priceVal = (float)str_replace(',', '.', $newPrice);
                if ($priceVal >= 0) {
                    $cur = product_price_on($pdo, $id, $today);
                    if (round($priceVal, 2) !== round($cur, 2)) {
                        $pdo->prepare(<<<SQL
                            INSERT INTO product_prices (product_id, price, valid_from)
                            VALUES (?, ?, ?)
                            ON CONFLICT(product_id, valid_from) DO UPDATE SET price = excluded.price
                        SQL)->execute([$id, $priceVal, $validFrom]);
                    }
                }
            }
            $msg = 'Товар «' . $name . '» обновлён.';
            break;
        }

        case 'toggle': {
            $id = (int)($_POST['id'] ?? 0);
            $row = $pdo->prepare("SELECT name, is_active FROM products WHERE id = ?");
            $row->execute([$id]);
            $prod = $row->fetch();
            $pdo->prepare("UPDATE products SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            if ($prod) {
                $msg = 'Товар «' . $prod['name'] . '» '
                    . ((int)$prod['is_active'] === 1 ? 'деактивирован' : 'активирован') . '.';
            } else {
                $msg = 'Статус товара изменён.';
            }
            break;
        }

        case 'bulk_edit': {
            $ids        = $_POST['id']    ?? [];
            $names      = $_POST['name']  ?? [];
            $cats       = $_POST['cat']   ?? [];
            $prices     = $_POST['price'] ?? [];
            $validFrom  = $_POST['valid_from'] ?? $today;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) $validFrom = $today;

            if (!is_array($ids) || empty($ids)) {
                $msg = 'Не выбрано ни одного товара.';
                $msgType = 'error';
                break;
            }

            $updated     = 0;
            $priceUpdated = 0;
            $errors      = [];

            $pdo->beginTransaction();
            try {
                $upStmt   = $pdo->prepare("UPDATE products SET name = ?, category_id = ? WHERE id = ?");
                $insPrice = $pdo->prepare(<<<SQL
                    INSERT INTO product_prices (product_id, price, valid_from)
                    VALUES (?, ?, ?)
                    ON CONFLICT(product_id, valid_from) DO UPDATE SET price = excluded.price
                SQL);

                foreach ($ids as $idx => $rawId) {
                    $id      = (int)$rawId;
                    if ($id <= 0) continue;
                    $name    = trim($names[$idx] ?? '');
                    $catRaw  = $cats[$idx]   ?? '';
                    $catId2  = $catRaw !== '' ? (int)$catRaw : null;
                    $priceRw = trim((string)($prices[$idx] ?? ''));

                    if ($name === '') {
                        $errors[] = "Строка #" . ($idx + 1) . ": пустое имя";
                        continue;
                    }

                    $upStmt->execute([$name, $catId2, $id]);
                    $updated++;

                    if ($priceRw !== '') {
                        $priceVal = (float)str_replace(',', '.', $priceRw);
                        if ($priceVal >= 0) {
                            $cur = product_price_on($pdo, $id, $today);
                            if (round($priceVal, 2) !== round($cur, 2)) {
                                $insPrice->execute([$id, $priceVal, $validFrom]);
                                $priceUpdated++;
                            }
                        }
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $msg = 'Ошибка массового обновления: ' . $e->getMessage();
                $msgType = 'error';
                break;
            }

            $msg = "Обновлено товаров: $updated"
                 . ($priceUpdated > 0 ? ", цен обновлено: $priceUpdated" : '')
                 . (!empty($errors) ? '. Ошибки: ' . implode('; ', $errors) : '.');
            $msgType = !empty($errors) ? 'warning' : 'success';
            break;
        }
    }
}

// ── Список категорий (для select и фильтра) ────────────────
$allCategories = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order, name")->fetchAll();

// ── Поиск, фильтр по категории и пагинация ─────────────────
$search       = trim($_GET['q'] ?? '');
$catFilter    = $_GET['cat'] ?? '';     // '', '0' (без категории), or category id
$statusFilter = $_GET['status'] ?? 'active'; // active | archived | all
if (!in_array($statusFilter, ['active', 'archived', 'all'], true)) $statusFilter = 'active';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "p.name LIKE ?";
    $params[] = '%' . $search . '%';
}
if ($catFilter !== '') {
    if ($catFilter === '0') {
        $where[] = "p.category_id IS NULL";
    } else {
        $where[] = "p.category_id = ?";
        $params[] = (int)$catFilter;
    }
}
if ($statusFilter === 'active') {
    $where[] = "p.is_active = 1";
} elseif ($statusFilter === 'archived') {
    $where[] = "p.is_active = 0";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSql");
$cntStmt->execute($params);
$totalCount = (int)$cntStmt->fetchColumn();

$totalAll = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// Запрос с подзапросами: текущая и следующая цены
$queryParams = array_merge($params, [$today, $today, $today, $today, $perPage, $offset]);
$stmt = $pdo->prepare(<<<SQL
    SELECT p.*,
        c.name AS category_name,
        COALESCE((
            SELECT pp.price FROM product_prices pp
            WHERE pp.product_id = p.id AND pp.valid_from <= ?
            ORDER BY pp.valid_from DESC LIMIT 1
        ), 0) AS price,
        (
            SELECT pp.valid_from FROM product_prices pp
            WHERE pp.product_id = p.id AND pp.valid_from <= ?
            ORDER BY pp.valid_from DESC LIMIT 1
        ) AS price_since,
        (
            SELECT pp.price FROM product_prices pp
            WHERE pp.product_id = p.id AND pp.valid_from > ?
            ORDER BY pp.valid_from ASC LIMIT 1
        ) AS next_price,
        (
            SELECT pp.valid_from FROM product_prices pp
            WHERE pp.product_id = p.id AND pp.valid_from > ?
            ORDER BY pp.valid_from ASC LIMIT 1
        ) AS next_price_from
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    $whereSql
    ORDER BY p.is_active DESC, p.name
    LIMIT ? OFFSET ?
SQL);
$stmt->execute($queryParams);
$products = $stmt->fetchAll();

$formAction = pageUrl($page, $search, $catFilter, $statusFilter);

// Активный фильтр-чип категории
$activeCatChip = null;
if ($catFilter !== '') {
    if ($catFilter === '0') {
        $activeCatChip = ['label' => 'Без категории', 'remove' => 'products.php?' . http_build_query(['q' => $search])];
    } else {
        foreach ($allCategories as $c) {
            if ((string)$c['id'] === $catFilter) {
                $activeCatChip = ['label' => $c['name'], 'remove' => 'products.php?' . http_build_query(['q' => $search])];
                break;
            }
        }
    }
}

layout_header('Товары', true);
?>
<h1 class="page-title">Товары</h1>

<?php if ($msg): ?>
<div id="flash-data"
     data-msg="<?= htmlspecialchars($msg, ENT_QUOTES) ?>"
     data-type="<?= htmlspecialchars($msgType, ENT_QUOTES) ?>"
     hidden></div>
<?php endif; ?>

<!-- Поиск + фильтр + кнопка добавления -->
<div class="card card-pad-sm">
    <form method="get" class="search-bar m-0" data-auto-filter>
        <label for="search-input" class="sr-only">Поиск товара</label>
        <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
               placeholder="Поиск по наименованию…" class="w-input-xl" id="search-input">
        <label for="cat-filter" class="sr-only">Категория</label>
        <select id="cat-filter" name="cat" class="w-input-md">
            <option value="">Все категории</option>
            <option value="0" <?= $catFilter === '0' ? 'selected' : '' ?>>— без категории —</option>
            <?php foreach ($allCategories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (string)$c['id'] === $catFilter ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="status-filter" class="sr-only">Статус</label>
        <select id="status-filter" name="status" class="w-input-md">
            <option value="active"   <?= $statusFilter === 'active'   ? 'selected' : '' ?>>В наличии</option>
            <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Деактивированные</option>
            <option value="all"      <?= $statusFilter === 'all'      ? 'selected' : '' ?>>Все</option>
        </select>
        <?php if ($search !== '' || $catFilter !== '' || $statusFilter !== 'active'): ?>
            <a href="products.php" class="btn btn-secondary"><?= icon('x', 16) ?>Сбросить</a>
        <?php endif; ?>
        <span class="filter-info">
            <?= ($search !== '' || $catFilter !== '')
                ? 'Найдено: ' . $totalCount . ' из ' . $totalAll
                : 'Всего: '   . $totalCount ?>
        </span>
        <button type="button" class="btn btn-primary ms-auto" data-action="open-add-modal">
            <?= icon('plus', 16) ?>Добавить товар
        </button>
    </form>
</div>

<!-- Список товаров -->
<div class="card card-flush">
    <?php if ($activeCatChip): ?>
    <div class="active-filters">
        <span>Фильтр:</span>
        <span class="chip">
            <?= htmlspecialchars($activeCatChip['label']) ?>
            <a href="<?= htmlspecialchars($activeCatChip['remove']) ?>" title="Убрать фильтр" aria-label="Убрать фильтр">×</a>
        </span>
    </div>
    <?php endif; ?>
    <div class="table-scroll">
    <table class="table-cols">
        <thead>
            <tr>
                <th class="col-w-34">
                    <input type="checkbox" id="bulk-master" aria-label="Выбрать все на странице">
                </th>
                <th class="col-w-40">#</th>
                <th>Наименование</th>
                <th class="col-w-170">Категория</th>
                <th class="num col-w-160">Текущая цена (руб.)</th>
                <th class="col-w-100">Действия</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($products)): ?>
            <tr><td colspan="6" class="empty-cell">
                <?= ($search !== '' || $catFilter !== '') ? 'Ничего не найдено.' : 'Товары не добавлены.' ?>
            </td></tr>
        <?php endif; ?>
        <?php foreach ($products as $i => $p): ?>
            <?php
                $rowNum  = $offset + $i + 1;
                $hasNext = !empty($p['next_price_from']);
                $parts   = split_product_name($p['name']);
                $payload = [
                    'id'          => (int)$p['id'],
                    'name'        => $p['name'],
                    'category_id' => $p['category_id'] !== null ? (int)$p['category_id'] : '',
                    'price'       => (float)$p['price'],
                    'price_since' => $p['price_since'] ?? '',
                ];
            ?>
            <tr class="<?= $p['is_active'] ? '' : 'row-inactive' ?>">
                <td>
                    <input type="checkbox" class="bulk-check"
                           data-product="<?= htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>"
                           value="<?= (int)$p['id'] ?>" aria-label="Выбрать товар">
                </td>
                <td><?= $rowNum ?></td>
                <td>
                    <span class="product-name">
                        <span class="product-name__main"><?= htmlspecialchars($parts['main']) ?></span>
                        <?php if ($parts['meta'] !== ''): ?>
                            <span class="product-name__meta"><?= htmlspecialchars($parts['meta']) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if (!$p['is_active']): ?>
                        <span class="badge badge-muted badge-inline">архив</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($p['category_name']): ?>
                        <span class="badge badge-primary"><?= htmlspecialchars($p['category_name']) ?></span>
                    <?php else: ?>
                        <span class="text-faint text-sm">—</span>
                    <?php endif; ?>
                </td>
                <td class="num">
                    <div class="fw-700"><?= format_money((float)$p['price']) ?></div>
                    <div class="text-faint text-xs">
                        с <?= $p['price_since'] ? date('d.m.Y', strtotime($p['price_since'])) : '—' ?>
                    </div>
                    <?php if ($hasNext): ?>
                        <div class="next-price-hint" title="Следующая цена">
                            ↗ <?= format_money((float)$p['next_price']) ?>
                            с <?= date('d.m.Y', strtotime($p['next_price_from'])) ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="actions-cell">
                    <button type="button" class="btn btn-secondary btn-sm btn-icon"
                            title="Изменить" aria-label="Изменить"
                            data-action="edit-product"
                            data-product="<?= htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>">
                        <?= icon('edit', 14) ?>
                    </button>
                    <form method="post" action="<?= $formAction ?>" class="d-inline"
                          data-confirm="<?= $p['is_active'] ? 'Деактивировать товар?' : 'Активировать товар?' ?>"
                          data-confirm-variant="<?= $p['is_active'] ? 'danger' : 'primary' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id"     value="<?= $p['id'] ?>">
                        <button type="submit"
                                class="btn btn-sm btn-icon <?= $p['is_active'] ? 'btn-danger' : 'btn-secondary' ?>"
                                title="<?= $p['is_active'] ? 'Деактивировать' : 'Активировать' ?>"
                                aria-label="<?= $p['is_active'] ? 'Деактивировать' : 'Активировать' ?>">
                            <?= $p['is_active'] ? icon('x', 14) : icon('check', 14) ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php render_pagination(
        $page, $totalPages, $totalCount, $perPage, $offset,
        fn(int $p) => pageUrl($p, $search, $catFilter, $statusFilter)
    ); ?>
</div>

<!-- ═══════════════════════════════════════════════════
     МОДАЛКА — ДОБАВИТЬ ТОВАР
     ═══════════════════════════════════════════════════ -->
<div id="add-modal-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="add-modal-title">
    <div class="modal-box modal-box--form">
        <div class="modal-header">
            <h2 class="modal-title" id="add-modal-title">Добавить товар</h2>
            <button type="button" class="modal-close-btn" data-action="close-add-modal" aria-label="Закрыть (Esc)" title="Закрыть (Esc)">
                <?= icon('x', 16) ?>
            </button>
        </div>
        <form method="post" action="<?= $formAction ?>" class="modal-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label for="add-name">Наименование</label>
                <textarea id="add-name" name="name" class="name-textarea" required autocomplete="off" placeholder="Название товара"></textarea>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="add-cat">Категория</label>
                    <select id="add-cat" name="category_id">
                        <option value="">— без категории —</option>
                        <?php foreach ($allCategories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="add-price">Цена (руб.)</label>
                    <input type="number" id="add-price" name="price" step="0.01" min="0" placeholder="0.00" required>
                </div>
            </div>
            <div class="modal-form-footer">
                <button type="button" class="btn btn-secondary" data-action="close-add-modal">Отмена</button>
                <button type="submit" class="btn btn-primary"><?= icon('plus', 16) ?>Добавить</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     МОДАЛЬНОЕ ОКНО — РЕДАКТИРОВАНИЕ ТОВАРА
     ═══════════════════════════════════════════════════ -->
<div id="edit-modal-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
    <div class="modal-box modal-box--form">
        <div class="modal-header">
            <h2 class="modal-title" id="edit-modal-title">Изменить товар</h2>
            <button type="button" class="modal-close-btn" data-action="close-edit-modal" aria-label="Закрыть (Esc)" title="Закрыть (Esc)">
                <?= icon('x', 16) ?>
            </button>
        </div>

        <form method="post" action="<?= $formAction ?>" class="modal-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit-id">

            <div class="form-group">
                <label for="edit-name">Наименование</label>
                <textarea id="edit-name" name="name" class="name-textarea" required autocomplete="off"></textarea>
            </div>

            <div class="form-group">
                <label for="edit-category">Категория</label>
                <select id="edit-category" name="category_id">
                    <option value="">— без категории —</option>
                    <?php foreach ($allCategories as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="edit-price">Новая цена (руб.)</label>
                    <input type="number" id="edit-price" name="price" step="0.01" min="0">
                    <span class="form-hint">
                        Оставьте пустым, чтобы не менять. Текущая: <strong id="edit-current-price">—</strong>&nbsp;руб.
                    </span>
                </div>
                <div class="form-group">
                    <label for="edit-valid-from">Действует с</label>
                    <input type="date" id="edit-valid-from" name="valid_from" value="<?= $today ?>">
                    <span class="form-hint">Только если меняете цену</span>
                </div>
            </div>

            <div class="modal-form-footer">
                <button type="button" class="btn btn-secondary" data-action="close-edit-modal">Отмена</button>
                <button type="submit" class="btn btn-primary"><?= icon('check', 16) ?>Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     ПЛАВАЮЩАЯ ACTION-BAR (массовый выбор)
     ═══════════════════════════════════════════════════ -->
<div id="bulk-action-bar" class="bulk-action-bar" hidden>
    <div class="bulk-action-bar__count">
        Выбрано: <strong id="bulk-count">0</strong>
    </div>
    <div class="bulk-action-bar__actions">
        <button type="button" class="btn btn-secondary btn-sm" data-action="bulk-clear">
            <?= icon('x', 14) ?>Снять выделение
        </button>
        <button type="button" class="btn btn-primary btn-sm" data-action="bulk-edit-open">
            <?= icon('edit', 14) ?>Изменить выбранные
        </button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     МОДАЛКА — МАССОВОЕ РЕДАКТИРОВАНИЕ
     ═══════════════════════════════════════════════════ -->
<div id="bulk-modal-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="bulk-modal-title">
    <div class="modal-box modal-box--bulk">
        <div class="modal-header">
            <h2 class="modal-title" id="bulk-modal-title">Массовое редактирование</h2>
            <button type="button" class="modal-close-btn" data-action="bulk-modal-close" aria-label="Закрыть (Esc)" title="Закрыть (Esc)">
                <?= icon('x', 16) ?>
            </button>
        </div>
        <form method="post" action="<?= $formAction ?>" class="bulk-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_edit">

            <div class="bulk-form__top">
                <div class="form-group">
                    <label for="bulk-valid-from">Дата действия новой цены</label>
                    <input type="date" id="bulk-valid-from" name="valid_from" value="<?= $today ?>">
                    <span class="form-hint">Применяется только к товарам, у которых указана новая цена</span>
                </div>
            </div>

            <div class="bulk-table-wrap">
                <table class="bulk-table table-cols">
                    <thead>
                        <tr>
                            <th class="col-w-34">#</th>
                            <th>Наименование</th>
                            <th class="col-w-200">Категория</th>
                            <th class="num col-w-130">Новая цена</th>
                            <th class="col-w-34"></th>
                        </tr>
                    </thead>
                    <tbody id="bulk-rows"></tbody>
                </table>
            </div>

            <div class="modal-form-footer">
                <button type="button" class="btn btn-secondary" data-action="bulk-modal-close">Отмена</button>
                <button type="submit" class="btn btn-primary"><?= icon('check', 16) ?>Сохранить изменения</button>
            </div>
        </form>
    </div>
</div>

<!-- Шаблон строки массового редактирования -->
<template id="bulk-row-template">
    <tr>
        <td class="bulk-row-num"></td>
        <td>
            <input type="hidden" data-name="id">
            <textarea data-name="name" class="name-textarea" required></textarea>
        </td>
        <td>
            <select data-name="cat">
                <option value="">— без категории —</option>
                <?php foreach ($allCategories as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" data-name="price" step="0.01" min="0" placeholder="не менять">
            <div class="bulk-row-current text-faint text-xs"></div>
        </td>
        <td>
            <button type="button" class="btn btn-ghost btn-sm btn-icon" data-action="bulk-row-remove" title="Убрать из выборки">
                <?= icon('x', 14) ?>
            </button>
        </td>
    </tr>
</template>

<script src="assets/products.js?v=<?= asset_v('assets/products.js') ?>" defer></script>

<?php layout_footer(); ?>
