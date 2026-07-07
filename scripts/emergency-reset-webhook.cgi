#!/bin/bash

# ==============================================================================
# LUXQUOTE EMERGENCY RESET WEBHOOK
#
# Native CGI wrapper for emergency stack recovery.
# Keep the live secret out of git by setting LUXQUOTE_RESET_KEY in the CGI
# environment, or replace the placeholder only on the production server copy.
# ==============================================================================

APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"
SECRET_KEY_VAL="${LUXQUOTE_RESET_KEY:-CHANGE_ME_ON_THE_SERVER}"
LOCK_FILE="${LOCK_FILE:-/tmp/luxquote_reset.lock}"
COOLDOWN_SECONDS="${COOLDOWN_SECONDS:-300}"
RESTORE_SCRIPT="$APP_DIR/luxquote_restore_to_last_deploy.sh"
RECOVERY_SCRIPT="$APP_DIR/emergency_recover.sh"

url_decode() {
    local value="${1//+/ }"
    printf '%b' "${value//%/\\x}"
}

query_value() {
    local name="$1"
    local pair

    IFS='&' read -ra pairs <<< "$QUERY_STRING"

    for pair in "${pairs[@]}"; do
        if [[ "$pair" == "$name="* ]]; then
            url_decode "${pair#*=}"
            return 0
        fi
    done

    return 0
}

html_escape() {
    sed \
        -e 's/&/\&amp;/g' \
        -e 's/</\&lt;/g' \
        -e 's/>/\&gt;/g' \
        -e 's/"/\&quot;/g' \
        -e "s/'/\&#39;/g"
}

latest_backup_path() {
    cd "$APP_DIR" 2>/dev/null || return 1
    ls -1t backups/*.sql.gz 2>/dev/null | head -n 1
}

backup_summary() {
    local backup="$1"

    if [ -z "$backup" ]; then
        echo "No backup file found in $APP_DIR/backups."
        return 0
    fi

    local modified
    local size
    modified=$(stat -c '%y' "$APP_DIR/$backup" 2>/dev/null | cut -d '.' -f 1)
    size=$(du -h "$APP_DIR/$backup" 2>/dev/null | awk '{print $1}')

    echo "$(basename "$backup") - ${modified:-unknown time} - ${size:-unknown size}"
}

render_confirmation_page() {
    local req_key="$1"
    local latest_backup="$2"
    local summary
    local escaped_key
    local escaped_summary

    summary=$(backup_summary "$latest_backup")
    escaped_key=$(printf '%s' "$req_key" | html_escape)
    escaped_summary=$(printf '%s' "$summary" | html_escape)

    echo "Content-type: text/html; charset=utf-8"
    echo ""
    cat <<EOF
<!DOCTYPE html>
<html>
<head>
    <title>LuxQuote Recovery Gateway</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3); text-align: center; max-width: 440px; width: 90%; border: 1px solid #334155; }
        h2 { color: #f43f5e; margin-top: 0; font-size: 24px; letter-spacing: -0.5px; }
        p { color: #cbd5e1; font-size: 15px; line-height: 1.5; }
        .muted { color: #94a3b8; }
        .backup { margin: 16px 0; padding: 12px; border-radius: 8px; background: #0f172a; border: 1px solid #475569; overflow-wrap: anywhere; }
        label { display: block; margin: 10px 0; color: #e2e8f0; text-align: left; }
        input[type="text"] { width: 85%; padding: 12px; margin: 16px 0; border: 2px solid #475569; border-radius: 6px; font-size: 16px; text-align: center; background: #0f172a; color: white; outline: none; transition: border-color 0.2s; }
        input[type="text"]:focus { border-color: #f43f5e; }
        input[type="submit"] { background: #f43f5e; color: white; border: none; padding: 14px 28px; border-radius: 6px; font-size: 16px; cursor: pointer; font-weight: bold; width: 93%; transition: background 0.2s; margin-top: 14px; }
        input[type="submit"]:hover { background: #e11d48; }
        .choices { margin: 18px auto; width: 93%; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Emergency Reset Gate</h2>
        <p>You are about to recycle the infrastructure for <strong>quote.tamlite.co.uk</strong>.</p>

        <div class="backup">
            <strong>Latest database backup:</strong><br>
            <span class="muted">$escaped_summary</span>
        </div>

        <form method="GET" action="">
            <input type="hidden" name="key" value="$escaped_key">

            <p>Please type your name to confirm identity:</p>
            <input type="text" name="confirm_name" placeholder="Enter name" autofocus required autocomplete="off" autocapitalize="off">

            <div class="choices">
                <label><input type="radio" name="restore_db" value="no" checked> Restart containers only. Do not restore the database.</label>
                <label><input type="radio" name="restore_db" value="yes"> Restart containers and restore the latest backup if recovery is still unhealthy.</label>
            </div>

            <input type="submit" value="Run Recovery Script">
        </form>
    </div>
</body>
</html>
EOF
}

REQ_KEY=$(query_value "key")
CONFIRM_NAME=$(query_value "confirm_name" | tr '[:upper:]' '[:lower:]')
RESTORE_DB=$(query_value "restore_db" | tr '[:upper:]' '[:lower:]')
LATEST_BACKUP=$(latest_backup_path)

if [ "$REQ_KEY" != "$SECRET_KEY_VAL" ]; then
    echo "Content-type: text/plain; charset=utf-8"
    echo "Status: 403 Forbidden"
    echo ""
    echo "Access denied: invalid or missing security token."
    exit 1
fi

if [ "$CONFIRM_NAME" != "dean" ]; then
    render_confirmation_page "$REQ_KEY" "$LATEST_BACKUP"
    exit 0
fi

if [ "$RESTORE_DB" != "yes" ] && [ "$RESTORE_DB" != "no" ]; then
    echo "Content-type: text/plain; charset=utf-8"
    echo "Status: 400 Bad Request"
    echo ""
    echo "Invalid restore_db choice. Use yes or no."
    exit 1
fi

if [ "$RESTORE_DB" = "yes" ] && [ -z "$LATEST_BACKUP" ]; then
    echo "Content-type: text/plain; charset=utf-8"
    echo "Status: 409 Conflict"
    echo ""
    echo "Database restore was requested, but no backup was found in $APP_DIR/backups."
    exit 1
fi

if [ -f "$LOCK_FILE" ]; then
    LAST_RUN=$(stat -c %Y "$LOCK_FILE")
    CURRENT_TIME=$(date +%s)
    ELAPSED=$((CURRENT_TIME - LAST_RUN))

    if [ "$ELAPSED" -lt "$COOLDOWN_SECONDS" ]; then
        REMAINING=$((COOLDOWN_SECONDS - ELAPSED))
        echo "Content-type: text/plain; charset=utf-8"
        echo "Status: 429 Too Many Requests"
        echo ""
        echo "Rate limit active. Please wait another $REMAINING seconds before resetting again."
        exit 1
    fi
fi

touch "$LOCK_FILE"

echo "Content-type: text/plain; charset=utf-8"
echo ""
echo "Identity confirmed. Running emergency recovery chain..."
echo "Restore database if recovery remains unhealthy: $RESTORE_DB"
echo "Latest backup: $(backup_summary "$LATEST_BACKUP")"
echo "------------------------------------------------------------------"

if [ ! -f "$RECOVERY_SCRIPT" ]; then
    echo "Error: recovery script missing at $RECOVERY_SCRIPT"
    exit 1
fi

if [ "$RESTORE_DB" = "yes" ]; then
    LUXQUOTE_AUTO_DB_RESTORE=1 bash "$RECOVERY_SCRIPT" 2>&1
else
    LUXQUOTE_AUTO_DB_RESTORE=0 bash "$RECOVERY_SCRIPT" 2>&1
fi
