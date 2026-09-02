<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector;
use Rector\Set\ValueObject\SetList;
use Utils\Rector\ChainExpectCallsRector;
use Utils\Rector\UseToHaveSameSizeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets()
    ->withSets([
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
    ])
    ->withRules([
        UseToHaveSameSizeRector::class,
        ChainExpectCallsRector::class,
    ])
    ->withSkip([
        RemoveUnusedPromotedPropertyRector::class,
    ]);
