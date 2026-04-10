<?php
/**
 * WC Дашборд — обзор продаж WooCommerce с KPI, графиками и последними заказами.
 * Только для администратора.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/woo_db.php';
require_once __DIR__ . '/woo_helpers.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../layout.php';

require_login();

// Авто-установка: если схема не создана — создать
$wooDB = woo_db();
$check = $wooDB->query("SELECT name FROM sqlite_master WHERE type='table' AND name='woo_orders'");
if (!$check->fetchColumn()) {
    require_once __DIR__ . '/woo_install.php';
    exit;
}

$today    = date('Y-m-d');
$lastSync = woo_last_sync();
$totalOrders = woo_order_count();

// ── Подхватить flash-сообщение после синхронизации ────────
$flash = flash_get();
$msg     = $flash ? $flash['msg']  : '';
$msgType = $flash ? $flash['type'] : '';

// ── KPI-карточки ──────────────────────────────────────────
function woo_dash_kpi(PDO $db, string $from, string $to, string $shift): array {
    $today = date('Y-m-d');
    $effTo = $to > $today ? $today : $to;
    if ($effTo < $from) {
        return ['orders'=>0, 'revenue'=>0, 'avg_order'=>0, 'items_sold'=>0, 'discount'=>0, 'customers'=>0, 'shipping'=>0, 'delta'=>0];
    }
    $cur = woo_kpi($db, $from, $effTo);

    $pFrom = date('Y-m-d', strtotime($shift, strtotime($from)));
    $pTo   = date('Y-m-d', strtotime($shift, strtotime($effTo)));
    $prev  = woo_kpi($db, $pFrom, $pTo);

    $delta = $prev['revenue'] == 0
        ? ($cur['revenue'] == 0 ? 0 : 100)
        : ($cur['revenue'] - $prev['revenue']) / $prev['revenue'] * 100;

    $cur['delta'] = $delta;
    return $cur;
}

[$tFrom, $tTo]   = period_preset('today');
[$yFrom, $yTo]   = period_preset('yesterday');
[$wFrom, $wTo]   = period_preset('this_week');
[$mFrom, $mTo]   = period_preset('this_month');
[$yrFrom, $yrTo] = period_preset('this_year');

$kpiToday = woo_dash_kpi($wooDB, $tFrom,  $tTo,  '-1 day');
$kpiYday  = woo_dash_kpi($wooDB, $yFrom,  $yTo,  '-1 day');
$kpiWeek  = woo_dash_kpi($wooDB, $wFrom,  $wTo,  '-1 week');
$kpiMonth = woo_dash_kpi($wooDB, $mFrom,  $mTo,  '-1 month');
$kpiYear  = woo_dash_kpi($wooDB, $yrFrom, $yrTo, '-1 year');

// ── Sparkline 30 дней ─────────────────────────────────────
[$spFrom, $spTo] = period_preset('last_30');
$sparkData = woo_daily_sparkline($wooDB, $spFrom, $spTo);

// ── Топ-5 товаров ─────────────────────────────────────────
$top5 = woo_top_products($wooDB, $spFrom, $spTo, 5, 'revenue');
$top5Max = !empty($top5) ? max(array_map(fn($r) => (float)$r['revenue'], $top5)) : 0;

// ── По дням недели ────────────────────────────────────────
$byWday  = woo_weekday_breakdown($wooDB, $spFrom, $spTo);
$wdNames = weekday_names_short();

// ── Оплата ────────────────────────────────────────────────
$payments = woo_payment_distribution($wooDB, $spFrom, $spTo);

// ── Последние заказы ──────────────────────────────────────
$recent = woo_recent_orders($wooDB, 10);

layout_header('WC — Дашборд', wide: true);
?>
<h1 class="page-title">WooCommerce — Дашборд</h1>

<?php if ($msg): ?>
<div id="flash-data"
     data-msg="<?= htmlspecialchars($msg, ENT_QUOTES) ?>"
     data-type="<?= htmlspecialchars($msgType, ENT_QUOTES) ?>"
     hidden></div>
<?php endif; ?>

<!-- Панель синхронизации -->
<div class="card woo-sync-bar">
    <div class="woo-sync-bar__info">
        <div class="woo-sync-bar__title">Синхронизация</div>
        <div class="text-muted text-sm">
            <?php if ($lastSync): ?>
                Последняя: <?= date('d.m.Y H:i', strtotime($lastSync)) ?> · Заказов в базе: <?= format_qty($totalOrders) ?>
            <?php else: ?>
                Ещё не выполнялась
            <?php endif; ?>
        </div>
    </div>
    <form method="post" action="woo_sync.php" class="woo-sync-bar__actions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sync_incremental">
        <button type="submit" class="btn btn-primary">
            <?= icon('refresh-ccw', 16) ?> Синхронизировать
        </button>
    </form>
</div>

<?php if ($totalOrders === 0): ?>
    <div class="card woo-empty">
        <p>Нет данных для отображения. Нажмите «Синхронизировать» чтобы загрузить заказы из WooCommerce.</p>
    </div>
<?php else: ?>

<!-- KPI карточки -->
<div class="summary-box dashboard-kpi">
    <a href="woo_report.php?preset=today" class="summary-item kpi-today" title="Отчёт за сегодня">
        <div class="lbl">Сегодня <small>· <?= date('d.m', strtotime($tFrom)) ?></small></div>
        <div class="val"><?= format_money($kpiToday['revenue']) ?> <small>руб.</small></div>
        <div class="kpi-meta"><?= $kpiToday['orders'] ?> заказов · <?= format_qty($kpiToday['items_sold']) ?> шт.</div>
        <div class="pct-delta <?= pct_class($kpiToday['delta']) ?>"><?= format_pct($kpiToday['delta']) ?> <small>vs вчера</small></div>
    </a>
    <a href="woo_report.php?preset=yesterday" class="summary-item kpi-yday">
        <div class="lbl">Вчера <small>· <?= date('d.m', strtotime($yFrom)) ?></small></div>
        <div class="val"><?= format_money($kpiYday['revenue']) ?> <small>руб.</small></div>
        <div class="kpi-meta"><?= $kpiYday['orders'] ?> заказов</div>
        <div class="pct-delta <?= pct_class($kpiYday['delta']) ?>"><?= format_pct($kpiYday['delta']) ?> <small>vs позавчера</small></div>
    </a>
    <a href="woo_report.php?preset=this_week" class="summary-item kpi-week">
        <div class="lbl">Эта неделя</div>
        <div class="val"><?= format_money($kpiWeek['revenue']) ?> <small>руб.</small></div>
        <div class="kpi-meta"><?= $kpiWeek['orders'] ?> заказов · ср. чек <?= format_money($kpiWeek['avg_order']) ?></div>
        <div class="pct-delta <?= pct_class($kpiWeek['delta']) ?>"><?= format_pct($kpiWeek['delta']) ?> <small>vs прошлая</small></div>
    </a>
    <a href="woo_report.php?preset=this_month" class="summary-item kpi-month">
        <div class="lbl">Этот месяц</div>
        <div class="val"><?= format_money($kpiMonth['revenue']) ?> <small>руб.</small></div>
        <div class="kpi-meta"><?= $kpiMonth['orders'] ?> заказов · <?= $kpiMonth['customers'] ?> клиентов</div>
        <div class="pct-delta <?= pct_class($kpiMonth['delta']) ?>"><?= format_pct($kpiMonth['delta']) ?> <small>vs тот же период мес. назад</small></div>
    </a>
    <a href="woo_report.php?preset=this_year" class="summary-item kpi-year">
        <div class="lbl">Этот год</div>
        <div class="val"><?= format_money($kpiYear['revenue']) ?> <small>руб.</small></div>
        <div class="kpi-meta"><?= $kpiYear['orders'] ?> заказов</div>
        <div class="pct-delta <?= pct_class($kpiYear['delta']) ?>"><?= format_pct($kpiYear['delta']) ?> <small>vs тот же период год назад</small></div>
    </a>
</div>

<!-- Sparkline 30 дней -->
<div class="card">
    <div class="dashboard-card-header">
        <div class="card-title" style="margin-bottom:0">Динамика выручки за 30 дней</div>
        <a href="woo_report.php?preset=last_30&group=day" class="btn btn-ghost btn-sm">Подробнее →</a>
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
            <a href="woo_report.php?preset=last_30&group=product" class="btn btn-ghost btn-sm">Все →</a>
        </div>
        <?php if (empty($top5)): ?>
            <div class="empty-cell">Нет продаж за период</div>
        <?php else: ?>
        <div class="top-products">
            <?php foreach ($top5 as $i => $t):
                $share = $top5Max > 0 ? ((float)$t['revenue'] / $top5Max) * 100 : 0;
            ?>
            <div class="top-row top-row-compact">
                <div class="top-rank">#<?= $i + 1 ?></div>
                <div class="top-name"><?= htmlspecialchars($t['name']) ?></div>
                <div class="top-bar-wrap"><div class="top-bar" style="width:<?= round($share, 1) ?>%"></div></div>
                <div class="top-sum"><?= format_money((float)$t['revenue'], 0) ?></div>
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

<?php if (!empty($payments)): ?>
<div class="dashboard-grid">
    <!-- Оплата (doughnut) -->
    <div class="card">
        <div class="card-title">Способы оплаты · 30 дней</div>
        <?php
            $payData = [];
            foreach ($payments as $p) $payData[$p['method'] ?: 'Не указан'] = (float)$p['revenue'];
        ?>
        <?= chart_doughnut($payData, ['height' => 220]) ?>
    </div>
    <!-- По часам -->
    <div class="card">
        <div class="card-title">Активность по часам · 30 дней</div>
        <?php
            $hourly = woo_hourly_breakdown($wooDB, $spFrom, $spTo);
            if (array_sum($hourly) > 0):
                $hBar = [];
                foreach ($hourly as $h => $rev) $hBar[sprintf('%02d:00', $h)] = $rev;
        ?>
            <?= chart_bar($hBar, ['height' => 220, 'label' => 'Выручка']) ?>
        <?php else: ?>
            <div class="sparkline-empty">Нет данных</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Последние заказы -->
<div class="card card-flush">
    <div class="dashboard-card-header" style="padding:var(--sp-4) var(--sp-5)">
        <div class="card-title" style="margin-bottom:0">Последние заказы</div>
        <a href="woo_orders.php?preset=last_30" class="btn btn-ghost btn-sm">Все заказы →</a>
    </div>
    <?php if (empty($recent)): ?>
        <div class="empty-cell" style="padding:var(--sp-6);">Нет заказов</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th class="col-w-48">#</th>
                <th class="col-w-100">Дата</th>
                <th>Клиент</th>
                <th class="text-right col-w-80">Позиций</th>
                <th class="text-right col-w-120">Сумма</th>
                <th class="col-w-120">Оплата</th>
                <th class="col-w-120">Источник</th>
                <th class="col-w-120">Статус</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
                <td class="text-muted"><?= (int)$r['id'] ?></td>
                <td><?= date('d.m.Y', strtotime($r['order_date'])) ?></td>
                <td><?= htmlspecialchars($r['customer_name'] ?: 'Гость') ?></td>
                <td class="text-right"><?= (int)$r['items_count'] ?></td>
                <td class="text-right"><?= format_money((float)$r['total']) ?></td>
                <td class="text-sm text-muted"><?= htmlspecialchars($r['payment_title'] ?: '—') ?></td>
                <td class="text-sm"><?= htmlspecialchars($r['source'] ?: '—') ?></td>
                <td>
                    <span class="badge <?= woo_status_class($r['wc_status']) ?>">
                        <?= woo_status_label($r['wc_status']) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php endif; // totalOrders > 0 ?>

<?php layout_footer(); ?>
