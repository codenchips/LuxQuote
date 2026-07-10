#!/usr/bin/env bash

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/home/tamliteco/luxquote.app}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-production}"
PUBLIC_URL="${PUBLIC_URL:-https://quote.tamlite.co.uk}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/backups}"
PROTECTED_DATA_TABLES="${PROTECTED_DATA_TABLES:-users products teams projects project_revisions project_areas project_lines document_packs document_pack_items team_user activity_logs salesforce_pdf_uploads}"
RESTORE_ON_CATASTROPHIC_DATA_LOSS="${RESTORE_ON_CATASTROPHIC_DATA_LOSS:-true}"

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

mysql_query() {
    docker compose exec -T mysql mysql \
        -N \
        -B \
        -u "$DB_USERNAME" \
        -p"$DB_PASSWORD" \
        "$DB_DATABASE" \
        -e "$1"
}

table_exists() {
    local table="$1"
    local exists

    exists="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '${table}';")"
    [ "$exists" = "1" ]
}

record_table_counts() {
    local output_file="$1"
    local table count

    : > "$output_file"

    for table in $PROTECTED_DATA_TABLES; do
        if ! table_exists "$table"; then
            printf '%s\t%s\n' "$table" "MISSING" >> "$output_file"
            continue
        fi

        count="$(mysql_query "SELECT COUNT(*) FROM \`${table}\`;")"
        printf '%s\t%s\n' "$table" "$count" >> "$output_file"
    done
}

data_loss_summary() {
    local before_file="$1"
    local after_file="$2"
    local before_populated=0
    local emptied=0
    local partial_loss=0
    local table before after

    while IFS=$'\t' read -r table before; do
        if [[ ! "$before" =~ ^[0-9]+$ || "$before" -eq 0 ]]; then
            continue
        fi

        before_populated=$((before_populated + 1))
        after="$(awk -F '\t' -v table="$table" '$1 == table { print $2; exit }' "$after_file")"

        if [[ "$after" =~ ^[0-9]+$ && "$after" -gt 0 ]]; then
            continue
        fi

        emptied=$((emptied + 1))
    done < "$before_file"

    if [ "$before_populated" -eq 0 ]; then
        printf 'none\n'
        return
    fi

    if [ "$emptied" -eq 0 ]; then
        printf 'none\n'
        return
    fi

    if [ "$emptied" -eq "$before_populated" ]; then
        printf 'catastrophic\n'
        return
    fi

    partial_loss=$emptied

    if [ "$partial_loss" -gt 0 ]; then
        printf 'partial\n'
        return
    fi

    printf 'none\n'
}

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
backup_timestamp="$(date +%Y%m%d-%H%M%S)"
full_backup="$BACKUP_DIR/pre-deploy-${backup_timestamp}.sql.gz"
data_backup="$BACKUP_DIR/latest-protected-data-restore.sql.gz"
data_backup_tmp="$BACKUP_DIR/latest-protected-data-restore.sql.gz.tmp"
before_counts="$BACKUP_DIR/pre-deploy-counts-${backup_timestamp}.tsv"
after_counts="$BACKUP_DIR/post-migration-counts-${backup_timestamp}.tsv"

log "Recording protected table counts before deploy"
record_table_counts "$before_counts"

docker compose exec -T mysql mysqldump \
    -u "$DB_USERNAME" \
    -p"$DB_PASSWORD" \
    --single-transaction \
    --routines \
    --triggers \
    --no-tablespaces \
    "$DB_DATABASE" | gzip > "$full_backup"

log "Backing up protected table data for migrated-schema restore fallback"
existing_protected_tables=""
for table in $PROTECTED_DATA_TABLES; do
    if table_exists "$table"; then
        existing_protected_tables="$existing_protected_tables $table"
    fi
done

if [ -n "$existing_protected_tables" ]; then
    docker compose exec -T mysql mysqldump \
        -u "$DB_USERNAME" \
        -p"$DB_PASSWORD" \
        --single-transaction \
        --no-create-info \
        --complete-insert \
        --skip-triggers \
        --no-tablespaces \
        "$DB_DATABASE" \
        $existing_protected_tables | gzip > "$data_backup_tmp"

    mv "$data_backup_tmp" "$data_backup"
    rm -f "$BACKUP_DIR"/pre-deploy-data-only-*.sql.gz
else
    log "No protected tables existed before deploy; skipping data-only backup."
    rm -f "$data_backup" "$data_backup_tmp"
fi

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
docker compose exec laravel.test sh -lc 'mkdir -p /var/www/html/storage/app/browsershot /home/sail/.cache/puppeteer && chown -R sail:sail /var/www/html/storage/app/browsershot /home/sail/.cache'

log "Installing Composer dependencies without framework scripts"
docker compose exec laravel.test composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

log "Installing npm dependencies and building assets"
docker compose exec -u sail laravel.test npm install
docker compose exec -u sail laravel.test npx puppeteer browsers install chrome-headless-shell
docker compose exec -u sail laravel.test npm run build

log "Completing Composer autoload and framework discovery"
docker compose exec laravel.test composer dump-autoload --optimize

log "Restarting app container after dependencies are available"
docker compose restart laravel.test

log "Verifying qpdf runtime"
docker compose exec laravel.test qpdf --version

log "Verifying PDF runtime"
docker compose exec laravel.test php artisan app:diagnose-pdf-environment

log "Running migrations"
docker compose exec -T laravel.test php artisan migrate --force --no-interaction

log "Checking migration status"
docker compose exec -T laravel.test php artisan migrate:status

log "Checking protected table counts after migrations"
record_table_counts "$after_counts"
data_loss_state="$(data_loss_summary "$before_counts" "$after_counts")"

if [ "$data_loss_state" = "catastrophic" ]; then
    log "ERROR: Catastrophic protected data loss detected after migrations."

    if [ "$RESTORE_ON_CATASTROPHIC_DATA_LOSS" = "true" ] && [ -s "$data_backup" ]; then
        log "Restoring protected table data into the migrated schema from $data_backup"

        if ! gzip -dc "$data_backup" | docker compose exec -T mysql mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"; then
            log "ERROR: The migrated-schema data restore failed."
            log "This usually means the new migrations changed table or column structures in a way that the data-only backup cannot be inserted safely."
            log "The database may need a custom manual recovery using the full pre-deploy backup and a purpose-built data migration."
            log "Full backup: $full_backup"
            log "Data-only restore backup: $data_backup"
            exit 1
        fi

        log "Re-running migrations after data restore to confirm migrated schema state"
        docker compose exec -T laravel.test php artisan migrate --force --no-interaction
        log "Protected data restore completed. Failing deploy so this incident is visible."
    else
        log "Automatic data restore is disabled or $data_backup is missing."
    fi

    exit 1
fi

if [ "$data_loss_state" = "partial" ]; then
    log "ERROR: Partial protected data loss detected after migrations."
    log "Automatic restore was not attempted to avoid duplicate or mixed-state rows."
    log "Full backup: $full_backup"
    log "Data-only backup: $data_backup"
    exit 1
fi

log "Clearing and rebuilding Laravel caches"
docker compose exec laravel.test php artisan optimize:clear
docker compose exec laravel.test php artisan config:cache
docker compose exec laravel.test php artisan route:cache
docker compose exec laravel.test php artisan view:cache

log "Smoke checking $PUBLIC_URL"
curl --fail --silent --show-error --location --max-time 20 "$PUBLIC_URL" >/dev/null

log "Pruning unused Docker build cache older than 24 hours"
docker builder prune --all --force --filter "until=24h"

log "Pruning database backups older than 14 days"
find "$BACKUP_DIR" -name "pre-deploy-*.sql.gz" -type f -mtime +14 -delete
rm -f "$BACKUP_DIR"/pre-deploy-data-only-*.sql.gz "$data_backup_tmp"
find "$BACKUP_DIR" -name "pre-deploy-counts-*.tsv" -type f -mtime +14 -delete
find "$BACKUP_DIR" -name "post-migration-counts-*.tsv" -type f -mtime +14 -delete

log "Production deploy complete"
