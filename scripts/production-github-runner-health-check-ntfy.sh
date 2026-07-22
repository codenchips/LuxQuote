#!/usr/bin/env bash

set -uo pipefail

RUNNER_NAME="${RUNNER_NAME:-luxquote-github-runner}"
NTFY_URL="${NTFY_URL:-https://ntfy.sh/LuxQuoteRunner}"
NTFY_TITLE="${NTFY_TITLE:-LuxQuote GitHub runner health check failed}"
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
    message="LuxQuote GitHub runner health check failed at $(timestamp) on $(hostname).

${trimmed_output}"

    curl --silent --show-error --max-time 15 --retry 2 \
        -H "Title: ${NTFY_TITLE}" \
        -H "Priority: ${NTFY_PRIORITY}" \
        -H "Tags: ${NTFY_TAGS}" \
        --data-binary "$message" \
        "$NTFY_URL" >/dev/null || true
}

run_checks() {
    (
        set -e

        docker inspect "$RUNNER_NAME" >/dev/null

        status="$(docker inspect --format '{{.State.Status}}' "$RUNNER_NAME")"
        restarting="$(docker inspect --format '{{.State.Restarting}}' "$RUNNER_NAME")"

        [ "$status" = "running" ] || {
            printf 'Runner container status is %s.\n' "$status"
            exit 1
        }

        [ "$restarting" = "false" ] || {
            printf 'Runner container is restart-looping.\n'
            exit 1
        }

        docker exec "$RUNNER_NAME" test -s /runner/config/.runner
        docker exec "$RUNNER_NAME" test -s /root/.ssh/luxquote_github_repo_deploy
        docker exec "$RUNNER_NAME" test -s /root/.ssh/known_hosts

        if ! docker top "$RUNNER_NAME" -eo args | grep --fixed-strings --quiet 'Runner.Listener'; then
            printf 'Runner.Listener process is not running.\n'
            exit 1
        fi
    ) 2>&1
}

main() {
    local output
    local status

    output="$(run_checks)"
    status=$?

    if [ "$status" -eq 0 ]; then
        printf '[%s] LuxQuote GitHub runner health check passed.\n' "$(timestamp)"
        return 0
    fi

    output="${output}

Recent runner logs:
$(docker logs --tail=80 "$RUNNER_NAME" 2>&1 || true)"

    printf '%s\n' "$output"
    notify_failure "$output"

    return "$status"
}

main
