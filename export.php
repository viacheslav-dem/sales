<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_login();

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$pdo = db();

// ── Параметры ──────────────────────────────────────────────
// Поддерживаем оба варианта: новый (from/to/group) и legacy (year/month).
$from = $_GET['from']  ?? null;
$to   = $_GET['to']    ?? null;
$grp  = $_GET['group'] ?? 'product';
$cat  = isset($_GET['cat']) && $_GET['cat'] !== '' ? (int)$_GET['cat'] : null;

if (!$from || !$to || !valid_date($from) || !valid_date($to)) {
    // Legacy: year+month → диапазон месяца
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    if ($month < 1 || $month > 12)        $month = (int)date('n');
    if ($year  < 2000 || $year  > 2100)   $year  = (int)date('Y');
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = date('Y-m-t', strtotime($from));
}
if ($from > $to) [$from, $to] = [$to, $from];

$opts = ['group_by' => $grp];
if ($cat !== null) $opts['category_id'] = $cat;

$report  = sales_in_range($pdo, $from, $to, $opts);
$rows    = $report['rows'];
$totals  = $report['totals'];
$hasDiscounts = abs($totals['discount']) > 0.005;

// ── Книга ──────────────────────────────────────────────────
$sp = new Spreadsheet();
$ws = $sp->getActiveSheet();

$periodLabel = $from === $to ? date('d.m.Y', strtotime($from))
    : date('d.m.Y', strtotime($from)) . ' — ' . date('d.m.Y', strtotime($to));
$ws->setTitle(mb_substr('Отчёт ' . $periodLabel, 0, 31));

$weekdayShort = weekday_names_short();

// Колонки в зависимости от группировки
$layouts = [
    'product'  => [
        'headers' => ['#', 'Наименование товара', 'Категория', 'Прайс (руб.)', 'Кол-во (шт.)', 'Скидка (руб.)', 'Сумма (руб.)'],
        'widths'  => [5, 38, 22, 14, 14, 16, 16],
    ],
    'category' => [
        'headers' => ['#', 'Категория', 'Кол-во (шт.)', 'Скидка (руб.)', 'Сумма (руб.)'],
        'widths'  => [5, 30, 14, 16, 18],
    ],
    'day'      => [
        'headers' => ['#', 'Дата', 'День недели', 'Кол-во (шт.)', 'Скидка (руб.)', 'Сумма (руб.)'],
        'widths'  => [5, 14, 14, 14, 16, 18],
    ],
    'weekday'  => [
        'headers' => ['День недели', 'Кол-во (шт.)', 'Скидка (руб.)', 'Сумма (руб.)'],
        'widths'  => [16, 14, 16, 18],
    ],
];
$layout  = $layouts[$grp] ?? $layouts['product'];
$headers = $layout['headers'];
$widths  = $layout['widths'];

// Если нет скидок — убираем колонку «Скидка»
if (!$hasDiscounts) {
    $discIndex = array_search('Скидка (руб.)', $headers, true);
    if ($discIndex !== false) {
        array_splice($headers, $discIndex, 1);
        array_splice($widths,  $discIndex, 1);
    }
}

$lastColIdx = count($headers); // 1-based last column
$lastCol = Coordinate::stringFromColumnIndex($lastColIdx);

// ── Заголовок отчёта ───────────────────────────────────────
$title = 'Отчёт о продажах за ' . $periodLabel;
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

// ── Тело таблицы ───────────────────────────────────────────
$row = 4;
foreach ($rows as $i => $r) {
    $col = 1;
    if ($grp === 'product') {
        $ws->setCellValue([$col++, $row], $i + 1);
        $ws->setCellValue([$col++, $row], $r['name']);
        $ws->setCellValue([$col++, $row], $r['category_name']);
        $ws->setCellValue([$col++, $row], (float)$r['catalog_price']);
        $ws->setCellValue([$col++, $row], (int)$r['net_qty']);
        if ($hasDiscounts) $ws->setCellValue([$col++, $row], (float)$r['net_discount']);
        $ws->setCellValue([$col++, $row], (float)$r['net_sum']);
    } elseif ($grp === 'category') {
        $ws->setCellValue([$col++, $row], $i + 1);
        $ws->setCellValue([$col++, $row], $r['name']);
        $ws->setCellValue([$col++, $row], (int)$r['net_qty']);
        if ($hasDiscounts) $ws->setCellValue([$col++, $row], (float)$r['net_discount']);
        $ws->setCellValue([$col++, $row], (float)$r['net_sum']);
    } elseif ($grp === 'day') {
        $dt = new DateTime($r['day']);
        $ws->setCellValue([$col++, $row], $i + 1);
        $ws->setCellValue([$col++, $row], $dt->format('d.m.Y'));
        $ws->setCellValue([$col++, $row], $weekdayShort[(int)$dt->format('N')]);
        $ws->setCellValue([$col++, $row], (int)$r['net_qty']);
        if ($hasDiscounts) $ws->setCellValue([$col++, $row], (float)$r['net_discount']);
        $ws->setCellValue([$col++, $row], (float)$r['net_sum']);
    } else { // weekday
        $w = (int)$r['weekday'];
        $wKey = $w === 0 ? 7 : $w;
        $ws->setCellValue([$col++, $row], $weekdayShort[$wKey]);
        $ws->setCellValue([$col++, $row], (int)$r['net_qty']);
        if ($hasDiscounts) $ws->setCellValue([$col++, $row], (float)$r['net_discount']);
        $ws->setCellValue([$col++, $row], (float)$r['net_sum']);
    }
    $row++;
}

// ── Итоги ──────────────────────────────────────────────────
$footRow = $row;
$labelSpan = 1;
switch ($grp) {
    case 'product':  $labelSpan = $hasDiscounts ? 4 : 3; break;
    case 'category': $labelSpan = 2; break;
    case 'day':      $labelSpan = 3; break;
    case 'weekday':  $labelSpan = 1; break;
}
if ($labelSpan > 1) {
    $ws->mergeCells('A' . $footRow . ':' . Coordinate::stringFromColumnIndex($labelSpan) . $footRow);
}
$ws->setCellValue('A' . $footRow, 'Итого');

$colCursor = $labelSpan + 1;
$ws->setCellValue([$colCursor++, $footRow], $totals['qty']);
if ($hasDiscounts) $ws->setCellValue([$colCursor++, $footRow], $totals['discount']);
$ws->setCellValue([$colCursor++, $footRow], $totals['sum']);

$ws->getStyle('A' . $footRow . ':' . $lastCol . $footRow)->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2F7']],
]);

// ── Стили данных ───────────────────────────────────────────
if ($row > 4) {
    $ws->getStyle('A4:' . $lastCol . ($row - 1))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFDDDDDD']]],
    ]);
}
// Денежный формат для последних 1-3 колонок
$startMoneyIdx = $hasDiscounts ? $lastColIdx - 1 : $lastColIdx;
$startMoneyCol = Coordinate::stringFromColumnIndex($startMoneyIdx);
$ws->getStyle($startMoneyCol . '4:' . $lastCol . $footRow)
   ->getNumberFormat()->setFormatCode('#,##0.00');

// Ширина столбцов
foreach ($widths as $i => $w) {
    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
}

// ── Отдача файла ───────────────────────────────────────────
$filename = 'sales_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to);
if ($grp !== 'product') $filename .= '_' . $grp;
$filename .= '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($sp))->save('php://output');
exit;
