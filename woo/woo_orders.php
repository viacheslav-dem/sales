<?php
/**
 * WC Заказы — детальный список заказов с позициями.
 * Поиск по клиенту/товару, фильтр по дате и статусу.
 * Только для администратора.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/woo_db.php';
require_once __DIR__ . '/woo_helpers.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../layout.php';

require_login();

$wooDB = woo_db();

// ── Параметры ─────────────────────────────────────────────
$preset = $_GET['preset'] ?? 'this_month';
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

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));

$perPage = 30;

$result = woo_orders_list($wooDB, $from, $to, [
    'search' => $search,
    'status' => $status,
], $perPage, $page);

$orders     = $result['orders'];
$totalCount = $result['totalCount'];
$pag        = paginate($totalCount, $perPage, $page);

// Загружаем позиции для каждого заказа на странице
$orderItems = [];
foreach ($orders as $o) {
    $orderItems[(int)$o['id']] = woo_order_items($wooDB, (int)$o['id']);
}

$dateFromDisplay = date('d.m.Y', strtotime($from));
$dateToDisplay   = date('d.m.Y', strtotime($to));
$periodLabel = $from === $to ? $dateFromDisplay : "$dateFromDisplay — $dateToDisplay";

// Статусы для фильтра
$allStatuses = [
    ''              => 'Все статусы',
    'wc-completed'  => 'Выполнен',
    'wc-processing' => 'В обработке',
    'wc-on-hold'    => 'На удержании',
    'wc-refunded'   => 'Возвращён',
    'wc-cancelled'  => 'Отменён',
];

// Построить URL для пагинации
$baseQuery = http_build_query(array_filter([
    'preset' => $preset,
    'from'   => $preset === 'custom' ? $from : null,
    'to'     => $preset === 'custom' ? $to : null,
    'q'      => $search !== '' ? $search : null,
    'status' => $status !== '' ? $status : null,
]));
$pageUrl = function(int $p) use ($baseQuery): string {
    return 'woo_orders.php?' . $baseQuery . '&page=' . $p;
};

layout_header('WC — Заказы');
?>
<h1 class="page-title">WooCommerce — Заказы</h1>

<!-- Фильтры -->
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
                <label for="rep-q">Клиент / товар</label>
                <input type="text" id="rep-q" name="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Имя, email или товар" style="width:220px;">
            </div>

            <div class="filter-item">
                <label for="rep-status">Статус</label>
                <select id="rep-status" name="status">
                    <?php foreach ($allStatuses as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $status === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary"><?= icon('check', 16) ?>Найти</button>
            </div>
        </div>
    </form>
    <div class="report-period-label">
        Период: <strong><?= $periodLabel ?></strong> · Найдено: <strong><?= format_qty($totalCount) ?></strong> заказов
    </div>
</div>

<!-- Таблица заказов -->
<?php if (empty($orders)): ?>
    <div class="card woo-empty">
        <p>Нет заказов за выбранный период<?= $search !== '' ? ' по запросу «' . htmlspecialchars($search) . '»' : '' ?>.</p>
    </div>
<?php else: ?>

<div class="card card-flush">
    <table class="woo-orders-table">
        <thead>
            <tr>
                <th style="width:9%;">Дата</th>
                <th style="width:52%;">Клиент</th>
                <th class="text-right" style="width:14%;">Сумма</th>
                <th style="width:12%;">Статус</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $o):
                $oid   = (int)$o['id'];
                $items = $orderItems[$oid] ?? [];

                $meta = array_filter([
                    $o['payment_title'] ?: '',
                    $o['source'] ?: '',
                    $o['billing_city'] ?: '',
                ]);
            ?>
            <tr class="order-row-main">
                <td>
                    <strong><?= date('d.m.Y', strtotime($o['order_date'])) ?></strong>
                    <br><span class="text-muted text-sm">#<?= $oid ?></span>
                </td>
                <td>
                    <strong><?= htmlspecialchars($o['customer_name'] ?: 'Гость') ?></strong>
                    <?php if ($o['customer_email']): ?>
                        <br><span class="text-muted text-sm"><?= htmlspecialchars($o['customer_email']) ?></span>
                    <?php endif; ?>
                    <?php if ($meta): ?>
                        <br><span class="text-muted text-sm"><?= htmlspecialchars(implode(' · ', $meta)) ?></span>
                    <?php endif; ?>
                </td>
                <td class="text-right">
                    <strong><?= format_money((float)$o['total']) ?></strong>
                    <br><span class="text-muted text-sm"><?= (int)$o['items_count'] ?> <?= (int)$o['items_count'] === 1 ? 'товар' : ((int)$o['items_count'] < 5 ? 'товара' : 'товаров') ?></span>
                </td>
                <td>
                    <span class="badge <?= woo_status_class($o['wc_status']) ?>">
                        <?= woo_status_label($o['wc_status']) ?>
                    </span>
                </td>
            </tr>
            <?php if (!empty($items)): ?>
            <tr>
                <td colspan="4" class="order-items-wrap">
                    <table class="order-items-table">
                        <thead>
                            <tr>
                                <th scope="col">Товар</th>
                                <th class="text-right col-w-80" scope="col">Кол-во</th>
                                <th class="text-right col-w-100" scope="col">Цена</th>
                                <th class="text-right col-w-100" scope="col">Сумма</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                <td class="text-right"><?= (int)$item['quantity'] ?></td>
                                <td class="text-right"><?= format_money((float)$item['unit_price']) ?></td>
                                <td class="text-right"><?= format_money((float)$item['line_total']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
    render_pagination($pag['page'], $pag['totalPages'], $pag['totalCount'], $pag['perPage'], $pag['offset'], $pageUrl);
endif;

layout_footer();
