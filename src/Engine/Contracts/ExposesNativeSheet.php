<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\Contracts;

interface ExposesNativeSheet
{
    /**
     * The engine's own object for the sheet currently being written.
     */
    public function currentNativeSheet(): object;
}
