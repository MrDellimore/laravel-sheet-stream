<?php

namespace MrDellimore\SheetStream\Support;

use Illuminate\Support\Facades\Storage;

trait ResolvesTempFile
{
    private function resolveLocalPath(string $prefix = 'sheet_stream_'): string
    {
        if ($this->disk === null) {
            return $this->filePath;
        }

        $tempDir = config('sheet-stream.temp_path') ?? sys_get_temp_dir();
        $tempPath = tempnam($tempDir, $prefix);
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
