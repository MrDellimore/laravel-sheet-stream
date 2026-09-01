<?php

namespace MrDellimore\SheetStream;

use Illuminate\Support\ServiceProvider;

class SheetStreamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sheet-stream.php', 'sheet-stream');
        $this->app->singleton('sheet-stream', fn ($app) => new SheetStreamManager($app));
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
