<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model with everything a bulk insert could get wrong: timestamps, casts, a
 * mutator, and event hooks that record every model event fired.
 */
class BulkPerson extends Model
{
    /** @var list<string> every model event fired, in order, e.g. "creating:Alice" */
    public static array $events = [];

    protected $table = 'bulk_people';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'born_on' => 'date',
            'seen_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : strtolower($value);
    }

    protected static function booted(): void
    {
        foreach (['saving', 'creating', 'created', 'saved', 'updating', 'updated'] as $event) {
            static::{$event}(function (self $model) use ($event): void {
                static::$events[] = $event.':'.$model->name;
            });
        }
    }
}
