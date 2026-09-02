<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

interface WithRowNumber
{
    public function setRowNumber(int $rowNumber): void;

    public function getRowNumber(): int;
}
