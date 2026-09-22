<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\Contracts;

interface SheetReader
{
    public function name(): string;

    /**
     * Yield each row's cells. Date-styled cells arrive as Excel serial numbers
     * by default (Laravel Excel parity); a `\DateTimeInterface` only appears
     * when the reader was built with `dates.import_as` set to `datetime`.
     *
     * @return iterable<int, array<int|string, scalar|null|\DateTimeInterface>>
     */
    public function rows(): iterable;
}
