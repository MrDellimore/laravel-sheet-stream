<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\RemembersChunkOffset;
use MrDellimore\SheetStream\Concerns\RemembersRowNumber;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Imports\Failure;

/**
 * Records every call the runner makes on an import, in order, so a test can assert the
 * exact sequence. Fixture sheets carry an `id` column (by convention the row's own
 * spreadsheet row number) that identifies a row in the log.
 *
 * rules() and onFailure() only take effect on a class that also implements
 * WithValidation / SkipsOnFailure; tests add those with an anonymous subclass.
 */
trait RecordsImportLog
{
    use RemembersChunkOffset, RemembersRowNumber;

    /** @var list<array<int, mixed>> */
    public array $log = [];

    /** Source row of each chunk's first row, as reported by AfterChunk::$startRow. */
    public array $chunkStartRows = [];

    /**
     * @param  array<string, mixed>  $rules  validation rules, for subclasses implementing WithValidation
     */
    public function __construct(
        public int $chunkSize = 2,
        public int $batchSize = 1000,
        public array $rules = ['name' => ['not_in:BAD']],
    ) {}

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }

    public function rules(): array
    {
        return $this->rules;
    }

    /** @param Collection<int, Failure> $failures */
    public function onFailure(Collection $failures): void
    {
        $this->log[] = ['onFailure', $failures->map(fn (Failure $f): int => $f->row())->all()];
    }

    public function registerEvents(): array
    {
        return [
            BeforeSheet::class => function (BeforeSheet $event): void {
                $this->log[] = ['BeforeSheet', $event->sheetIndex];
            },
            AfterChunk::class => function (AfterChunk $event): void {
                $this->log[] = ['AfterChunk', $event->chunkNumber, $event->rowsInChunk, ...$this->afterChunkExtras()];
                $this->chunkStartRows[] = $event->startRow;
            },
            AfterSheet::class => function (AfterSheet $event): void {
                $this->log[] = ['AfterSheet', $event->sheetIndex];
            },
        ];
    }

    /** Extra values a fixture appends to its AfterChunk log entries. */
    protected function afterChunkExtras(): array
    {
        return [];
    }

    /** Log entries with the given name, without the name. */
    public function entries(string $name): array
    {
        return array_values(array_map(
            fn (array $entry): array => array_slice($entry, 1),
            array_filter($this->log, fn (array $entry): bool => $entry[0] === $name),
        ));
    }

    /**
     * The id column of each row, keeping the keys the runner delivered.
     *
     * @param  iterable<int, mixed>  $rows
     * @return array<int, int|null>
     */
    protected function ids(iterable $rows): array
    {
        $ids = [];

        foreach ($rows as $key => $row) {
            $ids[$key] = isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null;
        }

        return $ids;
    }
}
