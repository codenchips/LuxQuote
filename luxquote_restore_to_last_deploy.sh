#!/usr/bin/env bash

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/backups}"
SELECTED_BACKUP="${1:-}"
WAS_IN_MAINTENANCE=0
MAINTENANCE_ENABLED_BY_SCRIPT=0

fail() {
    printf '❌ Error: %s\n' "$*" >&2
    exit 1
}

restore_application_state() {
    if [ "$MAINTENANCE_ENABLED_BY_SCRIPT" -eq 1 ]; then
        docker compose exec -T laravel.test php artisan up >/dev/null 2>&1 || true
    fi
}

latest_backup_name() {
    find "$BACKUP_DIR" \
        -maxdepth 1 \
        -type f \
        ! -type l \
        -name '*.sql.gz' \
        -printf '%T@ %f\n' 2>/dev/null \
        | sort -rn \
        | head -n 1 \
        | cut -d ' ' -f 2-
}

cd "$APP_DIR" || fail "Cannot access application directory: $APP_DIR"
[ -d "$BACKUP_DIR" ] || fail "Backup directory does not exist: $BACKUP_DIR"

if [ -z "$SELECTED_BACKUP" ]; then
    SELECTED_BACKUP="$(latest_backup_name)"
fi

if [[ ! "$SELECTED_BACKUP" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*\.sql\.gz$ ]]; then
    fail 'The selected backup filename is invalid.'
fi

BACKUP_PATH="$BACKUP_DIR/$SELECTED_BACKUP"

[ -f "$BACKUP_PATH" ] || fail "Selected backup does not exist: $SELECTED_BACKUP"
[ ! -L "$BACKUP_PATH" ] || fail 'Symbolic links are not accepted as database backups.'
gzip -t "$BACKUP_PATH" || fail 'Selected backup failed its gzip integrity check.'

DB_DATABASE="$(docker compose exec -T mysql sh -lc 'printf "%s" "$MYSQL_DATABASE"')"
[ -n "$DB_DATABASE" ] || fail 'The MySQL container did not provide MYSQL_DATABASE.'

if docker compose exec -T laravel.test test -f storage/framework/down; then
    WAS_IN_MAINTENANCE=1
else
    printf '🛡️  Enabling Laravel maintenance mode during the database import.\n'
    docker compose exec -T laravel.test php artisan down --retry=60
    MAINTENANCE_ENABLED_BY_SCRIPT=1
fi

trap restore_application_state EXIT

printf 'ℹ️  Restoring backup %s into database %s.\n' "$SELECTED_BACKUP" "$DB_DATABASE"

gzip -dc "$BACKUP_PATH" | docker compose exec -T mysql sh -lc '
    exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"
'

printf '✅ Database successfully restored from %s.\n' "$SELECTED_BACKUP"
printf '🔄 Applying any forward migrations required by the deployed code.\n'
docker compose exec -T laravel.test php artisan migrate --force --no-interaction
printf '⚡ Clearing Laravel optimization caches.\n'
docker compose exec -T laravel.test php artisan optimize:clear

if [ "$WAS_IN_MAINTENANCE" -eq 0 ]; then
    printf '🌐 Taking Laravel out of maintenance mode.\n'
    docker compose exec -T laravel.test php artisan up
    MAINTENANCE_ENABLED_BY_SCRIPT=0
fi

trap - EXIT
printf '✅ Database restore and application preparation completed successfully.\n'
