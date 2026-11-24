<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
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
        // A property added to a queued job after its first release needs a real default so
        // payloads serialized before it existed still unserialize; a promoted one has none.
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__.'/src/Jobs'],
    ]);
