<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_admin();

// Подключение библиотеки для работы с Excel
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

const RU_MONTHS = [
    'янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4, 'май' => 5,  'июн' => 6,
    'июл' => 7, 'авг' => 8, 'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12,
];

const SOURCE_FILE = 'jul2.xlsx';

$pdo     = db();
$log     = [];
$started = false;

/** Прочитать значение клетки по индексу столбца/строки. */
function cell_value($worksheet, int $col, int $row) {
    return $worksheet->getCell([$col, $row])->getValue();
}

/**
 * Преобразовать значение в дату «Y-m-d».
 * Поддерживает: Excel-serial, «01.мар», «01.03.2025».
 * Возвращает null, если распознать не удалось.
 */
function parse_excel_date($value, int $sheetYear): ?string {
    if ($value === null || $value === '') return null;

    if (is_numeric($value)) {
        try {
            return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    $str = trim((string)$value);
    if (preg_match('/^(\d{1,2})\.([а-яёА-ЯЁ]+)$/u', $str, $m)) {
        $abbr = mb_strtolower($m[2]);
        if (!isset(RU_MONTHS[$abbr])) return null;
        return sprintf('%04d-%02d-%02d', $sheetYear, RU_MONTHS[$abbr], (int)$m[1]);
    }

    try {
        return (new DateTime(str_replace('.', '-', $str)))->format('Y-m-d');
    } catch (\Throwable) {
        return null;
    }
}

/** Из имени листа («март 2025») вычислить номер месяца. */
function detect_month(string $sheetName): ?int {
    foreach (RU_MONTHS as $abbr => $num) {
        if (mb_stripos($sheetName, $abbr) !== false) {
            return $num;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    csrf_check();
    $started  = true;
    $filePath = __DIR__ . '/' . SOURCE_FILE;

    if (!file_exists($filePath)) {
        $log[] = ['error', "Файл не найден: $filePath"];
    } else {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);

            // Кэш существующих товаров: lower(name) => id
            $productCache = [];
            foreach ($pdo->query("SELECT id, name FROM products")->fetchAll() as $r) {
                $productCache[mb_strtolower(trim($r['name']))] = (int)$r['id'];
            }

            $insertProductSql = "INSERT INTO products (name) VALUES (?)";
            $upsertPriceSql   = <<<SQL
                INSERT INTO product_prices (product_id, price, valid_from)
                VALUES (?, ?, ?)
                ON CONFLICT(product_id, valid_from) DO UPDATE SET price = excluded.price
            SQL;
            $upsertSaleSql    = <<<SQL
                INSERT INTO sales (product_id, sale_date, quantity, base_price, unit_price, amount)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(product_id, sale_date) DO UPDATE SET
                    quantity   = excluded.quantity,
                    base_price = excluded.base_price,
                    unit_price = excluded.unit_price,
                    amount     = excluded.amount
            SQL;

            $insertProduct = $pdo->prepare($insertProductSql);
            $upsertPrice   = $pdo->prepare($upsertPriceSql);
            $upsertSale    = $pdo->prepare($upsertSaleSql);

            $totalProducts = 0;
            $totalSales    = 0;

            foreach ($spreadsheet->getSheetNames() as $sheetName) {
                $ws = $spreadsheet->getSheetByName($sheetName);

                // Год и месяц — из имени листа
                if (!preg_match('/(\d{4})/', $sheetName, $mYear)) {
                    $log[] = ['warn', "Лист «{$sheetName}»: в названии нет года — пропущен."];
                    continue;
                }
                $sheetYear  = (int)$mYear[1];
                $sheetMonth = detect_month($sheetName);
                if ($sheetMonth === null) {
                    $log[] = ['warn', "Лист «{$sheetName}»: не удалось определить месяц — пропущен."];
                    continue;
                }
                $priceDate = sprintf('%04d-%02d-01', $sheetYear, $sheetMonth);

                // Сбор дат из строки 1; каждая дата занимает 2 столбца (шт. + руб.)
                $maxColIdx = Coordinate::columnIndexFromString($ws->getHighestDataColumn());
                $dateCols  = [];                                // colIdx => 'YYYY-MM-DD'
                for ($col = 3; $col <= $maxColIdx; $col += 2) {
                    $date = parse_excel_date(cell_value($ws, $col, 1), $sheetYear);
                    if ($date !== null) {
                        $dateCols[$col] = $date;
                    }
                }

                if (!$dateCols) {
                    $log[] = ['warn', "Лист «{$sheetName}»: не найдено столбцов с датами — пропущен."];
                    continue;
                }

                $log[] = ['info', "Лист «{$sheetName}»: найдено дат: " . count($dateCols)];

                $maxRow        = $ws->getHighestDataRow();
                $sheetProducts = 0;
                $sheetSales    = 0;

                $pdo->beginTransaction();
                try {
                    for ($row = 3; $row <= $maxRow; $row++) {
                        $name      = trim((string)cell_value($ws, 1, $row));
                        $priceCell = cell_value($ws, 2, $row);

                        // Пропускаем пустые/итоговые строки
                        if ($name === '')                                 continue;
                        if ($priceCell === null || $priceCell === '')     continue;
                        if (preg_match('/^(итого|всего|возврат)/iu', $name)) continue;

                        $price = (float)str_replace(',', '.', (string)$priceCell);
                        if ($price < 0) $price = 0;

                        $key = mb_strtolower($name);
                        if (!isset($productCache[$key])) {
                            $insertProduct->execute([$name]);
                            $productCache[$key] = (int)$pdo->lastInsertId();
                            $sheetProducts++;
                            $totalProducts++;
                        }
                        $pid = $productCache[$key];

                        // Цена на 1-е число месяца листа
                        if ($price > 0) {
                            $upsertPrice->execute([$pid, $price, $priceDate]);
                        }

                        // Продажи за каждый день
                        foreach ($dateCols as $col => $dateStr) {
                            $rawQty = cell_value($ws, $col, $row);
                            if ($rawQty === null || $rawQty === '') continue;

                            $numQty = (float)str_replace(',', '.', (string)$rawQty);
                            if ($numQty <= 0) continue;

                            $qty = (int)$numQty;
                            if (abs($numQty - $qty) > 0.0001) {
                                $log[] = ['warn',
                                    "  · «{$name}» / {$dateStr}: дробное qty {$numQty} → округлено вниз до {$qty}"];
                                if ($qty <= 0) continue;
                            }

                            $rawAmount = cell_value($ws, $col + 1, $row);
                            $amount    = (float)str_replace(',', '.', (string)$rawAmount);
                            if ($amount < 0) $amount = 0;

                            $unitPrice = round($amount / $qty, 4);
                            $upsertSale->execute([$pid, $dateStr, $qty, $price, $unitPrice, $amount]);
                            $sheetSales++;
                            $totalSales++;
                        }
                    }

                    $pdo->commit();
                } catch (\Throwable $innerE) {
                    $pdo->rollBack();
                    throw $innerE;
                }

                $log[] = ['info', "  → Новых товаров: {$sheetProducts}, записей продаж: {$sheetSales}"];
            }

            $log[] = ['success',
                "Импорт завершён. Всего новых товаров: {$totalProducts}, записей продаж: {$totalSales}."];

        } catch (\Throwable $e) {
            $log[] = ['error',
                'Ошибка: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
        }
    }
}

layout_header('Импорт данных');
?>
<h1 class="page-title">Импорт данных из Excel</h1>

<div class="card">
    <div class="card-title">Источник данных</div>
    <div class="alert alert-info alert-mb">
        Импортирует данные из файла <strong><?= htmlspecialchars(SOURCE_FILE) ?></strong>,
        расположенного в корневой папке приложения.
        Операция безопасна для повторного запуска — существующие записи обновятся, дубликаты не создадутся.
    </div>
    <form method="post" id="import-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do_import" value="1">
        <button type="submit" class="btn btn-primary" id="import-btn">
            <?= icon('play', 16) ?>Запустить импорт
        </button>
    </form>
</div>

<script src="assets/import.js?v=<?= asset_v('assets/import.js') ?>" defer></script>

<?php if ($started && !empty($log)): ?>
<div class="card">
    <div class="card-title">Лог импорта</div>
    <div class="log-wrap">
    <?php foreach ($log as [$type, $text]): ?>
        <?php $cls = match($type) { 'success'=>'log-success','error'=>'log-error','warn'=>'log-warn',default=>'log-info' }; ?>
        <div class="<?= $cls ?>"><?= htmlspecialchars($text) ?></div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php layout_footer(); ?>
