<?php
/**
 * WC Отчёт — детальная аналитика продаж WooCommerce
 * с группировками, фильтрами, сортировкой и экспортом в Excel.
 * Только для администратора.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/woo_db.php';
require_once __DIR__ . '/woo_helpers.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../layout.php';

require_login();

$wooDB = woo_db();

// ── Параметры запроса ─────────────────────────────────────
$preset  = $_GET['preset'] ?? 'this_month';
$groupBy = $_GET['group']  ?? 'product';
$sortBy  = $_GET['sort']   ?? 'revenue';
$sortDir = $_GET['dir']    ?? 'desc';

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

$validGroups = ['product', 'category', 'day', 'month', 'customer', 'payment', 'status', 'city', 'source'];
if (!in_array($groupBy, $validGroups)) $groupBy = 'product';

$groupNames = [
    'product'  => 'По товарам',
    'category' => 'По категориям',
    'day'      => 'По дням',
    'month'    => 'По месяцам',
    'customer' => 'По клиентам',
    'payment'  => 'По способу оплаты',
    'status'   => 'По статусу',
    'city'     => 'По городу',
    'source'   => 'По источнику',
];

// ── Данные ────────────────────────────────────────────────
$opts = [
    'group_by' => $groupBy,
    'sort_by'  => $sortBy,
    'sort_dir' => $sortDir,
];

$report = woo_sales_report($wooDB, $from, $to, $opts);
$rows   = $report['rows'];
$totals = $report['totals'];

// KPI
$kpi = woo_kpi($wooDB, $from, $to);

// Мини-тренд vs предыдущий период
[$trendFrom, $trendTo] = shift_period_back($from, $to);
$kpiTrend = woo_kpi($wooDB, $trendFrom, $trendTo);

function woo_pct_change(float $cur, float $prev): float {
    if ($prev == 0) return $cur == 0 ? 0 : 100;
    return ($cur - $prev) / $prev * 100;
}

// Sparkline по дням
$daily = woo_daily_sparkline($wooDB, $from, $to);

// Топ-10 (не показываем при группировке по товарам)
$top = [];
$topMax = 0;
if ($groupBy !== 'product') {
    $top = woo_top_products($wooDB, $from, $to, 10);
    $topMax = !empty($top) ? max(array_map(fn($r) => (float)$r['revenue'], $top)) : 0;
}

// Метки периода
$dateFromDisplay = date('d.m.Y', strtotime($from));
$dateToDisplay   = date('d.m.Y', strtotime($to));
$periodLabel = $from === $to
    ? $dateFromDisplay
    : $dateFromDisplay . ' — ' . $dateToDisplay;

$weekdayShort = weekday_names_short();

// ── HTML ──────────────────────────────────────────────────
layout_header('WC — Отчёт', true, 'woo_report');
?>
<h1 class="page-title">WooCommerce — Отчёт</h1>

<!-- Панель фильтров -->
<div class="card card-pad-sm report-filters">
    <form method="get" id="report-form">
        <div class="report-row">
            <div class="report-presets">
                <?php foreach ($presetsList as $key => $label): ?>
                    <label class="preset-chip <?= $preset === $key ? 'active' : '' ?>">
                        <input type="radio" name="preset" value="<?= $key ?>" <?= $preset === $key ? 'checked' : '' ?> data-auto-submit-form>
                        <?= htmlspecialchars($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="report-row">
            <?php if ($preset === 'custom'): ?>
                <div class="filter-item">
                    <label for="rep-from">От</label>
                    <input type="date" id="rep-from" name="from" value="<?= htmlspecialchars($from) ?>">
                </div>
                <div class="filter-item">
                    <label for="rep-to">До</label>
                    <input type="date" id="rep-to" name="to" value="<?= htmlspecialchars($to) ?>">
                </div>
            <?php endif; ?>

            <div class="filter-item">
                <label for="rep-group">Группировка</label>
                <select id="rep-group" name="group">
                    <?php foreach ($groupNames as $g => $name): ?>
                        <option value="<?= htmlspecialchars($g) ?>" <?= $groupBy === $g ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary"><?= icon('check', 16) ?>Применить</button>
            </div>

            <?php if (!empty($rows)): ?>
            <div class="filter-item">
                <label>&nbsp;</label>
                <a href="woo_export.php?from=<?= $from ?>&to=<?= $to ?>&group=<?= $groupBy ?>"
                   class="btn btn-secondary"><?= icon('download', 16) ?>Экспорт в Excel</a>
            </div>
            <?php endif; ?>
        </div>
    </form>
    <div class="report-period-label">
        Период: <strong><?= $periodLabel ?></strong>
    </div>
</div>

<?php
$miniTrend = function (float $cur, float $prev): string {
    $p   = woo_pct_change($cur, $prev);
    $cls = pct_class($p);
    $arr = $p > 0.05 ? '↗' : ($p < -0.05 ? '↘' : '→');
    return '<div class="pct-delta ' . $cls . '">' . $arr . ' ' . format_pct($p)
         . ' <small>vs пред. период</small></div>';
};
?>

<!-- KPI -->
<div class="summary-box summary-box--kpi">
    <div class="summary-item summary-item--accent">
        <div class="lbl">Выручка</div>
        <div class="val"><?= format_money($kpi['revenue']) ?> <small>руб.</small></div>
        <?= $miniTrend($kpi['revenue'], $kpiTrend['revenue']) ?>
    </div>
    <div class="summary-item">
        <div class="lbl">Заказов</div>
        <div class="val"><?= format_qty($kpi['orders']) ?></div>
        <?= $miniTrend($kpi['orders'], $kpiTrend['orders']) ?>
    </div>
    <div class="summary-item">
        <div class="lbl">Средний чек</div>
        <div class="val"><?= format_money($kpi['avg_order']) ?> <small>руб.</small></div>
        <?= $miniTrend($kpi['avg_order'], $kpiTrend['avg_order']) ?>
    </div>
    <div class="summary-item">
        <div class="lbl">Товаров продано</div>
        <div class="val"><?= format_qty($kpi['items_sold']) ?> <small>шт.</small></div>
        <?= $miniTrend($kpi['items_sold'], $kpiTrend['items_sold']) ?>
    </div>
    <?php if ($kpi['discount'] > 0.005): ?>
    <div class="summary-item">
        <div class="lbl">Скидки</div>
        <div class="val"><?= format_money($kpi['discount']) ?> <small>руб.</small></div>
    </div>
    <?php endif; ?>
    <div class="summary-item">
        <div class="lbl">Клиентов</div>
        <div class="val"><?= format_qty($kpi['customers']) ?></div>
    </div>
</div>

<!-- Sparkline -->
<?php if (count($daily) > 1 && array_sum($daily) > 0): ?>
<div class="card">
    <div class="card-title">Динамика выручки по дням</div>
    <?= chart_line($daily, ['height' => 200, 'label' => 'Выручка']) ?>
</div>
<?php endif; ?>

<!-- Топ-10 товаров (если группировка не по товарам) -->
<?php if (!empty($top)): ?>
<div class="card">
    <div class="card-title">Топ-10 товаров за период</div>
    <div class="top-products">
        <?php foreach ($top as $i => $t):
            $share = $topMax > 0 ? ((float)$t['revenue'] / $topMax) * 100 : 0;
        ?>
        <div class="top-row top-row-compact">
            <div class="top-rank">#<?= $i + 1 ?></div>
            <div class="top-name"><?= htmlspecialchars($t['name']) ?></div>
            <div class="top-bar-wrap"><div class="top-bar" style="width:<?= round($share, 1) ?>%"></div></div>
            <div class="top-sum"><?= format_money((float)$t['revenue'], 0) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Таблица данных -->
<div class="card card-flush">
    <div class="card-title" style="padding:var(--sp-4) var(--sp-5) 0">
        <?= htmlspecialchars($groupNames[$groupBy] ?? $groupBy) ?>
    </div>
    <?php if (empty($rows)): ?>
        <div class="woo-empty">
            <p>Нет данных за выбранный период.</p>
        </div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <?php switch ($groupBy):
                    case 'product': ?>
                        <th class="col-w-48">#</th>
                        <th>Товар</th>
                        <th>Категория</th>
                        <th class="text-right">Кол-во</th>
                        <?php if ($totals['discount'] > 0.005): ?><th class="text-right">Скидка</th><?php endif; ?>
                        <th class="text-right">Сумма</th>
                        <th class="col-w-100">Доля</th>
                    <?php break;
                    case 'category': ?>
                        <th class="col-w-48">#</th>
                        <th>Категория</th>
                        <th class="text-right">Кол-во</th>
                        <th class="text-right">Заказов</th>
                        <th class="text-right">Сумма</th>
                        <th class="col-w-100">Доля</th>
                    <?php break;
                    case 'day': ?>
                        <th class="col-w-48">#</th>
                        <th>Дата</th>
                        <th>День недели</th>
                        <th class="text-right">Заказов</th>
                        <th class="text-right">Кол-во</th>
                        <th class="text-right">Сумма</th>
                    <?php break;
                    case 'month': ?>
                        <th>Месяц</th>
                        <th class="text-right">Заказов</th>
                        <th class="text-right">Кол-во</th>
                        <?php if ($totals['discount'] > 0.005): ?><th class="text-right">Скидка</th><?php endif; ?>
                        <th class="text-right">Сумма</th>
                    <?php break;
                    case 'customer': ?>
                        <th class="col-w-48">#</th>
                        <th>Клиент</th>
                        <th>Email</th>
                        <th class="text-right">Заказов</th>
                        <th class="text-right">Сумма</th>
                        <th class="text-right">Ср. чек</th>
                    <?php break;
                    case 'payment': ?>
                        <th>Способ оплаты</th>
                        <th class="text-right">Заказов</th>
                        <th class="text-right">Сумма</th>
                        <th class="col-w-100">Доля</th>
                    <?php break;
                    case 'status': ?>
                        <th>Статус</th>
                        <th class="text-right">Заказов</th>
                        <th class="text-right">Сумма</th>
                        <th class="col-w-100">Доля</th>
                    <?php break;
                    case 'city': ?>
                        <th>Город</th>
                        <th class="text-right">Заказов</th>
                        <th class="text-right">Сумма</th>
                        <th class="col-w-100">Доля</th>
                    <?php break;
                    case 'source': ?>
                        <th>Источник</th>
                        <th class="text-right">Заказов</th>
                        <th class="text-right">Сумма</th>
                        <th class="col-w-100">Доля</th>
                    <?php break;
                endswitch; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $i => $r):
                $share = $totals['revenue'] > 0 ? ((float)($r['revenue'] ?? 0) / $totals['revenue']) * 100 : 0;
            ?>
            <tr>
                <?php switch ($groupBy):
                    case 'product': ?>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($r['name'] ?? '') ?></td>
                        <td class="text-muted text-sm"><?= htmlspecialchars($r['category'] ?? '') ?></td>
                        <td class="text-right"><?= format_qty((int)($r['qty'] ?? 0)) ?></td>
                        <?php if ($totals['discount'] > 0.005): ?>
                            <td class="text-right"><?= format_money((float)($r['discount'] ?? 0)) ?></td>
                        <?php endif; ?>
                        <td class="text-right"><strong><?= format_money((float)($r['revenue'] ?? 0)) ?></strong></td>
                        <td>
                            <div class="report-share-bar"><div class="report-share-bar__fill" style="width:<?= round($share, 1) ?>%"></div></div>
                            <span class="text-sm text-muted"><?= number_format($share, 1, '.', '') ?>%</span>
                        </td>
                    <?php break;
                    case 'category': ?>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($r['name'] ?? '') ?></td>
                        <td class="text-right"><?= format_qty((int)($r['qty'] ?? 0)) ?></td>
                        <td class="text-right"><?= (int)($r['orders'] ?? 0) ?></td>
                        <td class="text-right"><strong><?= format_money((float)($r['revenue'] ?? 0)) ?></strong></td>
                        <td>
                            <div class="report-share-bar"><div class="report-share-bar__fill" style="width:<?= round($share, 1) ?>%"></div></div>
                            <span class="text-sm text-muted"><?= number_format($share, 1, '.', '') ?>%</span>
                        </td>
                    <?php break;
                    case 'day': ?>
                        <td><?= $i + 1 ?></td>
                        <td><?= date('d.m.Y', strtotime($r['day'])) ?></td>
                        <td><?php
                            $dow = (int)date('N', strtotime($r['day'])); // 1=Mon..7=Sun
                            echo $weekdayShort[$dow] ?? '';
                        ?></td>
                        <td class="text-right"><?= (int)($r['orders'] ?? 0) ?></td>
                        <td class="text-right"><?= format_qty((int)($r['qty'] ?? 0)) ?></td>
                        <td class="text-right"><strong><?= format_money((float)($r['revenue'] ?? 0)) ?></strong></td>
                    <?php break;
                    case 'month': ?>
                        <td><?php
                            $parts = explode('-', $r['month'] ?? '');
                            $ruMonths = ['','Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
                            echo ($ruMonths[(int)($parts[1] ?? 0)] ?? '') . ' ' . ($parts[0] ?? '');
                        ?></td>
                        <td class="text-right"><?= (int)($r['orders'] ?? 0) ?></td>
                        <td class="text-right"><?= format_qty((int)($r['qty'] ?? 0)) ?></td>
                        <?php if ($totals['discount'] > 0.005): ?>
                            <td class="text-right"><?= format_money((float)($r['discount'] ?? 0)) ?></td>
                        <?php endif; ?>
                        <td class="text-right"><strong><?= format_money((float)($r['revenue'] ?? 0)) ?></strong></td>
                    <?php break;
                    case 'customer': ?>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($r['name'] ?: 'Гость') ?></td>
                        <td class="text-muted text-sm"><?= htmlspecialchars($r['email'] ?? '') ?></td>
                        <td class="text-right"><?= (int)($r['orders'] ?? 0) ?></td>
                        <td class="text-right"><strong><?= format_money((float)($r['revenue'] ?? 0)) ?></strong></td>
                        <td class="text-right"><?= format_money((float)($r['avg_order'] ?? 0)) ?></td>
                    <?php break;
                    case 'payment': ?>
                        <td><?= htmlspecialchars($r['name'] ?? '—') ?></td>
                        <td class="text-right"><?= (int)($r['orders'] ?? 0) ?></td>
                        <td class="text-right"><strong><?= format_money((float)($r['revenue'] ?? 0)) ?></strong></td>
                        <td>
                            <div class="report-share-bar"><div class="report-share-bar__fill" style="width:<?= round($share, 1) ?>%"></div></div>
                            <span class="text-sm text-muted"><?= number_format($share, 1, '.', '') ?>%</span>
                        </td>
                    <?php break;
                    case 'status': ?>
                        <td>
                            <span class="badge <?= woo_status_class($r['name'] ?? '') ?>">
                                <?= woo_status_label($r['name'] ?? '') ?>
                            </span>
                        </td>
                        <td class="text-right"><?= (int)($r['orders'] ?? 0) ?></td>
                        <td class="text-right"><strong><?= format_money((float)($r['revenue'] ?? 0)) ?></strong></td>
                        <td>
                            <div class="report-share-bar"><div class="report-share-bar__fill" style="width:<?= round($share, 1) ?>%"></div></div>
                            <span class="text-sm text-muted"><?= number_format($share, 1, '.', '') ?>%</span>
                        </td>
                    <?php break;
                    case 'city': ?>
                        <td><?= htmlspecialchars($r['name'] ?? '—') ?></td>
                        <td class="text-right"><?= (int)($r['orders'] ?? 0) ?></td>
                        <td class="text-right"><strong><?= format_money((float)($r['revenue'] ?? 0)) ?></strong></td>
                        <td>
                            <div class="report-share-bar"><div class="report-share-bar__fill" style="width:<?= round($share, 1) ?>%"></div></div>
                            <span class="text-sm text-muted"><?= number_format($share, 1, '.', '') ?>%</span>
                        </td>
                    <?php break;
                    case 'source': ?>
                        <td><?= htmlspecialchars($r['name'] ?? '—') ?></td>
                        <td class="text-right"><?= (int)($r['orders'] ?? 0) ?></td>
                        <td class="text-right"><strong><?= format_money((float)($r['revenue'] ?? 0)) ?></strong></td>
                        <td>
                            <div class="report-share-bar"><div class="report-share-bar__fill" style="width:<?= round($share, 1) ?>%"></div></div>
                            <span class="text-sm text-muted"><?= number_format($share, 1, '.', '') ?>%</span>
                        </td>
                    <?php break;
                endswitch; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="report-totals">
                <?php switch ($groupBy):
                    case 'product': ?>
                        <td colspan="3"><strong>Итого</strong></td>
                        <td class="text-right"><strong><?= format_qty($totals['qty']) ?></strong></td>
                        <?php if ($totals['discount'] > 0.005): ?>
                            <td class="text-right"><strong><?= format_money($totals['discount']) ?></strong></td>
                        <?php endif; ?>
                        <td class="text-right"><strong><?= format_money($totals['revenue']) ?></strong></td>
                        <td></td>
                    <?php break;
                    case 'category': ?>
                        <td colspan="2"><strong>Итого</strong></td>
                        <td class="text-right"><strong><?= format_qty($totals['qty']) ?></strong></td>
                        <td class="text-right"><strong><?= format_qty($totals['orders']) ?></strong></td>
                        <td class="text-right"><strong><?= format_money($totals['revenue']) ?></strong></td>
                        <td></td>
                    <?php break;
                    case 'day': ?>
                        <td colspan="3"><strong>Итого</strong></td>
                        <td class="text-right"><strong><?= format_qty($totals['orders']) ?></strong></td>
                        <td class="text-right"><strong><?= format_qty($totals['qty']) ?></strong></td>
                        <td class="text-right"><strong><?= format_money($totals['revenue']) ?></strong></td>
                    <?php break;
                    case 'month': ?>
                        <td><strong>Итого</strong></td>
                        <td class="text-right"><strong><?= format_qty($totals['orders']) ?></strong></td>
                        <td class="text-right"><strong><?= format_qty($totals['qty']) ?></strong></td>
                        <?php if ($totals['discount'] > 0.005): ?>
                            <td class="text-right"><strong><?= format_money($totals['discount']) ?></strong></td>
                        <?php endif; ?>
                        <td class="text-right"><strong><?= format_money($totals['revenue']) ?></strong></td>
                    <?php break;
                    case 'customer': ?>
                        <td colspan="3"><strong>Итого</strong></td>
                        <td class="text-right"><strong><?= format_qty($totals['orders']) ?></strong></td>
                        <td class="text-right"><strong><?= format_money($totals['revenue']) ?></strong></td>
                        <td></td>
                    <?php break;
                    case 'payment':
                    case 'status':
                    case 'city':
                    case 'source': ?>
                        <td><strong>Итого</strong></td>
                        <td class="text-right"><strong><?= format_qty($totals['orders']) ?></strong></td>
                        <td class="text-right"><strong><?= format_money($totals['revenue']) ?></strong></td>
                        <td></td>
                    <?php break;
                endswitch; ?>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>

<?php layout_footer(); ?>
