<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use Illuminate\Database\Eloquent\Model;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Tests\Fixtures\StubModel;

/**
 * Logs ['model', id, row number, chunk offset, models saved so far] per model() call, and
 * appends the saved count to each AfterChunk entry. The saved counts show exactly when
 * each batch was flushed. A row named SKIP returns no model.
 */
class LoggedModelImport implements ToModel, WithBatchInserts, WithEvents, WithHeadingRow
{
    use RecordsImportLog;

    public function model(array $row): ?Model
    {
        $this->log[] = ['model', (int) $row['id'], $this->getRowNumber(), $this->getChunkOffset(), StubModel::count()];

        if ($row['name'] === 'SKIP') {
            return null;
        }

        return new StubModel(['name' => $row['name'], 'email' => (string) $row['id']]);
    }

    protected function afterChunkExtras(): array
    {
        return [StubModel::count()];
    }
}
