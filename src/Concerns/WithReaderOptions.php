<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

use OpenSpout\Reader\XLSX\Options;

interface WithReaderOptions
{
    /**
     * Return an OpenSpout reader Options object for the format being read.
     *
     * @return Options|\OpenSpout\Reader\CSV\Options|\OpenSpout\Reader\ODS\Options
     */
    public function readerOptions(): object;
}
