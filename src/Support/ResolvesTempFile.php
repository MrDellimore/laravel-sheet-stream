<?php

namespace MrDellimore\SheetStream\Support;

/**
 * Job-side wrapper around {@see TempFileResolver} reading the job's $filePath and $disk.
 */
trait ResolvesTempFile
{
    private function resolveLocalPath(string $prefix = 'sheet_stream_'): string
    {
        return TempFileResolver::localPath($this->filePath, $this->disk, $prefix);
    }

    private function cleanupTempFile(string $localPath): void
    {
        TempFileResolver::cleanup($localPath, $this->disk);
    }
}
