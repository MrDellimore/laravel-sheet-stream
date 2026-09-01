<?php

namespace MrDellimore\SheetStream\Concerns;

use OpenSpout\Common\Entity\Style\Style;

interface WithDefaultRowStyle
{
    public function defaultRowStyle(): Style;
}
