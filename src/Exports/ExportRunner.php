<?php

namespace MrDellimore\SheetStream\Exports;

use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\FromGenerator;
use MrDellimore\SheetStream\Concerns\FromQuery;
use MrDellimore\SheetStream\Concerns\WithColumnStyles;
use MrDellimore\SheetStream\Concerns\WithDefaultRowStyle;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Concerns\WithHeadingStyle;
use MrDellimore\SheetStream\Concerns\WithMapping;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithTitle;
use MrDellimore\SheetStream\Engine\Contracts\Writer;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeExport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Events\BeforeWriting;
use MrDellimore\SheetStream\Exceptions\InvalidConcernCombination;
use MrDellimore\SheetStream\Support\EventBus;

class ExportRunner
{
    public function __construct(
        private readonly int $chunkSize = 1000,
    ) {}

    public function run(object $export, Writer $writer): void
    {
        $sheets = $export instanceof WithMultipleSheets ? $export->sheets() : [$export];

        foreach ($sheets as $sheet) {
            $this->validateConcerns($sheet);
        }

        $bus = EventBus::for($export);
        $bus?->dispatch(new BeforeExport($export));

        foreach ($sheets as $sheetIndex => $sheet) {
            $sheetName = $sheet instanceof WithTitle ? $sheet->title() : null;
            $this->openSheet($sheet, $writer);
            $bus?->dispatch(new BeforeSheet($sheet, $sheetIndex, $sheetName));
            $this->writeSheet($sheet, $writer);
            $bus?->dispatch(new AfterSheet($sheet, $sheetIndex, $sheetName));
        }

        $bus?->dispatch(new BeforeWriting($export));
    }

    private function openSheet(object $export, Writer $writer): void
    {
        $writer->addSheet($export instanceof WithTitle ? $export->title() : null);
    }

    private function writeSheet(object $export, Writer $writer): void
    {
        if ($export instanceof WithHeadings) {
            $headingStyle = $export instanceof WithHeadingStyle ? $export->headingStyle() : null;
            $writer->addRow($export->headings(), $headingStyle);
        }

        $mapsRows = $export instanceof WithMapping;
        $defaultRowStyle = $export instanceof WithDefaultRowStyle ? $export->defaultRowStyle() : null;
        $columnStyles = $export instanceof WithColumnStyles ? $export->columnStyles() : [];

        foreach ($this->getRows($export) as $row) {
            $writer->addRow(
                $mapsRows ? $export->map($row) : (array) $row,
                $defaultRowStyle,
                $columnStyles,
            );
        }
    }

    private function validateConcerns(object $export): void
    {
        $sourceCount = ($export instanceof FromCollection ? 1 : 0)
                     + ($export instanceof FromQuery ? 1 : 0)
                     + ($export instanceof FromGenerator ? 1 : 0);

        if ($sourceCount === 0) {
            throw new InvalidConcernCombination(
                'Export sheet must implement at least one of: FromCollection, FromQuery, or FromGenerator.'
            );
        }

        if ($sourceCount > 1) {
            throw new InvalidConcernCombination(
                'Export sheet must implement only one of: FromCollection, FromQuery, or FromGenerator.'
            );
        }
    }

    private function getRows(object $export): iterable
    {
        if ($export instanceof FromCollection) {
            yield from $export->collection();

            return;
        }

        if ($export instanceof FromGenerator) {
            yield from $export->generator();

            return;
        }

        if ($export instanceof FromQuery) {
            foreach ($export->query()->lazy($this->chunkSize) as $row) {
                yield $row;
            }
        }
    }
}
