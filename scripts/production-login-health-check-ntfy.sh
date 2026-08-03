#!/usr/bin/env bash
set -uo pipefail

LOGIN_URL="${LOGIN_URL:-https://quote.tamlite.co.uk/login}"
EXPECTED_TEXT="${EXPECTED_TEXT:-LuxQuote}"
LOGIN_HEALTH_RETRIES="${LOGIN_HEALTH_RETRIES:-3}"
LOGIN_HEALTH_RETRY_DELAY_SECONDS="${LOGIN_HEALTH_RETRY_DELAY_SECONDS:-20}"
NTFY_URL="${NTFY_URL:-https://ntfy.sh/LuxQuoteLogin}"
NTFY_TITLE="${NTFY_TITLE:-LuxQuote login health check failed}"
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

    message="LuxQuote login health check failed at $(timestamp) on $(hostname).

URL: ${LOGIN_URL}
Expected text: ${EXPECTED_TEXT}

${trimmed_output}"

    curl --silent --show-error --max-time 15 --retry 2 \
        -H "Title: ${NTFY_TITLE}" \
        -H "Priority: ${NTFY_PRIORITY}" \
        -H "Tags: ${NTFY_TAGS}" \
        --data-binary "$message" \
        "$NTFY_URL" >/dev/null || true
}

run_check() {
    local response
    local status

    response="$(curl --fail --silent --show-error --location --connect-timeout 10 --max-time 25 "$LOGIN_URL" 2>&1)"
    status=$?

    if [ "$status" -ne 0 ]; then
        printf 'curl failed with exit code %s.\n\n%s\n' "$status" "$response"

        return "$status"
    fi

    if ! grep --fixed-strings --quiet "$EXPECTED_TEXT" <<< "$response"; then
        printf 'The login page responded, but the expected text was not found.\n'
        printf 'Expected text: %s\n' "$EXPECTED_TEXT"
        printf 'Response length: %s bytes\n\n' "$(printf '%s' "$response" | wc -c)"
        printf '%s\n' "$response" | head -c 1200
        printf '\n'

        return 1
    fi

    return 0
}

main() {
    local attempt
    local output
    local status

    attempt=1
    status=1

    while [ "$attempt" -le "$LOGIN_HEALTH_RETRIES" ]; do
        output="$(run_check)"
        status=$?

        if [ "$status" -eq 0 ]; then
            printf '[%s] LuxQuote login health check passed on attempt %s/%s.\n' "$(timestamp)" "$attempt" "$LOGIN_HEALTH_RETRIES"

            return 0
        fi

        if [ "$attempt" -lt "$LOGIN_HEALTH_RETRIES" ]; then
            sleep "$LOGIN_HEALTH_RETRY_DELAY_SECONDS"
        fi

        attempt=$((attempt + 1))
    done

    printf '%s\n' "$output"
    notify_failure "$output"

    return "$status"
}

main
