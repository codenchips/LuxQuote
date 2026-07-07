#!/usr/bin/env bash
set -uo pipefail

LOGIN_URL="${LOGIN_URL:-https://quote.tamlite.co.uk/login}"
EXPECTED_TEXT="${EXPECTED_TEXT:-LuxQuote}"
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

main() {
    local response
    local status

    response="$(curl --fail --silent --show-error --location --max-time 20 "$LOGIN_URL" 2>&1)"
    status=$?

    if [ "$status" -ne 0 ]; then
        printf '%s\n' "$response"
        notify_failure "curl failed with exit code ${status}.

${response}"

        return "$status"
    fi

    if ! printf '%s' "$response" | grep --fixed-strings --quiet "$EXPECTED_TEXT"; then
        printf 'Expected text was not found in the login page response.\n'
        notify_failure "The login page responded, but the expected text was not found."

        return 1
    fi

    printf '[%s] LuxQuote login health check passed.\n' "$(timestamp)"

    return 0
}

main
