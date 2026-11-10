<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Events;

/**
 * Fired once the writer has closed the file and before it is stored or
 * streamed, with the path of the finished local file. Use it to post-process
 * the file (e.g. patch parts of an .xlsx package the engine cannot write).
 */
class AfterWriting
{
    public function __construct(
        public readonly object $export,
        public readonly string $path,
    ) {}
}
