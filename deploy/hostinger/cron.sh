#!/bin/sh
# The one cron entry, every minute: the scheduler runs everything else.
# ~/shop/shared/php-bin holds the PHP 8.3 path the website uses, if `php` differs.
SHOP="${SHOP:-$HOME/shop}"
PHP_BIN="$(cat "$SHOP/shared/php-bin" 2>/dev/null || echo php)"
cd "$SHOP/current" && exec "$PHP_BIN" artisan schedule:run >> "$SHOP/shared/storage/logs/cron.log" 2>&1
