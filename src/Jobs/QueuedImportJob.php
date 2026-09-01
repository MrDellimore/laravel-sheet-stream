<?php

namespace MrDellimore\SheetStream\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithParallelSheets;
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Imports\ImportRunner;

class QueuedImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?int $tries = null;

    public ?int $timeout = null;

    public function __construct(
        public object $import,
        public string $filePath,
        public ?string $disk = null,
        public array $readerOptions = [],
        public int $batchSize = 1000,
    ) {
        $this->tries = $import->tries ?? null;
        $this->timeout = $import->timeout ?? null;
        $this->onQueue($import->queue ?? null);
        $this->onConnection($import->connection ?? null);
    }

    public function handle(): void
    {
        // Sheet-level parallelism: dispatch one job per sheet, then return.
        // Each sheet job independently resolves the file and processes its sheet.
        if ($this->import instanceof WithMultipleSheets && $this->import instanceof WithParallelSheets) {
            $this->dispatchSheetJobs();

            return;
        }

        $localPath = $this->resolveLocalPath();

        try {
            $driver = config('sheet-stream.default_reader', 'openspout');
            $nativeOptions = $this->import instanceof WithReaderOptions ? $this->import->readerOptions() : null;
            $reader = EngineFactory::reader($driver, $this->readerOptions, $nativeOptions);
            $reader->open($localPath);

            try {
                $runner = new ImportRunner($this->batchSize);
                $runner->run($this->import, $reader);
            } finally {
                $reader->close();
            }
        } finally {
            $this->cleanupTempFile($localPath);
        }
    }

    /**
     * Open the file, discover sheets, and dispatch one QueuedSheetImportJob per sheet.
     * The current job acts purely as a coordinator — row processing happens in the sheet jobs.
     */
    private function dispatchSheetJobs(): void
    {
        $localPath = $this->resolveLocalPath();

        try {
            $driver = config('sheet-stream.default_reader', 'openspout');
            $nativeOptions = $this->import instanceof WithReaderOptions ? $this->import->readerOptions() : null;
            $reader = EngineFactory::reader($driver, $this->readerOptions, $nativeOptions);
            $reader->open($localPath);

            try {
                $sheetMap = $this->import->sheets();

                foreach ($reader->sheets() as $index => $sheetReader) {
                    $subImport = $sheetMap[$index] ?? $sheetMap[$sheetReader->name()] ?? null;

                    if ($subImport !== null) {
                        QueuedSheetImportJob::dispatch(
                            parentImport: $this->import,
                            sheetImport: $subImport,
                            sheetIndex: $index,
                            filePath: $this->filePath,
                            disk: $this->disk,
                            readerOptions: $this->readerOptions,
                            batchSize: $this->batchSize,
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

    private function resolveLocalPath(): string
    {
        if ($this->disk === null) {
            return $this->filePath;
        }

        $tempDir = config('sheet-stream.temp_path') ?? sys_get_temp_dir();
        $tempPath = tempnam($tempDir, 'sheet_stream_import_');
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
