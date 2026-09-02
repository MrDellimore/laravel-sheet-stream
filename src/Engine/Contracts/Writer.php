<?php

declare(strict_types=1);

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

    /**
     * Load an HTML string into the current sheet using PhpSpreadsheet's Html reader.
     *
     * Only supported by the phpspreadsheet driver. OpenSpout will throw UnsupportedByEngine.
     */
    public function loadHtml(string $html): void;

    public function close(): void;
}
