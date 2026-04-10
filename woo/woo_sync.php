<?php
/**
 * Движок синхронизации заказов WooCommerce → локальная SQLite.
 *
 * Обрабатывает POST-запросы от woo_dashboard.php:
 *   action=sync_incremental — только новые/изменённые с последней синхронизации
 *   action=sync_full        — полная пересинхронизация (очистка + загрузка)
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/woo_db.php';
require_once __DIR__ . '/../helpers.php';

// ═══════════════════════════════════════════════════════════
// Функции синхронизации (определяются ДО вызова)
// ═══════════════════════════════════════════════════════════

/**
 * Инкрементальная синхронизация: только заказы, изменённые после последней успешной синхронизации.
 */
function woo_sync_incremental(): array
{
    $db = woo_db();
    $since = '2000-01-01 00:00:00';

    $stmt = $db->query("SELECT ended_at FROM woo_sync_log WHERE status='ok' ORDER BY id DESC LIMIT 1");
    $lastSync = $stmt->fetchColumn();
    if ($lastSync) $since = $lastSync;

    return woo_sync_execute($since, false);
}

/**
 * Полная синхронизация: очистка локальных таблиц и загрузка всех заказов.
 */
function woo_sync_full(): array
{
    $db = woo_db();
    $db->exec("DELETE FROM woo_order_items");
    $db->exec("DELETE FROM woo_orders");
    $db->exec("DELETE FROM woo_products");

    return woo_sync_execute('2000-01-01 00:00:00', true);
}

/**
 * Основной цикл синхронизации.
 *
 * @param string $since Дата (UTC) — забирать заказы, изменённые после этой даты
 * @param bool   $isFull Полная синхронизация (для записи в лог)
 * @return array Статистика: orders_new, orders_upd, items_synced
 */
function woo_sync_execute(string $since, bool $isFull): array
{
    $db     = woo_db();
    $wc     = wc_db();
    $prefix = woo_table_prefix();
    $mode   = woo_detect_storage_mode($wc, $prefix);
    $tz     = new DateTimeZone('Europe/Minsk');
    $utc    = new DateTimeZone('UTC');

    $batchSize = 500;
    $stats = ['orders_new' => 0, 'orders_upd' => 0, 'items_synced' => 0];

    // Получить разрешённые статусы
    $stStmt = $db->query("SELECT value FROM woo_settings WHERE key='wc_sync_statuses'");
    $statusStr = $stStmt->fetchColumn();
    $allowedStatuses = $statusStr ? array_filter(explode(',', $statusStr)) : ['wc-completed', 'wc-processing'];

    // Запись в лог
    $logStmt = $db->prepare("INSERT INTO woo_sync_log (started_at, sync_type, status) VALUES (datetime('now'), ?, 'running')");
    $logStmt->execute([$isFull ? 'full' : 'incremental']);
    $logId = $db->lastInsertId();

    try {
        $offset = 0;

        while (true) {
            // Забираем батч заказов из MySQL
            $orders = _woo_fetch_orders($wc, $prefix, $mode, $since, $allowedStatuses, $batchSize, $offset);
            if (empty($orders)) break;

            $orderIds = array_column($orders, 'id');

            // Забираем attribution (источник заказа) из meta
            $sourceMap = _woo_fetch_order_sources($wc, $prefix, $mode, $orderIds);
            foreach ($orders as &$o) {
                $oid = (int)$o['id'];
                $o['source_type'] = $sourceMap[$oid]['source_type'] ?? '';
                $o['source']      = $sourceMap[$oid]['source'] ?? '';
            }
            unset($o);

            // Забираем позиции для этих заказов
            $items = _woo_fetch_order_items($wc, $prefix, $orderIds);

            // Группируем позиции по order_id
            $itemsByOrder = [];
            foreach ($items as $item) {
                $itemsByOrder[(int)$item['order_id']][] = $item;
            }

            // Пишем в SQLite
            $db->beginTransaction();
            try {
                $upsertOrder = $db->prepare("
                    INSERT INTO woo_orders (id, wc_status, order_date, order_datetime,
                        customer_id, customer_name, customer_email, billing_city,
                        payment_method, payment_title, currency,
                        total, discount_total, shipping_total, tax_total, items_count,
                        source_type, source, synced_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
                    ON CONFLICT(id) DO UPDATE SET
                        wc_status      = excluded.wc_status,
                        order_date     = excluded.order_date,
                        order_datetime = excluded.order_datetime,
                        customer_id    = excluded.customer_id,
                        customer_name  = excluded.customer_name,
                        customer_email = excluded.customer_email,
                        billing_city   = excluded.billing_city,
                        payment_method = excluded.payment_method,
                        payment_title  = excluded.payment_title,
                        total          = excluded.total,
                        discount_total = excluded.discount_total,
                        shipping_total = excluded.shipping_total,
                        tax_total      = excluded.tax_total,
                        items_count    = excluded.items_count,
                        source_type    = excluded.source_type,
                        source         = excluded.source,
                        synced_at      = excluded.synced_at
                ");

                $deleteItems = $db->prepare("DELETE FROM woo_order_items WHERE order_id = ?");
                $insertItem  = $db->prepare("
                    INSERT INTO woo_order_items (id, order_id, product_id, variation_id,
                        product_name, sku, quantity, unit_price, line_total, line_subtotal)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($orders as $row) {
                    $orderId = (int)$row['id'];

                    // Конвертировать UTC → Europe/Minsk
                    $dt = new DateTime($row['date_created_gmt'] ?? $row['date_created'] ?? 'now', $utc);
                    $dt->setTimezone($tz);
                    $orderDate     = $dt->format('Y-m-d');
                    $orderDatetime = $dt->format('Y-m-d H:i:s');

                    // Нормализовать статус (может быть с или без префикса wc-)
                    $status = $row['status'] ?? '';
                    if (strpos($status, 'wc-') !== 0) $status = 'wc-' . $status;

                    $oItems = $itemsByOrder[$orderId] ?? [];

                    // Проверить, существует ли заказ (для статистики)
                    $exists = $db->prepare("SELECT 1 FROM woo_orders WHERE id = ?");
                    $exists->execute([$orderId]);
                    if ($exists->fetchColumn()) {
                        $stats['orders_upd']++;
                    } else {
                        $stats['orders_new']++;
                    }

                    $upsertOrder->execute([
                        $orderId,
                        $status,
                        $orderDate,
                        $orderDatetime,
                        (int)($row['customer_id'] ?? 0),
                        trim($row['customer_name'] ?? ''),
                        trim($row['billing_email'] ?? $row['customer_email'] ?? ''),
                        trim($row['billing_city'] ?? ''),
                        trim($row['payment_method'] ?? ''),
                        trim($row['payment_method_title'] ?? $row['payment_title'] ?? ''),
                        trim($row['currency'] ?? 'BYN'),
                        (float)($row['total'] ?? 0),
                        (float)($row['discount_total'] ?? 0),
                        (float)($row['shipping_total'] ?? 0),
                        (float)($row['tax_total'] ?? 0),
                        count($oItems),
                        trim($row['source_type'] ?? ''),
                        trim($row['source'] ?? ''),
                    ]);

                    // Перезаписать позиции
                    $deleteItems->execute([$orderId]);
                    foreach ($oItems as $item) {
                        $lineTotal    = (float)($item['line_total'] ?? 0);
                        $lineSubtotal = (float)($item['line_subtotal'] ?? 0);
                        $qty          = (int)($item['quantity'] ?? 0);
                        $unitPrice    = $qty > 0 ? round($lineTotal / $qty, 4) : 0;

                        $insertItem->execute([
                            (int)$item['id'],
                            $orderId,
                            (int)($item['product_id'] ?? 0),
                            (int)($item['variation_id'] ?? 0),
                            trim($item['product_name'] ?? ''),
                            trim($item['sku'] ?? ''),
                            $qty,
                            $unitPrice,
                            $lineTotal,
                            $lineSubtotal,
                        ]);
                        $stats['items_synced']++;
                    }
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            $offset += $batchSize;
            if (count($orders) < $batchSize) break; // Последний батч
        }

        // Синхронизация товаров (каталог)
        _woo_sync_products($wc, $db, $prefix);

        // Обновить лог
        $logUpd = $db->prepare("UPDATE woo_sync_log SET ended_at=datetime('now'), status='ok',
            orders_new=?, orders_upd=?, items_synced=? WHERE id=?");
        $logUpd->execute([$stats['orders_new'], $stats['orders_upd'], $stats['items_synced'], $logId]);

    } catch (Throwable $e) {
        // Обновить лог с ошибкой
        $logErr = $db->prepare("UPDATE woo_sync_log SET ended_at=datetime('now'), status='error', error_msg=? WHERE id=?");
        $logErr->execute([mb_substr($e->getMessage(), 0, 500), $logId]);
        throw $e;
    }

    return $stats;
}

// ═══════════════════════════════════════════════════════════
// Детекция режима хранения
// ═══════════════════════════════════════════════════════════

function woo_detect_storage_mode(PDO $wc, string $prefix): string
{
    try {
        $stmt = $wc->prepare("SELECT option_value FROM {$prefix}options WHERE option_name = 'woocommerce_custom_orders_table_enabled'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val === 'yes') {
            // Проверить, что таблица реально существует
            $check = $wc->query("SHOW TABLES LIKE '{$prefix}wc_orders'");
            if ($check->fetchColumn()) return 'hpos';
        }
    } catch (Throwable $e) {
        // Если запрос не удался — fallback на legacy
    }
    return 'legacy';
}

// ═══════════════════════════════════════════════════════════
// Загрузка заказов из MySQL
// ═══════════════════════════════════════════════════════════

function _woo_fetch_orders(PDO $wc, string $prefix, string $mode, string $since, array $statuses, int $limit, int $offset): array
{
    if (empty($statuses)) return [];

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    if ($mode === 'hpos') {
        // wp_wc_orders: базовые поля (total_amount, tax_amount)
        // wp_wc_order_operational_data: discount_total_amount, shipping_total_amount
        $sql = "
            SELECT
                o.id,
                o.status,
                o.date_created_gmt,
                o.customer_id,
                o.billing_email,
                o.payment_method,
                o.payment_method_title,
                o.currency,
                o.total_amount                          AS total,
                o.tax_amount                            AS tax_total,
                COALESCE(od.discount_total_amount, 0)   AS discount_total,
                COALESCE(od.shipping_total_amount, 0)   AS shipping_total,
                CONCAT_WS(' ', NULLIF(addr.first_name, ''), NULLIF(addr.last_name, '')) AS customer_name,
                COALESCE(addr.city, '') AS billing_city
            FROM {$prefix}wc_orders o
            LEFT JOIN {$prefix}wc_order_operational_data od ON od.order_id = o.id
            LEFT JOIN {$prefix}wc_order_addresses addr
                ON addr.order_id = o.id AND addr.address_type = 'billing'
            WHERE o.type = 'shop_order'
              AND o.status IN ($placeholders)
              AND o.date_updated_gmt >= ?
            ORDER BY o.id ASC
            LIMIT ? OFFSET ?
        ";
        $params = array_merge($statuses, [$since, $limit, $offset]);

    } else {
        // Legacy mode: wp_posts + wp_postmeta
        $sql = "
            SELECT
                p.ID AS id,
                p.post_status AS status,
                p.post_date_gmt AS date_created_gmt,
                COALESCE(pm_cust.meta_value, '0')   AS customer_id,
                COALESCE(pm_email.meta_value, '')    AS billing_email,
                COALESCE(pm_pm.meta_value, '')       AS payment_method,
                COALESCE(pm_pmt.meta_value, '')      AS payment_method_title,
                COALESCE(pm_cur.meta_value, 'BYN')   AS currency,
                COALESCE(pm_total.meta_value, '0')   AS total,
                COALESCE(pm_disc.meta_value, '0')    AS discount_total,
                COALESCE(pm_ship.meta_value, '0')    AS shipping_total,
                COALESCE(pm_tax.meta_value, '0')     AS tax_total,
                CONCAT_WS(' ', NULLIF(pm_fn.meta_value, ''), NULLIF(pm_ln.meta_value, '')) AS customer_name,
                COALESCE(pm_city.meta_value, '')      AS billing_city
            FROM {$prefix}posts p
            LEFT JOIN {$prefix}postmeta pm_cust  ON pm_cust.post_id  = p.ID AND pm_cust.meta_key  = '_customer_user'
            LEFT JOIN {$prefix}postmeta pm_email ON pm_email.post_id = p.ID AND pm_email.meta_key = '_billing_email'
            LEFT JOIN {$prefix}postmeta pm_pm    ON pm_pm.post_id    = p.ID AND pm_pm.meta_key    = '_payment_method'
            LEFT JOIN {$prefix}postmeta pm_pmt   ON pm_pmt.post_id   = p.ID AND pm_pmt.meta_key   = '_payment_method_title'
            LEFT JOIN {$prefix}postmeta pm_cur   ON pm_cur.post_id   = p.ID AND pm_cur.meta_key   = '_order_currency'
            LEFT JOIN {$prefix}postmeta pm_total ON pm_total.post_id = p.ID AND pm_total.meta_key = '_order_total'
            LEFT JOIN {$prefix}postmeta pm_disc  ON pm_disc.post_id  = p.ID AND pm_disc.meta_key  = '_cart_discount'
            LEFT JOIN {$prefix}postmeta pm_ship  ON pm_ship.post_id  = p.ID AND pm_ship.meta_key  = '_order_shipping'
            LEFT JOIN {$prefix}postmeta pm_tax   ON pm_tax.post_id   = p.ID AND pm_tax.meta_key   = '_order_tax'
            LEFT JOIN {$prefix}postmeta pm_fn    ON pm_fn.post_id    = p.ID AND pm_fn.meta_key    = '_billing_first_name'
            LEFT JOIN {$prefix}postmeta pm_ln    ON pm_ln.post_id    = p.ID AND pm_ln.meta_key    = '_billing_last_name'
            LEFT JOIN {$prefix}postmeta pm_city  ON pm_city.post_id  = p.ID AND pm_city.meta_key  = '_billing_city'
            WHERE p.post_type = 'shop_order'
              AND p.post_status IN ($placeholders)
              AND p.post_modified_gmt >= ?
            ORDER BY p.ID ASC
            LIMIT ? OFFSET ?
        ";
        $params = array_merge($statuses, [$since, $limit, $offset]);
    }

    $stmt = $wc->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════════════════
// Загрузка позиций заказов
// ═══════════════════════════════════════════════════════════

function _woo_fetch_order_items(PDO $wc, string $prefix, array $orderIds): array
{
    if (empty($orderIds)) return [];

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $sql = "
        SELECT
            oi.order_item_id   AS id,
            oi.order_id,
            oi.order_item_name AS product_name,
            COALESCE(oim_pid.meta_value, '0')   AS product_id,
            COALESCE(oim_vid.meta_value, '0')   AS variation_id,
            COALESCE(oim_qty.meta_value, '0')   AS quantity,
            COALESCE(oim_total.meta_value, '0') AS line_total,
            COALESCE(oim_sub.meta_value, '0')   AS line_subtotal,
            COALESCE(oim_sku.meta_value, '')     AS sku
        FROM {$prefix}woocommerce_order_items oi
        LEFT JOIN {$prefix}woocommerce_order_itemmeta oim_pid
            ON oim_pid.order_item_id = oi.order_item_id AND oim_pid.meta_key = '_product_id'
        LEFT JOIN {$prefix}woocommerce_order_itemmeta oim_vid
            ON oim_vid.order_item_id = oi.order_item_id AND oim_vid.meta_key = '_variation_id'
        LEFT JOIN {$prefix}woocommerce_order_itemmeta oim_qty
            ON oim_qty.order_item_id = oi.order_item_id AND oim_qty.meta_key = '_qty'
        LEFT JOIN {$prefix}woocommerce_order_itemmeta oim_total
            ON oim_total.order_item_id = oi.order_item_id AND oim_total.meta_key = '_line_total'
        LEFT JOIN {$prefix}woocommerce_order_itemmeta oim_sub
            ON oim_sub.order_item_id = oi.order_item_id AND oim_sub.meta_key = '_line_subtotal'
        LEFT JOIN {$prefix}woocommerce_order_itemmeta oim_sku
            ON oim_sku.order_item_id = oi.order_item_id AND oim_sku.meta_key = '_sku'
        WHERE oi.order_id IN ($placeholders)
          AND oi.order_item_type = 'line_item'
        ORDER BY oi.order_id, oi.order_item_id
    ";

    $stmt = $wc->prepare($sql);
    $stmt->execute($orderIds);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════════════════
// Загрузка источников заказов (order attribution)
// ═══════════════════════════════════════════════════════════

function _woo_fetch_order_sources(PDO $wc, string $prefix, string $mode, array $orderIds): array
{
    if (empty($orderIds)) return [];

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    // Attribution хранится в order meta (и в HPOS, и в legacy)
    $metaTable = ($mode === 'hpos') ? "{$prefix}wc_orders_meta" : "{$prefix}postmeta";
    $idCol     = ($mode === 'hpos') ? 'order_id' : 'post_id';

    $sql = "
        SELECT $idCol AS order_id, meta_key, meta_value
        FROM $metaTable
        WHERE $idCol IN ($placeholders)
          AND meta_key IN ('_wc_order_attribution_source_type', '_wc_order_attribution_utm_source', '_wc_order_attribution_referrer')
    ";

    $stmt = $wc->prepare($sql);
    $stmt->execute($orderIds);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $oid = (int)$r['order_id'];
        if (!isset($result[$oid])) $result[$oid] = ['source_type' => '', 'source' => ''];

        switch ($r['meta_key']) {
            case '_wc_order_attribution_source_type':
                $result[$oid]['source_type'] = $r['meta_value'] ?? '';
                break;
            case '_wc_order_attribution_utm_source':
                // UTM-источник имеет приоритет
                if (!empty($r['meta_value'])) {
                    $result[$oid]['source'] = $r['meta_value'];
                }
                break;
            case '_wc_order_attribution_referrer':
                // Referrer — fallback если нет UTM
                if (empty($result[$oid]['source']) && !empty($r['meta_value'])) {
                    // Извлечь домен из URL
                    $host = parse_url($r['meta_value'], PHP_URL_HOST);
                    $result[$oid]['source'] = $host ?: $r['meta_value'];
                }
                break;
        }
    }

    // Человеко-читаемые метки для source_type
    foreach ($result as &$item) {
        if (empty($item['source'])) {
            $item['source'] = _woo_source_type_label($item['source_type']);
        }
    }
    unset($item);

    return $result;
}

function _woo_source_type_label(string $type): string
{
    $map = [
        'typein'   => 'Прямой переход',
        'organic'  => 'Поиск',
        'referral' => 'Реферал',
        'utm'      => 'Рекламная кампания',
        'admin'    => 'Админ-панель',
        'direct'   => 'Прямой переход',
    ];
    return $map[$type] ?? ($type ?: 'Неизвестно');
}

// ═══════════════════════════════════════════════════════════
// Синхронизация каталога товаров
// ═══════════════════════════════════════════════════════════

function _woo_sync_products(PDO $wc, PDO $db, string $prefix): void
{
    $sql = "
        SELECT
            p.ID AS id,
            p.post_title AS name,
            COALESCE(pm_sku.meta_value, '') AS sku,
            COALESCE(pm_price.meta_value, '0') AS price,
            pm_stock.meta_value AS stock_qty,
            GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS category
        FROM {$prefix}posts p
        LEFT JOIN {$prefix}postmeta pm_sku
            ON pm_sku.post_id = p.ID AND pm_sku.meta_key = '_sku'
        LEFT JOIN {$prefix}postmeta pm_price
            ON pm_price.post_id = p.ID AND pm_price.meta_key = '_regular_price'
        LEFT JOIN {$prefix}postmeta pm_stock
            ON pm_stock.post_id = p.ID AND pm_stock.meta_key = '_stock'
        LEFT JOIN {$prefix}term_relationships tr
            ON tr.object_id = p.ID
        LEFT JOIN {$prefix}term_taxonomy tt
            ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
        LEFT JOIN {$prefix}terms t
            ON t.term_id = tt.term_id
        WHERE p.post_type IN ('product', 'product_variation')
          AND p.post_status = 'publish'
        GROUP BY p.ID
    ";

    $products = $wc->query($sql)->fetchAll();

    $upsert = $db->prepare("
        INSERT INTO woo_products (id, name, sku, category, price, stock_qty, synced_at)
        VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
        ON CONFLICT(id) DO UPDATE SET
            name      = excluded.name,
            sku       = excluded.sku,
            category  = excluded.category,
            price     = excluded.price,
            stock_qty = excluded.stock_qty,
            synced_at = excluded.synced_at
    ");

    $db->beginTransaction();
    try {
        foreach ($products as $p) {
            $upsert->execute([
                (int)$p['id'],
                trim($p['name']),
                trim($p['sku']),
                trim($p['category'] ?? ''),
                (float)($p['price'] ?? 0),
                $p['stock_qty'] !== null ? (int)$p['stock_qty'] : null,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        // Не прерываем весь процесс из-за ошибки каталога
    }
}

// ═══════════════════════════════════════════════════════════
// Обработка POST-запроса (выполняется после определения всех функций)
// ═══════════════════════════════════════════════════════════

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: woo_dashboard.php');
    exit;
}

csrf_check();

set_time_limit(300);

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'sync_incremental':
            $result = woo_sync_incremental();
            flash("Синхронизация завершена: новых {$result['orders_new']}, обновлено {$result['orders_upd']}, позиций {$result['items_synced']}.");
            break;

        case 'sync_full':
            $result = woo_sync_full();
            flash("Полная синхронизация завершена: загружено {$result['orders_new']} заказов, {$result['items_synced']} позиций.");
            break;

        default:
            flash('Неизвестное действие.', 'error');
    }
} catch (Throwable $e) {
    flash('Ошибка синхронизации: ' . $e->getMessage(), 'error');
}

header('Location: woo_dashboard.php');
exit;
