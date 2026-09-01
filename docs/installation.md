# Installation

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| Laravel | `^11.0 \| ^12.0 \| ^13.0` |
| OpenSpout | `^4.30` (installed automatically) |
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
