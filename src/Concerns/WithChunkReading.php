<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

/**
 * Process the sheet in chunks of chunkSize() rows instead of all at once: collection() /
 * array() is called per chunk, and onFailure() and AfterChunk fire per chunk.
 *
 * On a WithMultipleSheets import, a sheet without its own chunkSize() uses the parent's.
 */
interface WithChunkReading
{
    public function chunkSize(): int;
}
