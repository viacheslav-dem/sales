<?php
/**
 * Аналитические функции WooCommerce-модуля.
 * Все запросы работают с локальной SQLite (data/woo.db).
 */

require_once __DIR__ . '/woo_db.php';

// ═══════════════════════════════════════════════════════════
// Настройки модуля
// ═══════════════════════════════════════════════════════════

function woo_get_setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];

    try {
        $db = woo_db();
        $stmt = $db->prepare("SELECT value FROM woo_settings WHERE key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $cache[$key] = ($val !== false) ? $val : $default;
    } catch (Throwable $e) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

function woo_set_setting(string $key, string $value): void
{
    $db = woo_db();
    $stmt = $db->prepare("INSERT INTO woo_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $stmt->execute([$key, $value]);
}

/**
 * Дата/время последней успешной синхронизации или null.
 */
function woo_last_sync(): ?string
{
    $db = woo_db();
    try {
        $stmt = $db->query("SELECT ended_at FROM woo_sync_log WHERE status='ok' ORDER BY id DESC LIMIT 1");
        $val = $stmt->fetchColumn();
        return $val ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Количество заказов в локальной базе.
 */
function woo_order_count(array $statusFilter = []): int
{
    $db = woo_db();
    if (empty($statusFilter)) {
        return (int)$db->query("SELECT COUNT(*) FROM woo_orders")->fetchColumn();
    }
    $ph = implode(',', array_fill(0, count($statusFilter), '?'));
    $stmt = $db->prepare("SELECT COUNT(*) FROM woo_orders WHERE wc_status IN ($ph)");
    $stmt->execute($statusFilter);
    return (int)$stmt->fetchColumn();
}

// ═══════════════════════════════════════════════════════════
// KPI за период
// ═══════════════════════════════════════════════════════════

/**
 * @return array{orders:int, revenue:float, avg_order:float, items_sold:int,
 *               discount:float, customers:int, shipping:float}
 */
function woo_kpi(PDO $db, string $from, string $to, array $filters = []): array
{
    $where = "o.order_date BETWEEN ? AND ?";
    $params = [$from, $to];

    // Фильтр по статусам
    $statuses = $filters['statuses'] ?? [];
    if (!empty($statuses)) {
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $where .= " AND o.wc_status IN ($ph)";
        $params = array_merge($params, $statuses);
    }

    $sql = "
        SELECT
            COUNT(*)              AS orders,
            COALESCE(SUM(o.total), 0)          AS revenue,
            COALESCE(SUM(o.discount_total), 0) AS discount,
            COALESCE(SUM(o.shipping_total), 0) AS shipping,
            COUNT(DISTINCT CASE WHEN o.customer_id > 0 THEN o.customer_id ELSE NULL END) AS customers
        FROM woo_orders o
        WHERE $where
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    $orders  = (int)$row['orders'];
    $revenue = (float)$row['revenue'];

    // Подсчёт проданных единиц
    $sqlItems = "
        SELECT COALESCE(SUM(oi.quantity), 0) AS items_sold
        FROM woo_order_items oi
        INNER JOIN woo_orders o ON o.id = oi.order_id
        WHERE $where
    ";
    $stmtI = $db->prepare($sqlItems);
    $stmtI->execute($params);
    $itemsSold = (int)$stmtI->fetchColumn();

    return [
        'orders'     => $orders,
        'revenue'    => $revenue,
        'avg_order'  => $orders > 0 ? round($revenue / $orders, 2) : 0,
        'items_sold' => $itemsSold,
        'discount'   => (float)$row['discount'],
        'customers'  => (int)$row['customers'],
        'shipping'   => (float)$row['shipping'],
    ];
}

// ═══════════════════════════════════════════════════════════
// Агрегированный отчёт с группировкой
// ═══════════════════════════════════════════════════════════

/**
 * @param array $opts  group_by, statuses, sort_by, sort_dir, limit, offset
 * @return array{rows:array, totals:array}
 */
function woo_sales_report(PDO $db, string $from, string $to, array $opts = []): array
{
    $groupBy  = $opts['group_by']  ?? 'product';
    $statuses = $opts['statuses']  ?? [];
    $sortBy   = $opts['sort_by']   ?? 'revenue';
    $sortDir  = strtoupper($opts['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    $limit    = isset($opts['limit'])  ? (int)$opts['limit']  : null;
    $offset   = isset($opts['offset']) ? (int)$opts['offset'] : 0;

    $where = "o.order_date BETWEEN ? AND ?";
    $params = [$from, $to];

    if (!empty($statuses)) {
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $where .= " AND o.wc_status IN ($ph)";
        $params = array_merge($params, $statuses);
    }

    $validGroups = ['product', 'category', 'day', 'month', 'customer', 'payment', 'status', 'city', 'source'];
    if (!in_array($groupBy, $validGroups)) $groupBy = 'product';

    switch ($groupBy) {
        case 'product':
            $select  = "oi.product_id, oi.product_name AS name, COALESCE(wp.sku, oi.sku) AS sku, COALESCE(wp.category, '') AS category";
            $groupSql = "oi.product_id";
            $join     = "INNER JOIN woo_order_items oi ON oi.order_id = o.id LEFT JOIN woo_products wp ON wp.id = oi.product_id";
            $measures = "SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue, SUM(oi.line_subtotal - oi.line_total) AS discount, COUNT(DISTINCT o.id) AS orders";
            $having   = "HAVING qty > 0";
            break;

        case 'category':
            $select  = "COALESCE(NULLIF(wp.category, ''), 'Без категории') AS name";
            $groupSql = "name";
            $join     = "INNER JOIN woo_order_items oi ON oi.order_id = o.id LEFT JOIN woo_products wp ON wp.id = oi.product_id";
            $measures = "SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue, SUM(oi.line_subtotal - oi.line_total) AS discount, COUNT(DISTINCT o.id) AS orders";
            $having   = "HAVING qty > 0";
            break;

        case 'day':
            $select  = "o.order_date AS day";
            $groupSql = "o.order_date";
            $join     = "";
            $measures = "COUNT(*) AS orders, SUM(o.total) AS revenue, SUM(o.discount_total) AS discount, SUM(o.items_count) AS qty";
            $having   = "";
            break;

        case 'month':
            $select  = "strftime('%Y-%m', o.order_date) AS month";
            $groupSql = "month";
            $join     = "";
            $measures = "COUNT(*) AS orders, SUM(o.total) AS revenue, SUM(o.discount_total) AS discount, SUM(o.items_count) AS qty";
            $having   = "";
            break;

        case 'customer':
            $select  = "o.customer_id, o.customer_name AS name, o.customer_email AS email";
            $groupSql = "o.customer_id";
            $join     = "";
            $measures = "COUNT(*) AS orders, SUM(o.total) AS revenue, ROUND(SUM(o.total) / COUNT(*), 2) AS avg_order";
            $having   = "";
            break;

        case 'payment':
            $select  = "CASE WHEN o.payment_title != '' THEN o.payment_title ELSE o.payment_method END AS name";
            $groupSql = "name";
            $join     = "";
            $measures = "COUNT(*) AS orders, SUM(o.total) AS revenue";
            $having   = "";
            break;

        case 'status':
            $select  = "o.wc_status AS name";
            $groupSql = "o.wc_status";
            $join     = "";
            $measures = "COUNT(*) AS orders, SUM(o.total) AS revenue";
            $having   = "";
            break;

        case 'city':
            $select  = "CASE WHEN o.billing_city != '' THEN o.billing_city ELSE 'Не указан' END AS name";
            $groupSql = "name";
            $join     = "";
            $measures = "COUNT(*) AS orders, SUM(o.total) AS revenue";
            $having   = "";
            break;

        case 'source':
            $select  = "CASE WHEN o.source != '' THEN o.source ELSE 'Неизвестно' END AS name";
            $groupSql = "name";
            $join     = "";
            $measures = "COUNT(*) AS orders, SUM(o.total) AS revenue";
            $having   = "";
            break;
    }

    // Валидация sort_by
    $validSorts = ['revenue', 'qty', 'orders', 'name', 'discount', 'avg_order', 'day', 'month'];
    if (!in_array($sortBy, $validSorts)) $sortBy = 'revenue';
    $orderSql = "$sortBy $sortDir";

    $sql = "SELECT $select, $measures
            FROM woo_orders o $join
            WHERE $where
            GROUP BY $groupSql $having
            ORDER BY $orderSql";

    if ($limit !== null) {
        $sql .= " LIMIT $limit OFFSET $offset";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Подсчёт итогов
    $totals = ['revenue' => 0, 'qty' => 0, 'orders' => 0, 'discount' => 0];
    foreach ($rows as $r) {
        $totals['revenue']  += (float)($r['revenue'] ?? 0);
        $totals['qty']      += (int)($r['qty'] ?? 0);
        $totals['orders']   += (int)($r['orders'] ?? 0);
        $totals['discount'] += (float)($r['discount'] ?? 0);
    }

    return ['rows' => $rows, 'totals' => $totals, 'group_by' => $groupBy];
}

/**
 * Подсчёт строк отчёта (для пагинации).
 */
function woo_sales_report_count(PDO $db, string $from, string $to, array $opts = []): int
{
    $result = woo_sales_report($db, $from, $to, array_merge($opts, ['limit' => null]));
    return count($result['rows']);
}

// ═══════════════════════════════════════════════════════════
// Топ-N товаров
// ═══════════════════════════════════════════════════════════

function woo_top_products(PDO $db, string $from, string $to, int $limit = 10, string $by = 'revenue'): array
{
    $orderCol = $by === 'qty' ? 'qty' : 'revenue';
    $sql = "
        SELECT oi.product_id, oi.product_name AS name,
            SUM(oi.quantity) AS qty,
            SUM(oi.line_total) AS revenue
        FROM woo_order_items oi
        INNER JOIN woo_orders o ON o.id = oi.order_id
        WHERE o.order_date BETWEEN ? AND ?
        GROUP BY oi.product_id
        HAVING qty > 0
        ORDER BY $orderCol DESC
        LIMIT ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$from, $to, $limit]);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════════════════
// Данные для графиков
// ═══════════════════════════════════════════════════════════

/**
 * Дневная выручка: [date => revenue], с нулями для пустых дней.
 */
function woo_daily_sparkline(PDO $db, string $from, string $to): array
{
    $stmt = $db->prepare("
        SELECT order_date, SUM(total) AS revenue
        FROM woo_orders
        WHERE order_date BETWEEN ? AND ?
        GROUP BY order_date
    ");
    $stmt->execute([$from, $to]);
    $data = array_column($stmt->fetchAll(), 'revenue', 'order_date');

    $result = [];
    $d = new DateTime($from);
    $end = new DateTime($to);
    while ($d <= $end) {
        $key = $d->format('Y-m-d');
        $result[$key] = (float)($data[$key] ?? 0);
        $d->modify('+1 day');
    }
    return $result;
}

/**
 * Выручка по дням недели: [1=Пн..7=Вс => revenue].
 */
function woo_weekday_breakdown(PDO $db, string $from, string $to): array
{
    // SQLite strftime('%w') возвращает 0=Sun..6=Sat
    $stmt = $db->prepare("
        SELECT CAST(strftime('%w', order_date) AS INTEGER) AS dow,
               SUM(total) AS revenue
        FROM woo_orders
        WHERE order_date BETWEEN ? AND ?
        GROUP BY dow
    ");
    $stmt->execute([$from, $to]);
    $raw = array_column($stmt->fetchAll(), 'revenue', 'dow');

    // Конвертируем в 1=Пн..7=Вс
    $result = [];
    for ($i = 1; $i <= 7; $i++) {
        $sqlDow = $i % 7; // 1→1(Mon), 2→2(Tue), ... 7→0(Sun)
        $result[$i] = (float)($raw[$sqlDow] ?? 0);
    }
    return $result;
}

/**
 * Выручка по часам: [0..23 => revenue].
 */
function woo_hourly_breakdown(PDO $db, string $from, string $to): array
{
    $stmt = $db->prepare("
        SELECT CAST(strftime('%H', order_datetime) AS INTEGER) AS hour,
               SUM(total) AS revenue
        FROM woo_orders
        WHERE order_date BETWEEN ? AND ?
        GROUP BY hour
    ");
    $stmt->execute([$from, $to]);
    $raw = array_column($stmt->fetchAll(), 'revenue', 'hour');

    $result = [];
    for ($h = 0; $h < 24; $h++) {
        $result[$h] = (float)($raw[$h] ?? 0);
    }
    return $result;
}

/**
 * Тренд среднего чека: [date => avg_order_value].
 */
function woo_aov_trend(PDO $db, string $from, string $to): array
{
    $stmt = $db->prepare("
        SELECT order_date, ROUND(SUM(total) / COUNT(*), 2) AS aov
        FROM woo_orders
        WHERE order_date BETWEEN ? AND ?
        GROUP BY order_date
    ");
    $stmt->execute([$from, $to]);
    return array_column($stmt->fetchAll(), 'aov', 'order_date');
}

// ═══════════════════════════════════════════════════════════
// Клиенты и оплата
// ═══════════════════════════════════════════════════════════

/**
 * Статистика клиентов: новые vs возвратные, топ по выручке.
 */
function woo_customer_stats(PDO $db, string $from, string $to): array
{
    // Уникальные клиенты за период
    $stmt = $db->prepare("
        SELECT customer_id, customer_name, COUNT(*) AS orders, SUM(total) AS revenue
        FROM woo_orders
        WHERE order_date BETWEEN ? AND ? AND customer_id > 0
        GROUP BY customer_id
        ORDER BY revenue DESC
    ");
    $stmt->execute([$from, $to]);
    $customers = $stmt->fetchAll();

    // Новые клиенты (первый заказ в этом периоде)
    $newCount = 0;
    foreach ($customers as $c) {
        $first = $db->prepare("SELECT MIN(order_date) FROM woo_orders WHERE customer_id = ?");
        $first->execute([$c['customer_id']]);
        $firstDate = $first->fetchColumn();
        if ($firstDate >= $from) $newCount++;
    }

    return [
        'total'     => count($customers),
        'new'       => $newCount,
        'returning' => count($customers) - $newCount,
        'top'       => array_slice($customers, 0, 10),
    ];
}

/**
 * Распределение по статусам заказов.
 */
function woo_status_distribution(PDO $db, string $from, string $to): array
{
    $stmt = $db->prepare("
        SELECT wc_status, COUNT(*) AS cnt, SUM(total) AS revenue
        FROM woo_orders
        WHERE order_date BETWEEN ? AND ?
        GROUP BY wc_status
        ORDER BY cnt DESC
    ");
    $stmt->execute([$from, $to]);
    return $stmt->fetchAll();
}

/**
 * Распределение по способам оплаты.
 */
function woo_payment_distribution(PDO $db, string $from, string $to): array
{
    $stmt = $db->prepare("
        SELECT
            CASE WHEN payment_title != '' THEN payment_title ELSE payment_method END AS method,
            COUNT(*) AS cnt,
            SUM(total) AS revenue
        FROM woo_orders
        WHERE order_date BETWEEN ? AND ?
        GROUP BY method
        ORDER BY revenue DESC
    ");
    $stmt->execute([$from, $to]);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════════════════
// Последние заказы
// ═══════════════════════════════════════════════════════════

function woo_recent_orders(PDO $db, int $limit = 10): array
{
    $stmt = $db->prepare("
        SELECT id, wc_status, order_date, order_datetime,
               customer_name, items_count, total, payment_title, source
        FROM woo_orders
        ORDER BY order_datetime DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════════════════
// Список заказов с фильтрами и позициями
// ═══════════════════════════════════════════════════════════

/**
 * Заказы с фильтрами. Возвращает массив заказов.
 * @param array $filters  from, to, search (клиент/товар), status
 */
function woo_orders_list(PDO $db, string $from, string $to, array $filters = [], int $perPage = 30, int $page = 1): array
{
    $where  = "o.order_date BETWEEN ? AND ?";
    $params = [$from, $to];

    $search = trim($filters['search'] ?? '');
    if ($search !== '') {
        // Поиск по имени клиента, email или товару в позициях
        $where .= " AND (o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.id IN (
            SELECT oi.order_id FROM woo_order_items oi WHERE oi.product_name LIKE ?
        ))";
        $like = "%$search%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $status = $filters['status'] ?? '';
    if ($status !== '') {
        $where .= " AND o.wc_status = ?";
        $params[] = $status;
    }

    // Подсчёт
    $cntSql = "SELECT COUNT(*) FROM woo_orders o WHERE $where";
    $cntStmt = $db->prepare($cntSql);
    $cntStmt->execute($params);
    $totalCount = (int)$cntStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;

    $sql = "SELECT o.id, o.wc_status, o.order_date, o.order_datetime,
                   o.customer_id, o.customer_name, o.customer_email, o.billing_city,
                   o.payment_title, o.total, o.items_count, o.source
            FROM woo_orders o
            WHERE $where
            ORDER BY o.order_datetime DESC
            LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    return [
        'orders'     => $orders,
        'totalCount' => $totalCount,
        'page'       => $page,
        'perPage'    => $perPage,
    ];
}

/**
 * Позиции заказа.
 */
function woo_order_items(PDO $db, int $orderId): array
{
    $stmt = $db->prepare("
        SELECT product_name, sku, quantity, unit_price, line_total
        FROM woo_order_items
        WHERE order_id = ?
        ORDER BY product_name
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════════════════
// Вспомогательные: метки статусов
// ═══════════════════════════════════════════════════════════

function woo_status_label(string $status): string
{
    $map = [
        'wc-completed'  => 'Выполнен',
        'wc-processing' => 'В обработке',
        'wc-on-hold'    => 'На удержании',
        'wc-refunded'   => 'Возвращён',
        'wc-cancelled'  => 'Отменён',
        'wc-pending'    => 'Ожидание',
        'wc-failed'     => 'Неудачный',
    ];
    return $map[$status] ?? $status;
}

function woo_status_class(string $status): string
{
    $map = [
        'wc-completed'  => 'badge-success',
        'wc-processing' => 'badge-primary',
        'wc-on-hold'    => 'badge-warning',
        'wc-refunded'   => 'badge-danger',
        'wc-cancelled'  => 'badge-muted',
        'wc-pending'    => 'badge-warning',
        'wc-failed'     => 'badge-danger',
    ];
    return $map[$status] ?? 'badge-muted';
}
