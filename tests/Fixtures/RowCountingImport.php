<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\OnEachRow;

/**
 * Counts the rows an import delivers to user code. Tests extend it anonymously to add
 * the concerns under test (WithHeadingRow, SkipsEmptyRows, WithReaderOptions, …).
 */
class RowCountingImport implements OnEachRow
{
    public int $delivered = 0;

    public function __construct(public ?object $nativeOptions = null) {}

    public function onRow(array $row): void
    {
        $this->delivered++;
    }

    public function readerOptions(): object
    {
        return $this->nativeOptions ?? throw new \LogicException('No reader options given.');
    }
}
