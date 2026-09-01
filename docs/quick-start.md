# Quick Start

This guide walks you through your first import and export in under five minutes.

## Import a spreadsheet

### 1. Create an import class

```php
<?php

namespace App\Imports;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;

class UsersImport implements ToModel, WithHeadingRow, WithBatchInserts
{
    public function model(array $row): ?Model
    {
        return new User([
            'name'  => $row['name'],
            'email' => $row['email'],
        ]);
    }

    public function batchSize(): int
    {
        return 500;
    }
}
```

### 2. Run the import

```php
use MrDellimore\SheetStream\Facades\SheetStream;

SheetStream::import(new UsersImport, storage_path('imports/users.xlsx'));
```

That's it. The file is streamed row-by-row — memory stays flat regardless of file size.

## Export a spreadsheet

### 1. Create an export class

```php
<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use MrDellimore\SheetStream\Concerns\FromQuery;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Concerns\WithMapping;

class UsersExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return User::query()->orderBy('name');
    }

    public function headings(): array
    {
        return ['Name', 'Email', 'Joined'];
    }

    public function map(mixed $row): array
    {
        return [
            $row->name,
            $row->email,
            $row->created_at->toDateString(),
        ];
    }
}
```

### 2. Download or store

```php
use MrDellimore\SheetStream\Facades\SheetStream;

// Streamed download (no temp file bloat)
return SheetStream::download(new UsersExport, 'users.xlsx');

// Or store to a disk
SheetStream::store(new UsersExport, 'exports/users.xlsx', disk: 's3');
```

## Supported file formats

| Format | Extension | Import | Export |
|---|---|---|---|
| Excel (Open XML) | `.xlsx` | Yes | Yes |
| CSV | `.csv` | Yes | Yes |
| TSV | `.tsv` | Yes | Yes |
| OpenDocument | `.ods` | Yes | Yes |
| Legacy Excel | `.xls` | No | No |

Attempting to use `.xls` files throws an `UnsupportedByEngine` exception with a clear message.

## Next steps

- [Imports](imports.md) — all import concerns in detail
- [Exports](exports.md) — all export concerns in detail
- [Configuration](configuration.md) — tune batch sizes, date handling, etc.
- [Migration Guide](migration-guide.md) — coming from Laravel Excel?
