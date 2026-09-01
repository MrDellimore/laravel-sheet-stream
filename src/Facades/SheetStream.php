<?php

namespace MrDellimore\SheetStream\Facades;

use Illuminate\Support\Facades\Facade;

class SheetStream extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'sheet-stream';
    }
}
