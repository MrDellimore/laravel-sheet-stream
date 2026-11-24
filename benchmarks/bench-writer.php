<?php

/**
 * Write N rows through a writer driver, the way ExportRunner does: a styled heading row,
 * then rows with a column style and dates.
 *
 * Usage:  php [-d extension=…/librusty_sheet.so] benchmarks/bench-writer.php <openspout|rust> <out.xlsx|out.csv> [rows]
 */

require __DIR__.'/../vendor/autoload.php';

use MrDellimore\SheetStream\Engine\EngineFactory;
use OpenSpout\Common\Entity\Style\Style;

[$_, $driver, $path] = $argv;
$rows = (int) ($argv[3] ?? 400_000);
$ext = pathinfo($path, PATHINFO_EXTENSION);

$start = microtime(true);
$writer = EngineFactory::writer($driver, $ext);
$writer->openToFile($path);
$writer->addSheet('Export');
$writer->addRow(array_map(fn ($i) => "Column $i", range(1, 20)), new Style(fontBold: true));
$money = (new Style)->withFormat('#,##0.00');
$date = new DateTimeImmutable('2025-06-15 14:30:00');
for ($i = 0; $i < $rows; $i++) {
    $row = [];
    for ($c = 0; $c < 20; $c++) {
        $row[] = match ($c % 5) {
            0 => $i,
            1 => 'customer '.($i % 997),
            2 => $i / 7,
            3 => $date,
            4 => $i % 3 === 0 ? null : 'note',
        };
    }
    $writer->addRow($row, null, [2 => $money, 7 => $money]);
}
$writer->close();
$secs = microtime(true) - $start;
printf("%-9s %s  rows: %d  time: %.2fs  rows/s: %s  size: %.1f MB\n", $driver, $ext, $rows, $secs, number_format($rows / $secs), filesize($path) / 1048576);
