#!/usr/bin/env bash

set -uo pipefail

# ==============================================================================
# LUXQUOTE EMERGENCY RESET WEBHOOK
#
# Native CGI wrapper for emergency stack recovery and controlled DB restores.
# Keep the live URL secret out of git by setting LUXQUOTE_RESET_KEY in the CGI
# environment, or replace the placeholder only on the production server copy.
# ==============================================================================

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/backups}"
SECRET_KEY_VAL="${LUXQUOTE_RESET_KEY:-CHANGE_ME_ON_THE_SERVER}"
LOCK_FILE="${LOCK_FILE:-/tmp/luxquote_reset.lock}"
COOLDOWN_SECONDS="${COOLDOWN_SECONDS:-300}"
RESTORE_SCRIPT="$APP_DIR/luxquote_restore_to_last_deploy.sh"
RECOVERY_SCRIPT="$APP_DIR/emergency_recover.sh"
TARGET_URL="${TARGET_URL:-https://quote.tamlite.co.uk}"
REQUEST_BODY=""

url_decode() {
    local value="${1//+/ }"
    printf '%b' "${value//%/\\x}"
}

parameter_value() {
    local data="$1"
    local name="$2"
    local pair

    IFS='&' read -ra pairs <<< "$data"

    for pair in "${pairs[@]}"; do
        if [[ "$pair" == "$name="* ]]; then
            url_decode "${pair#*=}"
            return 0
        fi
    done

    return 0
}

request_value() {
    local name="$1"
    local value

    value="$(parameter_value "$REQUEST_BODY" "$name")"

    if [ -n "$value" ]; then
        printf '%s' "$value"
        return 0
    fi

    parameter_value "${QUERY_STRING:-}" "$name"
}

html_escape() {
    sed \
        -e 's/&/\&amp;/g' \
        -e 's/</\&lt;/g' \
        -e 's/>/\&gt;/g' \
        -e 's/"/\&quot;/g' \
        -e "s/'/\&#39;/g"
}

respond_plain() {
    local status="$1"
    local message="$2"

    printf 'Status: %s\r\n' "$status"
    printf 'Content-Type: text/plain; charset=utf-8\r\n\r\n'
    printf '%s\n' "$message"
}

backup_options() {
    local backup_file
    local backup_name
    local escaped_name
    local escaped_summary
    local modified
    local size

    if [ ! -d "$BACKUP_DIR" ]; then
        return 0
    fi

    while IFS= read -r backup_file; do
        [ -n "$backup_file" ] || continue
        [ ! -L "$backup_file" ] || continue

        backup_name="$(basename "$backup_file")"

        if [[ ! "$backup_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*\.sql\.gz$ ]]; then
            continue
        fi

        modified="$(stat -c '%y' "$backup_file" 2>/dev/null | cut -d '.' -f 1)"
        modified="${modified:-unknown date}"
        size="$(du -h "$backup_file" 2>/dev/null | awk '{print $1}')"
        escaped_name="$(printf '%s' "$backup_name" | html_escape)"
        escaped_summary="$(printf '%s — %s — %s' "$backup_name" "$modified" "${size:-unknown size}" | html_escape)"

        printf '<option value="%s">%s</option>\n' "$escaped_name" "$escaped_summary"
    done < <(
        find "$BACKUP_DIR" \
            -maxdepth 1 \
            -type f \
            ! -type l \
            -name '*.sql.gz' \
            -printf '%T@ %p\n' 2>/dev/null \
            | sort -rn \
            | cut -d ' ' -f 2-
    )
}

render_confirmation_page() {
    local auth_error="${1:-}"
    local escaped_auth_error
    local escaped_key
    local options

    escaped_key="$(printf '%s' "$REQ_KEY" | html_escape)"
    escaped_auth_error="$(printf '%s' "$auth_error" | html_escape)"
    options="$(backup_options)"

    printf 'Content-Type: text/html; charset=utf-8\r\n\r\n'
    cat <<EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>LuxQuote Recovery Gateway</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 24px; box-sizing: border-box; }
        .card { background: #1e293b; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3); max-width: 620px; width: 100%; border: 1px solid #334155; }
        h2 { color: #f43f5e; margin-top: 0; font-size: 24px; letter-spacing: -0.5px; text-align: center; }
        p { color: #cbd5e1; font-size: 15px; line-height: 1.5; }
        .field { margin: 18px 0; }
        .field-title { display: block; color: #f8fafc; font-weight: 700; margin-bottom: 8px; }
        .choice { display: block; margin: 10px 0; padding: 12px; color: #e2e8f0; text-align: left; background: #0f172a; border: 1px solid #475569; border-radius: 8px; cursor: pointer; }
        .choice:hover { border-color: #94a3b8; }
        input[type="text"], select { width: 100%; padding: 12px; border: 2px solid #475569; border-radius: 6px; font-size: 15px; background: #0f172a; color: white; outline: none; box-sizing: border-box; }
        input[type="text"]:focus, select:focus { border-color: #f43f5e; }
        select:disabled { opacity: 0.45; cursor: not-allowed; }
        input[type="submit"] { background: #f43f5e; color: white; border: none; padding: 14px 28px; border-radius: 6px; font-size: 16px; cursor: pointer; font-weight: bold; width: 100%; transition: background 0.2s; margin-top: 14px; }
        input[type="submit"]:hover { background: #e11d48; }
        .error { color: #fecaca; background: #7f1d1d; border: 1px solid #ef4444; padding: 10px 12px; border-radius: 6px; }
        .warning { color: #fde68a; background: #422006; border: 1px solid #a16207; padding: 12px; border-radius: 6px; }
        .muted { color: #94a3b8; font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <h2>LuxQuote Recovery Gateway</h2>
        <p class="warning">Database restore operations overwrite the current LuxQuote database with the selected archive. Check the filename and timestamp carefully.</p>

        ${escaped_auth_error:+<p class="error">$escaped_auth_error</p>}

        <form method="POST" action="">
            <input type="hidden" name="key" value="$escaped_key">

            <div class="field">
                <label class="field-title" for="auth_key">Auth key</label>
                <input id="auth_key" type="text" name="auth_key" placeholder="Enter auth key" autofocus required autocomplete="off" autocapitalize="off">
            </div>

            <fieldset class="field" style="border: 0; padding: 0;">
                <legend class="field-title">Operation</legend>
                <label class="choice"><input type="radio" name="operation" value="reset_only" checked> Reset the app without touching the database</label>
                <label class="choice"><input type="radio" name="operation" value="restore_only"> Restore the selected database backup without resetting the app</label>
                <label class="choice"><input type="radio" name="operation" value="reset_restore"> Reset the app, then restore the selected database backup</label>
            </fieldset>

            <div class="field">
                <label class="field-title" for="backup">Database backup</label>
                <select id="backup" name="backup" disabled>
                    <option value="">Choose a backup…</option>
                    $options
                </select>
                <p class="muted">Backups are ordered newest first. A selection is required for either restore operation.</p>
            </div>

            <input type="submit" value="Run selected operation">
        </form>
    </div>

    <script>
        const backup = document.getElementById('backup')
        const operations = document.querySelectorAll('input[name="operation"]')

        function updateBackupState() {
            const selected = document.querySelector('input[name="operation"]:checked').value
            const requiresBackup = selected === 'restore_only' || selected === 'reset_restore'
            backup.disabled = ! requiresBackup
            backup.required = requiresBackup
        }

        operations.forEach((operation) => operation.addEventListener('change', updateBackupState))
        updateBackupState()
    </script>
</body>
</html>
EOF
}

resolve_backup() {
    local backup_name="$1"
    local backup_path

    if [[ ! "$backup_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*\.sql\.gz$ ]]; then
        return 1
    fi

    backup_path="$BACKUP_DIR/$backup_name"

    [ -f "$backup_path" ] || return 1
    [ ! -L "$backup_path" ] || return 1

    printf '%s' "$backup_path"
}

operation_label() {
    case "$1" in
        reset_only) printf 'Reset app only' ;;
        restore_only) printf 'Restore database only' ;;
        reset_restore) printf 'Reset app and restore database' ;;
        *) printf 'Unknown operation' ;;
    esac
}

run_reset() {
    printf '=== Resetting application containers without database restore ===\n'
    LUXQUOTE_AUTO_DB_RESTORE=0 bash "$RECOVERY_SCRIPT"
}

run_restore() {
    local backup_name="$1"

    printf '=== Restoring selected database backup: %s ===\n' "$backup_name"
    bash "$RESTORE_SCRIPT" "$backup_name"
}

check_public_health() {
    local status

    printf '=== Checking public application health ===\n'
    status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$TARGET_URL" || true)"
    printf 'Public health check returned HTTP %s.\n' "${status:-unavailable}"

    [ "$status" = '200' ] || [ "$status" = '302' ]
}

run_selected_operation() {
    local operation="$1"
    local backup_name="$2"
    local reset_status
    local restore_status

    case "$operation" in
        reset_only)
            run_reset
            ;;
        restore_only)
            run_restore "$backup_name" || return $?
            check_public_health
            ;;
        reset_restore)
            run_reset
            reset_status=$?

            if [ "$reset_status" -ne 0 ]; then
                printf 'Reset health check failed with status %s; continuing with the explicitly requested database restore.\n' "$reset_status"
            fi

            run_restore "$backup_name"
            restore_status=$?

            if [ "$restore_status" -ne 0 ]; then
                return "$restore_status"
            fi

            check_public_health
            ;;
    esac
}

render_result_page() {
    local status="$1"
    local operation="$2"
    local backup_name="$3"
    local output_file="$4"
    local escaped_backup
    local escaped_operation
    local escaped_output
    local heading
    local heading_class
    local http_status

    escaped_operation="$(operation_label "$operation" | html_escape)"
    escaped_backup="$(printf '%s' "${backup_name:-Not used}" | html_escape)"
    escaped_output="$(html_escape < "$output_file")"

    if [ "$status" -eq 0 ]; then
        heading='Operation completed successfully'
        heading_class='success'
        http_status='200 OK'
    else
        heading="Operation failed with exit status $status"
        heading_class='failure'
        http_status='500 Internal Server Error'
    fi

    printf 'Status: %s\r\n' "$http_status"
    printf 'Content-Type: text/html; charset=utf-8\r\n\r\n'
    cat <<EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>LuxQuote Recovery Result</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; min-height: 100vh; margin: 0; padding: 24px; box-sizing: border-box; }
        .card { background: #1e293b; padding: 30px; border-radius: 12px; max-width: 980px; margin: 0 auto; border: 1px solid #334155; }
        h2 { margin-top: 0; }
        .success { color: #4ade80; }
        .failure { color: #f87171; }
        .summary { color: #cbd5e1; line-height: 1.6; }
        pre { background: #020617; color: #e2e8f0; border: 1px solid #334155; border-radius: 8px; padding: 18px; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; }
        button { background: #334155; color: white; border: 1px solid #64748b; padding: 11px 18px; border-radius: 6px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h2 class="$heading_class">$heading</h2>
        <p class="summary"><strong>Operation:</strong> $escaped_operation<br><strong>Backup:</strong> $escaped_backup</p>
        <h3>Operation output</h3>
        <pre>$escaped_output</pre>
        <button type="button" onclick="history.back()">Back to recovery options</button>
    </div>
</body>
</html>
EOF
}

if [ "${REQUEST_METHOD:-GET}" = 'POST' ]; then
    if [[ ! "${CONTENT_LENGTH:-}" =~ ^[0-9]+$ ]] || [ "$CONTENT_LENGTH" -gt 65536 ]; then
        respond_plain '400 Bad Request' 'Invalid request body length.'
        exit 1
    fi

    IFS= read -r -N "$CONTENT_LENGTH" REQUEST_BODY || true
fi

REQ_KEY="$(request_value 'key')"
AUTH_KEY="$(request_value 'auth_key' | tr '[:upper:]' '[:lower:]')"
OPERATION="$(request_value 'operation' | tr '[:upper:]' '[:lower:]')"
SELECTED_BACKUP="$(request_value 'backup')"

if [ "$REQ_KEY" != "$SECRET_KEY_VAL" ]; then
    respond_plain '403 Forbidden' 'Access denied: invalid or missing security token.'
    exit 1
fi

if [ "$AUTH_KEY" != 'dean' ]; then
    if [ -n "$AUTH_KEY" ]; then
        render_confirmation_page 'The Auth key was not accepted.'
    else
        render_confirmation_page
    fi

    exit 0
fi

case "$OPERATION" in
    reset_only|restore_only|reset_restore) ;;
    *)
        respond_plain '400 Bad Request' 'Invalid operation selected.'
        exit 1
        ;;
esac

if [ "$OPERATION" = 'restore_only' ] || [ "$OPERATION" = 'reset_restore' ]; then
    RESOLVED_BACKUP="$(resolve_backup "$SELECTED_BACKUP")" || {
        respond_plain '400 Bad Request' 'The selected backup is invalid or is no longer available.'
        exit 1
    }

    if ! gzip -t "$RESOLVED_BACKUP"; then
        respond_plain '409 Conflict' 'The selected backup failed its gzip integrity check. No operation was run.'
        exit 1
    fi
else
    SELECTED_BACKUP=''
fi

if [ ! -f "$RECOVERY_SCRIPT" ] && { [ "$OPERATION" = 'reset_only' ] || [ "$OPERATION" = 'reset_restore' ]; }; then
    respond_plain '500 Internal Server Error' "Recovery script is missing: $RECOVERY_SCRIPT"
    exit 1
fi

if [ ! -f "$RESTORE_SCRIPT" ] && { [ "$OPERATION" = 'restore_only' ] || [ "$OPERATION" = 'reset_restore' ]; }; then
    respond_plain '500 Internal Server Error' "Restore script is missing: $RESTORE_SCRIPT"
    exit 1
fi

exec 9>"${LOCK_FILE}.running"

if ! flock -n 9; then
    respond_plain '409 Conflict' 'Another recovery operation is already running.'
    exit 1
fi

if [ -f "$LOCK_FILE" ]; then
    LAST_RUN="$(stat -c %Y "$LOCK_FILE")"
    CURRENT_TIME="$(date +%s)"
    ELAPSED=$((CURRENT_TIME - LAST_RUN))

    if [ "$ELAPSED" -lt "$COOLDOWN_SECONDS" ]; then
        REMAINING=$((COOLDOWN_SECONDS - ELAPSED))
        respond_plain '429 Too Many Requests' "Rate limit active. Please wait another $REMAINING seconds before running another operation."
        exit 1
    fi
fi

touch "$LOCK_FILE"
RESULT_FILE="$(mktemp /tmp/luxquote-recovery-result-XXXXXX)"
trap 'rm -f -- "$RESULT_FILE"' EXIT

run_selected_operation "$OPERATION" "$SELECTED_BACKUP" > "$RESULT_FILE" 2>&1
OPERATION_STATUS=$?

render_result_page "$OPERATION_STATUS" "$OPERATION" "$SELECTED_BACKUP" "$RESULT_FILE"
exit "$OPERATION_STATUS"
