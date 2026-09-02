<?php

namespace MrDellimore\SheetStream\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithParallelSheets;
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Events\ImportFailed;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Support\ConfiguresFromConcern;
use MrDellimore\SheetStream\Support\EventBus;
use MrDellimore\SheetStream\Support\ResolvesTempFile;

class QueuedImportJob implements ShouldQueue
{
    use ConfiguresFromConcern, Dispatchable, InteractsWithQueue, Queueable, ResolvesTempFile, SerializesModels;

    public ?int $tries = null;

    public ?int $timeout = null;

    public function __construct(
        public object $import,
        public string $filePath,
        public ?string $disk = null,
        public array $readerOptions = [],
        public int $batchSize = 1000,
    ) {
        $this->applyJobConfig($import);
    }

    public function handle(): void
    {
        if ($this->import instanceof WithMultipleSheets && $this->import instanceof WithParallelSheets) {
            $this->dispatchSheetJobs();

            return;
        }

        $localPath = $this->resolveLocalPath('sheet_stream_import_');
        $bus = EventBus::for($this->import);

        try {
            $driver = config('sheet-stream.default_reader', 'openspout');
            $nativeOptions = $this->import instanceof WithReaderOptions ? $this->import->readerOptions() : null;
            $reader = EngineFactory::reader($driver, $this->readerOptions, $nativeOptions);
            $reader->open($localPath);

            try {
                $runner = new ImportRunner($this->batchSize);
                $runner->run($this->import, $reader, $bus);
            } catch (\Throwable $e) {
                $bus?->dispatch(new ImportFailed($this->import, $e));

                throw $e;
            } finally {
                $reader->close();
            }
        } finally {
            $this->cleanupTempFile($localPath);
        }
    }

    private function dispatchSheetJobs(): void
    {
        $localPath = $this->resolveLocalPath('sheet_stream_import_');

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
}
