<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\Contracts;

interface SheetReader
{
    public function name(): string;

    /** @return iterable<int, array<int|string, scalar|null|\DateTimeInterface>> */
    public function rows(): iterable;
}
