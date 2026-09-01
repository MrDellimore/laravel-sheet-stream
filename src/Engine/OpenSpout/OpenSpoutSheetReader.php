<?php

namespace MrDellimore\SheetStream\Engine\OpenSpout;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use OpenSpout\Reader\SheetInterface;

final class OpenSpoutSheetReader implements SheetReader
{
    private ?DateTimeZone $timezone;

    public function __construct(
        private SheetInterface $sheet,
        array $options = [],
    ) {
        $tz = $options['dates']['timezone'] ?? null;
        $this->timezone = $tz !== null ? new DateTimeZone($tz) : null;
    }

    public function name(): string
    {
        return $this->sheet->getName();
    }

    /** @return iterable<int, array<int|string, scalar|null|DateTimeInterface>> */
    public function rows(): iterable
    {
        $iterator = $this->sheet->getRowIterator();

        try {
            $iterator->rewind();
        } catch (\Throwable) {
            return;
        }

        while (true) {
            if (! $iterator->valid()) {
                break;
            }

            try {
                $row = $iterator->current();
                $cells = $this->safeToArray($row);
            } catch (\Throwable) {
                // Row could not be read (e.g. out-of-range date serial in a cell).
                // Skip the row and advance the iterator.
                try {
                    $iterator->next();
                } catch (\Throwable) {
                    break;
                }

                continue;
            }

            if ($this->timezone !== null) {
                $cells = $this->applyTimezone($cells);
            }

            yield $cells;

            try {
                $iterator->next();
            } catch (\Throwable) {
                // next() can throw if the following row has a corrupt cell.
                // The current row was already yielded, so just stop.
                break;
            }
        }
    }

    /**
     * Convert a row to a plain array, substituting null for any cell whose
     * value cannot be read (e.g. OpenSpout's InvalidValueException on a
     * date serial that falls outside the valid Excel range).
     *
     * @return array<int, scalar|null|DateTimeInterface>
     */
    private function safeToArray(\OpenSpout\Common\Entity\Row $row): array
    {
        try {
            return $row->toArray();
        } catch (\Throwable) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                try {
                    $cells[] = $cell->getValue();
                } catch (\Throwable) {
                    $cells[] = null;
                }
            }

            return $cells;
        }
    }

    private function applyTimezone(array $cells): array
    {
        foreach ($cells as $i => $cell) {
            if ($cell instanceof DateTimeImmutable) {
                $cells[$i] = $cell->setTimezone($this->timezone);
            } elseif ($cell instanceof DateTimeInterface) {
                $mutable = \DateTime::createFromInterface($cell);
                $mutable->setTimezone($this->timezone);
                $cells[$i] = DateTimeImmutable::createFromMutable($mutable);
            }
        }

        return $cells;
    }
}
