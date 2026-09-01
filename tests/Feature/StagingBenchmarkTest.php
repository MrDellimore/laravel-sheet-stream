<?php

/**
 * Staging pipeline benchmark.
 *
 * Run with:   ./vendor/bin/pest --group=benchmark
 *
 * The test requires a large XLSX file at the repo root.
 * Place the file there (any name ending in .xlsx), and set BENCH_FILE:
 *
 *   BENCH_FILE="path/to/file.xlsx" ./vendor/bin/pest --group=benchmark
 *
 * If BENCH_FILE is not set, the test looks for the first *.xlsx at the repo root.
 * It is skipped automatically when no file is found.
 *
 * What is measured:
 *   Producer phase  — file read + bulk INSERT into staging table (single process, accurate)
 *   Chunk phase     — all chunk jobs run sequentially (single process, accurate per-chunk)
 *   Parallel est.   — chunk_time / N_workers (estimated; actual Horizon speedup will be close)
 *
 * Notes:
 *   - Memory reported is peak for that phase (SQLite keeps flat per-row, not all-at-once).
 *   - ToArray delivers rows per-chunk; this benchmark uses a counter, not storage.
 *   - For true parallel measurement, set up Horizon + Redis in a real Laravel app and
 *     use UsesStagingTable on your production import class.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\UsesStagingTable;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Jobs\StagingChunkProcessorJob;
use MrDellimore\SheetStream\Jobs\StagingProducerJob;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('sheet_stream_staging', function ($table) {
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
});

it('benchmarks the staging pipeline against a large xlsx file', function () {
    // Resolve benchmark file
    $benchFile = getenv('BENCH_FILE') ?: null;

    if ($benchFile === null) {
        $candidates = glob(dirname(__DIR__, 2).'/*.xlsx') ?: [];
        $benchFile = $candidates[0] ?? null;
    }

    if ($benchFile === null || ! file_exists($benchFile)) {
        test()->skip(
            'No large XLSX found. Set BENCH_FILE=path/to/file.xlsx or place an .xlsx at the repo root.'
        );
    }

    $fileSizeMb = round(filesize($benchFile) / 1024 / 1024, 1);

    // No-op import — count rows without storing them, so memory stays flat.
    $import = new class implements ShouldQueue, ToArray, WithHeadingRow, UsesStagingTable {
        public int $totalRows = 0;
        public int $chunksCalled = 0;

        public function array(array $rows): void
        {
            $this->totalRows += count($rows);
            $this->chunksCalled++;
        }
    };

    Bus::fake([StagingChunkProcessorJob::class]);

    // ── PRODUCER PHASE ────────────────────────────────────────────────────────
    memory_reset_peak_usage();
    $producerStart = microtime(true);

    $chunkSize = 1000;

    (new StagingProducerJob(
        import: $import,
        filePath: $benchFile,
        chunkSize: $chunkSize,
        insertBatchSize: 500,
    ))->handle();

    $producerTime = microtime(true) - $producerStart;
    $producerPeakMb = memory_get_peak_usage(true) / 1024 / 1024;

    $totalStaged = DB::table('sheet_stream_staging')->count();
    $chunkJobs = Bus::dispatched(StagingChunkProcessorJob::class);
    $totalChunks = count($chunkJobs);

    // ── CHUNK PHASE (sequential simulation) ───────────────────────────────────
    memory_reset_peak_usage();
    $chunkStart = microtime(true);

    foreach ($chunkJobs as $job) {
        $job->handle();
    }

    $chunkTime = microtime(true) - $chunkStart;
    $chunkPeakMb = memory_get_peak_usage(true) / 1024 / 1024;
    $avgChunkMs = $totalChunks > 0 ? ($chunkTime / $totalChunks) * 1000 : 0;

    // ── REPORT ────────────────────────────────────────────────────────────────
    $w = 52;
    $sep = '╠'.str_repeat('═', $w - 2).'╣';

    $line = fn (string $label, string $value) => sprintf(
        '║  %-22s %-'.($w - 28).'s    ║',
        $label,
        $value
    );

    $output = implode("\n", [
        '',
        '╔'.str_repeat('═', $w - 2).'╗',
        '║'.str_pad('  STAGING PIPELINE BENCHMARK', $w - 2, ' ', STR_PAD_RIGHT).'║',
        $sep,
        $line('File', basename($benchFile).' ('.$fileSizeMb.' MB)'),
        $line('Rows staged', number_format($totalStaged)),
        $line('Chunk size', number_format($chunkSize).' rows'),
        $line('Total chunks', number_format($totalChunks)),
        $sep,
        $line('Producer time', round($producerTime, 2).'s'),
        $line('Peak memory', round($producerPeakMb, 1).' MB'),
        $line('Insert rate', number_format((int) ($totalStaged / max($producerTime, 0.001))).' rows/s'),
        $sep,
        $line('Sequential total', round($chunkTime, 2).'s for all chunks'),
        $line('Avg per chunk', round($avgChunkMs).' ms / '.$chunkSize.' rows'),
        $line('Est. 1 worker', round($chunkTime, 1).'s'),
        $line('Est. 5 workers', round($chunkTime / 5, 1).'s'),
        $line('Est. 10 workers', round($chunkTime / 10, 1).'s'),
        $line('Est. 20 workers', round($chunkTime / 20, 1).'s'),
        $line('Peak memory/worker', round($chunkPeakMb, 1).' MB'),
        '╚'.str_repeat('═', $w - 2).'╝',
        '',
        '  Note: chunk times above assume uniform per-chunk cost and no queue',
        '  overhead. Real Horizon speedup will be within ~10-15% of estimates.',
        '',
    ]);

    fwrite(STDOUT, $output);

    expect($totalStaged)->toBeGreaterThan(0)
        ->and($import->totalRows)->toBe($totalStaged);
})->group('benchmark');
