<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

/** Logs ['array', chunk offset, [key => id]] per array() call. */
class LoggedArrayImport implements ToArray, WithBatchInserts, WithEvents, WithHeadingRow
{
    use RecordsImportLog;

    /** Whether every row of every array() call was a plain array. */
    public bool $rowsWerePlainArrays = true;

    public function array(array $array): void
    {
        $this->rowsWerePlainArrays = $this->rowsWerePlainArrays && array_all($array, fn (mixed $row): bool => is_array($row));
        $this->log[] = ['array', $this->getChunkOffset(), $this->ids($array)];
    }
}
