#!/bin/sh
# usage: storage/dusk-run.sh <log> [dusk args]
cd /c/laragon/www/new_accounting
log="$1"; shift
APP_URL=http://127.0.0.1:8099 DB_CONNECTION=sqlite DB_DATABASE="$(pwd -W)/database/dusk.sqlite" DB_URL= php artisan dusk "$@" > "$log" 2>&1
echo exit=$? >> "$log"
