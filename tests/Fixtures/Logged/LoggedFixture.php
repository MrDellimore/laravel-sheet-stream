<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use MrDellimore\SheetStream\Tests\Fixtures\CsvFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\MultiSheetXlsxFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

/**
 * Builds sheets for the Logged* imports: a heading row (id, name) on row 1, then one row
 * per entry of $names, whose id cell is its own spreadsheet row number. A null entry is
 * a blank row; an empty string is a row of empty cells (CSV only: `,`).
 */
final class LoggedFixture
{
    /** @param  list<string|null>  $names */
    public static function xlsx(array $names): XlsxFixtureBuilder
    {
        return (new XlsxFixtureBuilder)->write(self::rows($names));
    }

    /** @param  array<string, list<string|null>>  $sheets  names per sheet, keyed by sheet name */
    public static function multiSheetXlsx(array $sheets): MultiSheetXlsxFixtureBuilder
    {
        return (new MultiSheetXlsxFixtureBuilder)->write(array_map(self::rows(...), $sheets));
    }

    /** @param  list<string|null>  $names */
    public static function csv(array $names): CsvFixtureBuilder
    {
        $lines = array_map(
            fn (array $row): string => implode(',', $row),
            self::rows($names, emptyCells: ['', '']),
        );

        return (new CsvFixtureBuilder)->raw(implode("\n", $lines)."\n");
    }

    /**
     * @param  list<string|null>  $names
     * @return list<list<int|string>>
     */
    private static function rows(array $names, array $emptyCells = []): array
    {
        $rows = [['id', 'name']];

        foreach ($names as $i => $name) {
            $rows[] = match ($name) {
                null => [],
                '' => $emptyCells,
                default => [$i + 2, $name],
            };
        }

        return $rows;
    }
}
