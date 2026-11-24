<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use MrDellimore\SheetStream\Concerns\OnEachRow;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

/** Logs ['row', id, row number, chunk offset] per onRow() call. */
class LoggedOnEachRowImport implements OnEachRow, WithBatchInserts, WithEvents, WithHeadingRow
{
    use RecordsImportLog;

    public function onRow(array $row): void
    {
        $this->log[] = ['row', (int) $row['id'], $this->getRowNumber(), $this->getChunkOffset()];
    }
}
