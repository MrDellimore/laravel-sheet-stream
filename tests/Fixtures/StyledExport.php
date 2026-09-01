<?php

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
        private array $rows = [],
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
        $style = new Style;
        $style->setFontBold();
        $style->setFontSize(14);

        return $style;
    }

    public function defaultRowStyle(): Style
    {
        $style = new Style;
        $style->setFontSize(11);

        return $style;
    }

    public function columnStyles(): array
    {
        $amountStyle = new Style;
        $amountStyle->setFormat('#,##0.00');
        $amountStyle->setFontColor(Color::DARK_BLUE);

        return [1 => $amountStyle];
    }
}
