#!/usr/bin/env bash

set -Eeuo pipefail

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/backups}"
THREE_HOURLY_RETENTION_MINUTES="${THREE_HOURLY_RETENTION_MINUTES:-2880}"
DAILY_RETENTION_MINUTES="${DAILY_RETENTION_MINUTES:-20160}"

timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

log() {
    printf '[%s] %s\n' "$(timestamp)" "$*"
}

fail() {
    log "ERROR: $*" >&2
    exit 1
}

main() {
    local backup_timestamp
    local backup_date
    local daily_backup
    local lock_file
    local temporary_backup
    local three_hourly_backup

    command -v docker >/dev/null 2>&1 || fail 'Docker is not installed or is not available in PATH.'
    command -v flock >/dev/null 2>&1 || fail 'flock is required to prevent overlapping backup runs.'
    command -v gzip >/dev/null 2>&1 || fail 'gzip is not installed or is not available in PATH.'

    [ -d "$APP_DIR" ] || fail "Application directory does not exist: $APP_DIR"

    cd "$APP_DIR"
    mkdir -p "$BACKUP_DIR"
    umask 077

    lock_file="$BACKUP_DIR/.luxquote-db-backup.lock"
    exec 9>"$lock_file"

    if ! flock -n 9; then
        log 'Another LuxQuote database backup is already running; skipping this cron invocation.'
        return 0
    fi

    backup_timestamp="$(date +%Y%m%d-%H%M%S)"
    backup_date="$(date +%Y%m%d)"
    three_hourly_backup="$BACKUP_DIR/luxquote-db-3hourly-$backup_timestamp.sql.gz"
    daily_backup="$BACKUP_DIR/luxquote-db-daily-$backup_date.sql.gz"
    temporary_backup="$(mktemp "$BACKUP_DIR/.luxquote-db-backup-XXXXXX.sql.gz")"

    trap "rm -f -- $(printf '%q' "$temporary_backup")" EXIT

    log 'Starting compressed LuxQuote MySQL backup.'

    docker compose exec -T mysql sh -lc '
        exec mysqldump \
            -u"$MYSQL_USER" \
            -p"$MYSQL_PASSWORD" \
            --single-transaction \
            --routines \
            --triggers \
            --no-tablespaces \
            "$MYSQL_DATABASE"
    ' | gzip -9 > "$temporary_backup"

    [ -s "$temporary_backup" ] || fail 'The compressed backup file is empty.'
    gzip -t "$temporary_backup" || fail 'The compressed backup failed its gzip integrity check.'

    mv -- "$temporary_backup" "$three_hourly_backup"
    trap - EXIT

    if [ ! -e "$daily_backup" ]; then
        ln -- "$three_hourly_backup" "$daily_backup"
        log "Created daily retention backup: $daily_backup"
    fi

    find "$BACKUP_DIR" \
        -maxdepth 1 \
        -type f \
        -name 'luxquote-db-3hourly-*.sql.gz' \
        -mmin "+$THREE_HOURLY_RETENTION_MINUTES" \
        -delete

    find "$BACKUP_DIR" \
        -maxdepth 1 \
        -type f \
        -name 'luxquote-db-daily-*.sql.gz' \
        -mmin "+$DAILY_RETENTION_MINUTES" \
        -delete

    find "$BACKUP_DIR" \
        -maxdepth 1 \
        -type f \
        -name 'luxquote-db-*.sql.gz' \
        ! -name 'luxquote-db-3hourly-*.sql.gz' \
        ! -name 'luxquote-db-daily-*.sql.gz' \
        -mmin "+$THREE_HOURLY_RETENTION_MINUTES" \
        -delete

    log "Backup completed: $three_hourly_backup ($(stat -c '%s' "$three_hourly_backup") bytes compressed)."
    log 'Retention complete: three-hourly backups kept for 48 hours; one daily backup kept for 14 days.'
}

main "$@"
