<?php
/**
 * JSON-endpoint автосохранения экрана «Продажи за день».
 *
 * Принимает application/json:
 *   {
 *     "date": "YYYY-MM-DD",
 *     "ops": [
 *       { "local_key": "n1", "id": null|int, "pid": int,
 *         "qty": int, "uprice": "12.34",
 *         "payment": "cash|card|other", "note": "..." },
 *       ...
 *     ],
 *     "deleted": [int, ...],
 *     "returns": { "<original_sale_id>": qty, ... }
 *   }
 *
 * Возвращает JSON:
 *   { "ok": true, "id_map": {"n1": 42, "n2": 43} }
 *   { "ok": false, "error": "..." }
 *
 * id_map нужен клиенту, чтобы после INSERT'а новой продажи перепривязать
 * локальный ключ ("n1") к серверному id (42) — следующие правки той же
 * строки полетят как UPDATE по id, а не как новая INSERT.
 *
 * CSRF-токен передаётся через заголовок X-CSRF-Token (csrf_check() читает
 * $_POST, поэтому здесь сравниваем вручную).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

/** Унифицированный JSON-ответ + завершение запроса. */
function json_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF: токен в заголовке
$sentToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($sentToken) || !hash_equals($_SESSION['csrf'] ?? '', $sentToken)) {
    json_out(['ok' => false, 'error' => 'CSRF token mismatch'], 419);
}

// Тело запроса. Лимит сырого размера — защита от DoS через гигантский payload.
// 256 KB с запасом покрывает любой реальный сценарий: даже сотня позиций
// с заметками по 500 символов укладывается в десятки KB.
$RAW_MAX_BYTES = 256 * 1024;
$raw = file_get_contents('php://input', false, null, 0, $RAW_MAX_BYTES + 1);
if ($raw === false || strlen($raw) > $RAW_MAX_BYTES) {
    json_out(['ok' => false, 'error' => 'Payload too large'], 413);
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_out(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
}

// Сами массивы операций тоже ограничиваем, чтобы prepared loops
// не растягивали транзакцию на минуты.
$OPS_MAX = 500;
if ((isset($payload['ops'])     && is_array($payload['ops'])     && count($payload['ops'])     > $OPS_MAX)
 || (isset($payload['deleted']) && is_array($payload['deleted']) && count($payload['deleted']) > $OPS_MAX)
 || (isset($payload['returns']) && is_array($payload['returns']) && count($payload['returns']) > $OPS_MAX)) {
    json_out(['ok' => false, 'error' => 'Too many operations in one batch'], 413);
}

$pdo   = db();
$today = date('Y-m-d');

$postDate = $payload['date'] ?? '';
// Жёсткая валидация: регэксп + checkdate(). Тихий fallback к $today
// здесь опасен — клиент с битой датой молча писал бы в текущий день
// и перезаписывал чужие продажи. Лучше отдать 400 и пусть клиент
// поймёт, что у него баг.
if (!is_string($postDate) || !valid_date($postDate)) {
    json_out(['ok' => false, 'error' => 'Invalid date'], 400);
}
// Запрещаем будущие даты на сервере. Клиентский input[type=date]
// имеет max=today, но прямой POST обходит это ограничение.
if ($postDate > $today) {
    json_out(['ok' => false, 'error' => 'Future dates are not allowed'], 400);
}

// Менеджер — только текущий день, если не разрешено в настройках.
if ($postDate !== $today && !is_admin()) {
    $managerCanEditPast = get_setting('manager_edit_past_days', '0') === '1';
    if (!$managerCanEditPast) {
        json_out(['ok' => false, 'error' => 'Редактирование прошлых дней доступно только администратору'], 403);
    }
}

$ALLOWED_PAYMENTS = ['cash', 'card', 'other'];
$idMap = [];
$timeMap = [];      // localKey → sold_at для новых продаж
$retTimeMap = [];   // origId → sold_at для новых возвратов

try {
    // Каталожные цены на дату — для НОВЫХ строк продаж
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

    $insertSale = $pdo->prepare(
        "INSERT INTO sales
            (product_id, sale_date, quantity, base_price, unit_price, amount,
             discount_amount, is_return, original_sale_id,
             sold_at, payment_method, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, ?, ?)"
    );
    $updateSale = $pdo->prepare(
        "UPDATE sales
         SET quantity = ?, unit_price = ?, amount = ?, discount_amount = ?,
             payment_method = ?, note = ?
         WHERE id = ? AND sale_date = ? AND is_return = 0"
    );
    $loadBasePrice = $pdo->prepare(
        "SELECT base_price FROM sales WHERE id = ? AND is_return = 0"
    );
    $deleteSaleById = $pdo->prepare(
        "DELETE FROM sales WHERE id = ? AND sale_date = ? AND is_return = 0"
    );
    // Удаление возвратов привязано к (orig_id, sale_date) исходной продажи —
    // иначе DELETE сносил бы все возвраты, даже если сама продажа была из
    // другого дня и в текущем запросе её удалить не получится.
    $deleteReturnsByOrig = $pdo->prepare(
        "DELETE FROM sales WHERE original_sale_id = ? AND is_return = 1"
    );
    // Проверка, что продажа действительно принадлежит запрошенному дню.
    // Без этой проверки $deleteReturnsByOrig мог бы удалить чужие возвраты
    // (см. комментарий ниже).
    $checkSaleDate = $pdo->prepare(
        "SELECT 1 FROM sales WHERE id = ? AND sale_date = ? AND is_return = 0"
    );

    $pdo->beginTransaction();

    // 0) Удаления продаж по списку id ---------------------------
    //
    // Безопасность: проверяем, что каждый id принадлежит $postDate.
    // Иначе атакующий с валидным CSRF мог бы прислать
    // {date: 'today', deleted: [<id из старого дня>]} и снести
    // все возвраты по этой старой продаже (deleteReturnsByOrig
    // не имеет фильтра по дате — он не может его иметь, потому что
    // возвраты лежат на дате возврата, а не дате исходной продажи).
    $deletedIds = $payload['deleted'] ?? [];
    if (is_array($deletedIds)) {
        foreach ($deletedIds as $rawId) {
            $id = (int)$rawId;
            if ($id <= 0) continue;
            $checkSaleDate->execute([$id, $postDate]);
            if (!$checkSaleDate->fetchColumn()) continue; // не наша продажа — игнор
            $deleteReturnsByOrig->execute([$id]);
            $deleteSaleById->execute([$id, $postDate]);
        }
    }

    // 1) Создание/обновление продаж ----------------------------
    $ops = $payload['ops'] ?? [];
    if (is_array($ops)) {
        foreach ($ops as $op) {
            if (!is_array($op)) continue;
            $localKey = isset($op['local_key']) ? (string)$op['local_key'] : '';
            $pid = (int)($op['pid'] ?? 0);
            $qty = max(0, (int)($op['qty'] ?? 0));
            if ($pid <= 0 || $qty <= 0) continue;

            $rawUprice = $op['uprice'] ?? null;
            $unitPrice = $rawUprice !== null
                ? max(0, (float)str_replace(',', '.', (string)$rawUprice))
                : ($priceMap[$pid] ?? 0.0);
            $amount = round($qty * $unitPrice, 2);

            $payment = (string)($op['payment'] ?? 'cash');
            if (!in_array($payment, $ALLOWED_PAYMENTS, true)) $payment = 'cash';

            $note = trim((string)($op['note'] ?? ''));
            if ($note === '') {
                $note = null;
            } elseif (mb_strlen($note) > 500) {
                // Раньше тихо обрезали — пользователь не понимал, почему
                // часть заметки исчезла. Теперь отвергаем явно.
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'Заметка длиннее 500 символов'], 400);
            }

            $opId = isset($op['id']) && $op['id'] !== null && $op['id'] !== ''
                ? (int)$op['id'] : 0;

            if ($opId > 0) {
                // Обновление существующей продажи. base_price и sold_at не трогаем.
                $loadBasePrice->execute([$opId]);
                $basePrice = (float)($loadBasePrice->fetchColumn() ?: 0);
                $discount  = max(0, round($qty * $basePrice - $amount, 2));
                $updateSale->execute([
                    $qty, $unitPrice, $amount, $discount,
                    $payment, $note, $opId, $postDate,
                ]);
            } else {
                // Новая продажа.
                $basePrice = $priceMap[$pid] ?? 0.0;
                $discount  = max(0, round($qty * $basePrice - $amount, 2));
                $soldAt = ($postDate === $today)
                    ? date('Y-m-d H:i:s')
                    : ($postDate . ' 12:00:00');
                $insertSale->execute([
                    $pid, $postDate, $qty, $basePrice, $unitPrice, $amount,
                    $discount, $soldAt, $payment, $note,
                ]);
                $newId = (int)$pdo->lastInsertId();
                if ($localKey !== '') {
                    $idMap[$localKey] = $newId;
                    $timeMap[$localKey] = $soldAt;
                }
            }
        }
    }

    // 2) Возвраты -------------------------------------------------
    $returns = $payload['returns'] ?? [];
    if (is_array($returns) && !empty($returns)) {
        $loadOrig = $pdo->prepare(
            "SELECT id, product_id, quantity, base_price, unit_price, payment_method
             FROM sales WHERE id = ? AND is_return = 0"
        );
        $sumOtherReturns = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity), 0) FROM sales
             WHERE original_sale_id = ? AND is_return = 1 AND sale_date <> ?"
        );
        $findTodayRet = $pdo->prepare(
            "SELECT id FROM sales
             WHERE original_sale_id = ? AND is_return = 1 AND sale_date = ?"
        );
        $deleteRet = $pdo->prepare("DELETE FROM sales WHERE id = ?");
        $updateRet = $pdo->prepare(
            "UPDATE sales SET quantity = ?, base_price = ?, unit_price = ?, amount = ?, discount_amount = ?
             WHERE id = ?"
        );
        $insertRet = $pdo->prepare(
            "INSERT INTO sales
                (product_id, sale_date, quantity, base_price, unit_price, amount,
                 discount_amount, is_return, original_sale_id, sold_at, payment_method)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)"
        );

        foreach ($returns as $origId => $rawQty) {
            $origId = (int)$origId;
            $qty = max(0, (int)$rawQty);

            $loadOrig->execute([$origId]);
            $orig = $loadOrig->fetch();
            if (!$orig) continue;

            $sumOtherReturns->execute([$origId, $postDate]);
            $returnedOther = (int)$sumOtherReturns->fetchColumn();
            $maxAllowed    = max(0, (int)$orig['quantity'] - $returnedOther);
            if ($qty > $maxAllowed) $qty = $maxAllowed;

            $findTodayRet->execute([$origId, $postDate]);
            $existingId = $findTodayRet->fetchColumn();

            if ($qty === 0) {
                if ($existingId) $deleteRet->execute([$existingId]);
                continue;
            }

            $bp = (float)$orig['base_price'];
            $up = (float)$orig['unit_price'];
            $amount = round($qty * $up, 2);
            $disc   = max(0, round($qty * $bp - $amount, 2));

            if ($existingId) {
                $updateRet->execute([$qty, $bp, $up, $amount, $disc, $existingId]);
            } elseif ($postDate === $today) {
                // Новые возвраты — только за сегодня
                $origPayment = $orig['payment_method'] ?: 'cash';
                $retSoldAt = date('Y-m-d H:i:s');
                $insertRet->execute([
                    (int)$orig['product_id'], $postDate,
                    $qty, $bp, $up, $amount, $disc, $origId,
                    $retSoldAt, $origPayment,
                ]);
                $retTimeMap[$origId] = $retSoldAt;
            }
        }
    }

    $pdo->commit();
    json_out(['ok' => true, 'id_map' => $idMap, 'times' => $timeMap, 'ret_times' => $retTimeMap]);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('daily_save error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    json_out(['ok' => false, 'error' => 'Внутренняя ошибка сервера. Попробуйте обновить страницу.'], 500);
}
