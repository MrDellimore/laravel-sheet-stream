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
                // current() failed — skip this row entirely.
                if (! $this->advancePastBadRow($iterator)) {
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
                // next() failed while reading the row that follows the one we just yielded.
                // The XML reader is now positioned after the bad cell, still inside that row.
                // We need to:
                //   1. Consume the remainder of the bad row (call next() to get a partial row).
                //   2. Discard that partial row by calling next() once more to get the
                //      clean row that follows.
                // If either recovery call fails we stop — but in practice each successive
                // bad row triggers its own recovery round on a future loop iteration.
                if (! $this->advancePastBadRow($iterator)) {
                    break;
                }
            }
        }
    }

    /**
     * Advance the iterator past a row whose cells could not be read.
     *
     * When next() throws, the XML reader is partway through the bad row (positioned
     * after the offending cell, because XMLReader::expand() advances past that element).
     * Calling next() again reads from there to the row's closing tag, producing a
     * garbage partial row in the iterator buffer. A second next() then advances to the
     * first clean row after the bad one.
     *
     * Both calls may throw independently (e.g. a row with multiple bad cells, or two
     * consecutive bad rows). We retry each step up to 5 times before giving up.
     *
     * Returns true when the iterator is positioned on a clean row and the caller
     * should continue; false when iteration must stop.
     */
    private function advancePastBadRow(\Iterator $iterator): bool
    {
        // Step 1: consume the remainder of the bad row. One call is normally enough;
        // retry up to 5 times in case the row contains more than one bad cell.
        $consumed = false;

        for ($i = 0; $i < 5; $i++) {
            try {
                $iterator->next();
                $consumed = true;
                break;
            } catch (\Throwable) {
                // Another bad cell within the same row — keep consuming.
            }
        }

        if (! $consumed) {
            return false;
        }

        // Step 2: the iterator buffer now holds a partial/garbage row. Call next()
        // to advance to the real row that follows. If that row also has bad cells,
        // this call throws — the outer loop will catch it on the next iteration and
        // call advancePastBadRow() again, so we handle cascading bad rows naturally.
        try {
            $iterator->next();
        } catch (\Throwable) {
            return false;
        }

        return true;
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
