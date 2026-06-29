#!/usr/bin/env bash

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-production}"
PUBLIC_URL="${PUBLIC_URL:-https://quote.tamlite.co.uk}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/backups}"

timestamp() {
    date +"%Y-%m-%d %H:%M:%S"
}

log() {
    printf '[%s] %s\n' "$(timestamp)" "$*"
}

cd "$APP_DIR"

env_value() {
    if [ -f .env ]; then
        awk -F= -v key="$1" '$1 == key { print substr($0, index($0, "=") + 1); exit }' .env \
            | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
    fi
}

DB_DATABASE="${DB_DATABASE:-$(env_value DB_DATABASE)}"
DB_USERNAME="${DB_USERNAME:-$(env_value DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(env_value DB_PASSWORD)}"
DB_DATABASE="${DB_DATABASE:-laravel}"
DB_USERNAME="${DB_USERNAME:-sail}"
DB_PASSWORD="${DB_PASSWORD:-password}"

if [ ! -d .git ]; then
    log "ERROR: $APP_DIR is not a git checkout. Complete the one-time production git setup before running deploys."
    exit 1
fi

log "Marking production checkout as a safe git directory"
git config --global --add safe.directory "$APP_DIR"

log "Starting production deploy for branch: $DEPLOY_BRANCH"

log "Checking Docker services"
docker compose up -d

log "Waiting for MySQL to accept connections"
for attempt in {1..60}; do
    if docker compose exec -T mysql mysqladmin ping -h 127.0.0.1 -u "$DB_USERNAME" -p"$DB_PASSWORD" --silent; then
        break
    fi

    if [ "$attempt" -eq 60 ]; then
        log "ERROR: MySQL did not become ready in time."
        docker compose logs --tail=80 mysql
        exit 1
    fi

    sleep 2
done

log "Backing up database"
mkdir -p "$BACKUP_DIR"
docker compose exec -T mysql mysqldump \
    -u "$DB_USERNAME" \
    -p"$DB_PASSWORD" \
    --single-transaction \
    --routines \
    --triggers \
    --no-tablespaces \
    "$DB_DATABASE" | gzip > "$BACKUP_DIR/pre-deploy-$(date +%Y%m%d-%H%M%S).sql.gz"

log "Fetching latest code"
git fetch origin "$DEPLOY_BRANCH"
git checkout -B "$DEPLOY_BRANCH" "origin/$DEPLOY_BRANCH"

log "Building and starting Docker services"
docker compose up -d --build

log "Removing local-only Vite dev marker"
rm -f public/hot

log "Ensuring container file ownership"
docker compose exec laravel.test chown -R sail:sail /var/www/html
docker compose exec laravel.test rm -rf /var/www/html/node_modules/.vite-temp

log "Installing Composer dependencies without framework scripts"
docker compose exec laravel.test composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

log "Installing npm dependencies and building assets"
docker compose exec -u sail laravel.test npm install
docker compose exec -u sail laravel.test npm run build

log "Completing Composer autoload and framework discovery"
docker compose exec laravel.test composer dump-autoload --optimize

log "Restarting app container after dependencies are available"
docker compose restart laravel.test

log "Verifying qpdf runtime"
docker compose exec laravel.test qpdf --version

log "Running migrations"
docker compose exec laravel.test php artisan migrate --force

log "Clearing and rebuilding Laravel caches"
docker compose exec laravel.test php artisan optimize:clear
docker compose exec laravel.test php artisan config:cache
docker compose exec laravel.test php artisan route:cache
docker compose exec laravel.test php artisan view:cache

log "Smoke checking $PUBLIC_URL"
curl --fail --silent --show-error --location --max-time 20 "$PUBLIC_URL" >/dev/null

log "Pruning database backups older than 14 days"
find "$BACKUP_DIR" -name "pre-deploy-*.sql.gz" -type f -mtime +14 -delete

log "Production deploy complete"
