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

    public static function open(string $driver, string $path, ?object $nativeOptions = null): Reader
    {
        if ($driver === 'rust' && ! RustReader::isAvailable()) {
            test()->markTestSkipped('The rusty_sheet extension is not loaded.');
        }

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
