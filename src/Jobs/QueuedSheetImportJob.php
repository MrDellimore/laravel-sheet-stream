<?php

namespace MrDellimore\SheetStream\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Imports\ImportRunner;

/**
 * Processes a single sheet from a WithMultipleSheets + WithParallelSheets import.
 *
 * Dispatched by QueuedImportJob (acting as a coordinator) — one job per sheet.
 * Each job independently resolves the file, opens a reader, skips to its
 * assigned sheet index, and runs the sub-import.
 */
class QueuedSheetImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?int $tries = null;

    public ?int $timeout = null;

    public function __construct(
        public readonly object $parentImport,
        public readonly object $sheetImport,
        public readonly int $sheetIndex,
        public readonly string $filePath,
        public readonly ?string $disk = null,
        public readonly array $readerOptions = [],
        public readonly int $batchSize = 1000,
    ) {
        $this->tries = $sheetImport->tries ?? $parentImport->tries ?? null;
        $this->timeout = $sheetImport->timeout ?? $parentImport->timeout ?? null;
        $this->onQueue($sheetImport->queue ?? $parentImport->queue ?? null);
        $this->onConnection($sheetImport->connection ?? $parentImport->connection ?? null);
    }

    public function handle(): void
    {
        $localPath = $this->resolveLocalPath();

        try {
            $driver = config('sheet-stream.default_reader', 'openspout');
            $nativeOptions = $this->parentImport instanceof WithReaderOptions
                ? $this->parentImport->readerOptions()
                : null;

            $reader = EngineFactory::reader($driver, $this->readerOptions, $nativeOptions);
            $reader->open($localPath);

            try {
                $runner = new ImportRunner($this->batchSize);

                foreach ($reader->sheets() as $index => $sheetReader) {
                    if ($index === $this->sheetIndex) {
                        $runner->runSheet($this->sheetImport, $sheetReader);
                        break;
                    }
                }
            } finally {
                $reader->close();
            }
        } finally {
            $this->cleanupTempFile($localPath);
        }
    }

    private function resolveLocalPath(): string
    {
        if ($this->disk === null) {
            return $this->filePath;
        }

        $tempDir = config('sheet-stream.temp_path') ?? sys_get_temp_dir();
        $tempPath = tempnam($tempDir, 'sheet_stream_sheet_');
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
}
