<?php

namespace MrDellimore\SheetStream\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Staging\StagingStore;

/**
 * Phase 1 of the staging-table pattern.
 *
 * Streams the import file, bulk-inserts every data row into the staging table
 * (tagged by import_id, sheet_index, and pre-computed chunk_number), then
 * dispatches one StagingChunkProcessorJob per chunk.
 *
 * This job should be fast: it never runs import logic — it only reads and inserts.
 */
class StagingProducerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?int $tries = null;

    public ?int $timeout = null;

    public function __construct(
        public readonly object $import,
        public readonly string $filePath,
        public readonly ?string $disk = null,
        public readonly array $readerOptions = [],
        public readonly int $chunkSize = 1000,
        public readonly int $insertBatchSize = 500,
    ) {
        $this->tries = $import->tries ?? null;
        $this->timeout = $import->timeout ?? null;
        $this->onQueue($import->queue ?? null);
        $this->onConnection($import->connection ?? null);
    }

    public function handle(): void
    {
        $importId = Str::uuid()->toString();
        $localPath = $this->resolveLocalPath();
        $store = app(StagingStore::class);

        try {
            $driver = config('sheet-stream.default_reader', 'openspout');
            $nativeOptions = $this->import instanceof WithReaderOptions ? $this->import->readerOptions() : null;
            $reader = EngineFactory::reader($driver, $this->readerOptions, $nativeOptions);
            $reader->open($localPath);

            try {
                foreach ($reader->sheets() as $sheetIndex => $sheetReader) {
                    $subImport = $this->resolveSheetImport($sheetIndex, $sheetReader->name());

                    if ($subImport === null) {
                        continue;
                    }

                    $chunksDispatched = $this->stageSheet($store, $importId, $sheetIndex, $sheetReader->name(), $subImport, $sheetReader);

                    for ($chunk = 0; $chunk < $chunksDispatched; $chunk++) {
                        StagingChunkProcessorJob::dispatch(
                            import: $this->import,
                            sheetImport: $subImport,
                            importId: $importId,
                            sheetIndex: $sheetIndex,
                            chunkNumber: $chunk,
                        );
                    }
                }
            } finally {
                $reader->close();
            }
        } finally {
            $this->cleanupTempFile($localPath);
        }
    }

    /**
     * Stages all data rows from one sheet via the configured StagingStore.
     * Returns the number of distinct chunk numbers created (= jobs to dispatch).
     */
    private function stageSheet(
        StagingStore $store,
        string $importId,
        int $sheetIndex,
        string $sheetName,
        object $subImport,
        SheetReader $sheetReader,
    ): int {
        $hasHeadingRow = $subImport instanceof WithHeadingRow;
        $skipsEmpty = $subImport instanceof SkipsEmptyRows;
        $now = now()->toDateTimeString();

        $headings = null;
        $headingCount = 0;
        $rowNumber = 0;
        $maxChunk = -1;
        $batch = [];

        foreach ($sheetReader->rows() as $rawRow) {
            $rawRow = array_values($rawRow);

            if ($headings === null && $hasHeadingRow) {
                $headings = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rawRow);
                $headingCount = count($headings);

                continue;
            }

            if ($skipsEmpty && $this->isEmptyRow($rawRow)) {
                continue;
            }

            $rowNumber++;

            if ($headings !== null) {
                $padded = array_pad($rawRow, $headingCount, null);
                $row = array_combine($headings, array_slice($padded, 0, $headingCount));
            } else {
                $row = $rawRow;
            }

            $chunkNumber = (int) floor(($rowNumber - 1) / $this->chunkSize);

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

            if (count($batch) >= $this->insertBatchSize) {
                $store->insertBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $store->insertBatch($batch);
        }

        return $maxChunk + 1; // total chunk count (0 if no rows)
    }

    private function resolveSheetImport(int $sheetIndex, string $sheetName): ?object
    {
        if ($this->import instanceof WithMultipleSheets) {
            $sheets = $this->import->sheets();

            return $sheets[$sheetIndex] ?? $sheets[$sheetName] ?? null;
        }

        return $sheetIndex === 0 ? $this->import : null;
    }

    private function resolveLocalPath(): string
    {
        if ($this->disk === null) {
            return $this->filePath;
        }

        $tempDir = config('sheet-stream.temp_path') ?? sys_get_temp_dir();
        $tempPath = tempnam($tempDir, 'sheet_stream_staging_');
        $stream = Storage::disk($this->disk)->readStream($this->filePath);
        $local = fopen($tempPath, 'wb');
        stream_copy_to_stream($stream, $local);
        fclose($local);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $tempPath;
    }

    private function cleanupTempFile(string $localPath): void
    {
        if ($this->disk !== null) {
            @unlink($localPath);
        }
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }

        return true;
    }
}
