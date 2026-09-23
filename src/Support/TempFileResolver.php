<?php

namespace MrDellimore\SheetStream\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves an import source to a path the spreadsheet engines can open.
 *
 * OpenSpout and PhpSpreadsheet both need a real local file (xlsx is a zip archive
 * read through ZipArchive), so a file on a Laravel filesystem disk is streamed down
 * to a temp file first and removed again once the import has finished.
 */
final class TempFileResolver
{
    /**
     * @param  string  $path  Local absolute path when $disk is null, otherwise a path on that disk.
     */
    public static function localPath(string $path, ?string $disk, string $prefix = 'sheet_stream_'): string
    {
        if ($disk === null) {
            return $path;
        }

        $tempDir = config('sheet-stream.temp_path') ?? sys_get_temp_dir();
        $tempPath = tempnam($tempDir, $prefix);

        // The engines choose their reader from the extension, so keep the source's.
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension !== '' && rename($tempPath, $tempPath.'.'.$extension)) {
            $tempPath .= '.'.$extension;
        }

        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            @unlink($tempPath);

            throw new FileNotFoundException("File [{$path}] does not exist on disk [{$disk}].");
        }

        $local = fopen($tempPath, 'wb');
        stream_copy_to_stream($stream, $local);
        fclose($local);
        fclose($stream);

        return $tempPath;
    }

    /**
     * Remove the temp copy created by localPath(). A no-op for local sources.
     */
    public static function cleanup(string $localPath, ?string $disk): void
    {
        if ($disk !== null) {
            @unlink($localPath);
        }
    }
}
