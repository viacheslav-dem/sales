<?php
/**
 * Создание / миграция схемы data/woo.db.
 * Доступ только для администратора.
 * Идемпотентно — безопасно вызывать повторно.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/woo_db.php';

require_login();
require_admin();

// ── Хелперы схемы ─────────────────────────────────────────

function woo_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function woo_column_exists(PDO $pdo, string $table, string $column): bool
{
    $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll();
    foreach ($rows as $r) {
        if ($r['name'] === $column) return true;
    }
    return false;
}

// ── Полная схема ───────────────────────────────────────────

function woo_create_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS woo_orders (
            id                 INTEGER PRIMARY KEY,
            wc_status          TEXT    NOT NULL,
            order_date         TEXT    NOT NULL,
            order_datetime     TEXT    NOT NULL,
            customer_id        INTEGER NOT NULL DEFAULT 0,
            customer_name      TEXT    NOT NULL DEFAULT '',
            customer_email     TEXT    NOT NULL DEFAULT '',
            billing_city       TEXT    NOT NULL DEFAULT '',
            payment_method     TEXT    NOT NULL DEFAULT '',
            payment_title      TEXT    NOT NULL DEFAULT '',
            currency           TEXT    NOT NULL DEFAULT 'BYN',
            total              REAL    NOT NULL DEFAULT 0,
            discount_total     REAL    NOT NULL DEFAULT 0,
            shipping_total     REAL    NOT NULL DEFAULT 0,
            tax_total          REAL    NOT NULL DEFAULT 0,
            items_count        INTEGER NOT NULL DEFAULT 0,
            source_type        TEXT    NOT NULL DEFAULT '',
            source             TEXT    NOT NULL DEFAULT '',
            synced_at          TEXT    NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_woo_orders_date   ON woo_orders(order_date);
        CREATE INDEX IF NOT EXISTS idx_woo_orders_status ON woo_orders(wc_status);
        CREATE INDEX IF NOT EXISTS idx_woo_orders_cust   ON woo_orders(customer_id);

        CREATE TABLE IF NOT EXISTS woo_order_items (
            id             INTEGER PRIMARY KEY,
            order_id       INTEGER NOT NULL REFERENCES woo_orders(id) ON DELETE CASCADE,
            product_id     INTEGER NOT NULL DEFAULT 0,
            variation_id   INTEGER NOT NULL DEFAULT 0,
            product_name   TEXT    NOT NULL DEFAULT '',
            sku            TEXT    NOT NULL DEFAULT '',
            quantity       INTEGER NOT NULL DEFAULT 0,
            unit_price     REAL    NOT NULL DEFAULT 0,
            line_total     REAL    NOT NULL DEFAULT 0,
            line_subtotal  REAL    NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_woo_items_order   ON woo_order_items(order_id);
        CREATE INDEX IF NOT EXISTS idx_woo_items_product ON woo_order_items(product_id);

        CREATE TABLE IF NOT EXISTS woo_products (
            id          INTEGER PRIMARY KEY,
            name        TEXT    NOT NULL DEFAULT '',
            sku         TEXT    NOT NULL DEFAULT '',
            category    TEXT    NOT NULL DEFAULT '',
            price       REAL    NOT NULL DEFAULT 0,
            stock_qty   INTEGER NULL,
            synced_at   TEXT    NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS woo_sync_log (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            started_at   TEXT    NOT NULL,
            ended_at     TEXT    NULL,
            sync_type    TEXT    NOT NULL DEFAULT 'incremental',
            orders_new   INTEGER NOT NULL DEFAULT 0,
            orders_upd   INTEGER NOT NULL DEFAULT 0,
            items_synced INTEGER NOT NULL DEFAULT 0,
            status       TEXT    NOT NULL DEFAULT 'running',
            error_msg    TEXT    NULL
        );

        CREATE TABLE IF NOT EXISTS woo_settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        );
    ");
}

// ── Миграции ───────────────────────────────────────────────

function woo_migrate_schema(PDO $pdo): array
{
    $messages = [];

    $pdo->beginTransaction();
    try {
        // Основные таблицы
        if (!woo_table_exists($pdo, 'woo_orders')) {
            woo_create_schema($pdo);
            $messages[] = 'Схема создана с нуля.';
            $pdo->commit();
            return $messages;
        }

        if (!woo_column_exists($pdo, 'woo_orders', 'source_type')) {
            $pdo->exec("ALTER TABLE woo_orders ADD COLUMN source_type TEXT NOT NULL DEFAULT ''");
            $pdo->exec("ALTER TABLE woo_orders ADD COLUMN source TEXT NOT NULL DEFAULT ''");
            $messages[] = 'Добавлены столбцы source_type, source.';
        }

        if (empty($messages)) {
            $messages[] = 'Схема актуальна, миграция не требуется.';
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $messages;
}

// ── Выполнение ─────────────────────────────────────────────

$pdo = woo_db();

try {
    $results = woo_migrate_schema($pdo);
    $success = true;
} catch (Throwable $e) {
    $results = ['Ошибка: ' . $e->getMessage()];
    $success = false;
}

// Если вызвано через редирект с woo_dashboard — вернуться туда
$back = $_GET['back'] ?? 'woo_dashboard.php';
if (!preg_match('/^woo_\w+\.php$/', $back)) {
    $back = 'woo_dashboard.php';
}

require_once __DIR__ . '/../layout.php';
require_once __DIR__ . '/../helpers.php';

layout_header('WC — Установка');
?>
<div class="card" style="max-width:560px;margin:2rem auto;">
    <div class="card-title">WooCommerce — Установка схемы</div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-mb" role="alert">
            <?= icon('check', 18) ?> Готово
        </div>
    <?php else: ?>
        <div class="alert alert-error alert-mb" role="alert">
            <?= icon('x', 18) ?> Ошибка установки
        </div>
    <?php endif; ?>

    <ul style="margin:0 0 var(--sp-5) var(--sp-4);line-height:1.8;">
        <?php foreach ($results as $msg): ?>
            <li><?= htmlspecialchars($msg) ?></li>
        <?php endforeach; ?>
    </ul>

    <a href="<?= htmlspecialchars($back) ?>" class="btn btn-primary">
        <?= icon('home', 16) ?> Перейти к модулю
    </a>
</div>
<?php
layout_footer();
