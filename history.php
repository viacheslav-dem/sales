<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';
require_login();

$pdo = db();

// ── Параметры ──────────────────────────────────────────────
// Сохранение фильтров между визитами реализовано клиентским
// localStorage в layout.php (см. layout_header(..., 'history')).
// Сервер просто читает GET и применяет дефолты, если параметров нет.
$from      = $_GET['from']      ?? date('Y-m-01');
$to        = $_GET['to']        ?? date('Y-m-d');
$catId     = isset($_GET['cat']) && $_GET['cat'] !== '' ? (int)$_GET['cat'] : null;
$productQ  = trim((string)($_GET['q'] ?? ''));        // подстрока для поиска по названию товара
$type      = $_GET['type']      ?? 'all';      // all | sale | return
$sort      = $_GET['sort']      ?? 'date_desc'; // date_desc|date_asc|name_asc|name_desc|qty_desc|qty_asc|sum_desc|sum_asc

if (!valid_date($from)) $from = date('Y-m-01');
if (!valid_date($to))   $to   = date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];

// Сборка WHERE
$where  = ['s.sale_date BETWEEN :from AND :to'];
$params = [':from' => $from, ':to' => $to];

if ($catId !== null) {
    $where[] = 'p.category_id = :cat';
    $params[':cat'] = $catId;
}
if ($productQ !== '') {
    // mb_lower — UDF из db.php, которая корректно опускает кириллицу
    // в нижний регистр (встроенный SQLite LOWER понимает только ASCII).
    // ESCAPE — чтобы пользовательские «50%» не интерпретировались как wildcard.
    $where[] = "mb_lower(p.name) LIKE mb_lower(:pname) ESCAPE '\\'";
    $escaped = strtr($productQ, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    $params[':pname'] = '%' . $escaped . '%';
}
if ($type === 'sale') {
    $where[] = 's.is_return = 0';
} elseif ($type === 'return') {
    $where[] = 's.is_return = 1';
}
$whereSql = implode(' AND ', $where);

$orderMap = [
    'date_desc' => 's.sale_date DESC, COALESCE(s.sold_at, "") DESC, p.name ASC',
    'date_asc'  => 's.sale_date ASC, COALESCE(s.sold_at, "") ASC, p.name ASC',
    'name_asc'  => 'p.name ASC, s.sale_date DESC',
    'name_desc' => 'p.name DESC, s.sale_date DESC',
    'qty_desc'  => 's.quantity DESC, s.sale_date DESC',
    'qty_asc'   => 's.quantity ASC, s.sale_date DESC',
    'sum_desc'  => 's.amount DESC, s.sale_date DESC',
    'sum_asc'   => 's.amount ASC, s.sale_date DESC',
];
$orderBy = $orderMap[$sort] ?? $orderMap['date_desc'];

// Пагинация
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$cntSql = "SELECT COUNT(*) FROM sales s INNER JOIN products p ON p.id = s.product_id WHERE $whereSql";
$cntStmt = $pdo->prepare($cntSql);
$cntStmt->execute($params);
$totalCount = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = <<<SQL
    SELECT
        s.sale_date,
        s.sold_at,
        s.payment_method,
        s.note,
        s.is_return,
        s.quantity,
        s.base_price,
        s.unit_price,
        s.amount,
        s.discount_amount,
        s.original_sale_id,
        o.sale_date AS orig_sale_date,
        p.id AS pid,
        p.name AS product_name,
        c.name AS category_name
    FROM sales s
    INNER JOIN products p ON p.id = s.product_id
    LEFT  JOIN categories c ON c.id = p.category_id
    LEFT  JOIN sales o ON o.id = s.original_sale_id
    WHERE $whereSql
    ORDER BY $orderBy
    LIMIT :lim OFFSET :off
SQL;
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

// Итоги по фильтрам (без LIMIT). Считаем продажи и возвраты раздельно —
// в шапке таблицы покажем разбивку «Продано / Возвращено / Чистая выручка»,
// в строках возврата суммы будут положительными, без знака минус.
$totSql = <<<SQL
    SELECT
        COALESCE(SUM(CASE WHEN s.is_return = 0 THEN s.quantity ELSE 0 END), 0) AS sold_qty,
        COALESCE(SUM(CASE WHEN s.is_return = 0 THEN s.amount   ELSE 0 END), 0) AS sold_sum,
        COALESCE(SUM(CASE WHEN s.is_return = 1 THEN s.quantity ELSE 0 END), 0) AS ret_qty,
        COALESCE(SUM(CASE WHEN s.is_return = 1 THEN s.amount   ELSE 0 END), 0) AS ret_sum,
        COALESCE(SUM(s.discount_amount * CASE WHEN s.is_return = 1 THEN -1 ELSE 1 END), 0) AS net_disc
    FROM sales s
    INNER JOIN products p ON p.id = s.product_id
    WHERE $whereSql
SQL;
$totStmt = $pdo->prepare($totSql);
$totStmt->execute($params);
$tot = $totStmt->fetch();
$soldQty = (int)$tot['sold_qty'];
$soldSum = (float)$tot['sold_sum'];
$retQty  = (int)$tot['ret_qty'];
$retSum  = (float)$tot['ret_sum'];
$netSum  = $soldSum - $retSum;
$netQty  = $soldQty - $retQty;
$netDisc = (float)$tot['net_disc'];

// Список категорий для select
$allCategories = list_categories($pdo);

// URL-helper для пагинации/сортировки с сохранением фильтров
function buildUrl(array $overrides = []): string {
    $q = array_merge($_GET, $overrides);
    return 'history.php?' . http_build_query($q);
}

$weekdayShort = weekday_names_short();

// Активные фильтры — для чипов над таблицей
$activeChips = [];
if ($catId !== null) {
    foreach ($allCategories as $c) {
        if ((int)$c['id'] === $catId) {
            $activeChips[] = ['label' => 'Категория: ' . $c['name'], 'remove' => buildUrl(['cat' => '', 'page' => 1])];
            break;
        }
    }
}
if ($productQ !== '') {
    $activeChips[] = ['label' => 'Товар: ' . $productQ, 'remove' => buildUrl(['q' => '', 'page' => 1])];
}
if ($type !== 'all') {
    $activeChips[] = [
        'label'  => $type === 'sale' ? 'Только продажи' : 'Только возвраты',
        'remove' => buildUrl(['type' => 'all', 'page' => 1]),
    ];
}

layout_header('История продаж', true, 'history');
?>
<h1 class="page-title">История продаж</h1>

<!-- Фильтры -->
<div class="card card-pad-sm">
    <form method="get" id="hist-form" data-auto-filter>
        <div class="filters-grid">
            <div class="filter-item filter-item--wide" role="group" aria-labelledby="hist-period-label">
                <span class="filter-group-label" id="hist-period-label">Период</span>
                <div class="filters-date-range">
                    <input type="date" id="hist-from" name="from" value="<?= htmlspecialchars($from) ?>" aria-label="Период с">
                    <span aria-hidden="true">—</span>
                    <input type="date" id="hist-to" name="to" value="<?= htmlspecialchars($to) ?>" aria-label="Период по">
                </div>
            </div>
            <div class="filter-item">
                <label for="hist-cat">Категория</label>
                <select id="hist-cat" name="cat">
                    <option value="">Все</option>
                    <?php foreach ($allCategories as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= (int)$c['id'] === $catId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item filter-item--wide">
                <label for="hist-q">Поиск товара</label>
                <input type="search" id="hist-q" name="q"
                       value="<?= htmlspecialchars($productQ) ?>"
                       placeholder="Часть наименования…"
                       autocomplete="off">
            </div>
            <div class="filter-item">
                <label for="hist-type">Тип</label>
                <select id="hist-type" name="type">
                    <option value="all"    <?= $type === 'all'    ? 'selected' : '' ?>>Продажи и возвраты</option>
                    <option value="sale"   <?= $type === 'sale'   ? 'selected' : '' ?>>Только продажи</option>
                    <option value="return" <?= $type === 'return' ? 'selected' : '' ?>>Только возвраты</option>
                </select>
            </div>
            <?php if ($sort !== 'date_desc'): ?>
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
            <?php endif; ?>
            <div class="filter-item filter-actions">
                <?php
                    // Сбросить = вернуть дефолтный период (начало месяца..сегодня)
                    // и снести все остальные фильтры. Явно передаём from/to в URL,
                    // иначе сохранённый в сессии период «прилипнет» обратно.
                    $resetUrl = 'history.php?' . http_build_query([
                        'from' => date('Y-m-01'),
                        'to'   => date('Y-m-d'),
                    ]);
                ?>
                <a href="<?= htmlspecialchars($resetUrl) ?>" class="btn btn-secondary"><?= icon('x', 16) ?>Сбросить</a>
                <?php if (!empty($rows)): ?>
                <a href="export.php?from=<?= $from ?>&to=<?= $to ?>&group=product<?= $catId !== null ? '&cat=' . $catId : '' ?>"
                   class="btn btn-secondary"><?= icon('download', 16) ?>Экспорт</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Сводка: продажи и возвраты раздельно, плюс чистая выручка -->
<div class="summary-box">
    <div class="summary-item summary-item--sm">
        <div class="val"><?= format_money($soldSum) ?> руб.</div>
        <div class="lbl">Продано · <?= format_qty($soldQty) ?> шт.</div>
    </div>
    <?php if ($retQty > 0): ?>
    <div class="summary-item summary-item--sm summary-warn">
        <div class="val val-warn">−<?= format_money($retSum) ?> руб.</div>
        <div class="lbl">Возвраты · <?= format_qty($retQty) ?> шт.</div>
    </div>
    <?php endif; ?>
    <div class="summary-item summary-item--sm accent">
        <div class="val"><?= format_money($netSum) ?> руб.</div>
        <div class="lbl">Чистая выручка · <?= format_qty($netQty) ?> шт.</div>
    </div>
    <?php if (abs($netDisc) > 0.005): ?>
    <div class="summary-item summary-item--sm summary-warn">
        <div class="val val-warn"><?= format_money($netDisc) ?> руб.</div>
        <div class="lbl">Скидки</div>
    </div>
    <?php endif; ?>
</div>

<!-- Таблица истории -->
<?php
/**
 * Хелпер: ссылка-сортировка для шапки. Тот же приём, что в report.php —
 * клик переключает asc/desc, активная колонка подсвечивается стрелкой.
 *
 * Для каждого ключа задаётся «дефолтное направление при первом клике»:
 *   • date — desc (сначала свежие)
 *   • name — asc  (А→Я)
 *   • qty/sum — desc (сначала большие)
 */
$sortLink = function (string $label, string $key) use ($sort) {
    $asc  = $key . '_asc';
    $desc = $key . '_desc';
    $isActive   = ($sort === $asc || $sort === $desc);
    $defaultDir = ($key === 'name') ? $asc : $desc;
    if (!$isActive) {
        $next  = $defaultDir;
        $arrow = '';
    } else {
        $next  = $sort === $desc ? $asc : $desc;
        $arrow = $sort === $desc ? ' ↓' : ' ↑';
    }
    // Сохраняем все остальные query-параметры (фильтры, страницу), меняем только sort
    $url = htmlspecialchars(buildUrl(['sort' => $next, 'page' => 1]));
    $cls = 'sortable' . ($isActive ? ' is-sorted' : '');
    return [$cls, '<a href="' . $url . '">' . htmlspecialchars($label) . $arrow . '</a>'];
};
?>
<div class="card card-flush">
<?php if ($activeChips || $totalCount > 0): ?>
<div class="active-filters">
    <span>Найдено записей: <strong><?= format_qty($totalCount) ?></strong></span>
    <?php foreach ($activeChips as $chip): ?>
        <span class="chip">
            <?= htmlspecialchars($chip['label']) ?>
            <a href="<?= htmlspecialchars($chip['remove']) ?>" title="Убрать фильтр" aria-label="Убрать фильтр">×</a>
        </span>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (empty($rows)): ?>
    <div class="empty-cell">Нет записей за выбранные фильтры.</div>
<?php else: ?>
<div class="history-table-wrap">
<table class="table-cols history-table">
    <thead>
        <?php
            [$dateCls, $dateLink] = $sortLink('Дата',   'date');
            [$nameCls, $nameLink] = $sortLink('Товар',  'name');
            [$qtyCls,  $qtyLink ] = $sortLink('Кол-во', 'qty');
            [$sumCls,  $sumLink ] = $sortLink('Сумма',  'sum');
        ?>
        <tr>
            <th class="col-w-140 <?= $dateCls ?>"><?= $dateLink ?></th>
            <th class="<?= $nameCls ?>"><?= $nameLink ?></th>
            <th class="col-w-150">Категория</th>
            <th class="num col-w-70 <?= $qtyCls ?>"><?= $qtyLink ?></th>
            <th class="num col-w-90">Прайс</th>
            <th class="num col-w-90">Цена прод.</th>
            <th class="num col-w-90">Скидка</th>
            <th class="num col-divider col-w-110 <?= $sumCls ?>"><?= $sumLink ?></th>
            <th class="col-w-80">Оплата</th>
            <th class="col-w-80">Тип</th>
        </tr>
    </thead>
    <tbody>
    <?php
        $payLabels = ['cash' => 'Наличные', 'card' => 'Карта', 'other' => 'Другое'];
    ?>
    <?php foreach ($rows as $r): ?>
        <?php
            $isRet = (int)$r['is_return'] === 1;
            $sign  = $isRet ? -1 : 1;
            $disc  = (float)$r['discount_amount'] * $sign;
            $dt    = new DateTime($r['sale_date']);
            $wIdx  = (int)$dt->format('N');
            $parts = split_product_name($r['product_name']);
            $payCode = $r['payment_method'];
            $payLabel = $payCode && isset($payLabels[$payCode]) ? $payLabels[$payCode] : '—';
            $note = trim((string)($r['note'] ?? ''));
            $timeStr = '';
            if (!empty($r['sold_at']) && preg_match('/(\d{2}:\d{2})/', $r['sold_at'], $tm)) {
                $timeStr = $tm[1];
            }
        ?>
        <tr class="<?= $isRet ? 'is-return-row' : '' ?>">
            <td>
                <?= $dt->format('d.m.Y') ?>
                <span class="text-faint text-xs">· <?= $weekdayShort[$wIdx] ?><?= $timeStr ? ' · ' . $timeStr : '' ?></span>
            </td>
            <td>
                <span class="product-name__main"><?= htmlspecialchars($parts['main']) ?></span>
                <?php if ($parts['meta'] !== ''): ?>
                    <span class="product-name__meta"><?= htmlspecialchars($parts['meta']) ?></span>
                <?php endif; ?>
                <?php if ($isRet && !empty($r['orig_sale_date'])): ?>
                    <span class="text-faint text-xs">из продажи от <?= (new DateTime($r['orig_sale_date']))->format('d.m.Y') ?></span>
                <?php endif; ?>
                <?php if ($note !== ''): ?>
                    <div class="text-faint text-xs" title="<?= htmlspecialchars($note) ?>">📝 <?= htmlspecialchars(mb_strimwidth($note, 0, 80, '…')) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($r['category_name']): ?>
                    <span class="badge badge-primary"><?= htmlspecialchars($r['category_name']) ?></span>
                <?php else: ?>
                    <span class="text-faint text-sm"><em>без категории</em></span>
                <?php endif; ?>
            </td>
            <td class="num"><?= format_qty((int)$r['quantity']) ?></td>
            <td class="num text-muted"><?= format_money((float)$r['base_price']) ?></td>
            <td class="num"><?= format_money((float)$r['unit_price']) ?></td>
            <td class="num">
                <?php if (abs($disc) > 0.005): ?>
                    <span class="discount-cell"><?= ($disc >= 0 ? '−' : '+') . format_money(abs($disc)) ?></span>
                <?php else: ?>
                    <span class="text-faint">—</span>
                <?php endif; ?>
            </td>
            <td class="num fw-700 col-divider"><?= format_money((float)$r['amount']) ?></td>
            <td>
                <?php if ($payCode): ?>
                    <span class="badge badge-pay badge-pay-<?= htmlspecialchars($payCode) ?>"><?= $payLabel ?></span>
                <?php else: ?>
                    <span class="text-faint text-sm">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($isRet): ?>
                    <span class="badge badge-warning">возврат</span>
                <?php else: ?>
                    <span class="badge badge-success">продажа</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div><!-- /.history-table-wrap -->

<?php render_pagination(
    $page, $totalPages, $totalCount, $perPage, $offset,
    fn(int $p) => buildUrl(['page' => $p])
); ?>
<?php endif; ?>
</div>

<?php layout_footer(); ?>
