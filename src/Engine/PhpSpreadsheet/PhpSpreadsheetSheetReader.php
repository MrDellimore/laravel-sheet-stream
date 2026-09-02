<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\PhpSpreadsheet;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final readonly class PhpSpreadsheetSheetReader implements SheetReader
{
    private ?DateTimeZone $timezone;

    public function __construct(
        private Worksheet $worksheet,
        ?string $timezone = null,
        private bool $calculateFormulas = false,
    ) {
        $this->timezone = $timezone !== null ? new DateTimeZone($timezone) : null;
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
                $value = $this->calculateFormulas
                    ? $cell->getCalculatedValue()
                    : $cell->getValue();

                if ($value instanceof DateTimeInterface) {
                    if ($this->timezone instanceof DateTimeZone) {
                        $value = $value instanceof DateTimeImmutable
                            ? $value->setTimezone($this->timezone)
                            : DateTimeImmutable::createFromInterface($value)->setTimezone($this->timezone);
                    }
                }

                $cells[] = $value;
            }

            yield $cells;
        }
    }
}
