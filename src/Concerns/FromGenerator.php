<?php

namespace MrDellimore\SheetStream\Concerns;

use Generator;

interface FromGenerator
{
    public function generator(): Generator;
}
