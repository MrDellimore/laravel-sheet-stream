<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Imports\ImportRunner;

/**
 * Runs an import through a named reader driver, so a test can use every driver as a
 * dataset: ->with(ReaderDrivers::ALL). The rust rows are skipped when the extension
 * isn't loaded.
 */
final class ReaderDrivers
{
    /** @var list<string> */
    public const array ALL = ['openspout', 'phpspreadsheet', 'rust'];

    /** Make a driver the configured default, for code that builds its own reader (the manager, queued jobs). */
    public static function configure(string $driver): void
    {
        self::skipIfUnavailable($driver);

        config()->set('sheet-stream.default_reader', $driver);
    }

    public static function skipIfUnavailable(string $driver): void
    {
        if ($driver === 'rust' && ! RustReader::isAvailable()) {
            test()->markTestSkipped('The rusty_sheet extension is not loaded.');
        }
    }

    public static function open(string $driver, string $path, ?object $nativeOptions = null): Reader
    {
        self::skipIfUnavailable($driver);

        $reader = EngineFactory::reader($driver, [], $nativeOptions);
        $reader->open($path);

        return $reader;
    }

    /**
     * @template T of object
     *
     * @param  T  $import
     * @return T
     */
    public static function import(string $driver, object $import, string $path, ?ImportRunner $runner = null, ?object $nativeOptions = null): object
    {
        $reader = self::open($driver, $path, $nativeOptions);

        try {
            ($runner ?? new ImportRunner)->run($import, $reader);
        } finally {
            $reader->close();
        }

        return $import;
    }
}
