#!/usr/bin/env bash

set -Eeuo pipefail

RUNNER_NAME="${RUNNER_NAME:-luxquote-github-runner}"
RUNNER_LABEL="${RUNNER_LABEL:-luxquote-production}"
RUNNER_ROOT="${RUNNER_ROOT:-/opt/actions-runner/luxquote-production}"
RUNNER_IMAGE="${RUNNER_IMAGE:-myoung34/github-runner:latest}"
REPO_URL="${REPO_URL:-https://github.com/codenchips/LuxQuote}"
APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"
WORK_DIR="${WORK_DIR:-$RUNNER_ROOT/work}"
CONFIG_DIR="${CONFIG_DIR:-$RUNNER_ROOT/config}"
SSH_DIR="${SSH_DIR:-$RUNNER_ROOT/ssh}"
HOST_DEPLOY_KEY="${HOST_DEPLOY_KEY:-/root/.ssh/luxquote_github_repo_deploy}"
HOST_KNOWN_HOSTS="${HOST_KNOWN_HOSTS:-/root/.ssh/known_hosts}"

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        fail "Run this script as root on the production VPS."
    fi
}

require_prerequisites() {
    command -v docker >/dev/null 2>&1 || fail "Docker is not installed."
    [ -d "$APP_DIR" ] || fail "Application directory does not exist: $APP_DIR"
    [ -S /var/run/docker.sock ] || fail "Docker socket is not available."
    [ -x /usr/bin/docker ] || fail "Expected Docker client does not exist at /usr/bin/docker."
    [ -d /usr/libexec/docker/cli-plugins ] || fail "Docker CLI plugins directory is missing: /usr/libexec/docker/cli-plugins"
}

prepare_directories() {
    install -d -m 700 "$RUNNER_ROOT" "$CONFIG_DIR" "$SSH_DIR"
    install -d -m 755 "$WORK_DIR"
}

preserve_working_ssh_files() {
    if docker inspect "$RUNNER_NAME" >/dev/null 2>&1; then
        log "Preserving SSH configuration from the existing runner container"
        docker cp "$RUNNER_NAME:/root/.ssh/." "$SSH_DIR/" >/dev/null 2>&1 || true
    fi

    if [ ! -f "$SSH_DIR/luxquote_github_repo_deploy" ] && [ -f "$HOST_DEPLOY_KEY" ]; then
        log "Installing the host deploy key into persistent runner storage"
        install -m 600 "$HOST_DEPLOY_KEY" "$SSH_DIR/luxquote_github_repo_deploy"
    fi

    if [ ! -f "$SSH_DIR/known_hosts" ] && [ -f "$HOST_KNOWN_HOSTS" ]; then
        log "Installing host SSH fingerprints into persistent runner storage"
        install -m 600 "$HOST_KNOWN_HOSTS" "$SSH_DIR/known_hosts"
    fi

    [ -s "$SSH_DIR/luxquote_github_repo_deploy" ] || fail "No persistent GitHub deploy key is available at $SSH_DIR/luxquote_github_repo_deploy"
    [ -s "$SSH_DIR/known_hosts" ] || fail "No persistent SSH known_hosts file is available at $SSH_DIR/known_hosts"

    chmod 700 "$SSH_DIR"
    chmod 600 "$SSH_DIR/luxquote_github_repo_deploy" "$SSH_DIR/known_hosts"
}

capture_registration_token() {
    local raw_token="${RUNNER_TOKEN:-}"

    if [ -s "$CONFIG_DIR/.runner" ]; then
        RUNNER_TOKEN=""
        return
    fi

    if [ -z "$raw_token" ] && [ -t 0 ]; then
        read -rsp "Paste a fresh GitHub runner registration token: " raw_token
        printf '\n'
    fi

    RUNNER_TOKEN="$(printf '%s' "$raw_token" | tr -d '[:space:]')"
    raw_token=""

    if [ "${#RUNNER_TOKEN}" -lt 20 ]; then
        fail "A fresh repository runner registration token is required for the initial persistent registration."
    fi
}

remove_existing_runner_container() {
    if docker inspect "$RUNNER_NAME" >/dev/null 2>&1; then
        log "Removing only the existing GitHub runner container"
        docker rm -f "$RUNNER_NAME" >/dev/null
    fi
}

start_runner() {
    local -a command

    command=(
        docker run -d
        --name "$RUNNER_NAME"
        --restart unless-stopped
        --security-opt label=disable
        -e "RUNNER_NAME=$RUNNER_LABEL"
        -e "RUNNER_LABELS=$RUNNER_LABEL"
        -e "LABELS=$RUNNER_LABEL"
        -e "RUNNER_WORKDIR=/home/runner/_work"
        -e "REPO_URL=$REPO_URL"
        -e "CONFIGURED_ACTIONS_RUNNER_FILES_DIR=/runner/config"
        -e "DISABLE_AUTO_UPDATE=true"
        -v "$WORK_DIR:/home/runner/_work"
        -v "$CONFIG_DIR:/runner/config"
        -v "$SSH_DIR:/root/.ssh:ro"
        -v "$APP_DIR:$APP_DIR"
        -v /var/run/docker.sock:/var/run/docker.sock
        -v /usr/bin/docker:/usr/bin/docker:ro
        -v /usr/libexec/docker/cli-plugins:/usr/libexec/docker/cli-plugins:ro
    )

    if [ -n "$RUNNER_TOKEN" ]; then
        command+=(-e "RUNNER_TOKEN=$RUNNER_TOKEN")
    fi

    command+=("$RUNNER_IMAGE")

    log "Starting persistent GitHub Actions runner"
    "${command[@]}" >/dev/null
    RUNNER_TOKEN=""
}

verify_runner() {
    local status

    sleep 5
    status="$(docker inspect --format '{{.State.Status}}' "$RUNNER_NAME")"

    if [ "$status" != "running" ]; then
        docker logs --tail=120 "$RUNNER_NAME" || true
        fail "Runner container did not remain running."
    fi

    docker exec "$RUNNER_NAME" test -s /root/.ssh/luxquote_github_repo_deploy || fail "Runner cannot read the persistent deploy key."
    docker exec "$RUNNER_NAME" test -s /root/.ssh/known_hosts || fail "Runner cannot read persistent SSH fingerprints."
    docker exec "$RUNNER_NAME" sh -lc "cd '$APP_DIR' && git ls-remote --exit-code origin refs/heads/production >/dev/null" || fail "Runner cannot read the production branch from GitHub."

    log "Runner container is running. Recent logs follow:"
    docker logs --tail=40 "$RUNNER_NAME"
    log "Setup complete. GitHub should show runner '$RUNNER_LABEL' as online."
}

main() {
    require_root
    require_prerequisites
    prepare_directories
    preserve_working_ssh_files
    capture_registration_token
    remove_existing_runner_container
    start_runner
    verify_runner
}

main "$@"
