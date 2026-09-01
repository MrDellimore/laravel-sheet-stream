#!/usr/bin/env bash
set -euo pipefail

echo "=== Code style (Pint) ==="
vendor/bin/pint --test

echo ""
echo "=== Static analysis (PHPStan) ==="
vendor/bin/phpstan analyse --no-progress

echo ""
echo "=== Tests (Pest) ==="
vendor/bin/pest --no-coverage --exclude-group=benchmark

echo ""
echo "=== All checks passed ==="
