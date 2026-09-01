<?php

namespace MrDellimore\SheetStream\Support;

use MrDellimore\SheetStream\Concerns\WithMultipleSheets;

final class SheetResolver
{
    public static function resolve(object $import, int $sheetIndex, string $sheetName, ?array $cachedSheets = null): ?object
    {
        if ($import instanceof WithMultipleSheets) {
            $sheets = $cachedSheets ?? $import->sheets();

            return $sheets[$sheetIndex] ?? $sheets[$sheetName] ?? null;
        }

        return $sheetIndex === 0 ? $import : null;
    }
}
