<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/helpers.php';
require_login();

$pdo   = db();
$msg   = '';
$today = date('Y-m-d');

$selDate = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selDate)) $selDate = $today;

// ── Сохранение ──────────────────────────────────────────────
//
// Модель: ключ операции = (product_id, sale_date, is_return).
// Из формы приходят два массива:
//   $_POST['qty'][pid][ret]    — количество (ret = 0 продажа, 1 возврат)
//   $_POST['uprice'][pid][ret] — фактическая цена за единицу
// Если qty = 0 → строка удаляется.
//
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $postDate = $_POST['date'] ?? $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postDate)) $postDate = $today;

    // Каталожные цены на дату
    $priceStmt = $pdo->prepare(<<<SQL
        SELECT p.id,
            COALESCE((
                SELECT pp.price FROM product_prices pp
                WHERE pp.product_id = p.id AND pp.valid_from <= ?
                ORDER BY pp.valid_from DESC LIMIT 1
            ), 0) AS price
        FROM products p
    SQL);
    $priceStmt->execute([$postDate]);
    $priceMap = [];
    foreach ($priceStmt->fetchAll() as $r) {
        $priceMap[(int)$r['id']] = (float)$r['price'];
    }

    $upsert = $pdo->prepare(<<<SQL
        INSERT INTO sales (product_id, sale_date, quantity, base_price, unit_price, amount, is_return)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(product_id, sale_date, is_return) DO UPDATE SET
            quantity   = excluded.quantity,
            base_price = excluded.base_price,
            unit_price = excluded.unit_price,
            amount     = excluded.amount
    SQL);
    $delete = $pdo->prepare("DELETE FROM sales WHERE product_id = ? AND sale_date = ? AND is_return = ?");

    $pdo->beginTransaction();
    foreach ($_POST['qty'] ?? [] as $pid => $byRet) {
        $pid = (int)$pid;
        if (!is_array($byRet)) continue;

        foreach ($byRet as $retKey => $rawQty) {
            $isReturn = (int)$retKey === 1 ? 1 : 0;
            $qty = max(0, (int)$rawQty);

            if ($qty === 0) {
                $delete->execute([$pid, $postDate, $isReturn]);
                continue;
            }

            $basePrice = $priceMap[$pid] ?? 0.0;
            $rawUprice = $_POST['uprice'][$pid][$retKey] ?? null;
            $unitPrice = $rawUprice !== null
                ? max(0, (float)str_replace(',', '.', $rawUprice))
                : $basePrice;
            $amount    = round($qty * $unitPrice, 2);

            $upsert->execute([$pid, $postDate, $qty, $basePrice, $unitPrice, $amount, $isReturn]);
        }
    }
    $pdo->commit();

    $selDate = $postDate;
    $msg     = 'Данные сохранены.';
}

// ── Загрузка списка товаров и продаж за дату ───────────────
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

// Все строки продаж/возвратов за выбранную дату
$rowStmt = $pdo->prepare(<<<SQL
    SELECT product_id, is_return, quantity, base_price, unit_price
    FROM sales
    WHERE sale_date = :date
SQL);
$rowStmt->execute([':date' => $selDate]);
$dailyRows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

// Данные для JS передаём через <script type="application/json"> — см. daily.js.
// JSON_HEX_* нужны даже для JSON-скрипта (защита от `</script>` в именах).
$bootstrap = [
    'products' => array_map(fn($p) => [
        'id'    => (int)$p['id'],
        'name'  => $p['name'],
        'price' => (float)$p['price'],
    ], $allProducts),
    'rows' => array_map(fn($r) => [
        'pid'        => (int)$r['product_id'],
        'is_return'  => (int)$r['is_return'],
        'qty'        => (int)$r['quantity'],
        'base_price' => (float)$r['base_price'],
        'unit_price' => (float)$r['unit_price'],
    ], $dailyRows),
];
$bootstrapJson = json_encode(
    $bootstrap,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$dateDisplay = (new DateTime($selDate))->format('d.m.Y');
$weekdays    = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
$wd          = $weekdays[(int)(new DateTime($selDate))->format('w')];

layout_header('Продажи за день');
?>
<h1 class="page-title">Продажи за день</h1>

<?php if ($msg): ?>
<div id="flash-data"
     data-msg="<?= htmlspecialchars($msg, ENT_QUOTES) ?>"
     data-type="success"
     hidden></div>
<?php endif; ?>

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

<!-- POS-блок -->
<div class="card card-flush">
    <div class="pos-header">
        <div class="pos-header-left">
            <span class="pos-title">Продажи за <?= $dateDisplay ?></span>
            <span id="badge-count" class="badge badge-primary" hidden></span>
        </div>
        <div class="pos-header-actions">
            <button type="button" class="btn btn-primary" data-action="open-modal" data-mode="0">
                <?= icon('plus', 16) ?>Добавить продажу
            </button>
            <button type="button" class="btn btn-outline-warning" data-action="open-modal" data-mode="1" title="Оформить возврат товара">
                <?= icon('refresh-ccw', 16) ?>Оформить возврат
            </button>
        </div>
    </div>

    <div id="sales-list" class="sales-list">
        <div id="empty-state" class="empty-state">
            <div class="empty-state-icon"><?= icon('clipboard', 32) ?></div>
            <div class="empty-state-title">Нет продаж за этот день</div>
            <div class="empty-state-hint">Добавьте продажу или оформите возврат</div>
            <div class="empty-state-actions">
                <button type="button" class="btn btn-primary" data-action="open-modal" data-mode="0">
                    <?= icon('plus', 16) ?>Добавить продажу
                </button>
                <button type="button" class="btn btn-outline-warning" data-action="open-modal" data-mode="1">
                    <?= icon('refresh-ccw', 16) ?>Оформить возврат
                </button>
            </div>
        </div>
        <table id="sales-table" class="sales-table" hidden>
            <thead>
                <tr>
                    <th class="col-num">#</th>
                    <th>Наименование товара</th>
                    <th class="num col-price">Прайс</th>
                    <th class="num col-uprice">Цена продажи</th>
                    <th class="num col-disc">Скидка/шт.</th>
                    <th class="num col-qty">Кол-во (шт.)</th>
                    <th class="num col-sum">Сумма (руб.)</th>
                    <th class="col-act"></th>
                </tr>
            </thead>
            <tbody id="sales-tbody"></tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="foot-label">Итого</td>
                    <td></td>
                    <td class="num foot-discount" id="foot-discount">0.00</td>
                    <td class="num" id="foot-qty">0</td>
                    <td class="num" id="foot-sum">0.00</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="pos-footer">
        <div id="totals-bar" class="totals-bar">Нет данных</div>
        <form method="post" id="save-form">
            <?= csrf_field() ?>
            <input type="hidden" name="date" value="<?= htmlspecialchars($selDate) ?>">
            <div id="hidden-inputs"></div>
            <button type="submit" class="btn btn-success">
                <?= icon('save', 16) ?>Сохранить
            </button>
        </form>
    </div>
</div>

<!-- Модальное окно -->
<div id="modal-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-box">
        <div class="modal-header">
            <h2 class="modal-title" id="modal-title">Добавить продажу</h2>
            <button type="button" class="modal-close-btn" data-action="close-modal" aria-label="Закрыть (Esc)" title="Закрыть (Esc)">
                <?= icon('x', 16) ?>
            </button>
        </div>

        <!-- Переключатель: Продажа / Возврат (не tablist, а group toggle) -->
        <div class="mode-switch" role="group" aria-label="Режим операции">
            <button type="button" class="mode-switch-btn is-active" data-mode="0" data-action="set-mode" aria-pressed="true">
                <?= icon('plus', 14) ?>Продажа
            </button>
            <button type="button" class="mode-switch-btn" data-mode="1" data-action="set-mode" aria-pressed="false">
                <?= icon('refresh-ccw', 14) ?>Возврат
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


<!-- Данные для фронтенд-скрипта. Не выполняется — JSON.parse(). -->
<script id="daily-bootstrap" type="application/json"><?= $bootstrapJson ?></script>
<script src="assets/daily.js?v=<?= asset_v('assets/daily.js') ?>" defer></script>

<?php layout_footer(); ?>
