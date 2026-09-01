<?php

namespace MrDellimore\SheetStream;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\UsesStagingTable;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use MrDellimore\SheetStream\Concerns\WithWriterOptions;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Exceptions\UnsupportedByEngine;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Jobs\QueuedExportJob;
use MrDellimore\SheetStream\Jobs\QueuedImportJob;
use MrDellimore\SheetStream\Jobs\StagingProducerJob;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SheetStreamManager
{
    public function __construct(
        protected Application $app,
    ) {}

    public function import(object $import, string $path, ?string $disk = null): ?PendingDispatch
    {
        if ($import instanceof ShouldQueue) {
            return $this->queueImport($import, $path, $disk);
        }

        $driver = (string) ($this->app['config']['sheet-stream.default_reader'] ?? 'openspout');
        $nativeOptions = $import instanceof WithReaderOptions ? $import->readerOptions() : null;
        $reader = EngineFactory::reader($driver, $this->readerOptions(), $nativeOptions);
        $reader->open($path);

        try {
            $runner = new ImportRunner((int) ($this->app['config']['sheet-stream.batch_size'] ?? 1000));
            $runner->run($import, $reader);
        } finally {
            $reader->close();
        }

        return null;
    }

    public function download(object $export, string $filename): StreamedResponse
    {
        $extension = $this->extension($filename);
        $this->validateExportFormat($export, $extension);

        // Write to temp before opening the HTTP response so exceptions propagate
        // normally rather than mid-stream after headers are sent.
        $tmp = $this->runExportToTemp($export, $extension);

        return new StreamedResponse(function () use ($tmp): void {
            try {
                $handle = fopen($tmp, 'rb');

                if ($handle === false) {
                    return;
                }

                while (! feof($handle)) {
                    echo fread($handle, 65536);
                    flush();
                }

                fclose($handle);
            } finally {
                @unlink($tmp);
            }
        }, 200, [
            'Content-Type' => $this->mimeType($extension),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) (filesize($tmp) ?: 0),
        ]);
    }

    public function store(object $export, string $path, ?string $disk = null): bool|PendingDispatch
    {
        if ($export instanceof ShouldQueue) {
            return $this->queueExport($export, $path, $disk);
        }

        $extension = $this->extension($path);
        $this->validateExportFormat($export, $extension);

        $tmp = $this->runExportToTemp($export, $extension);

        try {
            $disk ??= (string) ($this->app['config']['filesystems.default'] ?? 'local');
            $handle = fopen($tmp, 'rb');

            if ($handle === false) {
                throw new \RuntimeException("Failed to open temp export file for reading: {$tmp}");
            }

            try {
                $result = Storage::disk($disk)->writeStream($path, $handle);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            return (bool) $result;
        } finally {
            @unlink($tmp);
        }
    }

    public function queueImport(object $import, string $path, ?string $disk = null): PendingDispatch
    {
        if ($import instanceof UsesStagingTable) {
            return new PendingDispatch(new StagingProducerJob(
                import: $import,
                filePath: $path,
                disk: $disk,
                readerOptions: $this->readerOptions(),
                chunkSize: (int) ($this->app['config']['sheet-stream.chunk_size'] ?? 1000),
                insertBatchSize: (int) ($this->app['config']['sheet-stream.staging.insert_batch_size'] ?? 500),
            ));
        }

        return new PendingDispatch(new QueuedImportJob(
            import: $import,
            filePath: $path,
            disk: $disk,
            readerOptions: $this->readerOptions(),
            batchSize: (int) ($this->app['config']['sheet-stream.batch_size'] ?? 1000),
        ));
    }

    public function queueExport(object $export, string $path, ?string $disk = null): PendingDispatch
    {
        $extension = $this->extension($path);
        $this->validateExportFormat($export, $extension);

        return new PendingDispatch(new QueuedExportJob(
            export: $export,
            storagePath: $path,
            disk: $disk,
            extension: $extension,
            chunkSize: (int) ($this->app['config']['sheet-stream.chunk_size'] ?? 1000),
        ));
    }

    private function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'xlsx');
    }

    private function runExportToTemp(object $export, string $extension): string
    {
        $driver = (string) ($this->app['config']['sheet-stream.default_writer'] ?? 'openspout');
        $nativeOptions = $export instanceof WithWriterOptions ? $export->writerOptions() : null;
        $writer = EngineFactory::writer($driver, $extension, $this->writerOptions(), $nativeOptions);
        $tmp = tempnam($this->tempPath(), 'sheet_stream_');
        $writer->openToFile($tmp);

        try {
            $runner = new ExportRunner((int) ($this->app['config']['sheet-stream.chunk_size'] ?? 1000));
            $runner->run($export, $writer);
            $writer->close();
        } catch (\Throwable $e) {
            $writer->close();
            @unlink($tmp);

            throw $e;
        }

        return $tmp;
    }

    private function readerOptions(): array
    {
        return [
            'dates' => [
                'coerce' => (bool) ($this->app['config']['sheet-stream.dates.coerce'] ?? true),
                'timezone' => $this->app['config']['sheet-stream.dates.timezone'] ?? null,
            ],
        ];
    }

    private function writerOptions(): array
    {
        return [
            'dates' => [
                'format' => (string) ($this->app['config']['sheet-stream.dates.format'] ?? 'yyyy-mm-dd'),
                'datetime_format' => (string) ($this->app['config']['sheet-stream.dates.datetime_format'] ?? 'yyyy-mm-dd hh:mm:ss'),
            ],
        ];
    }

    private function validateExportFormat(object $export, string $extension): void
    {
        if ($export instanceof WithMultipleSheets && in_array($extension, ['csv', 'tsv'], true)) {
            throw new UnsupportedByEngine(
                'The CSV/TSV format does not support multiple sheets. Use .xlsx or .ods instead.'
            );
        }
    }

    private function tempPath(): string
    {
        return (string) ($this->app['config']['sheet-stream.temp_path'] ?? sys_get_temp_dir());
    }

    private function mimeType(string $extension): string
    {
        return match ($extension) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'csv', 'tsv' => 'text/csv',
            default => 'application/octet-stream',
        };
    }
}
