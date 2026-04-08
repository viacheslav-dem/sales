<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';
require_login();

$pdo = db();

// ── Параметры запроса ──────────────────────────────────────
$preset    = $_GET['preset']   ?? 'this_month';
$groupBy   = $_GET['group']    ?? 'product';   // product | category | day | weekday
$catId     = isset($_GET['cat']) && $_GET['cat'] !== '' ? (int)$_GET['cat'] : null;
$compare   = isset($_GET['compare']) && $_GET['compare'] === '1';
$sortBy    = $_GET['sort']     ?? 'sum_desc';  // name | sum_desc | sum_asc | qty_desc | qty_asc | discount_desc

$presetsList = period_presets_list();

if ($preset === 'custom') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');
    if (!valid_date($from)) $from = date('Y-m-01');
    if (!valid_date($to))   $to   = date('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];
} else {
    if (!isset($presetsList[$preset])) $preset = 'this_month';
    [$from, $to] = period_preset($preset);
}
// Сохранение фильтров между визитами — клиентское (localStorage),
// см. layout_header(..., 'report').

// ── Данные ─────────────────────────────────────────────────
$opts = ['group_by' => $groupBy];
if ($catId !== null) $opts['category_id'] = $catId;

// Сортировка применяется только к группировке "по товарам"
if ($groupBy === 'product') {
    $opts['order_by'] = $sortBy;
}

$report  = sales_in_range($pdo, $from, $to, $opts);
$rows    = $report['rows'];
$totals  = $report['totals'];

// Постсортировка для других группировок (массивами в PHP — данные уже маленькие)
if ($groupBy !== 'product') {
    $cmp = null;
    switch ($sortBy) {
        case 'sum_asc':       $cmp = fn($a, $b) => $a['net_sum']      <=> $b['net_sum']; break;
        case 'sum_desc':      $cmp = fn($a, $b) => $b['net_sum']      <=> $a['net_sum']; break;
        case 'qty_asc':       $cmp = fn($a, $b) => $a['net_qty']      <=> $b['net_qty']; break;
        case 'qty_desc':      $cmp = fn($a, $b) => $b['net_qty']      <=> $a['net_qty']; break;
        case 'discount_desc': $cmp = fn($a, $b) => abs((float)$b['net_discount']) <=> abs((float)$a['net_discount']); break;
    }
    if ($cmp) usort($rows, $cmp);
}

// KPI
$kpi = kpi_for_period($pdo, $from, $to, $catId !== null ? ['category_id' => $catId] : []);

// Сравнение с прошлым годом (опционально)
$kpiPrev = null;
$prevFrom = $prevTo = null;
if ($compare) {
    [$prevFrom, $prevTo] = shift_year_back($from, $to);
    $kpiPrev = kpi_for_period($pdo, $prevFrom, $prevTo, $catId !== null ? ['category_id' => $catId] : []);
}

// Мини-тренд относительно предыдущего равного периода (всегда, независимо от compare).
// Используется как короткая стрелка/процент рядом с основными KPI.
[$trendFrom, $trendTo] = shift_period_back($from, $to);
$kpiTrend = kpi_for_period($pdo, $trendFrom, $trendTo, $catId !== null ? ['category_id' => $catId] : []);

function pct_change(float $cur, float $prev): float {
    if ($prev == 0) return $cur == 0 ? 0 : 100;
    return ($cur - $prev) / $prev * 100;
}

// Топ-10 товаров за период
$top = top_products($pdo, $from, $to, 10, 'revenue');
$topMax = !empty($top) ? max(array_map(fn($r) => (float)$r['net_sum'], $top)) : 0;

// Sparkline по дням периода
$daily = daily_sparkline($pdo, $from, $to);

// Распределение по дням недели
$byWeekday = weekday_breakdown($pdo, $from, $to);

// Список категорий для select
$allCategories = list_categories($pdo);

// Человеческие метки
$dateFromDisplay = date('d.m.Y', strtotime($from));
$dateToDisplay   = date('d.m.Y', strtotime($to));
$periodLabel = $from === $to
    ? $dateFromDisplay
    : $dateFromDisplay . ' — ' . $dateToDisplay;

$groupNames = [
    'product'  => 'По товарам',
    'category' => 'По категориям',
    'day'      => 'По дням',
    'weekday'  => 'По дням недели',
];

$weekdayShort = weekday_names_short();

layout_header('Отчёт по продажам', true, 'report');
?>
<h1 class="page-title">Отчёт по продажам</h1>

<!-- Панель фильтров -->
<div class="card card-pad-sm report-filters">
    <form method="get" id="report-form" data-auto-filter>
        <div class="report-row">
            <div class="report-presets">
                <?php foreach ($presetsList as $key => $label): ?>
                    <label class="preset-chip <?= $preset === $key ? 'active' : '' ?>">
                        <input type="radio" name="preset" value="<?= $key ?>" <?= $preset === $key ? 'checked' : '' ?> data-auto-submit>
                        <?= htmlspecialchars($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="report-row">
            <?php if ($preset === 'custom'): ?>
                <div class="filter-item">
                    <label for="rep-from">От</label>
                    <input type="date" id="rep-from" name="from" value="<?= htmlspecialchars($from) ?>" data-auto-submit>
                </div>
                <div class="filter-item">
                    <label for="rep-to">До</label>
                    <input type="date" id="rep-to" name="to" value="<?= htmlspecialchars($to) ?>" data-auto-submit>
                </div>
            <?php endif; ?>

            <div class="filter-item">
                <label for="rep-group">Группировка</label>
                <select id="rep-group" name="group" data-auto-submit data-scroll-table>
                    <?php foreach ($groupNames as $g => $name): ?>
                        <option value="<?= $g ?>" <?= $groupBy === $g ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label for="rep-cat">Категория</label>
                <select id="rep-cat" name="cat" data-auto-submit data-scroll-table>
                    <option value="">Все</option>
                    <?php foreach ($allCategories as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= (int)$c['id'] === $catId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>&nbsp;</label>
                <label class="report-compare-toggle">
                    <input type="checkbox" name="compare" value="1" <?= $compare ? 'checked' : '' ?> data-auto-submit>
                    Сравнить с прошлым годом
                </label>
            </div>

            <?php if (!empty($rows)): ?>
            <div class="filter-item">
                <label>&nbsp;</label>
                <a href="export.php?from=<?= $from ?>&to=<?= $to ?>&group=<?= $groupBy ?><?= $catId !== null ? '&cat=' . $catId : '' ?>"
                   class="btn btn-secondary"><?= icon('download', 16) ?>Экспорт в Excel</a>
            </div>
            <?php endif; ?>
        </div>
    </form>
    <div class="report-period-label">
        Период: <strong><?= $periodLabel ?></strong>
        <?php if ($compare && $prevFrom): ?>
            &nbsp;·&nbsp;Сравнение: <?= date('d.m.Y', strtotime($prevFrom)) ?> — <?= date('d.m.Y', strtotime($prevTo)) ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Хелпер: собрать мини-тренд (стрелка + процент) относительно предыдущего периода
$miniTrend = function (float $cur, float $prev): string {
    $p   = pct_change($cur, $prev);
    $cls = pct_class($p);
    $arr = $p > 0.05 ? '↗' : ($p < -0.05 ? '↘' : '→');
    return '<div class="pct-delta ' . $cls . '">' . $arr . ' ' . format_pct($p)
         . ' <small>vs пред. период</small></div>';
};
?>
<!-- KPI карточки -->
<div class="summary-box summary-box--kpi">
    <div class="summary-item">
        <div class="val"><?= format_qty($kpi['qty']) ?> шт.</div>
        <div class="lbl">Продано</div>
        <?php if ($compare): $p = pct_change($kpi['qty'], $kpiPrev['qty']); ?>
            <div class="pct-delta <?= pct_class($p) ?>"><?= format_pct($p) ?> <small>vs <?= format_qty($kpiPrev['qty']) ?> шт.</small></div>
        <?php else: ?>
            <?= $miniTrend((float)$kpi['qty'], (float)$kpiTrend['qty']) ?>
        <?php endif; ?>
    </div>
    <div class="summary-item accent">
        <div class="val"><?= format_money($kpi['revenue']) ?> руб.</div>
        <div class="lbl">Выручка</div>
        <?php if ($compare): $p = pct_change($kpi['revenue'], $kpiPrev['revenue']); ?>
            <div class="pct-delta <?= pct_class($p) ?>"><?= format_pct($p) ?> <small>vs <?= format_money($kpiPrev['revenue']) ?> руб.</small></div>
        <?php else: ?>
            <?= $miniTrend((float)$kpi['revenue'], (float)$kpiTrend['revenue']) ?>
        <?php endif; ?>
    </div>
    <?php if ($kpi['discount'] > 0.005 || ($compare && $kpiPrev['discount'] > 0.005)): ?>
    <div class="summary-item summary-warn">
        <div class="val val-warn"><?= format_money($kpi['discount']) ?> руб.</div>
        <div class="lbl">Скидки</div>
        <?php if ($compare): $p = pct_change($kpi['discount'], $kpiPrev['discount']); ?>
            <div class="pct-delta <?= pct_class($p) ?>"><?= format_pct($p) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="summary-item">
        <div class="val"><?= $kpi['days_with_sales'] ?></div>
        <div class="lbl">Дней с продажами</div>
    </div>
    <div class="summary-item">
        <div class="val"><?= format_money($kpi['avg_per_day'], 0) ?> руб.</div>
        <div class="lbl">Средняя выручка/день</div>
        <?php if (!$compare): ?>
            <?= $miniTrend((float)$kpi['avg_per_day'], (float)$kpiTrend['avg_per_day']) ?>
        <?php endif; ?>
    </div>
</div>

<?php if (count($daily) > 1 && $kpi['revenue'] > 0): ?>
<!-- Динамика выручки по дням -->
<div class="card">
    <div class="card-title">Динамика выручки по дням</div>
    <?= chart_line($daily, ['height' => 260, 'label' => 'Выручка']) ?>
</div>
<?php endif; ?>

<?php if (!empty($top) && $kpi['revenue'] > 0): ?>
<div class="card">
    <div class="card-title">Топ-10 товаров за период</div>
    <div class="top-products">
        <?php foreach ($top as $i => $t):
            $share = $topMax > 0 ? ((float)$t['net_sum'] / $topMax) * 100 : 0;
            $parts = split_product_name($t['name']);
        ?>
            <div class="top-row">
                <div class="top-rank">#<?= $i + 1 ?></div>
                <div class="top-name" title="<?= htmlspecialchars($t['name']) ?>">
                    <span class="product-name__main"><?= htmlspecialchars($parts['main']) ?></span>
                    <?php if ($parts['meta'] !== ''): ?>
                        <span class="product-name__meta"><?= htmlspecialchars($parts['meta']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="top-bar-wrap">
                    <div class="top-bar" style="width:<?= round($share, 1) ?>%"></div>
                </div>
                <div class="top-qty"><?= format_qty((int)$t['net_qty']) ?>&nbsp;шт.</div>
                <div class="top-sum"><?= format_money((float)$t['net_sum']) ?>&nbsp;руб.</div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (array_sum($byWeekday) > 0): ?>
<div class="card">
    <div class="card-title">Распределение выручки по дням недели</div>
    <?php
        $bar = [];
        foreach ($weekdayShort as $idx => $name) $bar[$name] = $byWeekday[$idx] ?? 0;
    ?>
    <?= chart_bar($bar, ['height' => 280, 'label' => 'Выручка']) ?>
</div>
<?php endif; ?>

<!-- Таблица детализации -->
<?php
    // Максимум выручки по строкам — для share-bar (доля от лидера)
    $maxRowSum = 0;
    foreach ($rows as $r) {
        $v = (float)$r['net_sum'];
        if ($v > $maxRowSum) $maxRowSum = $v;
    }
    $totalSumAbs = max(0.0001, (float)$totals['sum']);

    /** Хелпер: ссылка-сортировка для шапки. Кликает — переключает asc/desc. */
    $sortLink = function (string $label, string $key) use ($sortBy) {
        // У каждого ключа есть 2 направления
        $asc  = $key . '_asc';
        $desc = $key . '_desc';
        $isActive = ($sortBy === $asc || $sortBy === $desc);
        // По умолчанию первый клик — desc (кроме name, там asc)
        $defaultDir = ($key === 'name') ? $asc : $desc;
        if (!$isActive) {
            $next = $defaultDir;
            $arrow = '';
        } else {
            $next  = $sortBy === $desc ? $asc : $desc;
            $arrow = $sortBy === $desc ? ' ↓' : ' ↑';
        }
        $url = htmlspecialchars(buildSortUrl($next));
        $cls = 'sortable' . ($isActive ? ' is-sorted' : '');
        return [$cls, '<a href="' . $url . '">' . htmlspecialchars($label) . $arrow . '</a>'];
    };
?>
<?php
function buildSortUrl(string $sort): string {
    $q = array_merge($_GET, ['sort' => $sort]);
    return 'report.php?' . http_build_query($q);
}
?>
<div class="card card-flush table-scroll report-table-wrap" id="report-table">
<?php if (empty($rows)): ?>
    <div class="empty-cell">Нет данных за выбранный период.</div>
<?php else: ?>
<table class="report-table table-cols">
    <thead>
        <?php
            [$nameCls, $nameLink] = $sortLink('Наименование товара', 'name');
            [$qtyCls,  $qtyLink ] = $sortLink('Кол-во', 'qty');
            [$discCls, $discLink] = $sortLink('Скидка', 'discount');
            [$sumCls,  $sumLink ] = $sortLink('Выручка', 'sum');
            [$catCls,  $catLink ] = $sortLink('Категория', 'name');
            [$dayCls,  $dayLink ] = $sortLink('День недели', 'name');
        ?>
        <?php if ($groupBy === 'product'): ?>
            <tr>
                <th class="col-w-36">#</th>
                <th class="<?= $nameCls ?>"><?= $nameLink ?></th>
                <th class="col-w-170">Категория</th>
                <th class="num col-w-90">Прайс</th>
                <th class="num col-w-80 <?= $qtyCls ?>"><?= $qtyLink ?></th>
                <th class="num col-w-100 <?= $discCls ?>"><?= $discLink ?></th>
                <th class="num col-divider col-w-120 <?= $sumCls ?>"><?= $sumLink ?></th>
                <th class="num col-w-60">Доля</th>
            </tr>
        <?php elseif ($groupBy === 'category'): ?>
            <tr>
                <th class="col-w-36">#</th>
                <th class="<?= $catCls ?>"><?= $catLink ?></th>
                <th class="num col-w-90 <?= $qtyCls ?>"><?= $qtyLink ?></th>
                <th class="num col-w-110 <?= $discCls ?>"><?= $discLink ?></th>
                <th class="num col-divider col-w-140 <?= $sumCls ?>"><?= $sumLink ?></th>
                <th class="num col-w-60">Доля</th>
            </tr>
        <?php elseif ($groupBy === 'day'): ?>
            <tr>
                <th class="col-w-36">#</th>
                <th>Дата</th>
                <th class="col-w-120">День недели</th>
                <th class="num col-w-90 <?= $qtyCls ?>"><?= $qtyLink ?></th>
                <th class="num col-w-110 <?= $discCls ?>"><?= $discLink ?></th>
                <th class="num col-divider col-w-140 <?= $sumCls ?>"><?= $sumLink ?></th>
                <th class="num col-w-60">Доля</th>
            </tr>
        <?php else: // weekday ?>
            <tr>
                <th class="<?= $dayCls ?>"><?= $dayLink ?></th>
                <th class="num col-w-90 <?= $qtyCls ?>"><?= $qtyLink ?></th>
                <th class="num col-w-110 <?= $discCls ?>"><?= $discLink ?></th>
                <th class="num col-divider col-w-140 <?= $sumCls ?>"><?= $sumLink ?></th>
                <th class="num col-w-60">Доля</th>
            </tr>
        <?php endif; ?>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
        <?php
            $hasDisc = abs((float)$r['net_discount']) > 0.005;
            $discTxt = $hasDisc
                ? '<span class="discount-cell">' . ((float)$r['net_discount'] >= 0 ? '−' : '+') . format_money(abs((float)$r['net_discount'])) . '</span>'
                : '<span class="text-faint">—</span>';
            $rowSum  = (float)$r['net_sum'];
            $share   = $totalSumAbs > 0 ? $rowSum / $totalSumAbs * 100 : 0;
            $barShare = $maxRowSum > 0 ? $rowSum / $maxRowSum * 100 : 0;
        ?>
        <?php if ($groupBy === 'product'): ?>
            <?php $parts = split_product_name($r['name']); ?>
            <tr>
                <td class="text-faint"><?= $i + 1 ?></td>
                <td>
                    <span class="product-name__main"><?= htmlspecialchars($parts['main']) ?></span>
                    <?php if ($parts['meta'] !== ''): ?>
                        <span class="product-name__meta"><?= htmlspecialchars($parts['meta']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($r['category_name'])): ?>
                        <span class="badge badge-primary"><?= htmlspecialchars($r['category_name']) ?></span>
                    <?php else: ?>
                        <span class="text-faint text-sm"><em>без категории</em></span>
                    <?php endif; ?>
                </td>
                <td class="num text-muted"><?= format_money((float)$r['catalog_price']) ?></td>
                <td class="num"><?= format_qty((int)$r['net_qty']) ?></td>
                <td class="num"><?= $discTxt ?></td>
                <td class="num fw-700 col-divider"><?= format_money($rowSum) ?></td>
                <td class="report-share-cell">
                    <div class="report-share-bar"><div class="report-share-bar__fill" style="width:<?= round($barShare, 1) ?>%"></div></div>
                    <div class="report-share-pct"><?= number_format($share, 1, '.', '') ?>%</div>
                </td>
            </tr>
        <?php elseif ($groupBy === 'category'): ?>
            <tr>
                <td class="text-faint"><?= $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                <td class="num"><?= format_qty((int)$r['net_qty']) ?></td>
                <td class="num"><?= $discTxt ?></td>
                <td class="num fw-700 col-divider"><?= format_money($rowSum) ?></td>
                <td class="report-share-cell">
                    <div class="report-share-bar"><div class="report-share-bar__fill" style="width:<?= round($barShare, 1) ?>%"></div></div>
                    <div class="report-share-pct"><?= number_format($share, 1, '.', '') ?>%</div>
                </td>
            </tr>
        <?php elseif ($groupBy === 'day'): ?>
            <?php
                $dt = new DateTime($r['day']);
                $wIdx = (int)$dt->format('N'); // 1=Mon..7=Sun
            ?>
            <tr>
                <td class="text-faint"><?= $i + 1 ?></td>
                <td><?= $dt->format('d.m.Y') ?></td>
                <td class="text-muted text-sm"><?= $weekdayShort[$wIdx] ?></td>
                <td class="num"><?= format_qty((int)$r['net_qty']) ?></td>
                <td class="num"><?= $discTxt ?></td>
                <td class="num fw-700 col-divider"><?= format_money($rowSum) ?></td>
                <td class="report-share-cell">
                    <div class="report-share-bar"><div class="report-share-bar__fill" style="width:<?= round($barShare, 1) ?>%"></div></div>
                    <div class="report-share-pct"><?= number_format($share, 1, '.', '') ?>%</div>
                </td>
            </tr>
        <?php else: // weekday ?>
            <?php
                $w = (int)$r['weekday'];
                $wKey = $w === 0 ? 7 : $w;
            ?>
            <tr>
                <td><strong><?= $weekdayShort[$wKey] ?></strong></td>
                <td class="num"><?= format_qty((int)$r['net_qty']) ?></td>
                <td class="num"><?= $discTxt ?></td>
                <td class="num fw-700 col-divider"><?= format_money($rowSum) ?></td>
                <td class="report-share-cell">
                    <div class="report-share-bar"><div class="report-share-bar__fill" style="width:<?= round($barShare, 1) ?>%"></div></div>
                    <div class="report-share-pct"><?= number_format($share, 1, '.', '') ?>%</div>
                </td>
            </tr>
        <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <?php
            $totalDiscTxt = abs($totals['discount']) > 0.005
                ? ($totals['discount'] >= 0 ? '−' : '+') . format_money(abs($totals['discount']))
                : '—';
        ?>
        <?php if ($groupBy === 'product'): ?>
            <tr>
                <td colspan="4">Итого</td>
                <td class="num"><?= format_qty($totals['qty']) ?></td>
                <td class="num"><?= $totalDiscTxt ?></td>
                <td class="num col-divider"><?= format_money($totals['sum']) ?></td>
                <td class="num">100%</td>
            </tr>
        <?php elseif ($groupBy === 'category'): ?>
            <tr>
                <td colspan="2">Итого</td>
                <td class="num"><?= format_qty($totals['qty']) ?></td>
                <td class="num"><?= $totalDiscTxt ?></td>
                <td class="num col-divider"><?= format_money($totals['sum']) ?></td>
                <td class="num">100%</td>
            </tr>
        <?php elseif ($groupBy === 'day'): ?>
            <tr>
                <td colspan="3">Итого</td>
                <td class="num"><?= format_qty($totals['qty']) ?></td>
                <td class="num"><?= $totalDiscTxt ?></td>
                <td class="num col-divider"><?= format_money($totals['sum']) ?></td>
                <td class="num">100%</td>
            </tr>
        <?php else: ?>
            <tr>
                <td>Итого</td>
                <td class="num"><?= format_qty($totals['qty']) ?></td>
                <td class="num"><?= $totalDiscTxt ?></td>
                <td class="num col-divider"><?= format_money($totals['sum']) ?></td>
                <td class="num">100%</td>
            </tr>
        <?php endif; ?>
    </tfoot>
</table>
<?php endif; ?>
</div>

<script src="assets/report.js?v=<?= asset_v('assets/report.js') ?>" defer></script>

<?php layout_footer(); ?>
