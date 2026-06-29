#!/usr/bin/env bash

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"

log() {
    printf '[%s] %s\n' "$(date +"%Y-%m-%d %H:%M:%S")" "$*"
}

cd "$APP_DIR"

log "Docker disk usage before cleanup"
docker system df

log "Pruning unused Docker build cache older than 24 hours"
docker builder prune --all --force --filter "until=24h"

log "Pruning unused Docker images older than 24 hours"
docker image prune --all --force --filter "until=24h"

log "Pruning stopped containers older than 24 hours"
docker container prune --force --filter "until=24h"

log "Docker disk usage after cleanup"
docker system df

log "Filesystem usage"
df -h /

log "Cleanup complete. Docker volumes were not pruned."
