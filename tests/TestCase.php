<?php

namespace MrDellimore\SheetStream\Tests;

use MrDellimore\SheetStream\SheetStreamServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SheetStreamServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['view']->addNamespace('sheet-stream-tests', __DIR__.'/Fixtures/views');

        // Run the whole suite against other drivers, e.g.
        //   SHEET_STREAM_TEST_READER=rust SHEET_STREAM_TEST_WRITER=rust php -d extension=… vendor/bin/pest
        foreach (['reader' => 'SHEET_STREAM_TEST_READER', 'writer' => 'SHEET_STREAM_TEST_WRITER'] as $kind => $env) {
            $driver = getenv($env);
            if (is_string($driver) && $driver !== '') {
                $app['config']->set("sheet-stream.default_{$kind}", $driver);
            }
        }
    }
}
