<?php

namespace MrDellimore\SheetStream;

use Illuminate\Support\ServiceProvider;
use MrDellimore\SheetStream\Staging\DatabaseStagingStore;
use MrDellimore\SheetStream\Staging\FileStagingStore;
use MrDellimore\SheetStream\Staging\StagingStore;

class SheetStreamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sheet-stream.php', 'sheet-stream');
        $this->app->singleton('sheet-stream', fn ($app) => new SheetStreamManager($app));

        $this->app->singleton(StagingStore::class, function ($app) {
            $driver = $app['config']['sheet-stream.staging.driver'] ?? 'database';

            return match ($driver) {
                'file' => new FileStagingStore(
                    $app['config']['sheet-stream.staging.path']
                        ?? ($app['config']['sheet-stream.temp_path'] ?? sys_get_temp_dir()).'/sheet_stream_staging'
                ),
                default => new DatabaseStagingStore(
                    $app['config']['sheet-stream.staging.table'] ?? 'sheet_stream_staging'
                ),
            };
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sheet-stream.php' => config_path('sheet-stream.php'),
            ], 'sheet-stream-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'sheet-stream-migrations');
        }
    }
}
