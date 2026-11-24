<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Engine\Rust\RustReader;

/**
 * One XLSX and one CSV file holding every kind of "blank" cell next to the values
 * that must never be mistaken for one, plus what each driver yields for them.
 *
 * XLSX (11 headings):
 *   a='x'  gap=(no cell)  empty_el=<c/>  inline_empty=''  shared_empty=''  ws='  '
 *   zero=0  flag=false  zero_str='0'  last='y'  trail=(no cell)
 *   then a short row holding only 'z'.
 *
 * CSV (8 headings):
 *   x,,"","  ",0,false,y,          and a short row holding only 'z'.
 *
 * The OpenSpout and rust drivers ("streaming") agree with each other; the
 * phpspreadsheet driver already yields null for a cell that isn't there and
 * type-casts CSV text.
 */
final class BlankCellsFixtures
{
    public const array DRIVERS = ['openspout', 'phpspreadsheet', 'rust'];

    public const array XLSX_HEADINGS = ['a', 'gap', 'empty_el', 'inline_empty', 'shared_empty', 'ws', 'zero', 'flag', 'zero_str', 'last', 'trail'];

    public const array CSV_HEADINGS = ['a', 'gap', 'quoted_empty', 'ws', 'zero', 'flag', 'last', 'trail'];

    /** @var list<string> */
    private static array $csvPaths = [];

    public static function xlsx(): BlankCellsXlsxFixtureBuilder
    {
        return (new BlankCellsXlsxFixtureBuilder)->write([
            array_map(fn (string $heading): array => ['inline', $heading], self::XLSX_HEADINGS),
            [
                ['inline', 'x'], null, ['empty'], ['inline', ''], ['shared', ''], ['inline', '  '],
                ['number', '0'], ['bool', false], ['shared', '0'], ['inline', 'y'],
            ],
            [['inline', 'z']],
        ]);
    }

    public static function csv(?string $contents = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sheet_blank_fixture_').'.csv';
        file_put_contents($path, $contents ?? implode(',', self::CSV_HEADINGS)."\nx,,\"\",\"  \",0,false,y,\nz\n");

        return self::$csvPaths[] = $path;
    }

    public static function cleanup(): void
    {
        foreach (self::$csvPaths as $path) {
            @unlink($path);
            @unlink(substr($path, 0, -4));
        }

        self::$csvPaths = [];
    }

    public static function driverAvailable(string $driver): bool
    {
        return $driver !== 'rust' || RustReader::isAvailable();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function driversAndFormats(): array
    {
        $sets = [];

        foreach (self::DRIVERS as $driver) {
            foreach (['xlsx', 'csv'] as $format) {
                $sets["{$driver} {$format}"] = [$driver, $format];
            }
        }

        return $sets;
    }

    /**
     * The data rows exactly as the driver's reader yields them (heading row excluded).
     *
     * @return list<list<mixed>>
     */
    public static function rawRows(string $driver, string $format): array
    {
        $streaming = $driver !== 'phpspreadsheet';

        return match (true) {
            $streaming && $format === 'xlsx' => [
                ['x', '', '', '', '', '  ', 0, false, '0', 'y'],
                ['z'],
            ],
            $streaming => [
                ['x', '', '', '  ', '0', 'false', 'y', ''],
                ['z'],
            ],
            $format === 'xlsx' => [
                ['x', null, null, '', '', '  ', 0, false, '0', 'y', null],
                ['z', null, null, null, null, null, null, null, null, null, null],
            ],
            default => [
                ['x', null, null, '  ', 0, false, 'y', null],
                ['z', null, null, null, null, null, null, null],
            ],
        };
    }

    /**
     * The data rows as an import without WithHeadingRow must receive them.
     *
     * @return list<list<mixed>>
     */
    public static function listRows(string $driver, string $format, bool $blanksAsNull): array
    {
        if (! $blanksAsNull) {
            return self::rawRows($driver, $format);
        }

        $streaming = $driver !== 'phpspreadsheet';

        return match (true) {
            $streaming && $format === 'xlsx' => [
                ['x', null, null, null, null, '  ', 0, false, '0', 'y'],
                ['z'],
            ],
            $streaming => [
                ['x', null, null, '  ', '0', 'false', 'y', null],
                ['z'],
            ],
            $format === 'xlsx' => [
                ['x', null, null, null, null, '  ', 0, false, '0', 'y', null],
                ['z', null, null, null, null, null, null, null, null, null, null],
            ],
            default => [
                ['x', null, null, '  ', 0, false, 'y', null],
                ['z', null, null, null, null, null, null, null],
            ],
        };
    }

    /**
     * The data rows as an import with WithHeadingRow must receive them.
     *
     * @return list<array<string, mixed>>
     */
    public static function keyedRows(string $driver, string $format, bool $blanksAsNull): array
    {
        $streaming = $driver !== 'phpspreadsheet';
        // What the reader yields for a blank inside the row when it is left untouched.
        $gap = $blanksAsNull || ! $streaming ? null : '';

        if ($format === 'xlsx') {
            return [
                [
                    'a' => 'x',
                    'gap' => $gap,
                    'empty_el' => $gap,
                    // An explicit empty string is '' on every driver until it is normalized.
                    'inline_empty' => $blanksAsNull ? null : '',
                    'shared_empty' => $blanksAsNull ? null : '',
                    'ws' => '  ',
                    'zero' => 0,
                    'flag' => false,
                    'zero_str' => '0',
                    'last' => 'y',
                    'trail' => null,
                ],
                ['a' => 'z'] + array_fill_keys(self::XLSX_HEADINGS, null),
            ];
        }

        return [
            [
                'a' => 'x',
                'gap' => $gap,
                'quoted_empty' => $gap,
                'ws' => '  ',
                'zero' => $streaming ? '0' : 0,
                'flag' => $streaming ? 'false' : false,
                'last' => 'y',
                // A trailing CSV field exists in the file, so it is '' rather than padding.
                'trail' => $gap,
            ],
            ['a' => 'z'] + array_fill_keys(self::CSV_HEADINGS, null),
        ];
    }
}
