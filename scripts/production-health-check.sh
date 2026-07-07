#!/usr/bin/env bash
set -uo pipefail

APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"
PUBLIC_URL="${PUBLIC_URL:-https://quote.tamlite.co.uk}"
LOCAL_URL="${LOCAL_URL:-http://127.0.0.1:8080}"
PING_URL="${HEALTHCHECK_PING_URL:-}"

cd "$APP_DIR" || exit 2

timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

log() {
    printf '[%s] %s\n' "$(timestamp)" "$*"
}

ping_url() {
    local suffix="${1:-}"
    local body="${2:-}"

    if [ -z "$PING_URL" ]; then
        return 0
    fi

    local url="${PING_URL%/}"

    if [ -n "$suffix" ]; then
        url="${url}/${suffix#/}"
    fi

    curl --silent --show-error --max-time 10 --retry 2 \
        --data-binary "$body" \
        "$url" >/dev/null || true
}

run_check() {
    log "$*"
    "$@"
}

main() {
    local output
    local status

    ping_url start "LuxQuote production health check started."

    output="$(
        {
            set -e
            run_check docker compose ps
            run_check docker compose exec -T mysql sh -lc 'mysqladmin ping -h 127.0.0.1 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD"'
            run_check curl --fail --silent --show-error --max-time 15 --output /dev/null "$LOCAL_URL"
            run_check curl --fail --silent --show-error --max-time 20 --head --output /dev/null "$PUBLIC_URL"
            run_check docker compose exec -T -u sail laravel.test php artisan app:production-health-check
        } 2>&1
    )"
    status=$?

    printf '%s\n' "$output"

    if [ "$status" -eq 0 ]; then
        ping_url "" "$output"
        log "LuxQuote production health check passed."

        return 0
    fi

    ping_url fail "$output"
    log "LuxQuote production health check failed with exit code $status."

    return "$status"
}

main
