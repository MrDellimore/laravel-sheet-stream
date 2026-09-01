<?php

namespace MrDellimore\SheetStream\Engine;

use InvalidArgumentException;
use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\Contracts\Writer;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Engine\PhpSpreadsheet\PhpSpreadsheetReader;
use MrDellimore\SheetStream\Engine\PhpSpreadsheet\PhpSpreadsheetWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

final class EngineFactory
{
    public static function reader(string $driver, array $options = [], ?object $nativeOptions = null): Reader
    {
        return match ($driver) {
            'openspout' => new OpenSpoutReader($options, $nativeOptions),
            'phpspreadsheet' => self::requirePhpSpreadsheet($driver, fn () => new PhpSpreadsheetReader($options)),
            default => throw new InvalidArgumentException(
                "Unsupported reader driver: {$driver}. Supported drivers: openspout, phpspreadsheet."
            ),
        };
    }

    public static function writer(string $driver, string $extension, ?object $nativeOptions = null): Writer
    {
        return match ($driver) {
            'openspout' => new OpenSpoutWriter($extension, $nativeOptions),
            'phpspreadsheet' => self::requirePhpSpreadsheet($driver, fn () => new PhpSpreadsheetWriter($extension)),
            default => throw new InvalidArgumentException(
                "Unsupported writer driver: {$driver}. Supported drivers: openspout, phpspreadsheet."
            ),
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $factory
     * @return T
     */
    private static function requirePhpSpreadsheet(string $driver, callable $factory): mixed
    {
        if (! class_exists(Spreadsheet::class)) {
            throw new InvalidArgumentException(
                "The '{$driver}' driver requires phpoffice/phpspreadsheet. Install it with: composer require phpoffice/phpspreadsheet"
            );
        }

        return $factory();
    }
}
