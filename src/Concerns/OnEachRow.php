<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

/**
 * Receive each row individually for manual processing.
 *
 * Unlike ToModel (which returns a Model for batch persistence) or
 * ToCollection (which buffers all rows), OnEachRow calls onRow()
 * once per row and leaves persistence entirely to the implementer.
 */
interface OnEachRow
{
    public function onRow(array $row): void;
}
