#!/bin/bash
# ==============================================================================
# LUXQUOTE.APP - DOCKER IPTABLES RECOVERY
# Safety Level: HIGH (preserves Docker volumes and database data)
# ==============================================================================
#
# Use this when Docker container start/restart fails with:
#   iptables: No chain/target/match by that name
#
# This usually means a host firewall reload removed Docker-managed iptables
# chains while Docker was running. Restarting Docker recreates those chains.

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"
LOCAL_URL="${LOCAL_URL:-http://127.0.0.1:8080}"
PUBLIC_URL="${PUBLIC_URL:-https://quote.tamlite.co.uk}"
RUNNER_NAME="${RUNNER_NAME:-luxquote-github-runner}"

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

http_status() {
    curl -sS -o /dev/null -w '%{http_code}' "$1" || true
}

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root on the VPS because restarting Docker requires root."
    exit 1
fi

cd "$APP_DIR" || {
    echo "Cannot access app directory: $APP_DIR"
    exit 1
}

log "Starting Docker iptables recovery for LuxQuote"
log "This script does not remove Docker volumes and does not restore the database."

if command -v iptables >/dev/null 2>&1; then
    if iptables -nL DOCKER >/dev/null 2>&1; then
        log "Docker iptables chain exists before restart."
    else
        log "Docker iptables chain is missing before restart; restarting Docker should recreate it."
    fi
fi

log "Restarting Docker daemon"
if command -v systemctl >/dev/null 2>&1; then
    systemctl restart docker
else
    service docker restart
fi

log "Waiting for Docker to settle"
sleep 15

if command -v iptables >/dev/null 2>&1; then
    if iptables -nL DOCKER >/dev/null 2>&1; then
        log "Docker iptables chain exists after restart."
    else
        log "Warning: Docker iptables chain is still missing after Docker restart."
    fi
fi

log "Starting LuxQuote Docker Compose services"
docker compose up -d
docker compose ps

log "Clearing Laravel optimization caches if the app container is ready"
docker compose exec -T laravel.test php artisan optimize:clear || true

local_status="$(http_status "$LOCAL_URL")"
public_status="$(http_status "$PUBLIC_URL")"

log "Local health $LOCAL_URL returned HTTP $local_status"
log "Public health $PUBLIC_URL returned HTTP $public_status"

if [ "$local_status" = "200" ] || [ "$local_status" = "302" ] || [ "$public_status" = "200" ] || [ "$public_status" = "302" ]; then
    log "Recovery looks successful."
else
    log "Recovery did not produce a healthy HTTP response. Recent app logs follow:"
    docker compose logs --tail=120 laravel.test || true
    exit 1
fi

if docker ps --format '{{.Names}}' | grep -qx "$RUNNER_NAME"; then
    log "GitHub runner container is running. Recent runner logs:"
    docker logs --tail=40 "$RUNNER_NAME" || true
else
    log "GitHub runner container is not currently running. Recreate it with a fresh GitHub runner token if deploys remain queued."
fi

