<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\PhpSpreadsheet;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Support\CellNormalizer;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final readonly class PhpSpreadsheetSheetReader implements SheetReader
{
    private ?DateTimeZone $timezone;

    private bool $calculateFormulas;

    private bool $formatData;

    private string $dateMode;

    public function __construct(
        private Worksheet $worksheet,
        array $options = [],
    ) {
        $tz = $options['dates']['timezone'] ?? null;
        $this->timezone = $tz !== null ? new DateTimeZone($tz) : null;
        $this->calculateFormulas = (bool) ($options['calculateFormulas'] ?? false);
        $this->formatData = (bool) ($options['formatData'] ?? false);
        $this->dateMode = CellNormalizer::assertValidDateMode(
            (string) ($options['dates']['import_as'] ?? CellNormalizer::DATES_AS_SERIAL)
        );
    }

    public function name(): string
    {
        return $this->worksheet->getTitle();
    }

    /** @return iterable<int, array<int|string, scalar|null|DateTimeInterface>> */
    public function rows(): iterable
    {
        foreach ($this->worksheet->getRowIterator() as $row) {
            $cells = [];

            foreach ($row->getCellIterator() as $cell) {
                $cells[] = $this->readCell($cell);
            }

            yield $cells;
        }
    }

    /**
     * Mirror Laravel Excel's cell reading: raw value (or calculated value),
     * rendered through the cell's number format under WithFormatData.
     * PhpSpreadsheet stores dates as serials, so a date only becomes an
     * object when `dates.import_as` is `datetime`.
     */
    private function readCell(Cell $cell): mixed
    {
        $value = $this->calculateFormulas
            ? $cell->getCalculatedValue()
            : $cell->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if ($value === null) {
            return null;
        }

        if ($this->formatData) {
            return NumberFormat::toFormattedString(
                $value,
                $cell->getStyle()->getNumberFormat()->getFormatCode() ?? NumberFormat::FORMAT_GENERAL,
            );
        }

        if ($this->dateMode === CellNormalizer::DATES_AS_DATETIME && is_numeric($value) && $this->isDateCell($cell)) {
            $date = DateTimeImmutable::createFromMutable(Date::excelToDateTimeObject((float) $value));

            return $this->timezone instanceof DateTimeZone ? $date->setTimezone($this->timezone) : $date;
        }

        if ($value instanceof DateTimeInterface) {
            if ($this->timezone instanceof DateTimeZone) {
                $value = DateTimeImmutable::createFromInterface($value)->setTimezone($this->timezone);
            }

            return CellNormalizer::normalize([$value], $this->dateMode)[0];
        }

        return $value;
    }

    private function isDateCell(Cell $cell): bool
    {
        // With "data only" reading the style table is not loaded, so every
        // cell reports the General format and dates stay numeric.
        return Date::isDateTime($cell);
    }
}
