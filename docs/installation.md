# Installation

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.4` |
| Laravel | `^11.0 \| ^12.0 \| ^13.0` |
| OpenSpout | `^5.12` (installed automatically) |
| ext-zip | Required for `.xlsx` support |
| ext-xmlreader | Required for `.xlsx` support |

## Install via Composer

```bash
composer require mrdellimore/laravel-sheet-stream
```

The package uses Laravel's auto-discovery, so the service provider and facade are registered automatically.

## Publish the config (optional)

```bash
php artisan vendor:publish --tag=sheet-stream-config
```

This publishes `config/sheet-stream.php` where you can adjust batch sizes, chunk sizes, and date coercion settings. See [Configuration](configuration.md) for details.

## Verify installation

You can verify the package is loaded by checking the facade resolves:

```php
use MrDellimore\SheetStream\Facades\SheetStream;

// In tinker or a test:
app('sheet-stream'); // Returns SheetStreamManager instance
```

## Optional: PhpSpreadsheet driver

If you need `.xls` (legacy binary) support, formula evaluation, or other features not available in the OpenSpout engine, install PhpSpreadsheet:

```bash
composer require phpoffice/phpspreadsheet
```

Then set the driver in your config:

```php
// config/sheet-stream.php
'default_reader' => 'phpspreadsheet',
'default_writer' => 'phpspreadsheet',
```

You can also mix drivers — for example, use `phpspreadsheet` only for reading (to support `.xls` imports) while keeping `openspout` for writing (better memory on large exports):

```php
'default_reader' => 'phpspreadsheet',
'default_writer' => 'openspout',
```

See [Configuration](configuration.md) for details on when to use each driver.

## Optional: rust driver

For large `.xlsx` and `.csv` imports and exports, the `rust` driver is many times faster than OpenSpout, with flat memory. It needs the [`rusty_sheet`](https://github.com/MrDellimore/rusty-php-sheet) PHP extension (PHP 8.4 or 8.5). On Linux (x86_64 and arm64, glibc and musl/Alpine), install it with [PIE](https://github.com/php/pie), which downloads a prebuilt binary and enables it. On macOS, [build it from source](https://github.com/MrDellimore/rusty-php-sheet#install).

```bash
pie install "mrdellimore/rusty-sheet:^0.1"
php -m | grep rusty_sheet   # check it loads
```

Install it for every PHP that runs your app: the CLI, PHP-FPM, and your queue workers. If you have more than one PHP, pick the right one with `pie install "mrdellimore/rusty-sheet:^0.1" --with-php-path="$(which php)"`. Then set the driver:

```php
// config/sheet-stream.php
'default_reader' => 'rust',
'default_writer' => 'rust',
```

If the extension isn't loaded, or its version isn't one this package supports (currently `^0.1`, i.e. 0.1.x), the `rust` driver throws rather than silently falling back to OpenSpout.

The extension is upgraded separately from Composer: `composer update` never touches it. Re-run the `pie install "mrdellimore/rusty-sheet:^0.1"` command above (for example in your Dockerfile or deploy script) to pick up the newest compatible release; the constraint keeps out versions that could break this package. To have Composer refuse to install on a server without a compatible extension, add it to your app's `composer.json`:

```json
"require": {
    "ext-rusty_sheet": "^0.1"
}
```

## Using without auto-discovery

If you have disabled auto-discovery, add the provider and alias manually in your `bootstrap/providers.php` or `config/app.php`:

```php
// bootstrap/providers.php (Laravel 11+)
return [
    // ...
    MrDellimore\SheetStream\SheetStreamServiceProvider::class,
];
```

```php
// config/app.php — aliases
'SheetStream' => MrDellimore\SheetStream\Facades\SheetStream::class,
```
