<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\Rust;

use DateTimeInterface;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Support\CellNormalizer;
use RustySheet\Reader as NativeReader;

final readonly class RustSheetReader implements SheetReader
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
     * @return iterable<int, array<int|string, scalar|null|DateTimeInterface>>
     */
    public function rows(): iterable
    {
        while (($batch = $this->reader->nextRows($this->index, $this->batchSize)) !== []) {
            foreach ($batch as $row) {
                yield $this->convertDatesToSerial ? CellNormalizer::normalize($row) : $row;
            }
        }
    }
}
