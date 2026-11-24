<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\Rust;

use DateTimeInterface;
use MrDellimore\SheetStream\Engine\Contracts\KeysRowsBySourceRow;
use MrDellimore\SheetStream\Engine\Contracts\ReadsFirstRow;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Support\CellNormalizer;
use RustySheet\Reader as NativeReader;

final readonly class RustSheetReader implements KeysRowsBySourceRow, ReadsFirstRow, SheetReader
{
    public function __construct(
        private NativeReader $reader,
        private int $index,
        private string $name,
        private bool $convertDatesToSerial,
        private int $batchSize,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Rows arrive from the extension already trimmed and typed like the OpenSpout
     * driver's output, in batches to keep PHP↔Rust calls cheap.
     *
     * A sheet streams once: the extension doesn't rewind, so calling rows() again on
     * the same sheet yields nothing (open the file again to re-read).
     *
     * The extension keys each batch by 1-based source row (the line a record starts on for
     * CSV), so a blank row it skipped leaves a gap in the keys rather than shifting them.
     *
     * @return iterable<int, array<int|string, scalar|null|DateTimeInterface>>
     */
    public function rows(): iterable
    {
        while (($batch = $this->reader->nextRows($this->index, $this->batchSize)) !== []) {
            foreach ($batch as $sourceRow => $row) {
                yield $sourceRow => $this->convert($row);
            }
        }
    }

    /**
     * Asks the extension for a single row, so reading a header leaves the rest of the
     * sheet unparsed (rows() would fetch a whole batch).
     *
     * @return array<int|string, scalar|null|DateTimeInterface>|null
     */
    public function firstRow(): ?array
    {
        $batch = $this->reader->nextRows($this->index, 1);

        if ($batch === []) {
            return null;
        }

        return $this->convert(reset($batch));
    }

    /**
     * @param  array<int|string, scalar|null|DateTimeInterface>  $row
     * @return array<int|string, scalar|null|DateTimeInterface>
     */
    private function convert(array $row): array
    {
        return $this->convertDatesToSerial ? CellNormalizer::normalize($row) : $row;
    }
}
