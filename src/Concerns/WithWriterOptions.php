<?php

namespace MrDellimore\SheetStream\Concerns;

use OpenSpout\Writer\XLSX\Options;

interface WithWriterOptions
{
    /**
     * Return an OpenSpout writer Options object for the format being written.
     *
     * @return Options|\OpenSpout\Writer\CSV\Options|\OpenSpout\Writer\ODS\Options
     */
    public function writerOptions(): object;
}
