<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Support;

final class ConversionResult
{
    /**
     * @param  array<int, string>  $csvPaths    CSV file paths indexed by sheet index
     * @param  array<int, string>  $sheetNames  Sheet names indexed by sheet index
     */
    public function __construct(
        public readonly array $csvPaths,
        public readonly array $sheetNames,
    ) {}

    public function cleanup(): void
    {
        foreach ($this->csvPaths as $path) {
            @unlink($path);
        }
    }
}
