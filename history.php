<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';
require_login();

$pdo = db();

// ── Параметры ──────────────────────────────────────────────
$from      = $_GET['from']      ?? date('Y-m-01');
$to        = $_GET['to']        ?? date('Y-m-d');
$catId     = isset($_GET['cat']) && $_GET['cat'] !== '' ? (int)$_GET['cat'] : null;
$productId = isset($_GET['pid']) && $_GET['pid'] !== '' ? (int)$_GET['pid'] : null;
$type      = $_GET['type']      ?? 'all';      // all | sale | return
$sort      = $_GET['sort']      ?? 'date_desc'; // date_asc|date_desc|name|sum_desc

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
if ($productId !== null) {
    $where[] = 'p.id = :pid';
    $params[':pid'] = $productId;
}
if ($type === 'sale') {
    $where[] = 's.is_return = 0';
} elseif ($type === 'return') {
    $where[] = 's.is_return = 1';
}
$whereSql = implode(' AND ', $where);

$orderMap = [
    'date_desc' => 's.sale_date DESC, p.name ASC',
    'date_asc'  => 's.sale_date ASC, p.name ASC',
    'name'      => 'p.name ASC, s.sale_date DESC',
    'sum_desc'  => 's.amount DESC',
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
        s.is_return,
        s.quantity,
        s.base_price,
        s.unit_price,
        s.amount,
        p.id AS pid,
        p.name AS product_name,
        c.name AS category_name
    FROM sales s
    INNER JOIN products p ON p.id = s.product_id
    LEFT  JOIN categories c ON c.id = p.category_id
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

// Итоги по фильтрам (без LIMIT)
$totSql = <<<SQL
    SELECT
        COALESCE(SUM(s.quantity * CASE WHEN s.is_return = 1 THEN -1 ELSE 1 END), 0) AS net_qty,
        COALESCE(SUM(s.amount   * CASE WHEN s.is_return = 1 THEN -1 ELSE 1 END), 0) AS net_sum,
        COALESCE(SUM((s.quantity * s.base_price - s.amount) * CASE WHEN s.is_return = 1 THEN -1 ELSE 1 END), 0) AS net_disc
    FROM sales s
    INNER JOIN products p ON p.id = s.product_id
    WHERE $whereSql
SQL;
$totStmt = $pdo->prepare($totSql);
$totStmt->execute($params);
$tot = $totStmt->fetch();

// Списки для select
$allCategories = list_categories($pdo);
$allProducts   = list_products_simple($pdo);

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
if ($productId !== null && isset($allProducts[$productId])) {
    $activeChips[] = ['label' => 'Товар: ' . $allProducts[$productId], 'remove' => buildUrl(['pid' => '', 'page' => 1])];
}
if ($type !== 'all') {
    $activeChips[] = [
        'label'  => $type === 'sale' ? 'Только продажи' : 'Только возвраты',
        'remove' => buildUrl(['type' => 'all', 'page' => 1]),
    ];
}

layout_header('История продаж', true);
?>
<h1 class="page-title">История продаж</h1>

<!-- Фильтры -->
<div class="card card-pad-sm">
    <form method="get" id="hist-form" data-auto-filter>
        <div class="filters-grid">
            <div class="filter-item filter-item--wide">
                <label>Период</label>
                <div class="filters-date-range">
                    <input type="date" id="hist-from" name="from" value="<?= htmlspecialchars($from) ?>">
                    <span>—</span>
                    <input type="date" id="hist-to" name="to" value="<?= htmlspecialchars($to) ?>">
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
                <label for="hist-pid">Товар</label>
                <select id="hist-pid" name="pid">
                    <option value="">Все товары</option>
                    <?php foreach ($allProducts as $pid => $pname): ?>
                        <option value="<?= $pid ?>" <?= (int)$pid === $productId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <label for="hist-type">Тип</label>
                <select id="hist-type" name="type">
                    <option value="all"    <?= $type === 'all'    ? 'selected' : '' ?>>Продажи и возвраты</option>
                    <option value="sale"   <?= $type === 'sale'   ? 'selected' : '' ?>>Только продажи</option>
                    <option value="return" <?= $type === 'return' ? 'selected' : '' ?>>Только возвраты</option>
                </select>
            </div>
            <div class="filter-item">
                <label for="hist-sort">Сортировка</label>
                <select id="hist-sort" name="sort">
                    <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Дата (новые сверху)</option>
                    <option value="date_asc"  <?= $sort === 'date_asc'  ? 'selected' : '' ?>>Дата (старые сверху)</option>
                    <option value="name"      <?= $sort === 'name'      ? 'selected' : '' ?>>По названию</option>
                    <option value="sum_desc"  <?= $sort === 'sum_desc'  ? 'selected' : '' ?>>По сумме</option>
                </select>
            </div>
            <div class="filter-item filter-actions">
                <a href="history.php" class="btn btn-secondary"><?= icon('x', 16) ?>Сбросить</a>
                <?php if (!empty($rows)): ?>
                <a href="export.php?from=<?= $from ?>&to=<?= $to ?>&group=product<?= $catId !== null ? '&cat=' . $catId : '' ?>"
                   class="btn btn-success"><?= icon('download', 16) ?>Экспорт</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Сводка (компактная, без «Записей в выборке») -->
<div class="summary-box">
    <div class="summary-item summary-item--sm">
        <div class="val"><?= format_qty((int)$tot['net_qty']) ?> шт.</div>
        <div class="lbl">Чистое количество</div>
    </div>
    <div class="summary-item summary-item--sm accent">
        <div class="val"><?= format_money((float)$tot['net_sum']) ?> руб.</div>
        <div class="lbl">Чистая выручка</div>
    </div>
    <?php if (abs((float)$tot['net_disc']) > 0.005): ?>
    <div class="summary-item summary-item--sm summary-warn">
        <div class="val val-warn"><?= format_money((float)$tot['net_disc']) ?> руб.</div>
        <div class="lbl">Скидки</div>
    </div>
    <?php endif; ?>
</div>

<!-- Таблица истории -->
<div class="card card-flush table-scroll">
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
<table class="table-cols history-table">
    <thead>
        <tr>
            <th style="width:130px">Дата</th>
            <th>Товар</th>
            <th style="width:170px">Категория</th>
            <th class="num" style="width:70px">Кол-во</th>
            <th class="num" style="width:90px">Прайс</th>
            <th class="num" style="width:90px">Цена прод.</th>
            <th class="num" style="width:90px">Скидка</th>
            <th class="num col-divider" style="width:120px">Сумма</th>
            <th style="width:90px">Тип</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <?php
            $isRet = (int)$r['is_return'] === 1;
            $sign  = $isRet ? -1 : 1;
            $disc  = ((int)$r['quantity'] * (float)$r['base_price'] - (float)$r['amount']) * $sign;
            $dt    = new DateTime($r['sale_date']);
            $wIdx  = (int)$dt->format('N');
            $parts = split_product_name($r['product_name']);
        ?>
        <tr class="<?= $isRet ? 'is-return-row' : '' ?>">
            <td>
                <?= $dt->format('d.m.Y') ?>
                <span class="text-faint text-xs">· <?= $weekdayShort[$wIdx] ?></span>
            </td>
            <td>
                <span class="product-name__main"><?= htmlspecialchars($parts['main']) ?></span>
                <?php if ($parts['meta'] !== ''): ?>
                    <span class="product-name__meta"><?= htmlspecialchars($parts['meta']) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($r['category_name']): ?>
                    <span class="badge badge-primary"><?= htmlspecialchars($r['category_name']) ?></span>
                <?php else: ?>
                    <span class="text-faint text-sm"><em>без категории</em></span>
                <?php endif; ?>
            </td>
            <td class="num"><?= ($isRet ? '−' : '') . format_qty((int)$r['quantity']) ?></td>
            <td class="num text-muted"><?= format_money((float)$r['base_price']) ?></td>
            <td class="num"><?= format_money((float)$r['unit_price']) ?></td>
            <td class="num">
                <?php if (abs($disc) > 0.005): ?>
                    <span class="discount-cell"><?= ($disc >= 0 ? '−' : '+') . format_money(abs($disc)) ?></span>
                <?php else: ?>
                    <span class="text-faint">—</span>
                <?php endif; ?>
            </td>
            <td class="num fw-700 col-divider"><?= ($isRet ? '−' : '') . format_money((float)$r['amount']) ?></td>
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

<?php if ($totalPages > 1): ?>
<div class="pagination-wrap">
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="<?= buildUrl(['page' => 1]) ?>">«</a>
            <a href="<?= buildUrl(['page' => $page - 1]) ?>">‹</a>
        <?php endif; ?>
        <?php
            $range = 2;
            $start = max(1, $page - $range);
            $end   = min($totalPages, $page + $range);
            if ($start > 1): ?>
            <a href="<?= buildUrl(['page' => 1]) ?>">1</a>
            <?php if ($start > 2): ?><span class="dots">…</span><?php endif; ?>
        <?php endif; ?>
        <?php for ($p2 = $start; $p2 <= $end; $p2++): ?>
            <?php if ($p2 === $page): ?>
                <span class="current"><?= $p2 ?></span>
            <?php else: ?>
                <a href="<?= buildUrl(['page' => $p2]) ?>"><?= $p2 ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?><span class="dots">…</span><?php endif; ?>
            <a href="<?= buildUrl(['page' => $totalPages]) ?>"><?= $totalPages ?></a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
            <a href="<?= buildUrl(['page' => $page + 1]) ?>">›</a>
            <a href="<?= buildUrl(['page' => $totalPages]) ?>">»</a>
        <?php endif; ?>
        <span class="filter-info">
            Стр. <?= $page ?> из <?= $totalPages ?>
            (<?= $offset + 1 ?>–<?= min($offset + $perPage, $totalCount) ?> из <?= $totalCount ?>)
        </span>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
</div>

<?php layout_footer(); ?>
