<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\WithColumnStyles;
use MrDellimore\SheetStream\Concerns\WithDefaultRowStyle;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Concerns\WithHeadingStyle;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;

class StyledExport implements FromCollection, WithColumnStyles, WithDefaultRowStyle, WithHeadings, WithHeadingStyle
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
        return ['Name', 'Amount'];
    }

    public function headingStyle(): Style
    {
        return new Style(fontBold: true, fontSize: 14);
    }

    public function defaultRowStyle(): Style
    {
        return new Style(fontSize: 11);
    }

    public function columnStyles(): array
    {
        return [1 => new Style(fontColor: Color::DARK_BLUE, format: '#,##0.00')];
    }
}
