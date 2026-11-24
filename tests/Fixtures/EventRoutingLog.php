<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Closure;
use MrDellimore\SheetStream\Events\AfterChunk;

/**
 * Shared log for the event-routing fixtures. Static, because queued sheet and chunk jobs carry
 * their own copies of the import objects on a real queue.
 *
 * Entries read "<owner>:<Event>" for import-scoped events, "<owner>:<Event>:<sheetIndex>" for
 * sheet events, and "<owner>:AfterChunk:<sheetIndex>#<chunkNumber>" for chunks.
 */
final class EventRoutingLog
{
    /** @var list<string> */
    public static array $entries = [];

    /** @var list<array{owner: string, event: object}> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$entries = [];
        self::$events = [];
    }

    public static function record(string $owner, object $event): void
    {
        $entry = $owner.':'.class_basename($event);

        if (property_exists($event, 'sheetIndex')) {
            $entry .= ':'.$event->sheetIndex;
        }

        if ($event instanceof AfterChunk) {
            $entry .= '#'.$event->chunkNumber;
        }

        self::$entries[] = $entry;
        self::$events[] = ['owner' => $owner, 'event' => $event];
    }

    /**
     * Listeners that log under $owner and then run the optional callback.
     *
     * @param  array<int|class-string, class-string|Closure|null>  $listeners  a list of event classes, or event class => callback
     * @return array<class-string, Closure>
     */
    public static function listeners(string $owner, array $listeners): array
    {
        $registered = [];

        foreach ($listeners as $key => $value) {
            [$eventClass, $callback] = is_int($key) ? [$value, null] : [$key, $value];

            $registered[$eventClass] = static function (object $event) use ($owner, $callback): void {
                self::record($owner, $event);

                if ($callback instanceof Closure) {
                    $callback($event);
                }
            };
        }

        return $registered;
    }

    /**
     * @return list<string> entries of one owner
     */
    public static function of(string $owner): array
    {
        return array_values(array_filter(self::$entries, fn (string $entry): bool => str_starts_with($entry, $owner.':')));
    }

    /**
     * @return list<string> entries of one event, any owner
     */
    public static function named(string $event): array
    {
        return array_values(array_filter(self::$entries, fn (string $entry): bool => explode(':', $entry)[1] === $event));
    }
}
