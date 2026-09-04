# Production Deployment

This app is deployed to a Linux VPS managed through cPanel / WHM.

## Production Architecture

- Live domain: `https://quote.tamlite.co.uk`
- Root app directory: `/home/tamliteco/luxquote.app/`
- Runtime: Docker Compose using Laravel Sail containers
- Main app container: `laravel.test`
- Database container: `mysql`
- Database target: containerized MySQL, not cPanel MySQL
- External SSL/reverse proxy: cPanel host Apache terminates HTTPS and proxies traffic to the app container on local port `8080`
- MySQL and Redis host port bindings in `compose.yaml` are loopback-only (`127.0.0.1`) so Docker does not expose them publicly

Because Apache terminates SSL before proxying to Docker, Laravel must trust proxy headers so generated URLs and redirects use the public HTTPS domain rather than `http://127.0.0.1:8080`.

Production should keep proxy trust configured in `bootstrap/app.php`:

```php
$middleware->trustProxies(at: '*');
```

## Command Rules

Do not run bare PHP, Composer, Artisan, or npm commands directly on the host VPS. Run them through Docker Compose from:

```bash
cd /home/tamliteco/luxquote.app/
```

Common production commands:

```bash
docker compose exec laravel.test php artisan optimize:clear
docker compose exec laravel.test php artisan migrate --force
```

Use `--force` for production migrations to bypass Laravel's interactive production prompt.

## Database Safety Rule

Never run destructive database commands without explicit user approval.

Forbidden unless the operator explicitly asks for a restore/reset:

- `migrate:fresh`
- `migrate:refresh`
- `db:wipe`
- `schema:dump --prune`
- `TRUNCATE`
- `DROP DATABASE`
- deleting all rows from business tables
- restoring backups over an existing database

Production deploys must not use destructive database commands. Normal production deploys may only run forward migrations:

```bash
docker compose exec laravel.test php artisan migrate --force
```

Before any command that may affect data, confirm the exact database name and whether the command is destructive. If the command is destructive, stop unless the operator has explicitly requested that specific restore/reset action.

## Environment Configuration

Production should include:

```dotenv
APP_TIMEZONE=Europe/London
```

Use `Europe/London`, not a fixed `GMT+1` offset, so PHP automatically handles GMT/BST changes. After changing `.env` or deploying a config change, clear Laravel's cached config:

```bash
docker compose exec laravel.test php artisan optimize:clear
docker compose exec laravel.test php artisan config:show app.timezone
```

## Production 0.2.5 Release Record

Version `0.2.5` is the production-visible release containing the feature work prepared after `0.2.3`. The `0.2.5` commit followed a one-time reconciliation of divergent `main` and `production` release-history commits; it did not duplicate migrations or application changes. A manual database backup did not cause that Git divergence because backup archives are outside the tracked release history.

This release deploys these forward-only migrations:

- `2026_08_05_095813_add_currency_to_projects_table`
- `2026_08_27_092936_change_default_project_revision_to_one`
- `2026_08_27_162004_add_calendar_view_permission`
- `2026_08_28_114940_add_calendar_update_permission`
- `2026_08_28_135337_add_calendar_delete_permission`
- `2026_08_28_140716_add_calendar_create_permission`
- `2026_09_01_091754_add_default_landing_page_to_permission_groups_table`

They add project currency, change only the default revision for future projects, add the four Calendar capabilities to permission groups, and add the group landing-page setting. They do not rewrite existing project revision sequences or restore/reset the database.

The first Docker image build after an older build cache has expired can spend several minutes printing package installation output from `docker/8.5/Dockerfile`. That output is expected during `docker compose up -d --build` and is not, by itself, a runner loop. Do not start a second deployment while the first workflow is still running. The existing persistent `luxquote-production` runner does not need to be recreated for this release when it remains online and its logs end with `Listening for Jobs`.

## Pending Resources and Document Pack Templates Rollout

The Resources and reusable Document Pack template features add these forward-only migrations:

- `2026_09_03_094427_create_resource_files_table`
- `2026_09_03_114115_add_resource_permissions`
- `2026_09_03_114829_disable_resource_permissions_for_non_admin_groups`
- `2026_09_03_151703_create_document_pack_templates_table`
- `2026_09_03_151706_create_document_pack_template_items_table`
- `2026_09_04_093500_add_quoted_at_to_project_revisions_table`
- `2026_09_04_100302_backfill_project_revision_quoted_at`

The first migration creates Resource metadata, the next two add the four `resources.*` permission catalogue entries and guarantee that their rollout defaults are off for every group except Admin, and the following two create reusable Document Pack templates plus their ordered items. The final pair separately add a nullable, indexed quote-generation timestamp to project revisions and safely backfill it from current project status plus live and legacy activity history. Keeping the schema change and data backfill separate makes an interrupted MySQL deployment straightforward to resume. None deletes existing business records or uploaded files. The normal production workflow runs them with the existing `php artisan migrate --force --no-interaction` step. Resource, template, permission, live-history, and legacy-history tables are included in the deploy data-loss guard.

Uploaded files live under `storage/app/private/resources`; template snapshots live under `storage/app/private/document-pack-templates`. The production bind mount preserves both directories across container rebuilds and Git checkouts. The current `backup-production-database.sh` job is database-only and therefore does not back up either set of PDF contents. Add a separate protected file backup before treating the Resource library or templates as the only copy of business-critical documents.

The template migrations are required before opening a project's Document Packs tab. If that page reports that `document_pack_templates` does not exist, verify deployment includes commit `0656b8c` or later and inspect migration state with:

```bash
docker compose exec -T laravel.test php artisan migrate:status
```

Do not create the tables manually and do not use a destructive reset. A normal production deployment applies the pending forward migrations automatically; if an operator must apply them independently, use only:

```bash
docker compose exec -T laravel.test php artisan migrate --force --no-interaction
```

## Pending Statistics Rollout

The management Statistics feature is deployed through these forward-only migrations:

- `2026_09_04_142322_create_reporting_events_table`
- `2026_09_04_142331_add_statistics_view_permission`
- `2026_09_04_142633_create_reporting_event_products_table`
- `2026_09_04_142700_backfill_reporting_events`
- `2026_09_04_145714_add_owner_name_to_projects_table`

The migrations create two durable reporting tables, install `statistics.view`, copy supported retained Activity History into reporting snapshots, and add the nullable `projects.owner_name` field. The owner-name migration only fills blank names where an existing LuxQuote user has the same email address. It does not contact Salesforce during deployment. None of these `up()` paths drops a table or column, truncates data, deletes business records, changes project values, or rewrites existing owner email addresses.

Reporting snapshots are intentionally separate from Activity History. The three-month History prune therefore does not remove management statistics. `app:sync-reporting-events` runs daily at `01:31`, ten minutes before Activity History pruning, to reconcile any reportable activity that could not be captured inline. The capture observer fails open: a reporting error is logged for reconciliation and cannot make a successful login, project creation, or PDF generation fail.

`reporting_events` and `reporting_event_products` are included in the production deploy protected-table count and backup guard. They will be absent from the pre-deploy data-only backup on their first deployment because they do not exist yet, but the full pre-deploy database backup is still taken and the reporting backfill is repeatable through the reconciliation command. On later deployments both reporting tables are protected normally.

Optional production settings use safe defaults when omitted:

```dotenv
STATISTICS_HIGH_VALUE_THRESHOLD=25000
STATISTICS_INACTIVE_DAYS=30
STATISTICS_MAX_RANGE_DAYS=3650
```

`STATISTICS_MAX_RANGE_DAYS` is the absolute report limit. Daily grouping is additionally limited to 400 days and weekly grouping to 3,650 days, preventing accidental browser or database overload; the page returns a validation message and retains the previous report instead of failing.

Owner names are stored locally for new Salesforce imports. Older linked projects resolve `Opportunity.Owner` through the existing Salesforce owner lookup when needed, cache the result for six hours, and persist the name for subsequent reports. Matching local users and already-known project owners are resolved in bulk first. Salesforce, cache, or owner-name persistence failures leave the report available with `Owner name unavailable`; email addresses are not displayed as the fallback.

The Statistics page requires `statistics.view`, granted to Admin and Manager by default. Commercial values additionally require `pricing.view`; users with Statistics but without Pricing receive counts and operational charts without net, gross, Cover, or quote-value fields.

After deployment, verify the forward migration and reporting state without changing business data:

```bash
cd /home/tamliteco/luxquote.app
docker compose exec -T laravel.test php artisan migrate:status
docker compose exec -T laravel.test php artisan config:show statistics
docker compose exec -T laravel.test php artisan app:sync-reporting-events
docker compose exec -T laravel.test php artisan schedule:list
```

Then sign in as an Admin or Manager, open **Admin → Statistics**, confirm the current-month charts load, change the date grouping, and verify owner names. Use a non-pricing test group with `statistics.view` to confirm commercial values remain hidden.

## Salesforce Visits Calendar Release Checklist

The Visits interface reads and mutates Salesforce `Event` records owned by a public Salesforce `Calendar`. Before the first production deployment, confirm the production integration user can:

- read the `Calendar` object and see the intended public calendar
- read, create, edit, and delete `Event` records on that calendar
- read the core Event fields `Id`, `Subject`, `StartDateTime`, `EndDateTime`, `IsAllDayEvent`, and `OwnerId`
- create/update the fields required by the UI: `Subject`, `Location`, `Type`, `StartDateTime`, `EndDateTime`, and `IsAllDayEvent`
- set `OwnerId` to the public calendar when creating an Event

`Owner.Name`, `CreatedBy.Name`, `Type`, and `Location` reads are optional. LuxQuote retries event reads without unavailable optional fields and keeps affected form controls read-only when describe metadata reports narrower access. The Salesforce public calendar sharing level must still allow the intended create/update/delete operations.

Production `.env` must include:

```dotenv
SALESFORCE_VISITS_CALENDAR_ID=023_PRODUCTION_CALENDAR_ID
SALESFORCE_VISITS_CALENDAR_NAME=Visits
CALENDAR_SHOW_FULL_DAYS=false
CALENDAR_SHOW_WEEKENDS=false
```

Do not copy a sandbox Calendar ID into production. Calendar IDs are org-specific. List the calendars visible to the production integration user and copy the production Visits ID:

```bash
cd /home/tamliteco/luxquote.app
docker compose exec -T laravel.test php artisan salesforce:calendars
```

The normal production deploy runs the four forward-only calendar permission migrations, builds the FullCalendar assets, and clears/rebuilds configuration caches. After deployment, verify the effective non-secret settings and migration status:

```bash
docker compose exec -T laravel.test php artisan config:show services.salesforce.visits_calendar_id
docker compose exec -T laravel.test php artisan config:show calendar
docker compose exec -T laravel.test php artisan migrate:status
docker compose exec -T laravel.test php artisan salesforce:calendars 023_PRODUCTION_CALENDAR_ID --from=2026-09-01 --to=2026-09-30
```

Complete one controlled browser smoke test with a disposable booking:

1. Confirm **Salesforce → Visits** loads month, week, and day views without an error notification.
2. Create a short test Event, then edit its Subject/date/time.
3. Delete the test Event through the confirmation dialog.
4. Confirm History contains green Created, blue Updated, and red Deleted rows with `Calendar` as Reference.
5. Confirm the disposable Event no longer exists in Salesforce.

If reads work but mutations fail, do not broaden LuxQuote permissions first. Check Salesforce Event object permissions, field-level access, public calendar sharing, and whether outbound Salesforce pushes are paused. The UI leaves failed actions open, shows a safe error, and does not write a completed calendar activity entry.

## App Version Configuration

The visible app version is read from the tracked `VERSION` file by default and shown in the expanded left sidebar. Leave `APP_VERSION` unset in production unless you deliberately need to pin or override the displayed version for an environment.

The local `./deploy-production` helper bumps `VERSION` before pushing the `production` branch:

```bash
./deploy-production
VERSION_BUMP=patch ./deploy-production
VERSION_BUMP=minor ./deploy-production
VERSION_BUMP=major ./deploy-production
VERSION_BUMP=none ./deploy-production
```

The default bump is now a normal patch increment, for example `0.2.1` to `0.2.2`. Use `VERSION_BUMP=beta` only if a future pre-release stream is deliberately needed.

The helper also prepends a `CHANGELOG.md` entry for the version being deployed. The entry lists the commits included between the current `production` branch and `main`, so the GitHub Actions run may still be triggered by a release/version commit, but the release contents are recorded against the visible app version. When there are deployable changes, the version commit message is:

```text
Release v<version>: <latest included commit subject>
```

## Reboot Recovery

The production Docker services should survive VPS reboots. `compose.yaml` sets `restart: unless-stopped` for the app, MySQL, Redis, Meilisearch, and Mailpit services. The GitHub Actions runner container is also started with `--restart unless-stopped`.

After a VPS reboot, verify the stack with:

```bash
cd /home/tamliteco/luxquote.app
docker compose ps
docker ps --filter name=luxquote-github-runner
curl -I http://127.0.0.1:8080
curl -I https://quote.tamlite.co.uk
```

If the runner is not listed, recreate it using the runner container command in the Deployment Method section with a fresh GitHub runner token.

The GitHub runner may not appear in `docker compose ps`. If it was recreated manually with `docker run`, it is managed by plain Docker rather than this app's Compose file. In that case GitHub showing the runner as **Idle** is healthy, and the correct checks are:

```bash
docker ps --filter name=luxquote-github-runner
docker logs --tail=80 luxquote-github-runner
```

Healthy logs end with `Listening for Jobs`. Use `docker restart luxquote-github-runner` for a quick runner restart. Do not use Compose commands for that manually-created runner unless it has later been moved into `compose.yaml`.

## Docker Firewall and Container DNS Recovery

The production host uses a custom iptables script at `/root/apply_iptables_rules.sh`. Docker creates and manages its own filter/NAT chains for bridge networking, container DNS, outbound traffic, and published ports. The host firewall script must not flush, delete, replace, or restart those Docker-managed rules while Docker is running.

The live firewall script should:

- manage host traffic through its own `LUXQUOTE_INPUT` chain
- attach that chain to `INPUT` without globally flushing other chains
- leave `FORWARD`, `DOCKER`, `DOCKER-USER`, `DOCKER-FORWARD`, Docker bridge chains, and Docker NAT rules untouched
- avoid global `iptables -F`, `iptables -X`, and `iptables -Z` operations
- avoid changing the `FORWARD` policy as part of host `INPUT` filtering
- avoid `systemctl restart iptables` after applying live rules while Docker is running
- use `DOCKER-USER` for any future policy that intentionally filters forwarded container traffic

Do not allowlist fixed Salesforce edge IP addresses. Salesforce hostnames may resolve to changing edge addresses; working container DNS and outbound HTTPS are required instead.

### Salesforce JWT requests timing out during DNS resolution

If Salesforce project loading or `salesforce:interrogate` fails after a deploy, read the Laravel log before rotating keys:

```bash
cd /home/tamliteco/luxquote.app
docker compose exec -T laravel.test tail -n 120 storage/logs/laravel.log
```

An error such as:

```text
cURL error 28: Resolving timed out ... /services/oauth2/token
```

means the request did not reach Salesforce. If the configured private key exists and is readable, this is a network/DNS failure rather than evidence that the Salesforce Connected App certificate has changed.

Compare host and container DNS:

```bash
getent hosts tamlite-lighting.my.salesforce.com
docker compose exec -T laravel.test getent hosts tamlite-lighting.my.salesforce.com
```

If the host resolves the hostname but the container does not, verify Docker's firewall chain still exists:

```bash
iptables -nL DOCKER-USER
docker compose ps
```

If container recreation fails with an iptables error containing `No chain/target/match by that name`, restart Docker to recreate its managed chains, then restore the Compose stack:

```bash
cd /home/tamliteco/luxquote.app
bash scripts/recover-docker-iptables.sh
```

`scripts/recover-docker-iptables.sh` is the preferred fast recovery path for this specific Docker firewall-chain failure. It restarts Docker, runs `docker compose up -d`, clears Laravel caches if the app container is ready, checks local and public HTTP status, and prints useful log tails. It does not remove Docker volumes and does not restore the database.

The manual equivalent is:

```bash
systemctl restart docker
cd /home/tamliteco/luxquote.app
docker compose up -d
docker compose ps
```

This is volume-preserving. Do not use `docker compose down -v`, remove volumes, restore the database, or rotate Salesforce keys for this symptom.

After correcting the host firewall script, verify Docker networking and Salesforce:

```bash
iptables -nL DOCKER-USER
docker compose exec -T laravel.test getent hosts tamlite-lighting.my.salesforce.com
docker compose exec -T laravel.test php artisan optimize:clear
docker compose exec -T -u sail laravel.test php artisan salesforce:interrogate --limit=1 --format=json
```

Only generate a new key/certificate pair when the original private key is permanently lost or the Salesforce Connected App certificate is deliberately being rotated. A Docker image rebuild, container recreation, DNS failure, or missing Docker firewall chain does not require a new certificate.

### Salesforce object inspection commands

These commands are useful when checking live Salesforce field visibility with the app's configured Salesforce user. Run them on the VPS from:

```bash
cd /home/tamliteco/luxquote.app
```

The local development equivalents use the same command body but replace `docker compose exec -T laravel.test php artisan` with `vendor/bin/sail artisan`.

For quick object inspection, use the generic sampler command. It describes the requested Salesforce object, samples records, and returns every readable field value it can fetch. The second argument is optional and defaults to `1`; it is capped at `25`.

```bash
docker compose exec -T laravel.test php artisan salesforce:sample-object Account
docker compose exec -T laravel.test php artisan salesforce:sample-object User
docker compose exec -T laravel.test php artisan salesforce:sample-object Opportunity 3
```

Default output is JSON. Use `--format=table` for easier manual inspection, or `--format=ndjson` for line-oriented output:

```bash
docker compose exec -T laravel.test php artisan salesforce:sample-object Account 1 --format=table
docker compose exec -T laravel.test php artisan salesforce:sample-object User 1 --format=table
docker compose exec -T laravel.test php artisan salesforce:sample-object Opportunity 1 --format=table
```

Local development uses Sail:

```bash
vendor/bin/sail artisan salesforce:sample-object Account 1 --format=table
vendor/bin/sail artisan salesforce:sample-object User 1 --format=table
vendor/bin/sail artisan salesforce:sample-object Opportunity 1 --format=table
```

If Salesforce rejects individual fields, the command reports them in `skipped_fields` instead of failing the whole sample.

List the public calendars visible to the configured Salesforce integration user (user calendars are excluded):

```bash
docker compose exec -T laravel.test php artisan salesforce:calendars
```

Copy a calendar ID from that output, then list its bookings. Date-only values are interpreted in the app timezone, and `--to` includes the whole final day:

```bash
docker compose exec -T laravel.test php artisan salesforce:calendars 023YOUR_CALENDAR_ID --from=2026-09-01 --to=2026-09-30
docker compose exec -T laravel.test php artisan salesforce:calendars 023YOUR_CALENDAR_ID --from=2026-09-01 --format=json
```

Local development uses Sail:

```bash
vendor/bin/sail artisan salesforce:calendars
vendor/bin/sail artisan salesforce:calendars 023YOUR_CALENDAR_ID --from=2026-09-01 --to=2026-09-30
```

Get a single Opportunity by Id, including all fields the integration user can read:

```bash
docker compose exec -T laravel.test php artisan tinker --execute '$sf = app(\App\Services\SalesforceService::class); $m = new ReflectionMethod($sf, "authenticate"); $auth = $m->invoke($sf); $id = "006YOUR_OPPORTUNITY_ID_HERE"; $describe = \Illuminate\Support\Facades\Http::withToken($auth["token"])->acceptJson()->get($auth["instanceUrl"]."/services/data/v65.0/sobjects/Opportunity/describe")->json(); $fields = collect($describe["fields"] ?? [])->pluck("name")->all(); $query = "SELECT ".implode(", ", $fields)." FROM Opportunity WHERE Id = '\''".$id."'\'' LIMIT 1"; echo json_encode(\Illuminate\Support\Facades\Http::withToken($auth["token"])->acceptJson()->get($auth["instanceUrl"]."/services/data/v65.0/query/", ["q" => $query])->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;'
```

Get Tender records for an Opportunity. The Tender custom object links to Opportunity through `Tender__c.Project__c`:

```bash
docker compose exec -T laravel.test php artisan tinker --execute '$sf = app(\App\Services\SalesforceService::class); $m = new ReflectionMethod($sf, "authenticate"); $auth = $m->invoke($sf); $opportunityId = "006YOUR_OPPORTUNITY_ID_HERE"; $describe = \Illuminate\Support\Facades\Http::withToken($auth["token"])->acceptJson()->get($auth["instanceUrl"]."/services/data/v65.0/sobjects/Tender__c/describe")->json(); $fields = collect($describe["fields"] ?? [])->pluck("name")->all(); $query = "SELECT ".implode(", ", $fields)." FROM Tender__c WHERE Project__c = '\''".$opportunityId."'\''"; echo json_encode(\Illuminate\Support\Facades\Http::withToken($auth["token"])->acceptJson()->get($auth["instanceUrl"]."/services/data/v65.0/query/", ["q" => $query])->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;'
```

Get an Account by Id from a Tender's `Account__c` value. Account IDs normally start with `001`; do not use the Tender record Id, which starts with a custom-object prefix such as `a0G`:

```bash
docker compose exec -T laravel.test php artisan tinker --execute '$sf = app(\App\Services\SalesforceService::class); $m = new ReflectionMethod($sf, "authenticate"); $auth = $m->invoke($sf); $accountId = "001YOUR_ACCOUNT_ID_HERE"; $describe = \Illuminate\Support\Facades\Http::withToken($auth["token"])->acceptJson()->get($auth["instanceUrl"]."/services/data/v65.0/sobjects/Account/describe")->json(); $fields = collect($describe["fields"] ?? [])->pluck("name")->all(); $query = "SELECT ".implode(", ", $fields)." FROM Account WHERE Id = '\''".$accountId."'\'' LIMIT 1"; echo json_encode(\Illuminate\Support\Facades\Http::withToken($auth["token"])->acceptJson()->get($auth["instanceUrl"]."/services/data/v65.0/query/", ["q" => $query])->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;'
```

## Emergency Stack Recovery

If production is returning HTTP 500 because the Docker stack or MySQL container is wedged, use the checked-in emergency recovery script from the production app directory:

```bash
cd /home/tamliteco/luxquote.app
bash emergency_recover.sh
```

`emergency_recover.sh` performs a volume-preserving stack refresh:

- runs `docker compose down`
- runs `docker compose up -d --force-recreate`
- waits briefly for MySQL to initialize
- clears Laravel caches with `docker compose exec -T laravel.test php artisan optimize:clear`
- checks `https://quote.tamlite.co.uk` and accepts HTTP `200` or `302` as healthy

This recreates containers, not Docker volumes. Do not use `docker compose down -v`, `docker volume rm`, or `docker volume prune` during incident recovery unless a restore/destroy operation is explicitly intended.

If the public health check still returns an unexpected status, `emergency_recover.sh` calls:

```bash
bash luxquote_restore_to_last_deploy.sh
```

`luxquote_restore_to_last_deploy.sh` finds the newest `backups/*.sql.gz` file, reads the production database name/user/password from `.env`, streams the backup into the `mysql` container, and clears Laravel caches after a successful import.

Use the restore fallback only when container recreation is not enough and restoring to the latest deploy backup is acceptable. It overwrites database data with the selected backup.

### Emergency Reset CGI

A reference CGI wrapper is stored at:

```bash
scripts/emergency-reset-webhook.cgi
```

The live production copy is outside the application checkout:

```text
/home/tamliteco/quote/cgi-bin/reset-app.cgi
```

It is not copied or replaced by the normal GitHub deployment. Install updates manually and preserve the live Auth key, which must remain outside git. The repository template accepts `LUXQUOTE_RESET_KEY` from the CGI environment or a server-only replacement for `CHANGE_ME_ON_THE_SERVER`.

The CGI confirmation page labels the existing `dean` confirmation value as **Auth key** and lists every valid, non-symlinked `.sql.gz` archive in the app `backups` directory, newest first with its modified date/time and size. It offers three explicit operations:

- Reset the app without touching the database.
- Restore the selected database backup without resetting the app.
- Reset the app, then restore the selected database backup.

Restore operations require an archive selection and validate its filename, location, and gzip integrity before running. The selected archive is passed to `luxquote_restore_to_last_deploy.sh`, which temporarily enables Laravel maintenance mode without resetting containers, restores into the database configured by the MySQL container, reapplies pending forward migrations, clears Laravel caches, and restores the app's previous maintenance state even if the import fails. The CGI serializes operations with a lock, preserves the five-minute cooldown, performs a final public HTTP health check after restores, and displays the escaped command output and final exit status in the browser.

The reset-only path calls `emergency_recover.sh` with `LUXQUOTE_AUTO_DB_RESTORE=0`, so it never invokes the recovery script's automatic database fallback. The combined path also disables that automatic fallback, runs the reset first, and then restores exactly the archive selected by the operator.

After copying the CGI file, ensure ownership and its deliberately restrictive executable mode match the live host setup:

```bash
chown tamliteco:tamliteco /home/tamliteco/quote/cgi-bin/reset-app.cgi
chmod 700 /home/tamliteco/quote/cgi-bin/reset-app.cgi
```

Do not expose the CGI URL without the secret key. The script also enforces a five-minute cooldown with `/tmp/luxquote_reset.lock`.

## Automated Database Backups

The production backup job is checked in as `scripts/backup-production-database.sh`. It dumps the database configured by the Docker `mysql` service using `--single-transaction`, includes routines and triggers, compresses the stream, and verifies gzip integrity before publishing the archive in:

```text
/home/tamliteco/luxquote.app/backups
```

The script creates two filename classes:

- `luxquote-db-3hourly-YYYYMMDD-HHMMSS.sql.gz`, retained for 48 hours.
- `luxquote-db-daily-YYYYMMDD.sql.gz`, the first successful backup of that calendar day, retained for 14 days.

The daily archive is a hard link to the underlying backup data. Removing the expired three-hourly filename does not remove the data while the daily filename still exists; disk space is released only after the final hard link expires. The script uses `flock` to skip overlapping invocations, `umask 077` for private files, a temporary archive plus atomic move, and container-provided database credentials rather than hard-coded values.

Install this root cron entry to run at 14 minutes past every third hour and send output to syslog under `luxquote-db-backup`:

```cron
14 */3 * * * /home/tamliteco/luxquote.app/scripts/backup-production-database.sh 2>&1 | /usr/bin/logger -t luxquote-db-backup
```

The previous `/root/backup-luxquote-db.sh` cron entry should be removed after this checked-in job is installed, avoiding duplicate dumps. Verify the job without restoring anything:

```bash
cd /home/tamliteco/luxquote.app
bash scripts/backup-production-database.sh
ls -liht backups/luxquote-db-*.sql.gz
gzip -t backups/luxquote-db-3hourly-*.sql.gz
journalctl -t luxquote-db-backup --since today
```

The backup command is read-only with respect to MySQL. A restore is destructive because it replaces current database state and must only be run after explicitly selecting and confirming the intended archive.

## Generated PDF Storage Cleanup

Quote, Schedule, datasheet, and Document Pack generation uses private working and output directories. Successful requests normally remove or relocate their generated files, but interrupted requests can leave abandoned output behind. LuxQuote schedules `app:prune-generated-pdfs` for 23 minutes past every hour with overlap protection.

Install the standard Laravel scheduler entry once in root's crontab:

```cron
* * * * * cd /home/tamliteco/luxquote.app && docker compose exec -T laravel.test php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

The cleanup removes recognised generated files from `legal-merge-outputs`, `datasheet-merge-outputs`, and `document-pack-outputs` after 24 hours; prepared download PDFs/ZIPs after 60 minutes; and abandoned UUID working directories after 24 hours. It never scans or removes persistent uploads in `storage/app/private/document-packs`.

Preview the eligible files without deleting anything:

```bash
docker compose exec -T laravel.test php artisan app:prune-generated-pdfs --dry-run
```

Run the cleanup immediately when required:

```bash
docker compose exec -T laravel.test php artisan app:prune-generated-pdfs
```

The default retention periods can be overridden with `GENERATED_PDF_OUTPUT_RETENTION_HOURS`, `GENERATED_PDF_DOWNLOAD_RETENTION_MINUTES`, and `GENERATED_PDF_TEMP_RETENTION_HOURS` before rebuilding the configuration cache.

## Docker Disk Cleanup

Docker build cache and old images can consume significant disk space on the VPS. The deploy script prunes build cache older than 24 hours after successful deploys, and `scripts/production-docker-cleanup.sh` can be run manually or from cron for broader safe cleanup.

The cleanup script prunes:

- unused Docker build cache older than 24 hours
- unused Docker images older than 24 hours
- stopped containers older than 24 hours

It deliberately does **not** prune Docker volumes, because the MySQL database lives in a Docker volume.

Manual run:

```bash
cd /home/tamliteco/luxquote.app
bash scripts/production-docker-cleanup.sh
```

Suggested weekly cron entry:

```cron
30 3 * * 0 cd /home/tamliteco/luxquote.app && bash scripts/production-docker-cleanup.sh >> /var/log/luxquote-docker-cleanup.log 2>&1
```

## SFTP Fallback Deployment Checklist

Normal production deployment is GitHub Actions from the `production` branch. Use SFTP only as a fallback when GitHub deploys are unavailable. Before running migrations for a structural fallback release, take a database backup:

```bash
mkdir -p backups
docker compose exec -T mysql mysqldump -u sail -ppassword --single-transaction --routines --triggers --no-tablespaces laravel > backups/pre-deploy-$(date +%Y%m%d-%H%M%S).sql
```

If SFTP cannot write because the app files are owned by the container user, temporarily hand ownership to the cPanel/SFTP user before uploading:

```bash
chown -R tamliteco:tamliteco /home/tamliteco/luxquote.app
```

After uploading files, hand ownership back to the container's actual `sail` user from inside the running app container. Do not hardcode a numeric UID/GID; Sail can remap the user at runtime.

```bash
docker compose exec laravel.test chown -R sail:sail /var/www/html
docker compose exec laravel.test rm -rf /var/www/html/node_modules/.vite-temp
```

Then remove Vite's local dev-server marker before building assets:

```bash
rm -f public/hot
docker compose exec -u sail laravel.test npm install
docker compose exec -u sail laravel.test npm run build
```

Never deploy `public/hot` to production. If it exists, Laravel will try to load assets from `http://localhost:5173`, causing missing styles or CORS errors for users.

Run production migrations and clear caches through Docker Compose:

```bash
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test php artisan optimize:clear
```

## PDF Runtime

PDF generation uses Spatie Laravel PDF / Browsershot, which requires Node.js, Puppeteer, and a headless Chrome binary inside the `laravel.test` container. `compose.yaml` sets `LARAVEL_PDF_TEMP_PATH=/var/www/html/storage/app/browsershot` and `PUPPETEER_CACHE_DIR=/home/sail/.cache/puppeteer`; the latter is backed by the `sail-puppeteer` named volume so the browser cache survives normal container recreation.

Document-pack generation additionally uses the system `qpdf` binary to validate uploaded PDFs and merge uploaded/generated documents. The application now builds Sail from the project-owned `docker/8.5/Dockerfile` (rather than the runtime under `vendor/`), and that image installs `qpdf`. `compose.yaml` also uses the project-owned `docker/mysql/create-testing-database.sh`.

After deploying these Docker/Compose changes, rebuild and recreate the application container before running migrations:

```bash
docker compose build --no-cache laravel.test
docker compose up -d laravel.test
docker compose exec laravel.test qpdf --version
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test php artisan optimize:clear
```

Do not install `qpdf` manually only in a running container; that change would be lost the next time the container is rebuilt.

The production deploy script creates the Browsershot temp directory, fixes Puppeteer cache ownership, installs the Puppeteer `chrome-headless-shell` browser, and runs `app:diagnose-pdf-environment` before migrations. If PDFs fail after a manual container rebuild, run npm and Puppeteer install/update commands as the `sail` user. Running them as root can create permission problems for the web process.

```bash
docker compose exec laravel.test sh -lc 'mkdir -p /var/www/html/storage/app/browsershot /home/sail/.cache/puppeteer && chown -R sail:sail /var/www/html/storage/app/browsershot /home/sail/.cache'
docker compose exec -u sail laravel.test npm install
docker compose exec -u sail laravel.test npx puppeteer browsers install chrome-headless-shell
docker compose exec laravel.test php artisan optimize:clear
docker compose exec laravel.test php artisan app:diagnose-pdf-environment
```

If PDFs fail in production, check `storage/logs/laravel.log` for Browsershot errors. A common failure is:

```text
Error: Cannot find module 'puppeteer'
```

That means the container cannot find the Node dependency required by Browsershot. Re-run the npm/Puppeteer commands above inside the `laravel.test` container. If the error is `Could not find Chrome` or `mkdir(): Invalid path`, verify `LARAVEL_PDF_TEMP_PATH`, `PUPPETEER_CACHE_DIR`, and rerun `app:diagnose-pdf-environment`.

### PDF Incident Checklist

Use this checklist before changing code when production PDF downloads return HTTP 500:

```bash
cd /home/tamliteco/luxquote.app
docker compose exec laravel.test php artisan config:show laravel-pdf.browsershot.temp_path
docker compose exec laravel.test sh -lc 'ls -ld /var/www/html/storage/app/browsershot /home/sail/.cache /home/sail/.cache/puppeteer || true'
docker compose exec laravel.test qpdf --version
docker compose exec laravel.test php artisan app:diagnose-pdf-environment
docker compose exec laravel.test tail -n 120 storage/logs/laravel.log
```

Known production failures and fixes:

- `mkdir(): Invalid path` means the Browsershot temp path is empty or cached incorrectly. Set `LARAVEL_PDF_TEMP_PATH=/var/www/html/storage/app/browsershot`, then run `docker compose exec laravel.test php artisan optimize:clear`.
- `Could not find Chrome ... /root/.cache/puppeteer` means Puppeteer is looking in the wrong user/cache or the browser cache was not installed for the web runtime. Keep `PUPPETEER_CACHE_DIR=/home/sail/.cache/puppeteer`, ensure `/home/sail/.cache` is owned by `sail:sail`, and run Puppeteer install as the `sail` user.
- `Cannot find module 'puppeteer'` means npm dependencies are missing inside the container. Run `docker compose exec -u sail laravel.test npm install`.

Recovery commands:

```bash
docker compose exec laravel.test sh -lc 'mkdir -p /var/www/html/storage/app/browsershot /home/sail/.cache/puppeteer && chown -R sail:sail /var/www/html/storage/app/browsershot /home/sail/.cache'
docker compose exec -u sail laravel.test npm install
docker compose exec -u sail laravel.test npx puppeteer browsers install chrome-headless-shell
docker compose exec laravel.test php artisan optimize:clear
docker compose exec laravel.test php artisan app:diagnose-pdf-environment
```

`app:diagnose-pdf-environment` proves the base Browsershot runtime only. It does not prove the full quote/schedule path, qpdf merge, standard legal page, datasheet endpoint, or Salesforce side effects. If user-facing PDFs are still failing after diagnostics pass, inspect `storage/logs/laravel.log` around the failing request and test the exact document type that failed.

If document-pack uploads or generation fail, verify `qpdf --version` in the container and check `storage/logs/laravel.log`. Uploaded files are checked with `qpdf --check`; corrupt, encrypted, or unsupported PDFs are intentionally rejected.

Optional document-pack environment overrides are:

```dotenv
QPDF_BINARY=qpdf
DOCUMENT_PACK_DISK=local
DOCUMENT_PACK_MAX_UPLOAD_KB=25600
DOCUMENT_PACK_PROCESS_TIMEOUT=60
```

Datasheet-inclusive quote/schedule PDFs also require the datasheet endpoint configuration in `config/services.php` / `.env`. The app posts to the legacy Tamlite endpoint, downloads the generated datasheet PDF from the public merge directory, then appends it after the generated quote/schedule PDF with `qpdf`.

The legacy datasheet endpoint streams JSON progress chunks while it works. The app stores those progress messages temporarily in cache for the authenticated user's browser to poll through `/pdf-progress/{token}`.

Browser-driven PDF opens/downloads use prepared authenticated URLs under `/pdf-downloads/{token}/{filename}` rather than blob URLs. Prepared files live in `storage/app/pdf-downloads`, are user-scoped, are reusable for 10 minutes, and are cleaned opportunistically after 30 minutes. They should not be treated as permanent generated-output storage.

## Activity History Retention

Global and project output History now retain only the most recent three months by default. Older rows are permanently deleted; there is no archive page or ongoing archive process. Adjust the window in production only when required:

```dotenv
ACTIVITY_LOG_RETENTION_MONTHS=3
```

After changing this value, clear Laravel's cached configuration as part of the normal deployment/configuration workflow. The value is clamped to a minimum of one month.

The deployment clears stale configuration caches before migrations, displays the effective retention configuration, then runs the retention command once after the forward migrations and protected-table checks while maintenance mode and the fresh full database backup are still in place. This makes the transition from the legacy archive immediate and means the only intentional production row deletion during deployment is expired activity history. The Laravel scheduler then runs the same command daily at 01:41 in bounded batches. The existing once-per-minute `schedule:run` cron is all that is required. A production-safe preview is available before allowing any manual deletion:

```bash
cd /home/tamliteco/luxquote.app
docker compose exec -T laravel.test php artisan app:prune-activity-logs --dry-run
```

To run the same scheduled cleanup immediately:

```bash
cd /home/tamliteco/luxquote.app
docker compose exec -T laravel.test php artisan app:prune-activity-logs
```

On its first successful run after this release, the command safely moves any still-retained rows from the legacy archive table back into live History, discards expired archive rows, and leaves the legacy table empty for rollback compatibility. Missing user/project relationships are nulled while their snapshot labels are preserved. If the old standalone `app:archive-activity-logs` cron was installed, remove that cron entry; the command no longer exists.

Quote status is stored independently on each project revision before pruning, so deleting old audit rows cannot downgrade a previously quoted project. This command does not restore, reset, truncate, or prune Docker volumes.

## Production Monitoring

Production should have two separate monitors:

1. An external uptime monitor for `https://quote.tamlite.co.uk`.
2. A cron heartbeat monitor for the deeper Docker, database, storage, cache, qpdf, Browsershot, and legal-page merge checks.

The production-safe health command is:

```bash
cd /home/tamliteco/luxquote.app
docker compose exec -T -u sail laravel.test php artisan app:production-health-check
```

It is safe to run unattended because it does not create projects, mutate business data, upload to Salesforce, or run tests with `RefreshDatabase`. It checks app boot, database connectivity, cache, storage writability, the standard legal PDF, `qpdf`, a tiny Browsershot render, and merging that generated PDF with the legal page.

The cron wrapper is:

```bash
cd /home/tamliteco/luxquote.app
bash scripts/production-health-check.sh
```

Set `HEALTHCHECK_PING_URL` in the cron environment to a heartbeat URL from a monitoring provider. The script pings `/start` before checks, the base URL on success, and `/fail` on failure. This URL is secret and should not be committed.

Suggested cron entry:

```cron
*/5 * * * * cd /home/tamliteco/luxquote.app && HEALTHCHECK_PING_URL="https://example-heartbeat-url" bash scripts/production-health-check.sh >> storage/logs/health-check.log 2>&1
```

If an alert fires, inspect the latest health log and Laravel log:

```bash
cd /home/tamliteco/luxquote.app
tail -n 120 storage/logs/health-check.log
docker compose exec -T laravel.test tail -n 120 storage/logs/laravel.log
docker compose ps
```

Good external monitoring options:

- **Better Stack**: simple uptime checks plus heartbeat monitors, with email, Slack, Teams, phone/SMS-style incident options depending on plan. Its heartbeat URLs support `/fail`.
- **Healthchecks.io**: excellent lightweight cron/dead-man monitoring. Free tier is generous for heartbeat checks, supports `/start`, `/fail`, and exit-code URLs, and can alert through integrations/webhooks.
- **UptimeRobot**: simple external uptime monitoring and webhook/email alerting. Good for the public `https://quote.tamlite.co.uk` monitor; less focused than Healthchecks.io for cron health.
- **Oh Dear**: Laravel-friendly hosted monitoring from the Spatie ecosystem, with uptime, SSL, broken-link, and cron heartbeat monitoring. Paid, but polished.

### ntfy PDF Alerts

If phone push notifications are handled through ntfy, use the dedicated PDF health wrapper:

```bash
cd /home/tamliteco/luxquote.app
bash scripts/production-pdf-health-check-ntfy.sh
```

By default it posts failures to:

```text
https://ntfy.sh/LuxQuotePdfs
```

Override the topic URL without editing the script:

```bash
NTFY_URL="https://ntfy.sh/LuxQuotePdfs" bash scripts/production-pdf-health-check-ntfy.sh
```

The script runs only the PDF health checks with `app:production-health-check --pdf-only`. It sends no notification on success, deletes the temporary merged PDF created by the health command, and does not create projects, activity logs, Salesforce uploads, or persistent output PDFs.

Suggested cron entry:

```cron
17 * * * * cd /home/tamliteco/luxquote.app && NTFY_URL="https://ntfy.sh/LuxQuotePdfs" bash scripts/production-pdf-health-check-ntfy.sh >/dev/null 2>&1
```

Run this hourly. It is intentionally heavier than a simple HTTP check because it launches headless Chrome and validates/merges PDFs with `qpdf`. Keep a separate external uptime monitor for `https://quote.tamlite.co.uk` every 1-5 minutes.

### ntfy Login Alerts

Use the login health wrapper to check the public login page through DNS, SSL, Apache, the reverse proxy, and Laravel:

```bash
bash scripts/production-login-health-check-ntfy.sh
```

By default it requests:

```text
https://quote.tamlite.co.uk/login
```

and fails unless the returned page contains:

```text
LuxQuote
```

Failures are posted to:

```text
https://ntfy.sh/LuxQuoteLogin
```

Suggested cron entry:

```cron
*/10 * * * * cd /home/tamliteco/luxquote.app && NTFY_URL="https://ntfy.sh/LuxQuoteLogin" bash scripts/production-login-health-check-ntfy.sh >/dev/null 2>&1
```

This is intentionally lightweight and can run every 10 minutes. It sends no notification on success.
To reduce false positives from brief proxy/network blips, the script now fails only after `LOGIN_HEALTH_RETRIES` attempts, defaulting to 3 attempts with `LOGIN_HEALTH_RETRY_DELAY_SECONDS=20` between attempts.

### ntfy Disk, Docker, Database, Salesforce, and Runner Alerts

These focused wrappers are also available:

| Topic | Script | What it checks | Suggested cadence |
|---|---|---|---|
| `LuxQuoteDisk` | `scripts/production-disk-health-check-ntfy.sh` | Host filesystem and inode usage for the app path, `/`, and `/var/lib/docker` when present | Every 15 minutes |
| `LuxQuoteDocker` | `scripts/production-docker-health-check-ntfy.sh` | Core Docker Compose services are running, MySQL responds, Redis responds | Every 10 minutes |
| `LuxQuoteDatabase` | `scripts/production-database-health-check-ntfy.sh` | MySQL responds and Laravel can run a `select 1` query, with retry protection against short transient failures | Every 10 minutes |
| `LuxQuoteSalesforce` | `scripts/production-salesforce-health-check-ntfy.sh` | Read-only Salesforce auth/API smoke using `salesforce:interrogate --limit=1 --format=json` | Hourly |
| `LuxQuoteRunner` | `scripts/production-github-runner-health-check-ntfy.sh` | Deployment runner container, listener process, persistent registration, and SSH mounts | Every 10 minutes |

Suggested cron entries:

```cron
*/15 * * * * cd /home/tamliteco/luxquote.app && NTFY_URL="https://ntfy.sh/LuxQuoteDisk" bash scripts/production-disk-health-check-ntfy.sh >/dev/null 2>&1
*/10 * * * * cd /home/tamliteco/luxquote.app && NTFY_URL="https://ntfy.sh/LuxQuoteDocker" bash scripts/production-docker-health-check-ntfy.sh >/dev/null 2>&1
*/10 * * * * cd /home/tamliteco/luxquote.app && NTFY_URL="https://ntfy.sh/LuxQuoteDatabase" bash scripts/production-database-health-check-ntfy.sh >/dev/null 2>&1
23 * * * * cd /home/tamliteco/luxquote.app && NTFY_URL="https://ntfy.sh/LuxQuoteSalesforce" bash scripts/production-salesforce-health-check-ntfy.sh >/dev/null 2>&1
*/10 * * * * cd /home/tamliteco/luxquote.app && NTFY_URL="https://ntfy.sh/LuxQuoteRunner" bash scripts/production-github-runner-health-check-ntfy.sh >/dev/null 2>&1
```

Disk thresholds default to 85% for both disk space and inodes. Override them in cron if needed:

```cron
*/15 * * * * cd /home/tamliteco/luxquote.app && DISK_THRESHOLD_PERCENT=80 INODE_THRESHOLD_PERCENT=80 NTFY_URL="https://ntfy.sh/LuxQuoteDisk" bash scripts/production-disk-health-check-ntfy.sh >/dev/null 2>&1
```

The Docker check expects these Compose services by default:

```text
laravel.test mysql redis meilisearch
```

Override with `EXPECTED_SERVICES` if production service names change. The Docker check retries before alerting to avoid false positives during deploys or brief container recreates; defaults are `DOCKER_HEALTH_RETRIES=3` and `DOCKER_HEALTH_RETRY_DELAY_SECONDS=20`. The Salesforce check is read-only and does not push PDFs or update Opportunity Amounts.

## Database Restore Workflow

When restoring a raw SQL backup into the containerized MySQL service, use this exact sequence to avoid duplicate or stray table errors:

```bash
docker compose exec laravel.test php artisan db:wipe
docker compose exec -T mysql mysql -u sail -ppassword laravel < backup.sql
docker compose exec laravel.test php artisan migrate --force
```

For a gzipped SQL backup already placed in the app root as `backup.gz`, use:

```bash
cd /home/tamliteco/luxquote.app
docker compose exec laravel.test sh -lc 'ls -lh /var/www/html/backup.gz && gzip -t /var/www/html/backup.gz'
docker compose exec laravel.test php artisan optimize:clear
docker compose exec laravel.test php artisan db:wipe --force
docker compose exec -T laravel.test sh -lc 'gzip -dc /var/www/html/backup.gz' | docker compose exec -T mysql mysql -u sail -ppassword laravel
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test php artisan optimize:clear
```

If restoring locally with Sail from `/home/tqdeanp/development/company-app`, the equivalent import command is:

```bash
vendor/bin/sail exec -T laravel.test sh -lc 'gzip -dc /var/www/html/backup.gz' | vendor/bin/sail mysql laravel
```

## Deployment Method

Code can be deployed automatically from GitHub by pushing the `production` branch. The workflow in `.github/workflows/deploy-production.yml` runs on the `luxquote-production` self-hosted GitHub Actions runner on the VPS and executes `scripts/deploy-production.sh` against the production checkout.

The local `./deploy-production` helper bumps the tracked app version in `VERSION`, commits that bump on `main`, pushes `main`, fast-forwards `production`, and pushes `production`. By default it increments the patch version, for example `0.2.1` to `0.2.2`. Use `VERSION_BUMP=minor`, `VERSION_BUMP=major`, `VERSION_BUMP=beta`, or `VERSION_BUMP=none` when a deploy needs a different version bump.

The deploy script:

- starts Docker services so the database is available
- enables Laravel maintenance mode before taking the pre-deploy backups, preventing user writes after the rollback snapshot point; users receive Laravel's HTTP 503 maintenance response during the deployment
- creates a compressed full pre-deploy MySQL backup in `/home/tamliteco/luxquote.app/backups`
- creates a single rolling data-only backup of protected business tables at `backups/latest-protected-data-restore.sql.gz` and records their pre-deploy row counts
- writes `backups/deploy-manifest-pending.env` after the backup is created, recording the previous commit and the backup paths for emergency rollback if the deploy fails mid-run
- fetches and checks out `origin/production`
- rebuilds/recreates Docker services with `docker compose up -d --build`
- removes `public/hot`
- fixes container-side ownership using `sail:sail`
- installs Composer dependencies
- installs/builds npm assets as the `sail` user
- verifies `qpdf`
- verifies the PDF runtime with `app:diagnose-pdf-environment`
- runs all pending migrations with `php artisan migrate --force --no-interaction`, then prints `migrate:status` in the deploy log
- checks protected business table row counts after migrations; if all previously-populated protected tables are empty, it restores the data-only backup into the migrated schema and fails the deploy so the incident is visible
- clears/rebuilds Laravel caches
- disables maintenance mode before the public smoke check; an exit trap also attempts to return the app to normal mode if any deploy step fails unexpectedly
- smoke-checks `https://quote.tamlite.co.uk`
- writes `backups/deploy-manifest-latest.env` after a successful smoke check, then removes the pending manifest
- prunes Docker build cache older than 24 hours
- prunes DB backups older than 14 days

The deploy data-loss guard is intentionally conservative. It only auto-restores when protected business data has catastrophically disappeared from every previously-populated protected table. If only some protected tables look emptied, the deploy stops and leaves the full backup plus `backups/latest-protected-data-restore.sql.gz` in place for manual inspection rather than risking duplicate or mixed-state rows. The rolling data-only restore file is replaced on each deploy, so only one `latest-protected-data-restore.sql.gz` should exist at a time. If the migrated-schema data restore fails, the deploy log explains that the new migrations likely changed table or column structures in a way that needs a custom manual recovery from the full backup. The protected table list can be overridden with `PROTECTED_DATA_TABLES`; automatic catastrophic restore can be disabled with `RESTORE_ON_CATASTROPHIC_DATA_LOSS=false`.

The GitHub workflow fetches the incoming `production` revision and executes that revision's deploy script from a temporary file. This ensures deployment-safety improvements in a release, including maintenance mode, are active for that same release without checking out the new application code before the pre-deploy backup. The script writes `storage/framework/luxquote-deploy-maintenance` only when it owns the maintenance state. An `if: always()` GitHub cleanup step uses that marker to recover from abrupt deploy interruption without clearing maintenance mode that was enabled manually. If the site was already manually placed in maintenance mode before the deploy began, the script preserves that state and skips the public smoke check rather than unexpectedly bringing the site online.

### Production Rollback

Use `scripts/rollback-production.sh` from the production checkout when a deploy needs to be undone quickly. The default path is code-only and does not touch the database:

```bash
cd /home/tamliteco/luxquote.app
bash scripts/rollback-production.sh
```

That reads `backups/deploy-manifest-latest.env`, checks out the commit that was live immediately before the last successful deploy, rebuilds the app containers, reinstalls Composer/npm dependencies, clears/rebuilds Laravel caches, and smoke-checks `https://quote.tamlite.co.uk`. It does not remove Docker volumes and does not restore the database.

If the most recent deploy failed before completion, use the pending manifest created immediately after the pre-deploy backup:

```bash
cd /home/tamliteco/luxquote.app
bash scripts/rollback-production.sh --manifest backups/deploy-manifest-pending.env
```

Only restore the database when code rollback alone is not enough and losing all post-deploy data changes is acceptable:

```bash
cd /home/tamliteco/luxquote.app
bash scripts/rollback-production.sh --with-database
```

The database restore path requires typing `RESTORE` unless `--yes` is passed. It streams the full pre-deploy backup from the selected manifest into the Docker `mysql` service, then clears/rebuilds Laravel caches and smoke-checks the public URL. If the restore fails, the script prints the backup path and explains that the dump may not be a valid full MySQL restore.

Useful overrides:

```bash
bash scripts/rollback-production.sh --commit <sha>
bash scripts/rollback-production.sh --with-database --backup backups/pre-deploy-YYYYMMDD-HHMMSS.sql.gz
bash scripts/rollback-production.sh --manifest backups/deploy-manifest-pending.env --with-database
```

After a successful rollback the script writes `backups/rollback-latest.env` plus a timestamped `backups/rollback-YYYYMMDD-HHMMSS.env` record.

### One-Time Server Setup

Production must be a git checkout of `git@github.com:codenchips/LuxQuote.git`. Keep `.env`, `storage/`, and `backups/` out of git. The server also needs SSH access to read the GitHub repo, usually via a read-only deploy key.

The VPS needs SSH access to read the GitHub repo. Create a read-only deploy key on the VPS, add the public key to the GitHub repository's deploy keys, and make sure `git fetch origin production` works from `/home/tamliteco/luxquote.app`.

Because the VPS firewall restricts inbound SSH, deployment uses a self-hosted runner rather than GitHub-hosted runners. The runner runs in Docker as `luxquote-github-runner`, connects outbound to GitHub, and has the host Docker socket mounted so it can run the normal Docker Compose deployment commands.

The runner must be installed with the checked-in setup script:

```bash
cd /home/tamliteco/luxquote.app
bash scripts/setup-production-github-runner.sh
```

The script creates separate persistent directories under `/opt/actions-runner/luxquote-production`:

- `work` for the Actions working directory.
- `config` for `.runner` and credential files required to reuse the registration after a container or Docker restart.
- `ssh` for the read-only repository deploy key, SSH configuration, and `known_hosts`.

It preserves working SSH files from an existing runner before replacing that runner container. On the first persistent installation it prompts, without echoing, for a fresh repository runner registration token. On later container recreations it reuses the persisted registration and does not need a new token. It removes only the `luxquote-github-runner` container and does not run Docker Compose, remove volumes, restore the database, or modify application data.

Before the first persistent installation:

1. Confirm no deployment job is currently running.
2. In GitHub, open **LuxQuote → Settings → Actions → Runners**, remove the existing `luxquote-production` registration, choose **New self-hosted runner**, and copy only the fresh token value shown after `--token`.
3. Confirm the currently working runner or VPS host has `/root/.ssh/luxquote_github_repo_deploy` and `known_hosts`. The script copies them into persistent storage before removing the container.
4. Run the setup script and paste the fresh token when prompted.

After setup, verify:

```bash
docker ps --filter name=luxquote-github-runner
docker logs --tail=80 luxquote-github-runner
bash scripts/production-github-runner-health-check-ntfy.sh
```

Healthy runner logs include `Listening for Jobs`. The health check verifies the container is running rather than restart-looping, `Runner.Listener` is active, and the persistent registration and SSH files are mounted.

Do not run the official GitHub runner directly on the CentOS 7 host; the host `libstdc++` is too old for the current runner binary.

### Runner Incident Checklist

If GitHub Actions shows `Waiting for a runner to pick up this job...`, check the runner container first:

```bash
docker ps --filter name=luxquote-github-runner
docker logs --tail=120 luxquote-github-runner
bash scripts/production-github-runner-health-check-ntfy.sh
```

Healthy logs should end with:

```text
Listening for Jobs
```

The workflow requires labels `self-hosted` and `luxquote-production`. The runner container should be named `luxquote-github-runner`, use `RUNNER_NAME="luxquote-production"`, and include the `luxquote-production` label.

If the job is picked up but deploy fails while fetching GitHub:

```bash
docker exec luxquote-github-runner sh -lc 'id; echo HOME=$HOME; ls -la ~/.ssh || true'
docker exec luxquote-github-runner sh -lc 'ssh -T git@github.com || true'
```

The runner image runs the deploy as root, so Git/SSH looks under `/root/.ssh` inside the runner container. That path is mounted read-only from `/opt/actions-runner/luxquote-production/ssh`. Ensure:

- `/root/.ssh/known_hosts` contains GitHub host keys.
- `/root/.ssh/luxquote_github_repo_deploy` exists inside the runner container.
- the private key is `chmod 600`, owned by root, and the corresponding public key is registered as a read-only deploy key on `codenchips/LuxQuote`.
- `/home/tamliteco/luxquote.app` is mounted into the runner at `/home/tamliteco/luxquote.app`, matching the workflow/deploy script path.

If the runner must be recreated, run `bash scripts/setup-production-github-runner.sh`. A new token is needed only when `/opt/actions-runner/luxquote-production/config/.runner` is missing or GitHub registration was manually removed. Do not remove `/opt/actions-runner/luxquote-production`, application files, or Docker volumes.

If converting the existing SFTP directory, take a database backup and preserve `.env`, `storage/`, and `backups/` before replacing the working tree with a clean clone. Do not delete the Docker MySQL volume.

After the production app directory is a git checkout, verify the deploy script manually before relying on GitHub Actions:

```bash
cd /home/tamliteco/luxquote.app
APP_DIR=/home/tamliteco/luxquote.app DEPLOY_BRANCH=production PUBLIC_URL=https://quote.tamlite.co.uk bash scripts/deploy-production.sh
```

### GitHub Secrets

Configure these repository or environment secrets in GitHub:

| Secret | Purpose |
|---|---|
| `PRODUCTION_URL` | Optional smoke-check URL, defaults to `https://quote.tamlite.co.uk` |

Manual SFTP deployment should now be treated as a fallback only.

After deploying code that changes PHP config, routes, views, migrations, dependencies, frontend assets, Dockerfiles, or Compose configuration, run the relevant Docker Compose commands above. The document-pack release requires the three new migrations for pack tables and permissions, plus a rebuilt `laravel.test` image containing `qpdf`.
