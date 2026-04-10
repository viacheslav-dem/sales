<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';
require_login();

$pdo   = db();
$today = date('Y-m-d');

$selDate = $_GET['date'] ?? $today;
// valid_date() — формат + checkdate(). Без него «2026-02-30» проходил
// regex, безопасно вырождался в SQL (0 строк), но потом падал на
// new DateTime($selDate) → 500 на странице.
if (!valid_date($selDate)) $selDate = $today;
// Запрещаем будущие даты на сервере. UI ограничивает выбор через
// max=today на input[type=date], но это только клиентская подсказка —
// через прямой URL можно обойти. Серверный clamp — единственная защита.
if ($selDate > $today) $selDate = $today;

// Право на редактирование текущего экрана: продажи прошлых месяцев правит
// только администратор. Возврат всегда оформляется текущим днём, поэтому
// Админ может редактировать любой день. Менеджер — текущий день,
// а прошлые — только если разрешено в настройках.
$managerCanEditPast = get_setting('manager_edit_past_days', '0') === '1';
$canEdit = is_admin() || ($selDate === $today) || $managerCanEditPast;

// Сохранение здесь не обрабатывается — экран работает в режиме autosave.
// Все мутации улетают на daily_save.php (JSON-endpoint) асинхронно с дебаунсом.
// Кнопка «Сохранить» в шапке зовёт ту же функцию JS — она нужна как
// ручной триггер «сохрани прямо сейчас», особенно при флаком интернете.

// ── Загрузка списка товаров ─────────────────────────────────
$prodStmt = $pdo->prepare(<<<SQL
    SELECT p.id, p.name,
        COALESCE((
            SELECT pp.price FROM product_prices pp
            WHERE pp.product_id = p.id AND pp.valid_from <= :date
            ORDER BY pp.valid_from DESC LIMIT 1
        ), 0) AS price
    FROM products p
    WHERE p.is_active = 1
    ORDER BY p.name
SQL);
$prodStmt->execute([':date' => $selDate]);
$allProducts = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

// Продажи за выбранную дату (is_return=0). Каждая операция = отдельная строка.
$saleStmt = $pdo->prepare(<<<SQL
    SELECT s.id, s.product_id, s.quantity, s.base_price, s.unit_price,
           s.discount_amount, s.sold_at, s.payment_method, s.note,
           p.name AS product_name
    FROM sales s
    INNER JOIN products p ON p.id = s.product_id
    WHERE s.sale_date = :date AND s.is_return = 0
    ORDER BY COALESCE(s.sold_at, s.sale_date) ASC, s.id ASC
SQL);
$saleStmt->execute([':date' => $selDate]);
$dailySales = $saleStmt->fetchAll(PDO::FETCH_ASSOC);

// Возвраты за выбранную дату (с подтягиванием исходной продажи)
$retStmt = $pdo->prepare(<<<SQL
    SELECT s.id            AS ret_id,
           s.original_sale_id,
           s.quantity, s.base_price, s.unit_price,
           s.sold_at,
           o.product_id    AS pid,
           o.sale_date     AS orig_date,
           o.quantity      AS orig_qty,
           p.name          AS product_name
    FROM sales s
    LEFT JOIN sales o    ON o.id = s.original_sale_id
    LEFT JOIN products p ON p.id = o.product_id
    WHERE s.sale_date = :date AND s.is_return = 1
SQL);
$retStmt->execute([':date' => $selDate]);
$dailyReturns = $retStmt->fetchAll(PDO::FETCH_ASSOC);

// Возврат можно оформить только текущим днём
$canReturn = ($selDate === $today);

// Список «возвратопригодных» исходных продаж — только если открыт сегодняшний день.
//
// Ограничиваем горизонтом в 14 дней (стандартный срок возврата непродовольственных
// товаров надлежащего качества по закону о защите прав потребителей):
//   • Возврат за пределами этого срока — исключение, оформляется админом руками.
//   • Без лимита SELECT тащил всю sales-таблицу за всю историю магазина,
//     PHP сериализовал её в JSON-bootstrap, JS отрисовывал в модалке —
//     через год работы это становилось сотни KB JSON и неюзабельным UI.
//   • Если бизнес-правила потребуют другого окна — поменяйте константу.
$RETURN_HORIZON_DAYS = 14;
$returnable = [];
if ($canReturn) {
    $horizonFrom = date('Y-m-d', strtotime("-{$RETURN_HORIZON_DAYS} days", strtotime($today)));
    $sql = <<<SQL
        SELECT s.id, s.product_id, p.name AS product_name,
               s.sale_date, s.quantity, s.base_price, s.unit_price,
               COALESCE((
                   SELECT SUM(r.quantity) FROM sales r
                   WHERE r.original_sale_id = s.id AND r.is_return = 1
               ), 0) AS returned_total
        FROM sales s
        INNER JOIN products p ON p.id = s.product_id
        WHERE s.is_return = 0 AND s.quantity > 0
          AND s.sale_date >= :horizon
        ORDER BY s.sale_date DESC, p.name ASC
    SQL;
    $retStmt2 = $pdo->prepare($sql);
    $retStmt2->execute([':horizon' => $horizonFrom]);
    foreach ($retStmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $remaining = (int)$r['quantity'] - (int)$r['returned_total'];
        if ($remaining <= 0) continue;
        $returnable[] = [
            'orig_id'       => (int)$r['id'],
            'pid'           => (int)$r['product_id'],
            'name'          => $r['product_name'],
            'orig_date'     => $r['sale_date'],
            'orig_qty'      => (int)$r['quantity'],
            'returned'      => (int)$r['returned_total'],
            'remaining'     => $remaining,
            'base_price'    => (float)$r['base_price'],
            'unit_price'    => (float)$r['unit_price'],
        ];
    }
}

$bootstrap = [
    'today'    => $today,
    'selDate'  => $selDate,
    'products' => array_map(fn($p) => [
        'id'    => (int)$p['id'],
        'name'  => $p['name'],
        'price' => (float)$p['price'],
    ], $allProducts),
    'sales' => array_map(fn($r) => [
        'id'         => (int)$r['id'],
        'pid'        => (int)$r['product_id'],
        'name'       => $r['product_name'],
        'qty'        => (int)$r['quantity'],
        'base_price' => (float)$r['base_price'],
        'unit_price' => (float)$r['unit_price'],
        'discount'   => (float)$r['discount_amount'],
        'payment'    => $r['payment_method'] ?: 'cash',
        'note'       => $r['note'] ?? '',
        'sold_at'    => $r['sold_at'],
    ], $dailySales),
    'returns' => array_map(fn($r) => [
        'orig_id'    => (int)$r['original_sale_id'],
        'pid'        => (int)$r['pid'],
        'name'       => $r['product_name'] ?? ('Товар #' . (int)$r['pid']),
        'orig_date'  => $r['orig_date'],
        'orig_qty'   => (int)$r['orig_qty'],
        'qty'        => (int)$r['quantity'],
        'base_price' => (float)$r['base_price'],
        'unit_price' => (float)$r['unit_price'],
        'sold_at'    => $r['sold_at'],
    ], $dailyReturns),
    'returnable' => $returnable,
    'canReturn'  => $canReturn,
    'canEdit'    => $canEdit,
];
$bootstrapJson = json_encode(
    $bootstrap,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($bootstrapJson === false) {
    // Битый UTF-8 в одном из имён товаров/заметок. Лучше упасть громко
    // в логах с понятным сообщением, чем отдать пустой <script>-тег и
    // получить молчаливый JS-краш «Unexpected end of JSON input».
    throw new \RuntimeException('daily.php: json_encode failed: ' . json_last_error_msg());
}

$dateDisplay = (new DateTime($selDate))->format('d.m.Y');
$weekdays    = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
$wd          = $weekdays[(int)(new DateTime($selDate))->format('w')];

layout_header('Продажи за день', wide: true);
?>
<h1 class="page-title">Продажи за день</h1>

<!-- Выбор даты -->
<div class="card card-pad-sm">
    <form method="get" class="form-row m-0">
        <label for="date-picker"><strong>Дата:</strong></label>
        <input type="date" id="date-picker" name="date" value="<?= htmlspecialchars($selDate) ?>" max="<?= $today ?>" min="2020-01-01">
        <button type="submit" class="btn btn-primary"><?= icon('check', 16) ?>Открыть</button>
        <?php if ($selDate !== $today): ?>
            <a href="daily.php" class="btn btn-secondary">Сегодня</a>
        <?php endif; ?>
        <span class="form-row-hint"><?= $dateDisplay ?>, <?= $wd ?></span>
    </form>
</div>

<?php if (!$canEdit): ?>
<div class="alert alert-readonly alert-mb">
    <div>
        <strong>Только чтение</strong> — продажи за <?= $dateDisplay ?>
        <div class="alert-hint">Редактирование прошлых дней доступно только администратору</div>
    </div>
</div>
<?php endif; ?>

<!-- POS-блок -->
<div class="card card-flush<?= $canEdit ? '' : ' is-readonly' ?>">
    <div class="pos-header">
        <div class="pos-header-left">
            <span class="pos-title">Продажи за <?= $dateDisplay ?></span>
            <span id="badge-count" class="badge badge-primary" hidden></span>
            <?php if ($canEdit): ?>
            <span id="save-status" class="save-status" data-status="idle" hidden></span>
            <?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
        <div class="pos-header-actions">
            <button type="button" class="btn btn-secondary" id="undo-btn" data-action="undo" disabled
                    title="Отменить последнее добавление/удаление (нет горячей клавиши)">
                <?= icon('refresh-ccw', 16) ?>Отменить
            </button>
            <button type="button" class="btn btn-primary" data-action="open-modal" data-mode="0">
                <?= icon('plus', 16) ?>Добавить продажу
            </button>
            <?php if ($canReturn): ?>
            <button type="button" class="btn btn-outline-warning" data-action="open-modal" data-mode="1" title="Оформить возврат товара">
                <?= icon('refresh-ccw', 16) ?>Оформить возврат
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div id="sales-list" class="sales-list">
        <div id="empty-state" class="empty-state">
            <div class="empty-state-icon"><?= icon('clipboard', 32) ?></div>
            <div class="empty-state-title">Нет продаж за этот день</div>
            <?php if ($canEdit): ?>
            <div class="empty-state-hint">Добавьте продажу<?= $canReturn ? ' или оформите возврат' : '' ?></div>
            <div class="empty-state-actions">
                <button type="button" class="btn btn-primary" data-action="open-modal" data-mode="0">
                    <?= icon('plus', 16) ?>Добавить продажу
                </button>
                <?php if ($canReturn): ?>
                <button type="button" class="btn btn-outline-warning" data-action="open-modal" data-mode="1">
                    <?= icon('refresh-ccw', 16) ?>Оформить возврат
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <table id="sales-table" class="sales-table" hidden>
            <thead>
                <tr>
                    <th class="col-num">#</th>
                    <th class="col-time">Время</th>
                    <th>Наименование товара</th>
                    <th class="num col-price">Прайс</th>
                    <th class="num col-uprice">Цена продажи</th>
                    <th class="num col-disc">Скидка</th>
                    <th class="num col-qty">Кол-во (шт.)</th>
                    <th class="num col-sum">Сумма (руб.)</th>
                    <th class="col-pay">Оплата</th>
                    <th class="col-note">Заметка</th>
                    <th class="col-act"></th>
                </tr>
            </thead>
            <tbody id="sales-tbody"></tbody>
            <tfoot>
                <!-- Продажи -->
                <tr class="foot-row foot-row--sales">
                    <td colspan="3" class="foot-label">Продано</td>
                    <td class="num foot-base" id="foot-base" title="Сумма по прайсу — без скидок">0.00</td>
                    <td></td>
                    <td class="num foot-discount" id="foot-discount">0.00</td>
                    <td class="num" id="foot-qty">0</td>
                    <td class="num" id="foot-sum">0.00</td>
                    <td colspan="3"></td>
                </tr>
                <!-- Возвраты — строка показывается только если есть возвраты -->
                <tr class="foot-row foot-row--returns" id="foot-returns-row" hidden>
                    <td colspan="3" class="foot-label">Возвращено</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td class="num" id="foot-ret-qty">0</td>
                    <td class="num foot-neg" id="foot-ret-sum">0.00</td>
                    <td colspan="3"></td>
                </tr>
                <!-- Чистая выручка -->
                <tr class="foot-row foot-row--net">
                    <td colspan="3" class="foot-label">Итого</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td class="num" id="foot-net-qty">0</td>
                    <td class="num" id="foot-net-sum">0.00</td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="pos-footer">
        <div id="totals-bar" class="totals-bar">Нет данных</div>
    </div>
</div>

<!-- Модальное окно: продажа -->
<div id="modal-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-box">
        <div class="modal-header">
            <h2 class="modal-title" id="modal-title">Добавить продажу</h2>
            <button type="button" class="modal-close-btn" data-action="close-modal" aria-label="Закрыть (Esc)" title="Закрыть (Esc)">
                <?= icon('x', 16) ?>
            </button>
        </div>

        <div class="modal-search-wrap">
            <label for="modal-search" class="sr-only">Поиск товара</label>
            <input type="search" id="modal-search" placeholder="Начните вводить название…" autocomplete="off">
            <span id="modal-count" class="filter-info"></span>
        </div>
        <div class="modal-results" id="modal-results"></div>
        <div class="modal-footer">
            <span id="modal-added-info" class="filter-info"></span>
            <button type="button" class="btn btn-primary" data-action="close-modal">Готово</button>
        </div>
    </div>
</div>

<!-- Модальное окно: возврат (отдельное, со списком возвратопригодных продаж) -->
<?php if ($canReturn): ?>
<div id="return-modal-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="return-modal-title">
    <div class="modal-box modal-box--return">
        <div class="modal-header">
            <h2 class="modal-title" id="return-modal-title">Оформить возврат</h2>
            <button type="button" class="modal-close-btn" data-action="close-return-modal" aria-label="Закрыть (Esc)" title="Закрыть (Esc)">
                <?= icon('x', 16) ?>
            </button>
        </div>
        <div class="modal-search-wrap">
            <label for="return-modal-search" class="sr-only">Поиск товара</label>
            <input type="search" id="return-modal-search" placeholder="Поиск по названию товара…" autocomplete="off">
            <span id="return-modal-count" class="filter-info"></span>
        </div>
        <div class="modal-results" id="return-modal-results"></div>
        <div class="modal-footer">
            <span class="filter-info">Выберите продажу, к которой относится возврат. Возврат всегда оформляется текущим днём.</span>
            <button type="button" class="btn btn-primary" data-action="close-return-modal">Готово</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script id="daily-bootstrap" type="application/json"><?= $bootstrapJson ?></script>
<script src="assets/daily.js?v=<?= asset_v('assets/daily.js') ?>" defer></script>

<?php layout_footer(); ?>
