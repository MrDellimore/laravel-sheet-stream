<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

use Illuminate\Support\Collection;

interface ToCollection
{
    /**
     * Every row of the sheet, or of one chunk with WithChunkReading. Each row is itself a
     * Collection (Laravel Excel parity) unless `sheet-stream.collection_rows` is 'array'.
     */
    public function collection(Collection $collection): void;
}
