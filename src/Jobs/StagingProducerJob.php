<?php

namespace MrDellimore\SheetStream\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\WithCsvPreConversion;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Staging\StagingStore;
use MrDellimore\SheetStream\Support\ConfiguresFromConcern;
use MrDellimore\SheetStream\Support\ConversionResult;
use MrDellimore\SheetStream\Support\CsvConverter;
use MrDellimore\SheetStream\Support\ResolvesTempFile;
use MrDellimore\SheetStream\Support\RowHelper;
use MrDellimore\SheetStream\Support\SheetResolver;

class StagingProducerJob implements ShouldQueue
{
    use ConfiguresFromConcern, Dispatchable, InteractsWithQueue, Queueable, ResolvesTempFile, SerializesModels;

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
        $this->applyJobConfig($import);
    }

    public function handle(): void
    {
        $importId = Str::uuid()->toString();
        $localPath = $this->resolveLocalPath('sheet_stream_staging_');
        $store = app(StagingStore::class);
        $conversion = null;

        try {
            if ($this->shouldPreConvert($localPath)) {
                $conversion = (CsvConverter::fromConfig())->convert($localPath);
                $this->stageConvertedSheets($store, $importId, $conversion);
            } else {
                $this->stageFromReader($store, $importId, $localPath);
            }
        } finally {
            $conversion?->cleanup();
            $this->cleanupTempFile($localPath);
        }
    }

    private function shouldPreConvert(string $localPath): bool
    {
        if (! ($this->import instanceof WithCsvPreConversion)) {
            return false;
        }

        $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));

        if (! in_array($extension, ['xlsx', 'ods'], true)) {
            return false;
        }

        return (CsvConverter::fromConfig())->isAvailable();
    }

    private function stageFromReader(StagingStore $store, string $importId, string $localPath): void
    {
        $driver = config('sheet-stream.default_reader', 'openspout');
        $nativeOptions = $this->import instanceof WithReaderOptions ? $this->import->readerOptions() : null;
        $reader = EngineFactory::reader($driver, $this->readerOptions, $nativeOptions);
        $reader->open($localPath);

        try {
            foreach ($reader->sheets() as $sheetIndex => $sheetReader) {
                $this->processSheet($store, $importId, $sheetIndex, $sheetReader->name(), $sheetReader);
            }
        } finally {
            $reader->close();
        }
    }

    private function stageConvertedSheets(StagingStore $store, string $importId, ConversionResult $conversion): void
    {
        foreach ($conversion->csvPaths as $sheetIndex => $csvPath) {
            $sheetName = $conversion->sheetNames[$sheetIndex] ?? "Sheet {$sheetIndex}";

            $subImport = SheetResolver::resolve($this->import, $sheetIndex, $sheetName);

            if ($subImport === null) {
                continue;
            }

            $reader = EngineFactory::reader('openspout', $this->readerOptions);
            $reader->open($csvPath);

            try {
                foreach ($reader->sheets() as $sheetReader) {
                    $chunksDispatched = $this->stageSheet($store, $importId, $sheetIndex, $sheetName, $subImport, $sheetReader);

                    for ($chunk = 0; $chunk < $chunksDispatched; $chunk++) {
                        StagingChunkProcessorJob::dispatch(
                            import: $this->import,
                            sheetImport: $subImport,
                            importId: $importId,
                            sheetIndex: $sheetIndex,
                            chunkNumber: $chunk,
                        );
                    }

                    break; // CSV only has one "sheet"
                }
            } finally {
                $reader->close();
            }
        }
    }

    private function processSheet(StagingStore $store, string $importId, int $sheetIndex, string $sheetName, SheetReader $sheetReader): void
    {
        $subImport = SheetResolver::resolve($this->import, $sheetIndex, $sheetName);

        if ($subImport === null) {
            return;
        }

        $chunksDispatched = $this->stageSheet($store, $importId, $sheetIndex, $sheetName, $subImport, $sheetReader);

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
                $headings = RowHelper::normalizeHeadings($rawRow);
                $headingCount = count($headings);

                continue;
            }

            if ($skipsEmpty && RowHelper::isEmptyRow($rawRow)) {
                continue;
            }

            $rowNumber++;

            $row = $headings !== null
                ? RowHelper::keyRow($rawRow, $headings, $headingCount)
                : $rawRow;

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

        return $maxChunk + 1;
    }
}
