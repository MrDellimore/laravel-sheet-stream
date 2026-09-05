<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Exceptions;

use RuntimeException;

class MissingHeadingsException extends RuntimeException
{
    /** @param list<string> $missing */
    public function __construct(array $missing, string $sheetName = '')
    {
        $sheet = $sheetName !== '' ? " in sheet \"{$sheetName}\"" : '';
        parent::__construct(
            'The following required headings are missing'.$sheet.': '.implode(', ', $missing)
        );
    }
}
