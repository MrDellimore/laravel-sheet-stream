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
    }
}
