<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;

final class RowHelper
{
    public static function isEmptyRow(array $row): bool
    {
        return array_all($row, fn ($cell) => $cell === null || $cell === '');
    }

    /**
     * Persist a batch of ToModel results.
     *
     * Laravel Excel parity: an import implementing WithBatchInserts (with a batch size
     * above 1) gets one query-builder INSERT per model class, which fires no model
     * events; any other import gets save() per model.
     *
     * @param  Model[]  $models
     */
    public static function flushModels(array $models, ?object $import = null): void
    {
        if ($import instanceof WithBatchInserts && $import->batchSize() > 1) {
            self::bulkInsertModels($models);

            return;
        }

        foreach ($models as $model) {
            $model->save();
        }
    }

    /**
     * Insert models with one statement per target (class, connection, table), keeping the
     * attributes exactly as the model stores them (casts and mutators already applied).
     *
     * Models of one class whose attribute sets differ go into separate statements: a
     * multi-row INSERT takes its column list from the first row, so mixing them would
     * fail or, worse, write values into the wrong columns.
     *
     * @param  Model[]  $models
     */
    private static function bulkInsertModels(array $models): void
    {
        /** @var array<string, array{0: Model, 1: list<array<string, mixed>>}> $statements */
        $statements = [];
        /** @var array<string, array{0: list<int|string>, 1: string}> $shapes last attribute keys seen per target */
        $shapes = [];

        foreach ($models as $model) {
            // A model that is already stored needs an UPDATE; inserting it again would
            // duplicate the row or trip its primary key.
            if ($model->exists) {
                $model->save();

                continue;
            }

            $attributes = self::withTimestamps($model)->getAttributes();

            // Nothing to list in a column list; let Eloquent write the default-values row.
            if ($attributes === []) {
                $model->save();

                continue;
            }

            $target = $model::class."\0".$model->getConnectionName()."\0".$model->getTable();
            $keys = array_keys($attributes);

            if (($shapes[$target][0] ?? null) !== $keys) {
                $sorted = $keys;
                sort($sorted, SORT_STRING);
                $shapes[$target] = [$keys, $target."\0".implode("\0", $sorted)];
            }

            $statement = $shapes[$target][1];
            $statements[$statement] ??= [$model, []];
            $statements[$statement][1][] = $attributes;
        }

        foreach ($statements as [$model, $rows]) {
            $model->newModelQuery()->insert($rows);
        }
    }

    /**
     * Fill created_at / updated_at the way save() would, unless the model provided them
     * (mirrors Laravel Excel's ModelManager::prepare()).
     */
    private static function withTimestamps(Model $model): Model
    {
        if (! $model->usesTimestamps()) {
            return $model;
        }

        $time = $model->freshTimestamp();

        $updatedAt = $model->getUpdatedAtColumn();

        if ($updatedAt && $model->{$updatedAt} === null) {
            $model->setUpdatedAt($time);
        }

        $createdAt = $model->getCreatedAtColumn();

        if ($createdAt && $model->{$createdAt} === null) {
            $model->setCreatedAt($time);
        }

        return $model;
    }

    /**
     * @param  array<int, mixed>  $rawRow
     * @return array<int, string>
     */
    public static function normalizeHeadings(array $rawRow, string $strategy = 'slug'): array
    {
        return array_map(
            fn ($h) => $strategy === 'none'
                ? mb_strtolower(trim((string) $h))
                : Str::slug((string) $h, '_'),
            $rawRow
        );
    }

    public static function keyRow(array $rawRow, array $headings, int $headingCount): array
    {
        $rawCount = count($rawRow);

        if ($rawCount >= $headingCount) {
            return array_combine($headings, array_slice($rawRow, 0, $headingCount));
        }

        return array_combine($headings, array_pad($rawRow, $headingCount, null));
    }
}
