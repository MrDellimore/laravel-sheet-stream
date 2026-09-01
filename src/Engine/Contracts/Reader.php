<?php

namespace MrDellimore\SheetStream\Engine\Contracts;

interface Reader
{
    public function open(string $path): void;

    /** @return iterable<int, SheetReader> */
    public function sheets(): iterable;

    public function close(): void;
}
