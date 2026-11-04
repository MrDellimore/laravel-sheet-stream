<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\Contracts;

interface WritesNativeRows
{
    /**
     * Whether the engine can write the given row object as it is.
     */
    public function acceptsNativeRow(mixed $row): bool;

    /**
     * Write a row object built for the engine (e.g. an OpenSpout Row with its
     * own height and per-cell styles) without any further mapping or styling.
     */
    public function addNativeRow(object $row): void;
}
