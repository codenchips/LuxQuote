#!/usr/bin/env bash
set -uo pipefail

APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"
NTFY_URL="${NTFY_URL:-https://ntfy.sh/LuxQuoteDatabase}"
NTFY_TITLE="${NTFY_TITLE:-LuxQuote database health check failed}"
NTFY_PRIORITY="${NTFY_PRIORITY:-high}"
NTFY_TAGS="${NTFY_TAGS:-warning}"

timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

notify_failure() {
    local output="$1"
    local message
    local trimmed_output

    trimmed_output="$(printf '%s' "$output" | tail -c 4000)"

    message="LuxQuote database health check failed at $(timestamp) on $(hostname).

${trimmed_output}"

    curl --silent --show-error --max-time 15 --retry 2 \
        -H "Title: ${NTFY_TITLE}" \
        -H "Priority: ${NTFY_PRIORITY}" \
        -H "Tags: ${NTFY_TAGS}" \
        --data-binary "$message" \
        "$NTFY_URL" >/dev/null || true
}

main() {
    local output
    local status

    if ! cd "$APP_DIR"; then
        output="Could not change to app directory: ${APP_DIR}"
        printf '%s\n' "$output"
        notify_failure "$output"

        return 2
    fi

    output="$(
        {
            set -e
            docker compose exec -T mysql sh -lc 'mysqladmin ping -h 127.0.0.1 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD"'
            docker compose exec -T -u sail laravel.test php artisan tinker --execute 'DB::select("select 1 as health_check"); echo "Laravel database query passed.\n";'
        } 2>&1
    )"
    status=$?

    if [ "$status" -eq 0 ]; then
        printf '[%s] LuxQuote database health check passed.\n' "$(timestamp)"

        return 0
    fi

    printf '%s\n' "$output"
    notify_failure "$output"

    return "$status"
}

main
