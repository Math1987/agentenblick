#!/usr/bin/env bash
# Run inside the disposable Shopware bench from the plugin checkout:
#   bash scripts/check.sh
# First-time tool setup (the bench's global Composer is too old):
#   php /var/www/html/vendor/bin/composer install --working-dir=dev/tools --no-interaction --no-scripts --no-plugins
# Tools may come from Shopware's vendor/bin or an isolated dev-tool directory.
# No package installation, catalogue refresh or network request happens here.
set -euo pipefail

cd "$(dirname "$0")/.."
PHP_BINARY="${PHP_BINARY:-php}"
SHOPWARE_ROOT="${SHOPWARE_ROOT:-/var/www/html}"
export SHOPWARE_AUTOLOAD="${SHOPWARE_AUTOLOAD:-$SHOPWARE_ROOT/vendor/autoload.php}"
PHPUNIT_BIN="${PHPUNIT_BIN:-$SHOPWARE_ROOT/vendor/bin/phpunit}"
PHPSTAN_BIN="${PHPSTAN_BIN:-.tools/vendor/bin/phpstan}"
PHP_CS_FIXER_BIN="${PHP_CS_FIXER_BIN:-.tools/vendor/bin/php-cs-fixer}"

missing=0
for dependency in "$SHOPWARE_AUTOLOAD" "$PHPUNIT_BIN" "$PHPSTAN_BIN" "$PHP_CS_FIXER_BIN"; do
    if [ ! -f "$dependency" ]; then
        printf 'Missing check dependency: %s\n' "$dependency" >&2
        missing=1
    fi
done
if [ "$missing" -ne 0 ]; then
    printf 'Provide the existing Shopware autoloader and isolated development binaries using the variables documented in this script.\n' >&2
    exit 2
fi

"$PHP_BINARY" --version
find src tests dev/probe -name '*.php' -type f -print0 | xargs -0 -n 1 "$PHP_BINARY" -l
"$PHP_BINARY" "$PHPUNIT_BIN" --configuration phpunit.xml
"$PHP_BINARY" "$PHPSTAN_BIN" analyse --configuration phpstan.neon --no-progress --memory-limit=1G
"$PHP_BINARY" "$PHP_CS_FIXER_BIN" fix --config=.php-cs-fixer.dist.php --dry-run --diff

# The lot 1a kit uses native Node APIs only; no frontend bundle exists yet.
for script in scripts/*.mjs; do
    node --check "$script"
done
python3 -c 'from pathlib import Path; [compile(path.read_bytes(), str(path), "exec") for path in Path("scripts").glob("*.py")]'
