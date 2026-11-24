<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RowHelper
{
    public static function isEmptyRow(array $row): bool
    {
        return array_all($row, fn ($cell) => $cell === null || $cell === '');
    }

    /**
     * Resolve the `blank_cells` config value: null (the default) hands blank cells to the
     * import as null, like Laravel Excel; '' leaves what the reader yields untouched.
     */
    public static function blankCellsAsNull(mixed $setting): bool
    {
        return match ($setting) {
            null => true,
            '' => false,
            default => throw new InvalidArgumentException(
                "Unsupported sheet-stream.blank_cells value: expected null or '' (an empty string), got "
                .(is_scalar($setting) ? var_export($setting, true) : get_debug_type($setting)).'.'
            ),
        };
    }

    /**
     * Replace every blank cell (exactly '') with null. 0, '0', false and whitespace-only
     * strings are values and stay as they are.
     *
     * Runs once per data row: array_keys() finds the blanks in C, so a row without any
     * costs a single scan and no writes.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $row
     * @return array<TKey, mixed>
     */
    public static function blanksToNull(array $row): array
    {
        foreach (array_keys($row, '', true) as $key) {
            $row[$key] = null;
        }

        return $row;
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
