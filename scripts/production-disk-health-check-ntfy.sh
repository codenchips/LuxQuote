#!/usr/bin/env bash
set -uo pipefail

APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"
DISK_THRESHOLD_PERCENT="${DISK_THRESHOLD_PERCENT:-85}"
INODE_THRESHOLD_PERCENT="${INODE_THRESHOLD_PERCENT:-85}"
NTFY_URL="${NTFY_URL:-https://ntfy.sh/LuxQuoteDisk}"
NTFY_TITLE="${NTFY_TITLE:-LuxQuote disk health check failed}"
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

    message="LuxQuote disk health check failed at $(timestamp) on $(hostname).

${trimmed_output}"

    curl --silent --show-error --max-time 15 --retry 2 \
        -H "Title: ${NTFY_TITLE}" \
        -H "Priority: ${NTFY_PRIORITY}" \
        -H "Tags: ${NTFY_TAGS}" \
        --data-binary "$message" \
        "$NTFY_URL" >/dev/null || true
}

check_df() {
    local mode="$1"
    local threshold="$2"
    shift 2

    local output
    output="$(df "$mode" -P "$@" 2>&1)"
    local status=$?

    if [ "$status" -ne 0 ]; then
        printf '%s\n' "$output"
        return "$status"
    fi

    printf '%s\n' "$output"
    printf '%s\n' "$output" | awk -v threshold="$threshold" '
        NR > 1 {
            usage = $5
            gsub(/%/, "", usage)
            if (usage + 0 >= threshold + 0) {
                failed = 1
            }
        }
        END { exit failed ? 1 : 0 }
    '
}

main() {
    local paths=("$APP_DIR" "/")

    if [ -d /var/lib/docker ]; then
        paths+=("/var/lib/docker")
    fi

    local output
    output="$(
        {
            set -e
            printf 'Disk threshold: %s%%\n' "$DISK_THRESHOLD_PERCENT"
            check_df "-h" "$DISK_THRESHOLD_PERCENT" "${paths[@]}"
            printf '\nInode threshold: %s%%\n' "$INODE_THRESHOLD_PERCENT"
            check_df "-i" "$INODE_THRESHOLD_PERCENT" "${paths[@]}"
            printf '\nDocker disk usage:\n'
            docker system df 2>/dev/null || true
        } 2>&1
    )"
    local status=$?

    if [ "$status" -eq 0 ]; then
        printf '[%s] LuxQuote disk health check passed.\n' "$(timestamp)"

        return 0
    fi

    printf '%s\n' "$output"
    notify_failure "$output"

    return "$status"
}

main
