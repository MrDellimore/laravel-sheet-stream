<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithRequiredHeadings;

class RequiredHeadingsImport implements ToArray, WithHeadingRow, WithRequiredHeadings
{
    public array $result = [];

    /** @param list<string> $required */
    public function __construct(private readonly array $required) {}

    public function array(array $array): void
    {
        $this->result = $array;
    }

    public function requiredHeadings(): array
    {
        return $this->required;
    }
}
