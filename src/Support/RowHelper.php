<?php

namespace MrDellimore\SheetStream\Support;

use Illuminate\Database\Eloquent\Model;

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

    /** @return array<int, string> */
    public static function normalizeHeadings(array $rawRow): array
    {
        return array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rawRow);
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
