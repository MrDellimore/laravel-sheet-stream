<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Generator;
use MrDellimore\SheetStream\Concerns\FromGenerator;
use MrDellimore\SheetStream\Concerns\WithHeadings;

class GeneratorExport implements FromGenerator, WithHeadings
{
    public function __construct(
        private array $rows = [],
    ) {}

    public function generator(): Generator
    {
        foreach ($this->rows as $row) {
            yield $row;
        }
    }

    public function headings(): array
    {
        return ['Name', 'Email'];
    }
}
