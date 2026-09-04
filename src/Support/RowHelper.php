<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class RowHelper
{
    public static function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param Model[] $models */
    public static function flushModels(array $models): void
    {
        foreach ($models as $model) {
            $model->save();
        }
    }

    /**
     * @param  array<int, mixed>  $rawRow
     * @return array<int, string>
     */
    public static function normalizeHeadings(array $rawRow, string $strategy = 'slug'): array
    {
        return array_map(
            fn ($h) => $strategy === 'none'
                ? mb_strtolower(trim((string) $h))
                : Str::slug((string) $h, '_'),
            $rawRow
        );
    }

    public static function keyRow(array $rawRow, array $headings, int $headingCount): array
    {
        $rawCount = count($rawRow);

        if ($rawCount >= $headingCount) {
            return array_combine($headings, array_slice($rawRow, 0, $headingCount));
        }

        return array_combine($headings, array_pad($rawRow, $headingCount, null));
    }
}
