<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';
require_login();

$pdo   = db();
$today = date('Y-m-d');

/**
 * Считает KPI и сравнение с предыдущим аналогичным периодом.
 * $shift — модификатор strtotime для предыдущего периода (например '-1 week').
 * Текущий период клипуется до сегодня (нет смысла смотреть «будущие» данные).
 */
function dash_kpi(PDO $pdo, string $from, string $to, string $shift): array {
    $today = date('Y-m-d');
    $effTo = $to > $today ? $today : $to;
    if ($effTo < $from) {
        return ['revenue'=>0, 'qty'=>0, 'days_with_sales'=>0, 'discount'=>0, 'avg_per_day'=>0, 'delta'=>0];
    }
    $cur = kpi_for_period($pdo, $from, $effTo);

    // Тот же диапазон, сдвинутый по календарю (не по дням)
    $pFrom = date('Y-m-d', strtotime($shift, strtotime($from)));
    $pTo   = date('Y-m-d', strtotime($shift, strtotime($effTo)));
    $prev  = kpi_for_period($pdo, $pFrom, $pTo);

    $delta = $prev['revenue'] == 0
        ? ($cur['revenue'] == 0 ? 0 : 100)
        : ($cur['revenue'] - $prev['revenue']) / $prev['revenue'] * 100;

    $cur['delta']  = $delta;
    $cur['p_from'] = $pFrom;
    $cur['p_to']   = $pTo;
    return $cur;
}

// ── Пресеты для KPI-карточек ───────────────────────────────
[$tFrom, $tTo]    = period_preset('today');
[$yFrom, $yTo]    = period_preset('yesterday');
[$wFrom, $wTo]    = period_preset('this_week');
[$mFrom, $mTo]    = period_preset('this_month');
[$yrFrom, $yrTo]  = period_preset('this_year');

// Каждая карточка сравнивается с аналогичным сдвигом по календарю.
// Это решает проблему «1–7 апреля vs полный март» — теперь сравнивается
// «1–7 апреля vs 1–7 марта» (сдвиг -1 month).
$kpiToday = dash_kpi($pdo, $tFrom,  $tTo,  '-1 day');
$kpiYday  = dash_kpi($pdo, $yFrom,  $yTo,  '-1 day');
$kpiWeek  = dash_kpi($pdo, $wFrom,  $wTo,  '-1 week');
$kpiMonth = dash_kpi($pdo, $mFrom,  $mTo,  '-1 month');
$kpiYear  = dash_kpi($pdo, $yrFrom, $yrTo, '-1 year');

// ── Sparkline за последние 30 дней ─────────────────────────
[$spFrom, $spTo] = period_preset('last_30');
$sparkData = daily_sparkline($pdo, $spFrom, $spTo);

// ── Топ-5 товаров за последние 30 дней ─────────────────────
$top5 = top_products($pdo, $spFrom, $spTo, 5, 'revenue');
$top5Max = !empty($top5) ? max(array_map(fn($r) => (float)$r['net_sum'], $top5)) : 0;

// ── По дням недели за последние 30 дней ────────────────────
$byWday = weekday_breakdown($pdo, $spFrom, $spTo);
$wdNames = weekday_names_short();

// ── Товары без продаж за 30 дней (среди активных) ──────────
$inactiveStmt = $pdo->prepare(<<<SQL
    SELECT p.id, p.name,
        COALESCE(c.name, '— без категории —') AS category_name,
        (SELECT MAX(s.sale_date) FROM sales s WHERE s.product_id = p.id) AS last_sale
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.is_active = 1
      AND NOT EXISTS (
        SELECT 1 FROM sales s
        WHERE s.product_id = p.id AND s.sale_date BETWEEN ? AND ?
      )
    ORDER BY CASE WHEN last_sale IS NULL THEN 1 ELSE 0 END, last_sale DESC
    LIMIT 8
SQL);
$inactiveStmt->execute([$spFrom, $spTo]);
$inactive = $inactiveStmt->fetchAll();

layout_header('Дашборд');
?>
<h1 class="page-title">Дашборд</h1>

<!-- KPI карточки (кликабельные → переход в отчёт с тем же пресетом) -->
<div class="summary-box dashboard-kpi">
    <a href="report.php?preset=today" class="summary-item kpi-today" title="Открыть отчёт за сегодня">
        <div class="lbl">Сегодня <small>· <?= date('d.m', strtotime($tFrom)) ?></small></div>
        <div class="val"><?= format_money($kpiToday['revenue']) ?> <small>руб.</small></div>
        <div class="kpi-meta"><?= format_qty($kpiToday['qty']) ?> шт.</div>
        <div class="pct-delta <?= pct_class($kpiToday['delta']) ?>"><?= format_pct($kpiToday['delta']) ?> <small>vs вчера</small></div>
    </a>
    <a href="report.php?preset=yesterday" class="summary-item kpi-yday">
        <div class="lbl">Вчера <small>· <?= date('d.m', strtotime($yFrom)) ?></small></div>
        <div class="val"><?= format_money($kpiYday['revenue']) ?> <small>руб.</small></div>
        <div class="kpi-meta"><?= format_qty($kpiYday['qty']) ?> шт.</div>
        <div class="pct-delta <?= pct_class($kpiYday['delta']) ?>"><?= format_pct($kpiYday['delta']) ?> <small>vs позавчера</small></div>
    </a>
    <a href="report.php?preset=this_week" class="summary-item kpi-week">
        <div class="lbl">Эта неделя</div>
        <div class="val"><?= format_money($kpiWeek['revenue']) ?> <small>руб.</small></div>
        <div class="kpi-meta"><?= format_qty($kpiWeek['qty']) ?> шт. · <?= $kpiWeek['days_with_sales'] ?> дн.</div>
        <div class="pct-delta <?= pct_class($kpiWeek['delta']) ?>"><?= format_pct($kpiWeek['delta']) ?> <small>vs прошлая</small></div>
    </a>
    <a href="report.php?preset=this_month" class="summary-item kpi-month">
        <div class="lbl">Этот месяц</div>
        <div class="val"><?= format_money($kpiMonth['revenue']) ?> <small>руб.</small></div>
        <div class="kpi-meta"><?= format_qty($kpiMonth['qty']) ?> шт. · <?= $kpiMonth['days_with_sales'] ?> дн.</div>
        <div class="pct-delta <?= pct_class($kpiMonth['delta']) ?>"><?= format_pct($kpiMonth['delta']) ?> <small>vs тот же период мес. назад</small></div>
    </a>
    <a href="report.php?preset=this_year" class="summary-item kpi-year">
        <div class="lbl">Этот год</div>
        <div class="val"><?= format_money($kpiYear['revenue']) ?> <small>руб.</small></div>
        <div class="kpi-meta"><?= format_qty($kpiYear['qty']) ?> шт.</div>
        <div class="pct-delta <?= pct_class($kpiYear['delta']) ?>"><?= format_pct($kpiYear['delta']) ?> <small>vs тот же период год назад</small></div>
    </a>
</div>

<!-- Sparkline 30 дней -->
<div class="card">
    <div class="dashboard-card-header">
        <div class="card-title" style="margin-bottom:0">Динамика выручки за 30 дней</div>
        <a href="report.php?preset=last_30" class="btn btn-ghost btn-sm">Подробнее →</a>
    </div>
    <?php if (array_sum($sparkData) > 0): ?>
        <?= chart_line($sparkData, ['height' => 240, 'label' => 'Выручка']) ?>
    <?php else: ?>
        <div class="sparkline-empty">Нет продаж за последние 30 дней</div>
    <?php endif; ?>
</div>

<div class="dashboard-grid">
    <!-- Топ-5 товаров -->
    <div class="card">
        <div class="dashboard-card-header">
            <div class="card-title" style="margin-bottom:0">Топ-5 товаров за 30 дней</div>
            <a href="report.php?preset=last_30" class="btn btn-ghost btn-sm">Все →</a>
        </div>
        <?php if (empty($top5)): ?>
            <div class="empty-cell">Нет продаж за период</div>
        <?php else: ?>
        <div class="top-products">
            <?php foreach ($top5 as $i => $t):
                $share = $top5Max > 0 ? ((float)$t['net_sum'] / $top5Max) * 100 : 0;
            ?>
            <div class="top-row top-row-compact">
                <div class="top-rank">#<?= $i + 1 ?></div>
                <div class="top-name"><?= htmlspecialchars($t['name']) ?></div>
                <div class="top-bar-wrap"><div class="top-bar" style="width:<?= round($share, 1) ?>%"></div></div>
                <div class="top-sum"><?= format_money((float)$t['net_sum'], 0) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- По дням недели -->
    <div class="card">
        <div class="dashboard-card-header">
            <div class="card-title" style="margin-bottom:0">По дням недели · 30 дней</div>
        </div>
        <?php if (array_sum($byWday) > 0): ?>
            <?php
                $bar = [];
                foreach ($wdNames as $idx => $name) $bar[$name] = $byWday[$idx] ?? 0;
            ?>
            <?= chart_bar($bar, ['height' => 220, 'label' => 'Выручка']) ?>
        <?php else: ?>
            <div class="sparkline-empty">Нет данных</div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($inactive)): ?>
<!-- Товары без продаж -->
<div class="card card-flush">
    <div class="dashboard-card-header" style="padding:var(--sp-4) var(--sp-5)">
        <div class="card-title" style="margin-bottom:0">Активные товары без продаж за последние 30 дней</div>
        <a href="report.php?preset=last_30" class="btn btn-ghost btn-sm">Открыть отчёт →</a>
    </div>
    <table>
        <thead>
            <tr>
                <th class="col-w-48">#</th>
                <th>Товар</th>
                <th>Категория</th>
                <th class="col-w-140">Последняя продажа</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inactive as $i => $r): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td class="text-muted text-sm"><?= htmlspecialchars($r['category_name']) ?></td>
                <td class="text-muted text-sm">
                    <?= $r['last_sale'] ? date('d.m.Y', strtotime($r['last_sale'])) : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php layout_footer(); ?>
