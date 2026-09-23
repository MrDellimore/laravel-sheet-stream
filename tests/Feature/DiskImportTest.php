<?php

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Jobs\QueuedImportJob;
use MrDellimore\SheetStream\Support\TempFileResolver;
use MrDellimore\SheetStream\Tests\Fixtures\QueuedArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

/**
 * Synchronous imports must honour the $disk argument the same way queued imports do:
 * the engines can only open local files, so a disk-hosted file is streamed to a temp
 * file for the duration of the import and removed afterwards.
 */
function tempImportCopies(): array
{
    return glob((config('sheet-stream.temp_path') ?? sys_get_temp_dir()).'/sheet_stream_import_*') ?: [];
}

it('imports synchronously from a filesystem disk', function () {
    Storage::fake('s3');

    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
    ]);
    Storage::disk('s3')->put('imports/people.xlsx', file_get_contents($fixture->path()));

    $before = tempImportCopies();

    $import = new SimpleArrayImport;
    $result = app('sheet-stream')->import($import, 'imports/people.xlsx', disk: 's3');

    expect($result)->toBeNull()
        ->and($import->result)->toHaveCount(2)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com'])
        ->and(tempImportCopies())->toBe($before);
});

it('removes the temp copy when opening the disk file fails', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('imports/broken.xlsx', 'not a zip archive');

    $before = tempImportCopies();

    expect(fn () => app('sheet-stream')->import(new SimpleArrayImport, 'imports/broken.xlsx', disk: 's3'))
        ->toThrow(Exception::class)
        ->and(tempImportCopies())->toBe($before);
});

it('leaves a local source file untouched when no disk is given', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name'],
        ['Alice'],
    ]);

    expect(TempFileResolver::localPath($fixture->path(), null))->toBe($fixture->path());

    TempFileResolver::cleanup($fixture->path(), null);

    expect(file_exists($fixture->path()))->toBeTrue();
});

it('keeps the source extension on the temp copy so the engine can pick a reader', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('imports/people.xlsx', 'placeholder');

    $localPath = TempFileResolver::localPath('imports/people.xlsx', 's3');

    expect($localPath)->toEndWith('.xlsx')
        ->and(file_exists($localPath))->toBeTrue();

    TempFileResolver::cleanup($localPath, 's3');

    expect(file_exists($localPath))->toBeFalse();
});

it('runs a queued import job from a filesystem disk', function () {
    Storage::fake('s3');

    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
    ]);
    Storage::disk('s3')->put('imports/people.xlsx', file_get_contents($fixture->path()));

    $before = tempImportCopies();

    $import = new QueuedArrayImport;
    $job = new QueuedImportJob(
        import: $import,
        filePath: 'imports/people.xlsx',
        disk: 's3',
        readerOptions: [
            'dates' => ['import_as' => 'serial', 'timezone' => null],
        ],
    );

    $job->handle();

    expect($import->result)->toHaveCount(1)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and(tempImportCopies())->toBe($before);
});

it('reports a missing disk file clearly and leaves no temp copy behind', function () {
    Storage::fake('s3');

    $before = tempImportCopies();

    expect(fn () => app('sheet-stream')->import(new SimpleArrayImport, 'imports/missing.xlsx', disk: 's3'))
        ->toThrow(FileNotFoundException::class, 'imports/missing.xlsx')
        ->and(tempImportCopies())->toBe($before);
});
