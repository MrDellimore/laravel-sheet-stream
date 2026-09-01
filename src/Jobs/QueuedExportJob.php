<?php

namespace MrDellimore\SheetStream\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Concerns\WithWriterOptions;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Support\ConfiguresFromConcern;

class QueuedExportJob implements ShouldQueue
{
    use ConfiguresFromConcern, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?int $tries = null;

    public ?int $timeout = null;

    public function __construct(
        public object $export,
        public string $storagePath,
        public ?string $disk = null,
        public string $extension = 'xlsx',
        public int $chunkSize = 1000,
    ) {
        $this->applyJobConfig($export);
    }

    public function handle(): void
    {
        $driver = config('sheet-stream.default_writer', 'openspout');
        $tempDir = config('sheet-stream.temp_path') ?? sys_get_temp_dir();
        $tmp = tempnam($tempDir, 'sheet_stream_export_');

        try {
            $nativeOptions = $this->export instanceof WithWriterOptions ? $this->export->writerOptions() : null;
            $writer = EngineFactory::writer($driver, $this->extension, [], $nativeOptions);
            $writer->openToFile($tmp);

            $runner = new ExportRunner($this->chunkSize);
            $runner->run($this->export, $writer);
            $writer->close();

            $targetDisk = $this->disk ?? config('filesystems.default', 'local');
            $handle = fopen($tmp, 'rb');
            Storage::disk($targetDisk)->writeStream($this->storagePath, $handle);

            if (is_resource($handle)) {
                fclose($handle);
            }
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
    }
}
