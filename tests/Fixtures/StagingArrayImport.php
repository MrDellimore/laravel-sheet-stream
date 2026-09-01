<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\UsesStagingTable;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

/**
 * Import fixture for the staging pipeline tests.
 *
 * ToArray is called once per chunk in the staging pattern —
 * this fixture accumulates across chunks so the test can
 * inspect the full result set after all chunk jobs run.
 *
 * In real usage with a real queue, each chunk job is a separate
 * process, so accumulation across chunks requires a persistent store
 * (DB, Redis, etc.). This fixture works because tests run chunk
 * jobs synchronously on the same object instance.
 */
class StagingArrayImport implements ShouldQueue, ToArray, WithHeadingRow, UsesStagingTable
{
    public array $result = [];

    public function array(array $rows): void
    {
        array_push($this->result, ...$rows);
    }
}
