<?php

namespace MrDellimore\SheetStream\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Events\ImportFailed;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Support\ConfiguresFromConcern;
use MrDellimore\SheetStream\Support\EventBus;
use MrDellimore\SheetStream\Support\ResolvesTempFile;

class QueuedSheetImportJob implements ShouldQueue
{
    use ConfiguresFromConcern, Dispatchable, InteractsWithQueue, Queueable, ResolvesTempFile, SerializesModels;

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
        $this->applyJobConfig($sheetImport, $parentImport);
    }

    public function handle(): void
    {
        $localPath = $this->resolveLocalPath('sheet_stream_sheet_');
        $bus = EventBus::for($this->parentImport);
        $bus?->merge($this->sheetImport);

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
                        $sheetName = $sheetReader->name();
                        $bus?->dispatch(new BeforeSheet($this->sheetImport, $this->sheetIndex, $sheetName));
                        $runner->runSheet($this->sheetImport, $sheetReader, $bus, $this->sheetIndex);
                        $bus?->dispatch(new AfterSheet($this->sheetImport, $this->sheetIndex, $sheetName));
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $bus?->dispatch(new ImportFailed($this->parentImport, $e));

                throw $e;
            } finally {
                $reader->close();
            }
        } finally {
            $this->cleanupTempFile($localPath);
        }
    }
}
