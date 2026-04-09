<?php
/**
 * Общие хелперы: форматирование, расчёт продаж, KPI, аналитика, графики.
 * Подключается всеми страницами, где это нужно.
 */

require_once __DIR__ . '/db.php';

/* ============================================================
   ФОРМАТИРОВАНИЕ
   ============================================================ */

/** Форматирование денежной суммы: «1 234.56». */
function format_money(float $value, int $decimals = 2): string {
    return number_format($value, $decimals, '.', ' ');
}

/** Форматирование целого количества: «1 234». */
function format_qty(int $value): string {
    return number_format($value, 0, '.', ' ');
}

/**
 * Делит длинное имя товара на «заголовок» и «комплектацию» по первой скобке.
 * «1-спальный набор ФЛАНЕЛЬ (пододеяльник 1шт, простынь 1шт)» →
 *   ['main' => '1-спальный набор ФЛАНЕЛЬ', 'meta' => 'пододеяльник 1шт, простынь 1шт']
 * Если скобки нет — meta пустой.
 */
function split_product_name(string $name): array {
    $name = trim($name);
    $pos  = mb_strpos($name, '(');
    if ($pos === false) {
        return ['main' => $name, 'meta' => ''];
    }
    $main = rtrim(mb_substr($name, 0, $pos));
    $meta = trim(mb_substr($name, $pos), " \t\n\r\0\x0B()");
    // Если после закрывающей скобки ещё есть текст — приклеиваем к meta
    return ['main' => $main, 'meta' => $meta];
}

/** Форматирование процента: «+12.3%» с цветом через CSS-класс. */
function format_pct(float $value, int $decimals = 1): string {
    $sign = $value > 0 ? '+' : '';
    return $sign . number_format($value, $decimals, '.', '') . '%';
}

/** CSS-класс для процента (положительный/отрицательный/нулевой). */
function pct_class(float $value): string {
    if ($value > 0.05)  return 'pct-up';
    if ($value < -0.05) return 'pct-down';
    return 'pct-zero';
}

/* ============================================================
   ДАТЫ И ПРЕСЕТЫ
   ============================================================ */

/**
 * Возвращает [from, to] для пресета периода.
 * Пресеты: today, yesterday, this_week, last_week, this_month,
 *          last_month, this_quarter, this_year, last_30, last_7
 */
function period_preset(string $name, ?string $today = null): array {
    $today = $today ?? date('Y-m-d');
    $t = strtotime($today);

    switch ($name) {
        case 'today':
            return [$today, $today];
        case 'yesterday':
            $y = date('Y-m-d', strtotime('-1 day', $t));
            return [$y, $y];
        case 'last_7':
            return [date('Y-m-d', strtotime('-6 days', $t)), $today];
        case 'last_30':
            return [date('Y-m-d', strtotime('-29 days', $t)), $today];
        case 'this_week': // Пн–Вс
            $dow = (int)date('N', $t); // 1 = Mon
            $from = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $t));
            $to   = date('Y-m-d', strtotime('+' . (7 - $dow) . ' days', $t));
            return [$from, $to];
        case 'last_week':
            $dow = (int)date('N', $t);
            $from = date('Y-m-d', strtotime('-' . ($dow - 1 + 7) . ' days', $t));
            $to   = date('Y-m-d', strtotime('-' . ($dow) . ' days', $t));
            return [$from, $to];
        case 'this_month':
            return [date('Y-m-01', $t), date('Y-m-t', $t)];
        case 'last_month':
            $lm = strtotime('first day of last month', $t);
            return [date('Y-m-01', $lm), date('Y-m-t', $lm)];
        case 'this_quarter':
            $m = (int)date('n', $t);
            $qStart = (int)floor(($m - 1) / 3) * 3 + 1;
            $from = sprintf('%04d-%02d-01', date('Y', $t), $qStart);
            $to   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', date('Y', $t), $qStart + 2)));
            return [$from, $to];
        case 'this_year':
            return [date('Y-01-01', $t), date('Y-12-31', $t)];
        default:
            // По умолчанию — этот месяц
            return [date('Y-m-01', $t), date('Y-m-t', $t)];
    }
}

/** Список всех доступных пресетов с человеческими названиями. */
function period_presets_list(): array {
    return [
        'today'        => 'Сегодня',
        'yesterday'    => 'Вчера',
        'last_7'       => 'Последние 7 дней',
        'this_week'    => 'Эта неделя',
        'last_week'    => 'Прошлая неделя',
        'this_month'   => 'Этот месяц',
        'last_month'   => 'Прошлый месяц',
        'last_30'      => 'Последние 30 дней',
        'this_quarter' => 'Этот квартал',
        'this_year'    => 'Этот год',
        'custom'       => 'Произвольно',
    ];
}

/** Сдвиг диапазона на «прошлый год» (для сравнения год-к-году). */
function shift_year_back(string $from, string $to): array {
    $f = date('Y-m-d', strtotime('-1 year', strtotime($from)));
    $t = date('Y-m-d', strtotime('-1 year', strtotime($to)));
    return [$f, $t];
}

/**
 * Предыдущий равный по длине период, идущий впритык к текущему.
 * Например, для (2026-04-01, 2026-04-07) → (2026-03-25, 2026-03-31).
 */
function shift_period_back(string $from, string $to): array {
    $dtFrom = new DateTime($from);
    $dtTo   = new DateTime($to);
    $days   = (int)$dtFrom->diff($dtTo)->days + 1;
    $prevTo   = (clone $dtFrom)->modify('-1 day');
    $prevFrom = (clone $prevTo)->modify('-' . ($days - 1) . ' days');
    return [$prevFrom->format('Y-m-d'), $prevTo->format('Y-m-d')];
}

/** Валидатор даты в формате Y-m-d. */
function valid_date(string $s): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y);
}

/**
 * Возвращает true, если дата находится в месяце, более раннем, чем текущий.
 * Используется для блокировки редактирования прошлых месяцев менеджерами:
 * только администратор может править закрытые отчётные периоды.
 */
function is_past_month(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    return substr($date, 0, 7) < date('Y-m');
}


/* ============================================================
   КОММОН: SQL-фрагменты для агрегации с учётом возвратов
   ============================================================ */

/**
 * Знаковые суммы: возврат вычитается. Возвращает строку SQL-фрагментов
 * для использования в SELECT-листе.
 */
function _signed_sums(string $alias = 's'): string {
    // Скидка берётся из явного поля discount_amount (заполняется при сохранении
    // продажи и при backfill-миграции). Это позволяет хранить «истинную» скидку,
    // даже если из-за округления qty*unit_price расходится с qty*base − discount.
    return "
        SUM($alias.quantity *  CASE WHEN $alias.is_return = 1 THEN -1 ELSE 1 END) AS net_qty,
        SUM($alias.amount   *  CASE WHEN $alias.is_return = 1 THEN -1 ELSE 1 END) AS net_sum,
        SUM($alias.discount_amount *
            CASE WHEN $alias.is_return = 1 THEN -1 ELSE 1 END) AS net_discount
    ";
}

/* ============================================================
   ОСНОВНОЙ ОТЧЁТ ПО ПРОДАЖАМ — sales_in_range()
   ============================================================ */

/**
 * Универсальная агрегация продаж за произвольный диапазон.
 *
 * @param PDO    $pdo
 * @param string $from   YYYY-MM-DD
 * @param string $to     YYYY-MM-DD
 * @param array  $opts:
 *   'group_by'    => 'product' | 'category' | 'day' | 'weekday'  (default 'product')
 *   'category_id' => int|null  фильтр по категории
 *   'product_id'  => int|null  фильтр по конкретному товару
 *   'limit'       => int|null  ограничение количества строк (для топ-N)
 *   'order_by'    => 'name' | 'sum' | 'qty' | 'discount' (default зависит от группировки)
 *
 * @return array [
 *   'rows'      => [...]   Массив строк, формат зависит от group_by
 *   'totals'    => ['qty', 'sum', 'discount', 'base']
 *   'date_from' => string,
 *   'date_to'   => string,
 * ]
 */
function sales_in_range(PDO $pdo, string $from, string $to, array $opts = []): array {
    $groupBy   = $opts['group_by']    ?? 'product';
    $catId     = $opts['category_id'] ?? null;
    $productId = $opts['product_id']  ?? null;
    $limit     = $opts['limit']       ?? null;
    $orderBy   = $opts['order_by']    ?? null;

    $params = [':from' => $from, ':to' => $to];

    // Дополнительные WHERE
    $extraWhere = '';
    if ($catId !== null) {
        $extraWhere .= ' AND p.category_id = :cat_id';
        $params[':cat_id'] = (int)$catId;
    }
    if ($productId !== null) {
        $extraWhere .= ' AND p.id = :pid';
        $params[':pid'] = (int)$productId;
    }

    $signed = _signed_sums('s');

    switch ($groupBy) {
        case 'category':
            $sql = <<<SQL
                SELECT
                    COALESCE(c.id, 0)                AS id,
                    COALESCE(c.name, 'Без категории') AS name,
                    $signed
                FROM sales s
                INNER JOIN products p ON p.id = s.product_id
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE s.sale_date BETWEEN :from AND :to
                  $extraWhere
                GROUP BY COALESCE(c.id, 0), COALESCE(c.name, 'Без категории')
                HAVING net_qty != 0
                ORDER BY net_sum DESC
            SQL;
            break;

        case 'day':
            $sql = <<<SQL
                SELECT
                    s.sale_date AS day,
                    $signed
                FROM sales s
                INNER JOIN products p ON p.id = s.product_id
                WHERE s.sale_date BETWEEN :from AND :to
                  $extraWhere
                GROUP BY s.sale_date
                HAVING net_qty != 0
                ORDER BY s.sale_date ASC
            SQL;
            break;

        case 'weekday':
            // strftime('%w'): 0=Sun, 1=Mon, ..., 6=Sat
            $sql = <<<SQL
                SELECT
                    CAST(strftime('%w', s.sale_date) AS INTEGER) AS weekday,
                    $signed
                FROM sales s
                INNER JOIN products p ON p.id = s.product_id
                WHERE s.sale_date BETWEEN :from AND :to
                  $extraWhere
                GROUP BY weekday
                ORDER BY CASE WHEN weekday = 0 THEN 7 ELSE weekday END
            SQL;
            break;

        case 'product':
        default:
            // Цена прайса берётся на конец периода
            $sql = <<<SQL
                SELECT
                    p.id, p.name,
                    c.name AS category_name,
                    COALESCE((
                        SELECT pp.price FROM product_prices pp
                        WHERE pp.product_id = p.id AND pp.valid_from <= :to
                        ORDER BY pp.valid_from DESC LIMIT 1
                    ), 0) AS catalog_price,
                    $signed
                FROM products p
                LEFT JOIN sales s ON s.product_id = p.id AND s.sale_date BETWEEN :from AND :to
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE 1=1 $extraWhere
                  AND (p.is_active = 1 OR EXISTS (
                        SELECT 1 FROM sales s2
                        WHERE s2.product_id = p.id
                          AND s2.sale_date BETWEEN :from AND :to
                  ))
                GROUP BY p.id, p.name, category_name
                HAVING net_qty != 0
            SQL;
            // Сортировка
            $orderMap = [
                'name'          => 'p.name ASC',
                'name_desc'     => 'p.name DESC',
                'sum'           => 'net_sum DESC',
                'sum_desc'      => 'net_sum DESC',
                'sum_asc'       => 'net_sum ASC',
                'qty'           => 'net_qty DESC',
                'qty_desc'      => 'net_qty DESC',
                'qty_asc'       => 'net_qty ASC',
                'discount'      => 'ABS(net_discount) DESC',
                'discount_desc' => 'ABS(net_discount) DESC',
            ];
            $sql .= ' ORDER BY ' . ($orderMap[$orderBy] ?? 'net_sum DESC');
            break;
    }

    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int)$limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Общие итоги (всегда считаются по всем строкам диапазона, без LIMIT и без группировки)
    $totalsSql = <<<SQL
        SELECT $signed
        FROM sales s
        INNER JOIN products p ON p.id = s.product_id
        WHERE s.sale_date BETWEEN :from AND :to
          $extraWhere
    SQL;
    $tStmt = $pdo->prepare($totalsSql);
    // Параметры для totals — те же, кроме лимита
    $tParams = $params;
    $tStmt->execute($tParams);
    $t = $tStmt->fetch();

    $netQty  = (int)  ($t['net_qty']      ?? 0);
    $netSum  = (float)($t['net_sum']      ?? 0);
    $netDisc = (float)($t['net_discount'] ?? 0);

    return [
        'rows'      => $rows,
        'totals'    => [
            'qty'      => $netQty,
            'sum'      => $netSum,
            'discount' => $netDisc,
            'base'     => $netSum + $netDisc, // выручка по прайсу
        ],
        'date_from' => $from,
        'date_to'   => $to,
        'group_by'  => $groupBy,
    ];
}

/**
 * Обратная совместимость: тонкая обёртка над sales_in_range.
 * Старый месячный отчёт продолжает работать без изменений.
 */
function monthly_sales(PDO $pdo, int $year, int $month): array {
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = date('Y-m-t', strtotime($from));
    $r = sales_in_range($pdo, $from, $to, ['group_by' => 'product']);

    // Старый формат имел ключ 'products' и поля 'total_qty', 'total_sum', 'total_discount'
    $products = [];
    foreach ($r['rows'] as $row) {
        $products[] = [
            'id'             => $row['id'],
            'name'           => $row['name'],
            'catalog_price'  => $row['catalog_price'],
            'total_qty'      => $row['net_qty'],
            'total_sum'      => $row['net_sum'],
            'total_discount' => $row['net_discount'],
        ];
    }

    return [
        'products'  => $products,
        'totals'    => $r['totals'],
        'date_from' => $r['date_from'],
        'date_to'   => $r['date_to'],
    ];
}

/* ============================================================
   ХЕЛПЕРЫ ДЛЯ ДАШБОРДА И АНАЛИТИКИ
   ============================================================ */

/**
 * KPI за период: количество, выручка, скидка, дни с продажами, средний чек/день.
 */
function kpi_for_period(PDO $pdo, string $from, string $to, array $filters = []): array {
    $signed = _signed_sums('s');
    $extra  = '';
    $params = [':from' => $from, ':to' => $to];
    if (!empty($filters['category_id'])) {
        $extra .= ' AND p.category_id = :cat';
        $params[':cat'] = (int)$filters['category_id'];
    }

    $sql = <<<SQL
        SELECT
            $signed,
            COUNT(DISTINCT s.sale_date) AS days_with_sales
        FROM sales s
        INNER JOIN products p ON p.id = s.product_id
        WHERE s.sale_date BETWEEN :from AND :to
          $extra
    SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $r = $stmt->fetch();

    $qty  = (int)  ($r['net_qty']         ?? 0);
    $sum  = (float)($r['net_sum']         ?? 0);
    $disc = (float)($r['net_discount']    ?? 0);
    $days = (int)  ($r['days_with_sales'] ?? 0);

    return [
        'qty'             => $qty,
        'revenue'         => $sum,
        'discount'        => $disc,
        'base'            => $sum + $disc,
        'days_with_sales' => $days,
        'avg_per_day'     => $days > 0 ? $sum / $days : 0.0,
    ];
}

/**
 * Топ-N товаров за период (по выручке или количеству).
 *
 * @param string $by 'revenue' | 'qty'
 */
function top_products(PDO $pdo, string $from, string $to, int $limit = 10, string $by = 'revenue'): array {
    $orderBy = $by === 'qty' ? 'net_qty DESC' : 'net_sum DESC';
    $signed  = _signed_sums('s');

    $sql = <<<SQL
        SELECT p.id, p.name,
            COALESCE(c.name, '— без категории —') AS category_name,
            $signed
        FROM sales s
        INNER JOIN products p  ON p.id = s.product_id
        LEFT  JOIN categories c ON c.id = p.category_id
        WHERE s.sale_date BETWEEN :from AND :to
        GROUP BY p.id, p.name, category_name
        HAVING net_qty > 0
        ORDER BY $orderBy
        LIMIT :lim
    SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':from', $from);
    $stmt->bindValue(':to',   $to);
    $stmt->bindValue(':lim',  (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Выручка по дням за период (с заполнением «пустых» дат нулями).
 * Возвращает массив [date => revenue], отсортированный по дате.
 */
function daily_sparkline(PDO $pdo, string $from, string $to): array {
    $signed = _signed_sums('s');
    $sql = <<<SQL
        SELECT s.sale_date AS d, $signed
        FROM sales s
        INNER JOIN products p ON p.id = s.product_id
        WHERE s.sale_date BETWEEN :from AND :to
        GROUP BY s.sale_date
    SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':from' => $from, ':to' => $to]);
    $byDate = [];
    foreach ($stmt->fetchAll() as $r) {
        $byDate[$r['d']] = (float)$r['net_sum'];
    }

    // Заполняем пустые даты нулями
    $out = [];
    $cur = strtotime($from);
    $end = strtotime($to);
    while ($cur <= $end) {
        $d = date('Y-m-d', $cur);
        $out[$d] = $byDate[$d] ?? 0.0;
        $cur = strtotime('+1 day', $cur);
    }
    return $out;
}

/**
 * Распределение выручки по дням недели за период.
 * Возвращает массив [1=Пн, 2=Вт, …, 7=Вс] => float.
 */
function weekday_breakdown(PDO $pdo, string $from, string $to): array {
    $signed = _signed_sums('s');
    $sql = <<<SQL
        SELECT
            CAST(strftime('%w', s.sale_date) AS INTEGER) AS dow,
            $signed
        FROM sales s
        INNER JOIN products p ON p.id = s.product_id
        WHERE s.sale_date BETWEEN :from AND :to
        GROUP BY dow
    SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':from' => $from, ':to' => $to]);

    // Инициализируем все 7 дней нулями (1..7, где 1 = Пн)
    $out = array_fill(1, 7, 0.0);
    foreach ($stmt->fetchAll() as $r) {
        // strftime('%w'): 0=Sun, 1=Mon, ..., 6=Sat → конвертируем в 1=Пн, ..., 7=Вс
        $w = (int)$r['dow'];
        $key = $w === 0 ? 7 : $w;
        $out[$key] = (float)$r['net_sum'];
    }
    return $out;
}

/** Имена дней недели для индексов 1..7 (Пн..Вс). */
function weekday_names_short(): array {
    return [1=>'Пн', 2=>'Вт', 3=>'Ср', 4=>'Чт', 5=>'Пт', 6=>'Сб', 7=>'Вс'];
}

/* ============================================================
   ВСПОМОГАТЕЛЬНЫЕ
   ============================================================ */

/**
 * Каталожная цена товара на конкретную дату.
 * Возвращает 0, если для товара ещё нет ни одной цены, действующей на $onDate.
 */
function product_price_on(PDO $pdo, int $productId, string $onDate): float {
    $stmt = $pdo->prepare(
        "SELECT price FROM product_prices
         WHERE product_id = ? AND valid_from <= ?
         ORDER BY valid_from DESC LIMIT 1"
    );
    $stmt->execute([$productId, $onDate]);
    return (float)($stmt->fetchColumn() ?: 0);
}

/**
 * Рендер пагинации (один общий компонент для products/history и любых
 * новых таблиц со списками). Эхает HTML — вызывать там, где нужна разметка.
 *
 * @param int      $page        Текущая страница (1-based).
 * @param int      $totalPages  Всего страниц.
 * @param int      $totalCount  Всего записей (для строки «X из Y»).
 * @param int      $perPage     Размер страницы.
 * @param int      $offset      Смещение (для расчёта диапазона «N–M»).
 * @param callable $urlFor      fn(int $page): string — построитель URL для страницы.
 * @param int      $range       Сколько соседних номеров показывать слева/справа от текущего.
 *
 * Контракт: если $totalPages <= 1 — ничего не выводится.
 * Шаблон <div class="pagination-wrap"><div class="pagination">…</div></div>
 * соответствует существующим стилям в style.css.
 */
function render_pagination(
    int $page,
    int $totalPages,
    int $totalCount,
    int $perPage,
    int $offset,
    callable $urlFor,
    int $range = 2
): void {
    if ($totalPages <= 1) return;

    $start = max(1, $page - $range);
    $end   = min($totalPages, $page + $range);
    $h     = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
    ?>
    <div class="pagination-wrap">
        <div class="pagination" role="navigation" aria-label="Постраничная навигация">
            <?php if ($page > 1): ?>
                <a href="<?= $h($urlFor(1)) ?>" aria-label="Первая страница" title="Первая страница">«</a>
                <a href="<?= $h($urlFor($page - 1)) ?>" aria-label="Предыдущая страница" title="Предыдущая страница" rel="prev">‹</a>
            <?php endif; ?>

            <?php if ($start > 1): ?>
                <a href="<?= $h($urlFor(1)) ?>">1</a>
                <?php if ($start > 2): ?><span class="dots" aria-hidden="true">…</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $start; $p <= $end; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current" aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= $h($urlFor($p)) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end < $totalPages): ?>
                <?php if ($end < $totalPages - 1): ?><span class="dots" aria-hidden="true">…</span><?php endif; ?>
                <a href="<?= $h($urlFor($totalPages)) ?>"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= $h($urlFor($page + 1)) ?>" aria-label="Следующая страница" title="Следующая страница" rel="next">›</a>
                <a href="<?= $h($urlFor($totalPages)) ?>" aria-label="Последняя страница" title="Последняя страница">»</a>
            <?php endif; ?>

            <span class="filter-info">
                Стр. <?= $page ?> из <?= $totalPages ?>
                (<?= $offset + 1 ?>–<?= min($offset + $perPage, $totalCount) ?> из <?= $totalCount ?>)
            </span>
        </div>
    </div>
    <?php
}

/** Все категории отсортированные по sort_order, name. */
function list_categories(PDO $pdo): array {
    return $pdo->query("SELECT id, name FROM categories ORDER BY sort_order, name")->fetchAll();
}

/** Все товары как [id => name] (для autocomplete и select). */
function list_products_simple(PDO $pdo): array {
    $rows = $pdo->query("SELECT id, name FROM products ORDER BY name")->fetchAll();
    $out  = [];
    foreach ($rows as $r) $out[(int)$r['id']] = $r['name'];
    return $out;
}

/**
 * Inline-SVG sparkline по массиву значений.
 * Используется в дашборде и отчётах. Никаких внешних библиотек.
 */
/**
 * Контейнер с <canvas> для Chart.js. Конфиг сериализуется в data-chart.
 * Используется хелперами chart_line/chart_bar/chart_doughnut ниже.
 */
function render_chart(array $cfg, int $height = 220): string {
    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $h    = max(80, $height);
    return '<div class="chart-wrap" style="height:' . $h . 'px"><canvas data-chart="' . htmlspecialchars($json, ENT_QUOTES) . '"></canvas></div>';
}

/**
 * Линейный график (line). $data — массив [label => value] или просто values.
 */
function chart_line(array $data, array $opts = []): string {
    if (empty($data)) return '<div class="sparkline-empty">Нет данных для графика</div>';
    $isAssoc = array_keys($data) !== range(0, count($data) - 1);
    $labels  = $isAssoc ? array_keys($data) : ($opts['labels'] ?? array_keys($data));
    $values  = array_values($data);

    // Если ключи — даты ISO (YYYY-MM-DD), форматируем по-русски
    if ($isAssoc && !empty($labels) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$labels[0])) {
        $labels = array_map(fn($d) => date('d.m', strtotime($d)), $labels);
    }

    return render_chart([
        'type'        => 'line',
        'labels'      => array_values($labels),
        'values'      => array_map('floatval', $values),
        'label'       => $opts['label']       ?? 'Выручка',
        'color'       => $opts['color']       ?? '#2563eb',
        'fill'        => $opts['fill']        ?? true,
        'avg'         => $opts['avg']         ?? true,
        'valueSuffix' => $opts['valueSuffix'] ?? 'руб.',
    ], $opts['height'] ?? 240);
}

/**
 * Столбчатая диаграмма. $data — массив [label => value].
 */
function chart_bar(array $data, array $opts = []): string {
    if (empty($data)) return '<div class="sparkline-empty">Нет данных</div>';
    return render_chart([
        'type'         => 'bar',
        'labels'       => array_keys($data),
        'values'       => array_map('floatval', array_values($data)),
        'label'        => $opts['label']        ?? 'Выручка',
        'color'        => $opts['color']        ?? '#2563eb',
        'avg'          => $opts['avg']          ?? true,
        'highlightMax' => $opts['highlightMax'] ?? true,
        'valueSuffix'  => $opts['valueSuffix']  ?? 'руб.',
    ], $opts['height'] ?? 280);
}

/**
 * Круговая диаграмма (doughnut). $data — [label => value].
 */
function chart_doughnut(array $data, array $opts = []): string {
    if (empty($data)) return '<div class="sparkline-empty">Нет данных</div>';
    return render_chart([
        'type'        => 'doughnut',
        'labels'      => array_keys($data),
        'values'      => array_map('floatval', array_values($data)),
        'valueSuffix' => $opts['valueSuffix'] ?? 'руб.',
        'colors'      => $opts['colors']      ?? null,
    ], $opts['height'] ?? 260);
}

function render_sparkline(array $values, int $width = 600, int $height = 80, array $opts = []): string {
    if (empty($values)) {
        return '<div class="sparkline-empty">Нет данных для графика</div>';
    }
    $color    = $opts['color']    ?? '#2563eb';
    $fillCol  = $opts['fill']     ?? 'rgba(37,99,235,0.12)';
    $padding  = $opts['padding']  ?? 8;

    $vals = array_values($values);
    $n = count($vals);
    $max = max($vals);
    $min = min(min($vals), 0);
    if ($max == $min) $max = $min + 1;

    $stepX = $n > 1 ? ($width - 2 * $padding) / ($n - 1) : 0;
    $rangeY = $max - $min;
    $h = $height - 2 * $padding;

    $points = [];
    foreach ($vals as $i => $v) {
        $x = $padding + $i * $stepX;
        $y = $padding + $h - (($v - $min) / $rangeY) * $h;
        $points[] = round($x, 2) . ',' . round($y, 2);
    }
    $polyline = implode(' ', $points);

    // Полигон для заливки (с замыканием по нижней границе)
    $first = explode(',', $points[0]);
    $last  = explode(',', $points[$n - 1]);
    $poly = $polyline . ' ' . $last[0] . ',' . ($padding + $h) . ' ' . $first[0] . ',' . ($padding + $h);

    // Точки максимума и минимума для визуального якоря
    $maxIdx = 0; $minIdx = 0;
    foreach ($vals as $i => $v) {
        if ($v > $vals[$maxIdx]) $maxIdx = $i;
        if ($v < $vals[$minIdx]) $minIdx = $i;
    }
    $maxPt = explode(',', $points[$maxIdx]);
    $minPt = explode(',', $points[$minIdx]);

    return <<<SVG
<svg class="sparkline" viewBox="0 0 $width $height" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
    <polygon points="$poly" fill="$fillCol" stroke="none"/>
    <polyline points="$polyline" fill="none" stroke="$color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
    <circle cx="{$maxPt[0]}" cy="{$maxPt[1]}" r="3.5" fill="$color" vector-effect="non-scaling-stroke"/>
    <circle cx="{$minPt[0]}" cy="{$minPt[1]}" r="3" fill="#ffffff" stroke="$color" stroke-width="1.5" vector-effect="non-scaling-stroke"/>
</svg>
SVG;
}

/**
 * Inline-SVG bar chart (вертикальные столбики).
 * @param array $items [label => value]
 */
function render_bar_chart(array $items, int $width = 600, int $height = 200, array $opts = []): string {
    if (empty($items)) return '<div class="sparkline-empty">Нет данных</div>';

    $color    = $opts['color']    ?? '#2563eb';
    $padding  = $opts['padding']  ?? 24;
    $labelH   = 18;
    $h = $height - 2 * $padding - $labelH;

    $vals = array_values($items);
    $labels = array_keys($items);
    $n = count($vals);
    $max = max(max($vals), 1);

    $gap = 6;
    $barW = ($width - 2 * $padding - $gap * ($n - 1)) / $n;

    $svg = '<svg class="bar-chart" viewBox="0 0 ' . $width . ' ' . $height . '" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">';
    foreach ($vals as $i => $v) {
        $bh = $max > 0 ? ($v / $max) * $h : 0;
        $x = $padding + $i * ($barW + $gap);
        $y = $padding + $h - $bh;
        $label = htmlspecialchars((string)$labels[$i]);
        $svg .= sprintf(
            '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="3" fill="%s"><title>%s: %s</title></rect>',
            $x, $y, $barW, max(0.5, $bh), $color, $label, format_money($v, 0)
        );
        $cx = $x + $barW / 2;
        $svg .= sprintf(
            '<text x="%.2f" y="%.2f" text-anchor="middle" font-size="12" fill="#64748b">%s</text>',
            $cx, $padding + $h + 14, $label
        );
    }
    $svg .= '</svg>';
    return $svg;
}
