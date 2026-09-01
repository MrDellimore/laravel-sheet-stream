<?php

namespace MrDellimore\SheetStream\Engine\Contracts;

interface Writer
{
    public function openToFile(string $path): void;

    public function addSheet(?string $name): void;

    /**
     * @param  array<int, mixed>  $cells
     * @param  object|null  $rowStyle  Row-level style (e.g. OpenSpout Style)
     * @param  array<int, object>  $columnStyles  Per-column styles keyed by column index
     */
    public function addRow(array $cells, ?object $rowStyle = null, array $columnStyles = []): void;

    public function close(): void;
}
