<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

use Generator;

interface FromGenerator
{
    public function generator(): Generator;
}
