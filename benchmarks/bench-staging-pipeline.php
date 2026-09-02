<?php

/**
 * Staging pipeline benchmark: compare database vs file staging drivers
 * against a real xlsx file.
 *
 * Usage:  php benchmarks/bench-staging-pipeline.php <path-to-xlsx> [chunk_size] [batch_size] [tests]
 *
 * The [tests] argument selects which tests to run: "all", "raw", "db", "file", or comma-separated.
 * Default: "all"
 *
 * Example:
 *   php benchmarks/bench-staging-pipeline.php "HHC - Zantac - 2nd Round Audit - Response 12-22-25 2.xlsx"
 *   php benchmarks/bench-staging-pipeline.php "HHC - Zantac - 2nd Round Audit - Response 12-22-25 2.xlsx" 1000 500
 *   php benchmarks/bench-staging-pipeline.php "HHC - Zantac - 2nd Round Audit - Response 12-22-25 2.xlsx" 1000 500 file
 *   php benchmarks/bench-staging-pipeline.php "HHC - Zantac - 2nd Round Audit - Response 12-22-25 2.xlsx" 1000 500 db,file
 *
 * This script:
 *   1. Reads the file with OpenSpout (baseline — raw streaming speed)
 *   2. Runs the full staging pipeline with the DATABASE driver (SQLite)
 *   3. Runs the full staging pipeline with the FILE driver (NDJSON)
 *
 * Results are printed to stdout in a format suitable for pasting into markdown.
 */

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Staging\DatabaseStagingStore;
use MrDellimore\SheetStream\Staging\FileStagingStore;

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$filePath = $argv[1] ?? null;

if ($filePath === null || ! file_exists($filePath)) {
    // Try relative to project root
    if ($filePath !== null && file_exists(__DIR__.'/../'.$filePath)) {
        $filePath = __DIR__.'/../'.$filePath;
    } else {
        echo "Usage: php benchmarks/bench-staging-pipeline.php <path-to-xlsx> [chunk_size] [batch_size]\n";
        exit(1);
    }
}

$filePath = realpath($filePath);
$chunkSize = (int) ($argv[2] ?? 1000);
$batchSize = (int) ($argv[3] ?? 500);
$testsArg = strtolower($argv[4] ?? 'all');
$runTests = $testsArg === 'all' ? ['raw', 'db', 'file'] : explode(',', $testsArg);
$fileSize = round(filesize($filePath) / 1024 / 1024, 2);

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║          Staging Pipeline Benchmark                        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";
echo "File:        ".basename($filePath)."\n";
echo "File size:   {$fileSize} MB\n";
echo "Chunk size:  {$chunkSize}\n";
echo "Batch size:  {$batchSize}\n";
echo "Tests:       ".implode(', ', $runTests)."\n";
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

function progressDot(int $count, int $interval = 10000): void
{
    if ($count % $interval === 0) {
        echo '  ...'.number_format($count)." rows\n";
    }
}

// Initialise result vars (may be set by tests below)
$t1Elapsed = 0;
$t1Peak = 0;
$expectedRows = 0;
$columnCount = 0;

$t2ProducerElapsed = 0;
$t2ConsumerElapsed = 0;
$t2TotalElapsed = 0;
$t2ProducerPeak = 0;
$t2ConsumerPeak = 0;
$t2AvgChunkTime = 0;
$dbRowsStaged = 0;
$dbChunkCount = 0;

$t3ProducerElapsed = 0;
$t3ConsumerElapsed = 0;
$t3TotalElapsed = 0;
$t3ProducerPeak = 0;
$t3ConsumerPeak = 0;
$t3AvgChunkTime = 0;
$fileRowsStaged = 0;
$fileChunkCount = 0;

// ===========================================================================
// TEST 1: Raw Streaming Read (Baseline)
// ===========================================================================

if (in_array('raw', $runTests)) {
echo str_repeat('─', 62)."\n";
echo "TEST 1: Raw Streaming Read (Baseline)\n";
echo str_repeat('─', 62)."\n";

memory_reset_peak_usage();
$t1Start = microtime(true);

$reader = new OpenSpoutReader();
$reader->open($filePath);

$totalRows = 0;
$headings = null;
$columnCount = 0;

foreach ($reader->sheets() as $sheet) {
    foreach ($sheet->rows() as $row) {
        if ($headings === null) {
            $headings = $row;
            $columnCount = count($headings);

            continue;
        }

        // Simulate heading row combination (what ImportRunner does)
        $assoc = array_combine(
            array_map(fn ($h) => mb_strtolower(trim((string) $h)), $headings),
            array_pad($row, $columnCount, null)
        );
        $totalRows++;
        progressDot($totalRows);
    }
}

$reader->close();

$t1Elapsed = microtime(true) - $t1Start;
$t1Peak = memory_get_peak_usage(true);

echo "\n  Rows:        ".number_format($totalRows)."\n";
echo "  Columns:     {$columnCount}\n";
echo "  Wall time:   ".formatTime($t1Elapsed)."\n";
echo "  Peak memory: ".formatMemory($t1Peak)."\n";
echo "  Rows/sec:    ".number_format(round($totalRows / $t1Elapsed))."\n\n";

$expectedRows = $totalRows;
} // end raw test

// ===========================================================================
// TEST 2: Database Staging Pipeline (SQLite)
// ===========================================================================

if (in_array('db', $runTests)) {
echo str_repeat('─', 62)."\n";
echo "TEST 2: Database Staging Pipeline (SQLite)\n";
echo str_repeat('─', 62)."\n";

// Bootstrap SQLite via Illuminate Database Capsule (no full Laravel needed)
$dbPath = sys_get_temp_dir().'/bench_staging_'.getmypid().'.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}
touch($dbPath);

$container = new Container;
$capsule = new Capsule($container);
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => $dbPath,
    'prefix' => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Wire up facades so DB::table(), DB::transaction() work
$container->instance('db', $capsule->getDatabaseManager());
Facade::setFacadeApplication($container);

// Apply SQLite pragmas for write performance
Capsule::connection()->statement('PRAGMA journal_mode = WAL');
Capsule::connection()->statement('PRAGMA synchronous = OFF');
Capsule::connection()->statement('PRAGMA temp_store = MEMORY');
Capsule::connection()->statement('PRAGMA cache_size = -64000'); // 64MB cache

// Create staging table
Capsule::schema()->create('sheet_stream_staging', function ($table) {
    $table->id();
    $table->char('import_id', 36);
    $table->unsignedSmallInteger('sheet_index')->default(0);
    $table->string('sheet_name')->default('');
    $table->unsignedInteger('chunk_number');
    $table->unsignedInteger('row_number');
    $table->longText('row_data');
    $table->timestamp('processed_at')->nullable();
    $table->timestamp('failed_at')->nullable();
    $table->text('error')->nullable();
    $table->timestamp('created_at')->useCurrent();
    $table->index(['import_id', 'sheet_index', 'chunk_number'], 'sss_import_sheet_chunk');
});

$dbStore = new DatabaseStagingStore('sheet_stream_staging');
$importId = Str::uuid()->toString();

// --- Phase 1: Producer (Read + Stage) ---
echo "\n  Phase 1: Producer (read → stage into DB)...\n";
memory_reset_peak_usage();
$t2ProducerStart = microtime(true);

$reader = new OpenSpoutReader();
$reader->open($filePath);

$headings = null;
$headingKeys = null;
$headingCount = 0;
$rowNumber = 0;
$maxChunk = -1;
$batch = [];
$now = date('Y-m-d H:i:s');

foreach ($reader->sheets() as $sheetIndex => $sheet) {
    foreach ($sheet->rows() as $rawRow) {
        $rawRow = array_values($rawRow);

        if ($headings === null) {
            $headingKeys = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rawRow);
            $headingCount = count($headingKeys);
            $headings = $rawRow;

            continue;
        }

        $rowNumber++;

        $padded = array_pad($rawRow, $headingCount, null);
        $row = array_combine($headingKeys, array_slice($padded, 0, $headingCount));

        $chunkNumber = (int) floor(($rowNumber - 1) / $chunkSize);
        if ($chunkNumber > $maxChunk) {
            $maxChunk = $chunkNumber;
        }

        $batch[] = [
            'import_id' => $importId,
            'sheet_index' => $sheetIndex,
            'sheet_name' => $sheet->name(),
            'chunk_number' => $chunkNumber,
            'row_number' => $rowNumber,
            'row_data' => $row,
            'created_at' => $now,
        ];

        if (count($batch) >= $batchSize) {
            $dbStore->insertBatch($batch);
            $batch = [];
        }

        progressDot($rowNumber, 25000);
    }
}

if ($batch !== []) {
    $dbStore->insertBatch($batch);
}

$reader->close();

$t2ProducerElapsed = microtime(true) - $t2ProducerStart;
$t2ProducerPeak = memory_get_peak_usage(true);
$dbChunkCount = $maxChunk + 1;
$dbRowsStaged = $rowNumber;

echo "  Producer done: ".number_format($dbRowsStaged)." rows → {$dbChunkCount} chunks\n";
echo "  Producer time: ".formatTime($t2ProducerElapsed)."\n";
echo "  Producer peak: ".formatMemory($t2ProducerPeak)."\n";

// --- Phase 2: Consumer (Process all chunks sequentially) ---
echo "\n  Phase 2: Consumer (process all {$dbChunkCount} chunks sequentially)...\n";
memory_reset_peak_usage();
$t2ConsumerStart = microtime(true);
$t2ChunkTimes = [];

for ($chunk = 0; $chunk < $dbChunkCount; $chunk++) {
    $chunkStart = microtime(true);

    $rows = $dbStore->readChunk($importId, 0, $chunk);

    foreach ($rows as $staged) {
        // Simulate processing: just access the row data
        $row = $staged->row_data;
        $dbStore->markProcessed($staged->id);
    }

    $chunkElapsed = microtime(true) - $chunkStart;
    $t2ChunkTimes[] = $chunkElapsed;

    if (($chunk + 1) % 25 === 0 || $chunk === $dbChunkCount - 1) {
        echo '  ...chunk '.($chunk + 1)."/{$dbChunkCount} (".formatTime($chunkElapsed).")\n";
    }
}

$t2ConsumerElapsed = microtime(true) - $t2ConsumerStart;
$t2ConsumerPeak = memory_get_peak_usage(true);
$t2TotalElapsed = $t2ProducerElapsed + $t2ConsumerElapsed;
$t2AvgChunkTime = count($t2ChunkTimes) > 0 ? array_sum($t2ChunkTimes) / count($t2ChunkTimes) : 0;

echo "\n  Consumer time:     ".formatTime($t2ConsumerElapsed)."\n";
echo "  Consumer peak:     ".formatMemory($t2ConsumerPeak)."\n";
echo "  Avg chunk time:    ".formatTime($t2AvgChunkTime)."\n";
echo "  ──────────────────────────────────────\n";
echo "  TOTAL time:        ".formatTime($t2TotalElapsed)."\n";
echo "  Rows staged:       ".number_format($dbRowsStaged)."\n";
echo "  Chunks:            {$dbChunkCount}\n";
if ($dbRowsStaged !== $expectedRows) {
    echo "  ⚠ Row count mismatch! Expected: ".number_format($expectedRows)."\n";
}

// Estimate parallel speedup
for ($workers = 2; $workers <= 8; $workers *= 2) {
    $parallelConsumerTime = $t2ConsumerElapsed / $workers;
    $parallelTotal = $t2ProducerElapsed + $parallelConsumerTime;
    echo "  Est. {$workers} workers:     ".formatTime($parallelTotal)."\n";
}

echo "\n";

// Cleanup SQLite
if (file_exists($dbPath)) {
    unlink($dbPath);
}
} // end db test

// ===========================================================================
// TEST 3: File Staging Pipeline (NDJSON)
// ===========================================================================

if (in_array('file', $runTests)) {
echo str_repeat('─', 62)."\n";
echo "TEST 3: File Staging Pipeline (NDJSON)\n";
echo str_repeat('─', 62)."\n";

$fileStoreBase = sys_get_temp_dir().'/bench_staging_files_'.getmypid();
if (is_dir($fileStoreBase)) {
    // Clean up from previous run
    array_map('unlink', glob($fileStoreBase.'/*/*.ndjson'));
    array_map('rmdir', glob($fileStoreBase.'/*'));
    rmdir($fileStoreBase);
}

$fileStore = new FileStagingStore($fileStoreBase);
$fileImportId = Str::uuid()->toString();

// --- Phase 1: Producer (Read + Stage to files) ---
echo "\n  Phase 1: Producer (read → stage to NDJSON files)...\n";
memory_reset_peak_usage();
$t3ProducerStart = microtime(true);

$reader = new OpenSpoutReader();
$reader->open($filePath);

$headings = null;
$headingKeys = null;
$headingCount = 0;
$rowNumber = 0;
$maxChunk = -1;
$batch = [];
$now = date('Y-m-d H:i:s');

foreach ($reader->sheets() as $sheetIndex => $sheet) {
    foreach ($sheet->rows() as $rawRow) {
        $rawRow = array_values($rawRow);

        if ($headings === null) {
            $headingKeys = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rawRow);
            $headingCount = count($headingKeys);
            $headings = $rawRow;

            continue;
        }

        $rowNumber++;

        $padded = array_pad($rawRow, $headingCount, null);
        $row = array_combine($headingKeys, array_slice($padded, 0, $headingCount));

        $chunkNumber = (int) floor(($rowNumber - 1) / $chunkSize);
        if ($chunkNumber > $maxChunk) {
            $maxChunk = $chunkNumber;
        }

        $batch[] = [
            'import_id' => $fileImportId,
            'sheet_index' => $sheetIndex,
            'sheet_name' => $sheet->name(),
            'chunk_number' => $chunkNumber,
            'row_number' => $rowNumber,
            'row_data' => $row,
            'created_at' => $now,
        ];

        if (count($batch) >= $batchSize) {
            $fileStore->insertBatch($batch);
            $batch = [];
        }

        progressDot($rowNumber, 25000);
    }
}

if ($batch !== []) {
    $fileStore->insertBatch($batch);
}

$reader->close();

$t3ProducerElapsed = microtime(true) - $t3ProducerStart;
$t3ProducerPeak = memory_get_peak_usage(true);
$fileChunkCount = $maxChunk + 1;
$fileRowsStaged = $rowNumber;

echo "  Producer done: ".number_format($fileRowsStaged)." rows → {$fileChunkCount} chunks\n";
echo "  Producer time: ".formatTime($t3ProducerElapsed)."\n";
echo "  Producer peak: ".formatMemory($t3ProducerPeak)."\n";

// Check NDJSON file sizes
$ndjsonFiles = glob($fileStoreBase.'/*/*.ndjson');
$totalNdjsonSize = 0;
foreach ($ndjsonFiles as $f) {
    $totalNdjsonSize += filesize($f);
}
echo "  NDJSON files:  ".count($ndjsonFiles)." files, ".round($totalNdjsonSize / 1024 / 1024, 2)." MB total\n";

// --- Phase 2: Consumer (Process all chunks sequentially) ---
echo "\n  Phase 2: Consumer (process all {$fileChunkCount} chunks sequentially)...\n";
memory_reset_peak_usage();
$t3ConsumerStart = microtime(true);
$t3ChunkTimes = [];

for ($chunk = 0; $chunk < $fileChunkCount; $chunk++) {
    $chunkStart = microtime(true);

    $rows = $fileStore->readChunk($fileImportId, 0, $chunk);

    foreach ($rows as $staged) {
        // Simulate processing: just access the row data
        $row = $staged->row_data;
        $fileStore->markProcessed($staged->id); // no-op for file driver
    }

    $fileStore->cleanupChunk($fileImportId, 0, $chunk);

    $chunkElapsed = microtime(true) - $chunkStart;
    $t3ChunkTimes[] = $chunkElapsed;

    if (($chunk + 1) % 25 === 0 || $chunk === $fileChunkCount - 1) {
        echo '  ...chunk '.($chunk + 1)."/{$fileChunkCount} (".formatTime($chunkElapsed).")\n";
    }
}

$t3ConsumerElapsed = microtime(true) - $t3ConsumerStart;
$t3ConsumerPeak = memory_get_peak_usage(true);
$t3TotalElapsed = $t3ProducerElapsed + $t3ConsumerElapsed;
$t3AvgChunkTime = count($t3ChunkTimes) > 0 ? array_sum($t3ChunkTimes) / count($t3ChunkTimes) : 0;

echo "\n  Consumer time:     ".formatTime($t3ConsumerElapsed)."\n";
echo "  Consumer peak:     ".formatMemory($t3ConsumerPeak)."\n";
echo "  Avg chunk time:    ".formatTime($t3AvgChunkTime)."\n";
echo "  ──────────────────────────────────────\n";
echo "  TOTAL time:        ".formatTime($t3TotalElapsed)."\n";
echo "  Rows staged:       ".number_format($fileRowsStaged)."\n";
echo "  Chunks:            {$fileChunkCount}\n";
if ($fileRowsStaged !== $expectedRows) {
    echo "  ⚠ Row count mismatch! Expected: ".number_format($expectedRows)."\n";
}

// Estimate parallel speedup
for ($workers = 2; $workers <= 8; $workers *= 2) {
    $parallelConsumerTime = $t3ConsumerElapsed / $workers;
    $parallelTotal = $t3ProducerElapsed + $parallelConsumerTime;
    echo "  Est. {$workers} workers:     ".formatTime($parallelTotal)."\n";
}

echo "\n";

// Cleanup NDJSON files
$remainingFiles = glob($fileStoreBase.'/*/*.ndjson');
foreach ($remainingFiles as $f) {
    @unlink($f);
}
$remainingDirs = glob($fileStoreBase.'/*');
foreach ($remainingDirs as $d) {
    if (is_dir($d)) {
        @rmdir($d);
    }
}
if (is_dir($fileStoreBase)) {
    @rmdir($fileStoreBase);
}
} // end file test

// ===========================================================================
// SUMMARY
// ===========================================================================

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                      SUMMARY                               ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "| Metric                  | Raw Read     | DB Staging   | File Staging |\n";
echo "|-------------------------|--------------|--------------|──────────────|\n";
printf("| Total rows              | %12s | %12s | %12s |\n", number_format($expectedRows), number_format($dbRowsStaged), number_format($fileRowsStaged));
printf("| Chunks                  | %12s | %12s | %12s |\n", 'n/a', $dbChunkCount, $fileChunkCount);
printf("| Producer time           | %12s | %12s | %12s |\n", formatTime($t1Elapsed), formatTime($t2ProducerElapsed), formatTime($t3ProducerElapsed));
printf("| Consumer time           | %12s | %12s | %12s |\n", 'n/a', formatTime($t2ConsumerElapsed), formatTime($t3ConsumerElapsed));
printf("| **Total time**          | %12s | %12s | %12s |\n", formatTime($t1Elapsed), formatTime($t2TotalElapsed), formatTime($t3TotalElapsed));
printf("| Peak memory             | %12s | %12s | %12s |\n", formatMemory($t1Peak), formatMemory(max($t2ProducerPeak, $t2ConsumerPeak)), formatMemory(max($t3ProducerPeak, $t3ConsumerPeak)));
printf("| Avg chunk time          | %12s | %12s | %12s |\n", 'n/a', formatTime($t2AvgChunkTime), formatTime($t3AvgChunkTime));

echo "\n";
echo "Parallel worker estimates (producer + consumer/N):\n\n";
echo "| Workers | DB Staging   | File Staging |\n";
echo "|---------|--------------|──────────────|\n";
printf("| 1       | %12s | %12s |\n", formatTime($t2TotalElapsed), formatTime($t3TotalElapsed));

for ($workers = 2; $workers <= 8; $workers *= 2) {
    $dbParallel = $t2ProducerElapsed + ($t2ConsumerElapsed / $workers);
    $fileParallel = $t3ProducerElapsed + ($t3ConsumerElapsed / $workers);
    printf("| %-7d | %12s | %12s |\n", $workers, formatTime($dbParallel), formatTime($fileParallel));
}

echo "\nOriginal upload time (reported): ~18 minutes\n";
echo "Note: Original included actual model DB writes; these benchmarks measure staging pipeline overhead only.\n";
echo "Note: DB staging uses SQLite (local); production MySQL/PostgreSQL will differ.\n";
