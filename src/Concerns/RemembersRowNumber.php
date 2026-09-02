<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

trait RemembersRowNumber
{
    private int $currentRowNumber = 0;

    public function setRowNumber(int $rowNumber): void
    {
        $this->currentRowNumber = $rowNumber;
    }

    public function getRowNumber(): int
    {
        return $this->currentRowNumber;
    }
}
