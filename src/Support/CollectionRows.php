<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Support;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The `collection_rows` setting: what each row of the Collection handed to
 * ToCollection::collection() is.
 */
final class CollectionRows
{
    /** Each row is an Illuminate\Support\Collection, as in Laravel Excel. */
    public const string AS_COLLECTION = 'collection';

    /** Each row is a plain array, as before the setting existed. */
    public const string AS_ARRAY = 'array';

    public static function assertValid(string $mode): string
    {
        if (! in_array($mode, [self::AS_COLLECTION, self::AS_ARRAY], true)) {
            throw new InvalidArgumentException(
                "Unsupported collection_rows value: '{$mode}'. Supported values: collection, array."
            );
        }

        return $mode;
    }

    /**
     * Build the Collection for collection(). Row keys are kept: with chunk reading they
     * are what makes getChunkOffset() + key the row number.
     *
     * @param  array<int, array<int|string, mixed>>  $rows
     * @return Collection<int, mixed>
     */
    public static function wrap(array $rows, string $mode = self::AS_COLLECTION): Collection
    {
        if (self::assertValid($mode) === self::AS_ARRAY) {
            return new Collection($rows);
        }

        return new Collection(array_map(
            static fn (array $row): Collection => new Collection($row),
            $rows,
        ));
    }
}
