<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

use Illuminate\Contracts\View\View;

interface FromView
{
    public function view(): View;
}
