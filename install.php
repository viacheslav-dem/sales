<?php
/**
 * Установочный/миграционный скрипт.
 *
 *  Первая установка:                /install.php
 *      Пустая БД → создаст все таблицы и пользователя admin.
 *
 *  Безопасная миграция:             /install.php
 *      Если таблицы уже есть и в БД есть данные → выполнит идемпотентные
 *      ALTER TABLE / CREATE TABLE IF NOT EXISTS, не теряя ни одной строки.
 *      Можно перезапускать сколько угодно раз.
 *
 *  Полное пересоздание (опасно!):   /install.php?confirm=1
 *      Удаляет ВСЕ данные и создаёт схему с нуля.
 *
 * После первой установки рекомендуется удалить этот файл.
 */
require_once __DIR__ . '/db.php';

$pdo = db();

// ─────────────────────────────────────────────────────────────
// Хелперы для безопасной миграции
// ─────────────────────────────────────────────────────────────

/** Возвращает true, если в таблице есть колонка с таким именем. */
function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM pragma_table_info(?) WHERE name = ?");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

/** Возвращает true, если такая таблица существует. */
function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Создаёт схему «с нуля». Используется и при первой установке,
 * и при ?confirm=1 (после DROP).
 */
function create_full_schema(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'manager'
);

CREATE TABLE IF NOT EXISTS categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL UNIQUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    is_active   INTEGER NOT NULL DEFAULT 1,
    category_id INTEGER NULL,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS product_prices (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      REAL    NOT NULL DEFAULT 0,
    valid_from TEXT    NOT NULL,
    UNIQUE(product_id, valid_from),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE IF NOT EXISTS sales (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id       INTEGER NOT NULL,
    sale_date        TEXT    NOT NULL,
    quantity         INTEGER NOT NULL DEFAULT 0,
    base_price       REAL    NOT NULL DEFAULT 0,
    unit_price       REAL    NOT NULL DEFAULT 0,
    amount           REAL    NOT NULL DEFAULT 0,
    discount_amount  REAL    NOT NULL DEFAULT 0,
    is_return        INTEGER NOT NULL DEFAULT 0,
    original_sale_id INTEGER NULL REFERENCES sales(id),
    sold_at          TEXT    NULL,
    payment_method   TEXT    NULL,
    note             TEXT    NULL,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE INDEX IF NOT EXISTS idx_sales_date     ON sales(sale_date);
CREATE INDEX IF NOT EXISTS idx_sales_product  ON sales(product_id);
CREATE INDEX IF NOT EXISTS idx_sales_return   ON sales(is_return);
CREATE INDEX IF NOT EXISTS idx_sales_orig     ON sales(original_sale_id);
CREATE INDEX IF NOT EXISTS idx_products_cat   ON products(category_id);
SQL
    );
}

/**
 * Идемпотентная миграция существующей БД до актуальной схемы.
 * Возвращает массив сообщений о выполненных шагах.
 */
function migrate_schema(PDO $pdo): array {
    $messages = [];
    $pdo->beginTransaction();
    try {
        // ── categories: создать, если нет ─────────────────────
        if (!table_exists($pdo, 'categories')) {
            $pdo->exec("
                CREATE TABLE categories (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    name       TEXT NOT NULL UNIQUE,
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )
            ");
            $messages[] = ['ok', 'Создана таблица <code>categories</code>.'];
        }

        // ── products.category_id ──────────────────────────────
        if (!column_exists($pdo, 'products', 'category_id')) {
            $pdo->exec("ALTER TABLE products ADD COLUMN category_id INTEGER NULL");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_products_cat ON products(category_id)");
            $messages[] = ['ok', 'В <code>products</code> добавлена колонка <code>category_id</code>.'];
        }

        // ── sales.is_return + новый UNIQUE ────────────────────
        // SQLite не умеет DROP CONSTRAINT, поэтому пересоздаём таблицу.
        if (!column_exists($pdo, 'sales', 'is_return')) {
            $pdo->exec("ALTER TABLE sales RENAME TO sales_old");
            $pdo->exec("
                CREATE TABLE sales (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    product_id INTEGER NOT NULL,
                    sale_date  TEXT    NOT NULL,
                    quantity   INTEGER NOT NULL DEFAULT 0,
                    base_price REAL    NOT NULL DEFAULT 0,
                    unit_price REAL    NOT NULL DEFAULT 0,
                    amount     REAL    NOT NULL DEFAULT 0,
                    is_return  INTEGER NOT NULL DEFAULT 0,
                    UNIQUE(product_id, sale_date, is_return),
                    FOREIGN KEY (product_id) REFERENCES products(id)
                )
            ");
            $pdo->exec("
                INSERT INTO sales (id, product_id, sale_date, quantity, base_price, unit_price, amount, is_return)
                SELECT id, product_id, sale_date, quantity, base_price, unit_price, amount, 0
                FROM sales_old
            ");
            $pdo->exec("DROP TABLE sales_old");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_date    ON sales(sale_date)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_product ON sales(product_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_return  ON sales(is_return)");
            $messages[] = ['ok', 'Таблица <code>sales</code> расширена полем <code>is_return</code> (поддержка возвратов).'];
        } else {
            // На всякий случай добавим индексы, если их нет
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_return ON sales(is_return)");
        }

        // ── sales.original_sale_id + снятие старого UNIQUE ───
        // Старый UNIQUE(product_id, sale_date, is_return) запрещал больше
        // одного возврата по одному товару в день. Теперь возврат
        // привязывается к конкретной продаже, поэтому уникальность
        // нужна только на «нормальных» продажах: один (товар, день).
        if (!column_exists($pdo, 'sales', 'original_sale_id')) {
            $pdo->exec("ALTER TABLE sales RENAME TO sales_old3");
            $pdo->exec("
                CREATE TABLE sales (
                    id               INTEGER PRIMARY KEY AUTOINCREMENT,
                    product_id       INTEGER NOT NULL,
                    sale_date        TEXT    NOT NULL,
                    quantity         INTEGER NOT NULL DEFAULT 0,
                    base_price       REAL    NOT NULL DEFAULT 0,
                    unit_price       REAL    NOT NULL DEFAULT 0,
                    amount           REAL    NOT NULL DEFAULT 0,
                    is_return        INTEGER NOT NULL DEFAULT 0,
                    original_sale_id INTEGER NULL REFERENCES sales(id),
                    FOREIGN KEY (product_id) REFERENCES products(id)
                )
            ");
            $pdo->exec("
                INSERT INTO sales (id, product_id, sale_date, quantity, base_price, unit_price, amount, is_return, original_sale_id)
                SELECT id, product_id, sale_date, quantity, base_price, unit_price, amount, is_return, NULL
                FROM sales_old3
            ");
            $pdo->exec("DROP TABLE sales_old3");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_date    ON sales(sale_date)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_product ON sales(product_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_return  ON sales(is_return)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_orig    ON sales(original_sale_id)");
            $messages[] = ['ok', 'Таблица <code>sales</code>: добавлена связь возврата с исходной продажей (<code>original_sale_id</code>); снято ограничение «один возврат на товар в день».'];
        }

        // ── sales: время продажи, способ оплаты, явная скидка, заметка ──
        if (!column_exists($pdo, 'sales', 'sold_at')) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN sold_at TEXT NULL");
            $messages[] = ['ok', 'В <code>sales</code> добавлена колонка <code>sold_at</code> (время продажи).'];
        }
        if (!column_exists($pdo, 'sales', 'payment_method')) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN payment_method TEXT NULL");
            $messages[] = ['ok', 'В <code>sales</code> добавлена колонка <code>payment_method</code> (наличные/карта/другое).'];
        }
        if (!column_exists($pdo, 'sales', 'discount_amount')) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN discount_amount REAL NOT NULL DEFAULT 0");
            // Backfill: для существующих обычных продаж сумма скидки = qty*base − amount.
            // Для возвратов хранится та же величина (унаследована от исходной продажи).
            $pdo->exec("UPDATE sales
                        SET discount_amount = ROUND(quantity * base_price - amount, 2)
                        WHERE quantity * base_price - amount > 0");
            $messages[] = ['ok', 'В <code>sales</code> добавлена колонка <code>discount_amount</code> (сумма скидки на строку, заполнена из существующих данных).'];
        }
        if (!column_exists($pdo, 'sales', 'note')) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN note TEXT NULL");
            $messages[] = ['ok', 'В <code>sales</code> добавлена колонка <code>note</code> (заметка к продаже).'];
        }

        // ── sales: снимаем UNIQUE(product_id, sale_date) ─────
        // Старая модель «одна продажа на товар в день» не подходит для розницы:
        // одну и ту же позицию могут пробить дважды по разным ценам
        // (одному со скидкой, другому без). Каждая продажа теперь = отдельная строка.
        $hasUniqIndex = (bool)$pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type='index' AND name='uniq_sales_normal'"
        )->fetchColumn();
        if ($hasUniqIndex) {
            $pdo->exec("DROP INDEX uniq_sales_normal");
            $messages[] = ['ok', 'Снят уникальный индекс <code>uniq_sales_normal</code>: теперь можно вбить несколько продаж одного товара за день по разным ценам.'];
        }

        // ── products.name: UNIQUE-индекс ──────────────────────
        // Серверная гарантия от дублей наименований. Дополняет проверку
        // в коде add/edit (которая страдает от TOCTOU race без индекса).
        // Если в БД уже есть дубли — CREATE UNIQUE INDEX упадёт; в этом
        // случае пропускаем шаг и сообщаем администратору, чтобы он
        // объединил дубли вручную.
        $hasNameUniq = (bool)$pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type='index' AND name='uniq_products_name'"
        )->fetchColumn();
        if (!$hasNameUniq) {
            $dupCount = (int)$pdo->query(
                "SELECT COUNT(*) FROM (SELECT name FROM products GROUP BY name HAVING COUNT(*) > 1)"
            )->fetchColumn();
            if ($dupCount > 0) {
                $messages[] = ['warn',
                    "Не удалось создать уникальный индекс на <code>products.name</code>: найдено {$dupCount} дублирующихся наименований. Объедините дубли вручную и перезапустите миграцию."];
            } else {
                $pdo->exec("CREATE UNIQUE INDEX uniq_products_name ON products(name)");
                $messages[] = ['ok', 'Создан уникальный индекс <code>uniq_products_name</code>: дубли наименований теперь невозможны.'];
            }
        }

        // ── users.role ────────────────────────────────────────
        if (!column_exists($pdo, 'users', 'role')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'manager'");
            // Существующий admin-пользователь получает роль admin
            $pdo->exec("UPDATE users SET role = 'admin' WHERE username = 'admin'");
            $messages[] = ['ok', 'В <code>users</code> добавлена колонка <code>role</code> (admin / manager).'];
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $messages;
}

// ─────────────────────────────────────────────────────────────
// Главная логика
// ─────────────────────────────────────────────────────────────

$tablesExist = (bool)$pdo->query(
    "SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'"
)->fetchColumn();

$rowsExist = false;
if ($tablesExist) {
    foreach (['users', 'products', 'product_prices', 'sales'] as $t) {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        if ($cnt > 0) { $rowsExist = true; break; }
    }
}

$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === '1';

// ─── Сценарий 1: пустая БД → fresh install ─────────────────────
if (!$rowsExist && !$confirmed) {
    create_full_schema($pdo);

    $messages = [['ok', 'Таблицы базы данных созданы (с индексами).']];

    // Создать пользователя admin (если его нет)
    $exists = $pdo->query("SELECT COUNT(*) FROM users WHERE username='admin'")->fetchColumn();
    if (!$exists) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('admin', ?, 'admin')")->execute([$hash]);
        $messages[] = ['ok', 'Пользователь <strong>admin</strong> создан. Пароль: <strong>admin123</strong> — смените после первого входа!'];
    } else {
        $messages[] = ['info', 'Пользователь admin уже существует.'];
    }
    $messages[] = ['warn', '<strong>Удалите этот файл (install.php) после установки!</strong>'];
    $title = 'Установка завершена';
}

// ─── Сценарий 2: есть данные, без confirm → миграция ──────────
elseif ($rowsExist && !$confirmed) {
    try {
        $migrated = migrate_schema($pdo);
        if (empty($migrated)) {
            $messages = [['info', 'База данных уже актуальна — никаких изменений не потребовалось.']];
        } else {
            $messages = $migrated;
            $messages[] = ['ok', 'Миграция выполнена успешно. Все данные сохранены.'];
        }
        $title = 'Миграция базы данных';
    } catch (\Throwable $e) {
        $messages = [['err', 'Ошибка миграции: ' . htmlspecialchars($e->getMessage())]];
        $title = 'Ошибка миграции';
    }

    // Также показать ссылку на опасный режим
    $showDangerLink = true;
}

// ─── Сценарий 3: confirm=1 → полное пересоздание ──────────────
else {
    $pdo->exec(<<<'SQL'
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS sales_old;
DROP TABLE IF EXISTS product_prices;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;
SQL
    );
    create_full_schema($pdo);

    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('admin', ?, 'admin')")->execute([$hash]);

    $messages = [
        ['warn', 'Все данные удалены. Схема создана с нуля.'],
        ['ok',   'Пользователь <strong>admin</strong> создан. Пароль: <strong>admin123</strong>.'],
        ['warn', '<strong>Удалите этот файл (install.php) после установки!</strong>'],
    ];
    $title = 'База пересоздана';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> — Учёт продаж</title>
<style>
    body { font-family: -apple-system, Segoe UI, sans-serif; max-width: 720px; margin: 50px auto; padding: 0 20px; color: #0f172a; line-height: 1.55; }
    h2 { font-size: 1.6rem; letter-spacing: -.02em; margin-bottom: 20px; }
    .msg { padding: 12px 16px; border-radius: 8px; margin: 10px 0; border-left: 3px solid; }
    .msg.ok   { background: #f0fdf4; color: #166534; border-color: #16a34a; }
    .msg.info { background: #f0f9ff; color: #075985; border-color: #0ea5e9; }
    .msg.warn { background: #fffbeb; color: #92400e; border-color: #d97706; }
    .msg.err  { background: #fef2f2; color: #991b1b; border-color: #dc2626; }
    code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: .9em; }
    .actions { margin-top: 28px; display: flex; gap: 12px; flex-wrap: wrap; }
    .btn { display: inline-block; padding: 10px 18px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: .9rem; }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-secondary { background: #e2e8f0; color: #0f172a; }
    .btn-danger { background: #dc2626; color: #fff; }
    .danger-zone { margin-top: 36px; padding-top: 20px; border-top: 1px dashed #cbd5e1; font-size: .85rem; color: #64748b; }
    .danger-zone strong { color: #991b1b; }
</style>
</head>
<body>
<h2><?= htmlspecialchars($title) ?></h2>

<?php foreach ($messages as [$type, $text]): ?>
    <div class="msg <?= $type ?>"><?= $text ?></div>
<?php endforeach; ?>

<div class="actions">
    <a href="login.php" class="btn btn-primary">Перейти к входу →</a>
</div>

<?php if (!empty($showDangerLink)): ?>
<div class="danger-zone">
    <p><strong>Опасная зона:</strong> если нужно полностью пересоздать БД с нуля
        (с потерей всех данных), перейдите по
        <a href="install.php?confirm=1"
           onclick="return confirm('Подтвердите удаление ВСЕХ данных. Это необратимо!')"
           style="color:#dc2626">этой ссылке</a>.</p>
</div>
<?php endif; ?>

</body>
</html>
