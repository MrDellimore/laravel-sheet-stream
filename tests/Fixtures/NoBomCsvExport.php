<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Concerns\WithWriterOptions;
use OpenSpout\Writer\CSV\Options;

class NoBomCsvExport implements FromCollection, WithHeadings, WithWriterOptions
{
    public function __construct(
        private readonly array $rows = [],
    ) {}

    public function collection(): Collection
    {
        return new Collection($this->rows);
    }

    public function headings(): array
    {
        return ['Name', 'Email'];
    }

    public function writerOptions(): object
    {
        return new Options(SHOULD_ADD_BOM: false);
    }
}
