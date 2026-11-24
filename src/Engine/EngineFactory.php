<?php

namespace MrDellimore\SheetStream\Engine;

use InvalidArgumentException;
use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\Contracts\Writer;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Engine\PhpSpreadsheet\PhpSpreadsheetReader;
use MrDellimore\SheetStream\Engine\PhpSpreadsheet\PhpSpreadsheetWriter;
use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Engine\Rust\RustWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

final class EngineFactory
{
    public static function reader(string $driver, array $options = [], ?object $nativeOptions = null): Reader
    {
        return match ($driver) {
            'openspout' => new OpenSpoutReader($options, $nativeOptions),
            'phpspreadsheet' => self::requirePhpSpreadsheet($driver, fn () => new PhpSpreadsheetReader($options)),
            'rust' => self::requireRustExtension(fn () => new RustReader($options, $nativeOptions)),
            default => throw new InvalidArgumentException(
                "Unsupported reader driver: {$driver}. Supported drivers: openspout, phpspreadsheet, rust."
            ),
        };
    }

    public static function writer(string $driver, string $extension, array $options = [], ?object $nativeOptions = null): Writer
    {
        return match ($driver) {
            'openspout' => new OpenSpoutWriter(
                $extension,
                $nativeOptions,
                dateFormat: $options['dates']['format'] ?? 'yyyy-mm-dd',
                dateTimeFormat: $options['dates']['datetime_format'] ?? 'yyyy-mm-dd hh:mm:ss',
            ),
            'phpspreadsheet' => self::requirePhpSpreadsheet($driver, fn () => new PhpSpreadsheetWriter($extension)),
            'rust' => self::requireRustExtension(fn () => new RustWriter(
                $extension,
                $nativeOptions,
                dateFormat: $options['dates']['format'] ?? 'yyyy-mm-dd',
                dateTimeFormat: $options['dates']['datetime_format'] ?? 'yyyy-mm-dd hh:mm:ss',
            ), 'writer'),
            default => throw new InvalidArgumentException(
                "Unsupported writer driver: {$driver}. Supported drivers: openspout, phpspreadsheet, rust."
            ),
        };
    }

    /**
     * The 'rust' driver is explicit: when the extension is missing we fail loudly
     * instead of silently running at OpenSpout speed.
     *
     * @template T
     *
     * @param  callable(): T  $factory
     * @return T
     */
    private static function requireRustExtension(callable $factory, string $kind = 'reader'): mixed
    {
        if (! RustReader::isAvailable()) {
            throw new InvalidArgumentException(
                "The 'rust' {$kind} driver requires the ".RustReader::EXTENSION.' PHP extension, which is not loaded. '
                ."Install it, or set sheet-stream.default_{$kind} to 'openspout'."
            );
        }

        return $factory();
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
