<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

/** Logs ['collection', chunk offset, [key => id]] per collection() call. */
class LoggedCollectionImport implements ToCollection, WithBatchInserts, WithEvents, WithHeadingRow
{
    use RecordsImportLog;

    public function collection(Collection $collection): void
    {
        $this->log[] = ['collection', $this->getChunkOffset(), $this->ids($collection)];
    }
}
