<?php
/**
 * WC Экспорт — выгрузка отчёта WooCommerce в Excel.
 * Только для администратора.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/woo_db.php';
require_once __DIR__ . '/woo_helpers.php';
require_once __DIR__ . '/../helpers.php';

require_login();

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$wooDB = woo_db();

// ── Параметры ─────────────────────────────────────────────
$from = $_GET['from'] ?? null;
$to   = $_GET['to']   ?? null;
$grp  = $_GET['group'] ?? 'product';

if (!$from || !$to || !valid_date($from) || !valid_date($to)) {
    $from = date('Y-m-01');
    $to   = date('Y-m-d');
}
if ($from > $to) [$from, $to] = [$to, $from];

$validGroups = ['product', 'category', 'day', 'month', 'customer', 'payment', 'status', 'city', 'source'];
if (!in_array($grp, $validGroups)) $grp = 'product';

// ── Данные ────────────────────────────────────────────────
$report = woo_sales_report($wooDB, $from, $to, ['group_by' => $grp]);
$rows   = $report['rows'];
$totals = $report['totals'];
$hasDiscounts = abs($totals['discount'] ?? 0) > 0.005;

// ── Книга ─────────────────────────────────────────────────
$sp = new Spreadsheet();
$ws = $sp->getActiveSheet();

$periodLabel = $from === $to ? date('d.m.Y', strtotime($from))
    : date('d.m.Y', strtotime($from)) . ' — ' . date('d.m.Y', strtotime($to));
$ws->setTitle(mb_substr('WC Отчёт ' . $periodLabel, 0, 31));

$weekdayShort = weekday_names_short();
$ruMonths = ['','Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];

// ── Конфигурация колонок ──────────────────────────────────
$layouts = [
    'product'  => [
        'headers' => ['#', 'Товар', 'Категория', 'Кол-во (шт.)', 'Скидка (руб.)', 'Сумма (руб.)'],
        'widths'  => [5, 42, 22, 14, 16, 16],
    ],
    'category' => [
        'headers' => ['#', 'Категория', 'Кол-во (шт.)', 'Заказов', 'Сумма (руб.)'],
        'widths'  => [5, 30, 14, 12, 18],
    ],
    'day' => [
        'headers' => ['#', 'Дата', 'День недели', 'Заказов', 'Кол-во (шт.)', 'Сумма (руб.)'],
        'widths'  => [5, 14, 14, 12, 14, 18],
    ],
    'month' => [
        'headers' => ['Месяц', 'Заказов', 'Кол-во (шт.)', 'Скидка (руб.)', 'Сумма (руб.)'],
        'widths'  => [20, 12, 14, 16, 18],
    ],
    'customer' => [
        'headers' => ['#', 'Клиент', 'Email', 'Заказов', 'Сумма (руб.)', 'Ср. чек (руб.)'],
        'widths'  => [5, 28, 28, 12, 16, 16],
    ],
    'payment' => [
        'headers' => ['Способ оплаты', 'Заказов', 'Сумма (руб.)'],
        'widths'  => [28, 12, 18],
    ],
    'status' => [
        'headers' => ['Статус', 'Заказов', 'Сумма (руб.)'],
        'widths'  => [22, 12, 18],
    ],
    'city' => [
        'headers' => ['Город', 'Заказов', 'Сумма (руб.)'],
        'widths'  => [28, 12, 18],
    ],
    'source' => [
        'headers' => ['Источник', 'Заказов', 'Сумма (руб.)'],
        'widths'  => [28, 12, 18],
    ],
];

$layout  = $layouts[$grp] ?? $layouts['product'];
$headers = $layout['headers'];
$widths  = $layout['widths'];

// Убираем скидку если нет
if (!$hasDiscounts) {
    $discIndex = array_search('Скидка (руб.)', $headers, true);
    if ($discIndex !== false) {
        array_splice($headers, $discIndex, 1);
        array_splice($widths,  $discIndex, 1);
    }
}

$lastColIdx = count($headers);
$lastCol = Coordinate::stringFromColumnIndex($lastColIdx);

// ── Заголовок ─────────────────────────────────────────────
$title = 'WooCommerce — Отчёт за ' . $periodLabel;
$ws->mergeCells('A1:' . $lastCol . '1');
$ws->setCellValue('A1', $title);
$ws->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$ws->getRowDimension(1)->setRowHeight(22);

// Шапка
$ws->fromArray($headers, null, 'A3');
$ws->getStyle('A3:' . $lastCol . '3')->applyFromArray([
    'font'      => ['bold' => true],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9E1F2']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);

// ── Тело ──────────────────────────────────────────────────
$row = 4;
foreach ($rows as $i => $r) {
    $col = 1;

    switch ($grp) {
        case 'product':
            $ws->setCellValue([$col++, $row], $i + 1);
            $ws->setCellValue([$col++, $row], $r['name'] ?? '');
            $ws->setCellValue([$col++, $row], $r['category'] ?? '');
            $ws->setCellValue([$col++, $row], (int)($r['qty'] ?? 0));
            if ($hasDiscounts) $ws->setCellValue([$col++, $row], (float)($r['discount'] ?? 0));
            $ws->setCellValue([$col++, $row], (float)($r['revenue'] ?? 0));
            break;

        case 'category':
            $ws->setCellValue([$col++, $row], $i + 1);
            $ws->setCellValue([$col++, $row], $r['name'] ?? '');
            $ws->setCellValue([$col++, $row], (int)($r['qty'] ?? 0));
            $ws->setCellValue([$col++, $row], (int)($r['orders'] ?? 0));
            $ws->setCellValue([$col++, $row], (float)($r['revenue'] ?? 0));
            break;

        case 'day':
            $dt = new DateTime($r['day']);
            $ws->setCellValue([$col++, $row], $i + 1);
            $ws->setCellValue([$col++, $row], $dt->format('d.m.Y'));
            $ws->setCellValue([$col++, $row], $weekdayShort[(int)$dt->format('N')] ?? '');
            $ws->setCellValue([$col++, $row], (int)($r['orders'] ?? 0));
            $ws->setCellValue([$col++, $row], (int)($r['qty'] ?? 0));
            $ws->setCellValue([$col++, $row], (float)($r['revenue'] ?? 0));
            break;

        case 'month':
            $parts = explode('-', $r['month'] ?? '');
            $monthName = ($ruMonths[(int)($parts[1] ?? 0)] ?? '') . ' ' . ($parts[0] ?? '');
            $ws->setCellValue([$col++, $row], $monthName);
            $ws->setCellValue([$col++, $row], (int)($r['orders'] ?? 0));
            $ws->setCellValue([$col++, $row], (int)($r['qty'] ?? 0));
            if ($hasDiscounts) $ws->setCellValue([$col++, $row], (float)($r['discount'] ?? 0));
            $ws->setCellValue([$col++, $row], (float)($r['revenue'] ?? 0));
            break;

        case 'customer':
            $ws->setCellValue([$col++, $row], $i + 1);
            $ws->setCellValue([$col++, $row], $r['name'] ?: 'Гость');
            $ws->setCellValue([$col++, $row], $r['email'] ?? '');
            $ws->setCellValue([$col++, $row], (int)($r['orders'] ?? 0));
            $ws->setCellValue([$col++, $row], (float)($r['revenue'] ?? 0));
            $ws->setCellValue([$col++, $row], (float)($r['avg_order'] ?? 0));
            break;

        case 'payment':
            $ws->setCellValue([$col++, $row], $r['name'] ?? '—');
            $ws->setCellValue([$col++, $row], (int)($r['orders'] ?? 0));
            $ws->setCellValue([$col++, $row], (float)($r['revenue'] ?? 0));
            break;

        case 'status':
            $ws->setCellValue([$col++, $row], woo_status_label($r['name'] ?? ''));
            $ws->setCellValue([$col++, $row], (int)($r['orders'] ?? 0));
            $ws->setCellValue([$col++, $row], (float)($r['revenue'] ?? 0));
            break;

        case 'city':
            $ws->setCellValue([$col++, $row], $r['name'] ?? '—');
            $ws->setCellValue([$col++, $row], (int)($r['orders'] ?? 0));
            $ws->setCellValue([$col++, $row], (float)($r['revenue'] ?? 0));
            break;

        case 'source':
            $ws->setCellValue([$col++, $row], $r['name'] ?? '—');
            $ws->setCellValue([$col++, $row], (int)($r['orders'] ?? 0));
            $ws->setCellValue([$col++, $row], (float)($r['revenue'] ?? 0));
            break;
    }
    $row++;
}

// ── Итоги ─────────────────────────────────────────────────
$footRow = $row;

$labelSpans = [
    'product'  => 3,
    'category' => 2,
    'day'      => 3,
    'month'    => 1,
    'customer' => 3,
    'payment'  => 1,
    'status'   => 1,
    'city'     => 1,
    'source'   => 1,
];
$labelSpan = $labelSpans[$grp] ?? 1;

if ($labelSpan > 1) {
    $ws->mergeCells('A' . $footRow . ':' . Coordinate::stringFromColumnIndex($labelSpan) . $footRow);
}
$ws->setCellValue('A' . $footRow, 'Итого');

$colCursor = $labelSpan + 1;

switch ($grp) {
    case 'product':
        $ws->setCellValue([$colCursor++, $footRow], $totals['qty']);
        if ($hasDiscounts) $ws->setCellValue([$colCursor++, $footRow], $totals['discount']);
        $ws->setCellValue([$colCursor++, $footRow], $totals['revenue']);
        break;
    case 'category':
        $ws->setCellValue([$colCursor++, $footRow], $totals['qty']);
        $ws->setCellValue([$colCursor++, $footRow], $totals['orders']);
        $ws->setCellValue([$colCursor++, $footRow], $totals['revenue']);
        break;
    case 'day':
        $ws->setCellValue([$colCursor++, $footRow], $totals['orders']);
        $ws->setCellValue([$colCursor++, $footRow], $totals['qty']);
        $ws->setCellValue([$colCursor++, $footRow], $totals['revenue']);
        break;
    case 'month':
        $ws->setCellValue([$colCursor++, $footRow], $totals['orders']);
        $ws->setCellValue([$colCursor++, $footRow], $totals['qty']);
        if ($hasDiscounts) $ws->setCellValue([$colCursor++, $footRow], $totals['discount']);
        $ws->setCellValue([$colCursor++, $footRow], $totals['revenue']);
        break;
    case 'customer':
        $ws->setCellValue([$colCursor++, $footRow], $totals['orders']);
        $ws->setCellValue([$colCursor++, $footRow], $totals['revenue']);
        $ws->setCellValue([$colCursor++, $footRow], '');
        break;
    case 'payment':
    case 'status':
    case 'city':
    case 'source':
        $ws->setCellValue([$colCursor++, $footRow], $totals['orders']);
        $ws->setCellValue([$colCursor++, $footRow], $totals['revenue']);
        break;
}

$ws->getStyle('A' . $footRow . ':' . $lastCol . $footRow)->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2F7']],
]);

// ── Стили данных ──────────────────────────────────────────
if ($row > 4) {
    $ws->getStyle('A4:' . $lastCol . ($row - 1))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFDDDDDD']]],
    ]);
}

// Денежный формат для последнего столбца (или двух если есть скидка)
$moneyColCount = $hasDiscounts ? 2 : 1;
if ($grp === 'customer') $moneyColCount = 2; // Сумма + Ср. чек
$startMoneyCol = Coordinate::stringFromColumnIndex($lastColIdx - $moneyColCount + 1);
$ws->getStyle($startMoneyCol . '4:' . $lastCol . $footRow)
   ->getNumberFormat()->setFormatCode('#,##0.00');

// Ширина столбцов
foreach ($widths as $i => $w) {
    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
}

// ── Отдача файла ──────────────────────────────────────────
$filename = 'woo_sales_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to);
if ($grp !== 'product') $filename .= '_' . $grp;
$filename .= '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($sp))->save('php://output');
exit;
