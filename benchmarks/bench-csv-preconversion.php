<?php

/**
 * Benchmark: CSV pre-conversion vs direct XLSX read for the staging producer.
 *
 * Compares the file staging pipeline with and without WithCsvPreConversion.
 *
 * Usage:  php benchmarks/bench-csv-preconversion.php <path-to-xlsx> [chunk_size] [batch_size]
 */

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Support\Str;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Staging\FileStagingStore;
use MrDellimore\SheetStream\Support\CsvConverter;

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$filePath = $argv[1] ?? null;

if ($filePath === null || ! file_exists($filePath)) {
    if ($filePath !== null && file_exists(__DIR__.'/../'.$filePath)) {
        $filePath = __DIR__.'/../'.$filePath;
    } else {
        echo "Usage: php benchmarks/bench-csv-preconversion.php <path-to-xlsx> [chunk_size] [batch_size]\n";
        exit(1);
    }
}

$filePath = realpath($filePath);
$chunkSize = (int) ($argv[2] ?? 1000);
$batchSize = (int) ($argv[3] ?? 500);
$fileSize = round(filesize($filePath) / 1024 / 1024, 2);

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║       CSV Pre-Conversion Benchmark                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";
echo "File:        ".basename($filePath)."\n";
echo "File size:   {$fileSize} MB\n";
echo "Chunk size:  {$chunkSize}\n";
echo "Batch size:  {$batchSize}\n";
echo "Date:        ".date('Y-m-d H:i:s')."\n";
echo "PHP:         ".PHP_VERSION."\n\n";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatTime(float $seconds): string
{
    if ($seconds < 60) {
        return round($seconds, 2).'s';
    }
    $minutes = floor($seconds / 60);
    $secs = round($seconds - ($minutes * 60), 1);

    return "{$minutes}m {$secs}s";
}

function formatMemory(int $bytes): string
{
    return round($bytes / 1024 / 1024, 2).' MB';
}

function progressDot(int $count, int $interval = 25000): void
{
    if ($count % $interval === 0) {
        echo '  ...'.number_format($count)." rows\n";
    }
}

function stageRows(FileStagingStore $store, string $importId, iterable $sheetRows, int $sheetIndex, string $sheetName, int $chunkSize, int $batchSize): array
{
    $headings = null;
    $headingCount = 0;
    $rowNumber = 0;
    $maxChunk = -1;
    $batch = [];
    $now = date('Y-m-d H:i:s');

    foreach ($sheetRows as $rawRow) {
        $rawRow = array_values($rawRow);

        if ($headings === null) {
            $headings = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rawRow);
            $headingCount = count($headings);

            continue;
        }

        $rowNumber++;

        $padded = array_pad($rawRow, $headingCount, null);
        $row = array_combine($headings, array_slice($padded, 0, $headingCount));

        $chunkNumber = (int) floor(($rowNumber - 1) / $chunkSize);
        if ($chunkNumber > $maxChunk) {
            $maxChunk = $chunkNumber;
        }

        $batch[] = [
            'import_id' => $importId,
            'sheet_index' => $sheetIndex,
            'sheet_name' => $sheetName,
            'chunk_number' => $chunkNumber,
            'row_number' => $rowNumber,
            'row_data' => $row,
            'created_at' => $now,
        ];

        if (count($batch) >= $batchSize) {
            $store->insertBatch($batch);
            $batch = [];
        }

        progressDot($rowNumber);
    }

    if ($batch !== []) {
        $store->insertBatch($batch);
    }

    return [$rowNumber, $maxChunk + 1, $headingCount];
}

// ===========================================================================
// TEST 1: Direct XLSX Read (current approach)
// ===========================================================================

echo str_repeat('─', 62)."\n";
echo "TEST 1: Direct XLSX Read → File Staging (current)\n";
echo str_repeat('─', 62)."\n";

$t1StoreBase = sys_get_temp_dir().'/bench_csv_direct_'.getmypid();
$t1Store = new FileStagingStore($t1StoreBase);
$t1ImportId = Str::uuid()->toString();

echo "\n  Reading XLSX with OpenSpout...\n";
memory_reset_peak_usage();
$t1Start = microtime(true);

$reader = new OpenSpoutReader();
$reader->open($filePath);

$t1TotalRows = 0;
$t1Chunks = 0;
$t1Columns = 0;

foreach ($reader->sheets() as $sheetIndex => $sheet) {
    [$rows, $chunks, $cols] = stageRows($t1Store, $t1ImportId, $sheet->rows(), $sheetIndex, $sheet->name(), $chunkSize, $batchSize);
    $t1TotalRows += $rows;
    $t1Chunks += $chunks;
    $t1Columns = max($t1Columns, $cols);
}

$reader->close();

$t1Elapsed = microtime(true) - $t1Start;
$t1Peak = memory_get_peak_usage(true);

echo "\n  Rows staged:     ".number_format($t1TotalRows)."\n";
echo "  Columns:         {$t1Columns}\n";
echo "  Chunks:          {$t1Chunks}\n";
echo "  Total time:      ".formatTime($t1Elapsed)."\n";
echo "  Peak memory:     ".formatMemory($t1Peak)."\n";
echo "  Rows/sec:        ".number_format(round($t1TotalRows / $t1Elapsed))."\n";

// Cleanup
$ndjsonFiles = glob($t1StoreBase.'/*/*.ndjson') ?: [];
foreach ($ndjsonFiles as $f) {
    @unlink($f);
}
$dirs = glob($t1StoreBase.'/*', GLOB_ONLYDIR) ?: [];
foreach ($dirs as $d) {
    @rmdir($d);
}
@rmdir($t1StoreBase);

// ===========================================================================
// TEST 2: CSV Pre-Conversion → CSV Read → File Staging
// ===========================================================================

echo "\n".str_repeat('─', 62)."\n";
echo "TEST 2: CSV Pre-Conversion → File Staging (WithCsvPreConversion)\n";
echo str_repeat('─', 62)."\n";

$converter = new CsvConverter();
if (! $converter->isAvailable()) {
    echo "\n  ⚠ No CSV converter available. Skipping test.\n";
    exit(1);
}

$t2StoreBase = sys_get_temp_dir().'/bench_csv_preconv_'.getmypid();
$t2Store = new FileStagingStore($t2StoreBase);
$t2ImportId = Str::uuid()->toString();

// Phase 1: Convert XLSX → CSV
echo "\n  Phase 1: Converting XLSX → CSV with ssconvert...\n";
memory_reset_peak_usage();
$t2ConvertStart = microtime(true);

$conversion = $converter->convert($filePath);

$t2ConvertElapsed = microtime(true) - $t2ConvertStart;
$t2ConvertPeak = memory_get_peak_usage(true);

echo "  Conversion time: ".formatTime($t2ConvertElapsed)."\n";
echo "  Sheets found:    ".count($conversion->csvPaths)."\n";
foreach ($conversion->csvPaths as $idx => $csvPath) {
    $csvSize = round(filesize($csvPath) / 1024 / 1024, 2);
    $sheetName = $conversion->sheetNames[$idx] ?? "Sheet {$idx}";
    echo "    Sheet {$idx} ({$sheetName}): {$csvSize} MB\n";
}

// Phase 2: Read CSVs → Stage
echo "\n  Phase 2: Reading CSVs → staging to NDJSON...\n";
memory_reset_peak_usage();
$t2ReadStart = microtime(true);

$t2TotalRows = 0;
$t2Chunks = 0;
$t2Columns = 0;

foreach ($conversion->csvPaths as $sheetIndex => $csvPath) {
    $sheetName = $conversion->sheetNames[$sheetIndex] ?? "Sheet {$sheetIndex}";
    echo "    Processing sheet {$sheetIndex} ({$sheetName})...\n";

    $reader = EngineFactory::reader('openspout', []);
    $reader->open($csvPath);

    foreach ($reader->sheets() as $sheetReader) {
        [$rows, $chunks, $cols] = stageRows($t2Store, $t2ImportId, $sheetReader->rows(), $sheetIndex, $sheetName, $chunkSize, $batchSize);
        $t2TotalRows += $rows;
        $t2Chunks += $chunks;
        $t2Columns = max($t2Columns, $cols);
        break; // CSV is single-sheet
    }

    $reader->close();
}

$t2ReadElapsed = microtime(true) - $t2ReadStart;
$t2ReadPeak = memory_get_peak_usage(true);
$t2TotalElapsed = $t2ConvertElapsed + $t2ReadElapsed;

// Cleanup conversion files
$conversion->cleanup();

echo "\n  CSV read time:   ".formatTime($t2ReadElapsed)."\n";
echo "  Rows staged:     ".number_format($t2TotalRows)."\n";
echo "  Columns:         {$t2Columns}\n";
echo "  Chunks:          {$t2Chunks}\n";
echo "  Read peak mem:   ".formatMemory($t2ReadPeak)."\n";
echo "  Rows/sec:        ".number_format(round($t2TotalRows / $t2ReadElapsed))."\n";

// Cleanup staging files
$ndjsonFiles = glob($t2StoreBase.'/*/*.ndjson') ?: [];
foreach ($ndjsonFiles as $f) {
    @unlink($f);
}
$dirs = glob($t2StoreBase.'/*', GLOB_ONLYDIR) ?: [];
foreach ($dirs as $d) {
    @rmdir($d);
}
@rmdir($t2StoreBase);

// ===========================================================================
// SUMMARY
// ===========================================================================

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo "║                      SUMMARY                               ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "| Metric                  | Direct XLSX  | CSV Pre-Conv |\n";
echo "|-------------------------|--------------|──────────────|\n";
printf("| Rows staged             | %12s | %12s |\n", number_format($t1TotalRows), number_format($t2TotalRows));
printf("| Chunks                  | %12s | %12s |\n", $t1Chunks, $t2Chunks);
printf("| Conversion time         | %12s | %12s |\n", 'n/a', formatTime($t2ConvertElapsed));
printf("| Read + stage time       | %12s | %12s |\n", formatTime($t1Elapsed), formatTime($t2ReadElapsed));
printf("| **Total time**          | %12s | %12s |\n", formatTime($t1Elapsed), formatTime($t2TotalElapsed));
printf("| Peak memory             | %12s | %12s |\n", formatMemory($t1Peak), formatMemory($t2ReadPeak));

$speedup = $t1Elapsed / $t2TotalElapsed;
echo "\n";
printf("Speedup: %.1fx faster with CSV pre-conversion\n", $speedup);
printf("Time saved: %s\n", formatTime($t1Elapsed - $t2TotalElapsed));

$rowDiff = $t2TotalRows - $t1TotalRows;
if ($rowDiff !== 0) {
    echo "\n⚠ Row count difference: {$rowDiff} rows\n";
    echo "  This may be due to ssconvert handling trailing empty rows differently.\n";
}

echo "\n";
