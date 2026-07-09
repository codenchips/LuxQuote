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

The default bump is the beta suffix, for example `0.1.0-beta.1` to `0.1.0-beta.2`.

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

Install it manually into the cPanel CGI directory only when the emergency web trigger is required. Keep the live reset secret out of git. Either set `LUXQUOTE_RESET_KEY` in the CGI environment, or replace the `CHANGE_ME_ON_THE_SERVER` placeholder only in the deployed CGI copy.

The CGI confirmation page shows the newest `backups/*.sql.gz` file, including its modified date/time and size. It requires the operator to type `dean`, then choose one of:

- Restart containers only; do not restore the database.
- Restart containers and restore the latest backup if recovery is still unhealthy.

The CGI calls `emergency_recover.sh` with `LUXQUOTE_AUTO_DB_RESTORE=0` or `1`. `emergency_recover.sh` preserves Docker volumes and only attempts a DB restore when that flag is enabled and the post-recreate health check still fails.

After copying the CGI file, ensure ownership and mode match the host cPanel setup:

```bash
chown tamliteco:tamliteco /path/to/cgi-bin/reset-app.cgi
chmod 755 /path/to/cgi-bin/reset-app.cgi
```

Do not expose the CGI URL without the secret key. The script also enforces a five-minute cooldown with `/tmp/luxquote_reset.lock`.

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

### ntfy Disk, Docker, Database, and Salesforce Alerts

These focused wrappers are also available:

| Topic | Script | What it checks | Suggested cadence |
|---|---|---|---|
| `LuxQuoteDisk` | `scripts/production-disk-health-check-ntfy.sh` | Host filesystem and inode usage for the app path, `/`, and `/var/lib/docker` when present | Every 15 minutes |
| `LuxQuoteDocker` | `scripts/production-docker-health-check-ntfy.sh` | Core Docker Compose services are running, MySQL responds, Redis responds | Every 10 minutes |
| `LuxQuoteDatabase` | `scripts/production-database-health-check-ntfy.sh` | MySQL responds and Laravel can run a `select 1` query | Every 10 minutes |
| `LuxQuoteSalesforce` | `scripts/production-salesforce-health-check-ntfy.sh` | Read-only Salesforce auth/API smoke using `salesforce:interrogate --limit=1 --format=json` | Hourly |

Suggested cron entries:

```cron
*/15 * * * * cd /home/tamliteco/luxquote.app && NTFY_URL="https://ntfy.sh/LuxQuoteDisk" bash scripts/production-disk-health-check-ntfy.sh >/dev/null 2>&1
*/10 * * * * cd /home/tamliteco/luxquote.app && NTFY_URL="https://ntfy.sh/LuxQuoteDocker" bash scripts/production-docker-health-check-ntfy.sh >/dev/null 2>&1
*/10 * * * * cd /home/tamliteco/luxquote.app && NTFY_URL="https://ntfy.sh/LuxQuoteDatabase" bash scripts/production-database-health-check-ntfy.sh >/dev/null 2>&1
23 * * * * cd /home/tamliteco/luxquote.app && NTFY_URL="https://ntfy.sh/LuxQuoteSalesforce" bash scripts/production-salesforce-health-check-ntfy.sh >/dev/null 2>&1
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

The local `./deploy-production` helper bumps the tracked app version in `VERSION`, commits that bump on `main`, pushes `main`, fast-forwards `production`, and pushes `production`. By default it increments the beta suffix, for example `0.1.0-beta.1` to `0.1.0-beta.2`. Use `VERSION_BUMP=patch`, `VERSION_BUMP=minor`, `VERSION_BUMP=major`, or `VERSION_BUMP=none` when a deploy needs a different version bump.

The deploy script:

- starts Docker services so the database is available
- creates a compressed pre-deploy MySQL backup in `/home/tamliteco/luxquote.app/backups`
- fetches and checks out `origin/production`
- rebuilds/recreates Docker services with `docker compose up -d --build`
- removes `public/hot`
- fixes container-side ownership using `sail:sail`
- installs Composer dependencies
- installs/builds npm assets as the `sail` user
- verifies `qpdf`
- verifies the PDF runtime with `app:diagnose-pdf-environment`
- runs migrations with `--force`
- clears/rebuilds Laravel caches
- smoke-checks `https://quote.tamlite.co.uk`
- prunes Docker build cache older than 24 hours
- prunes DB backups older than 14 days

### One-Time Server Setup

Production must be a git checkout of `git@github.com:codenchips/LuxQuote.git`. Keep `.env`, `storage/`, and `backups/` out of git. The server also needs SSH access to read the GitHub repo, usually via a read-only deploy key.

The VPS needs SSH access to read the GitHub repo. Create a read-only deploy key on the VPS, add the public key to the GitHub repository's deploy keys, and make sure `git fetch origin production` works from `/home/tamliteco/luxquote.app`.

Because the VPS firewall restricts inbound SSH, deployment uses a self-hosted runner rather than GitHub-hosted runners. The runner runs in Docker as `luxquote-github-runner`, connects outbound to GitHub, and has the host Docker socket mounted so it can run the normal Docker Compose deployment commands.

Runner container shape:

```bash
docker run -d \
  --name luxquote-github-runner \
  --restart unless-stopped \
  -e RUNNER_NAME="luxquote-production" \
  -e RUNNER_LABELS="luxquote-production" \
  -e RUNNER_WORKDIR="_work" \
  -e REPO_URL="https://github.com/codenchips/LuxQuote" \
  -e RUNNER_TOKEN="FRESH_TOKEN_FROM_GITHUB" \
  -v /opt/actions-runner/luxquote-production:/home/runner/_work \
  -v /home/tamliteco/luxquote.app:/home/runner/luxquote.app \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v /usr/bin/docker:/usr/bin/docker:ro \
  -v /usr/libexec/docker/cli-plugins:/usr/libexec/docker/cli-plugins:ro \
  myoung34/github-runner:latest
```

Do not run the official GitHub runner directly on the CentOS 7 host; the host `libstdc++` is too old for the current runner binary.

### Runner Incident Checklist

If GitHub Actions shows `Waiting for a runner to pick up this job...`, check the runner container first:

```bash
docker ps --filter name=luxquote-github-runner
docker logs --tail=120 luxquote-github-runner
```

Healthy logs should end with:

```text
Listening for Jobs
```

The workflow requires labels `self-hosted` and `luxquote-production`. The runner container should be named `luxquote-github-runner`, use `RUNNER_NAME="luxquote-production"`, and include `RUNNER_LABELS="luxquote-production"`.

If the job is picked up but deploy fails while fetching GitHub:

```bash
docker exec luxquote-github-runner sh -lc 'id; echo HOME=$HOME; ls -la ~/.ssh || true'
docker exec luxquote-github-runner sh -lc 'ssh -T git@github.com || true'
```

The runner image runs the deploy as root, so Git/SSH looks under `/root/.ssh` inside the runner container. Ensure:

- `/root/.ssh/known_hosts` contains GitHub host keys.
- `/root/.ssh/luxquote_github_repo_deploy` exists inside the runner container.
- the private key is `chmod 600`, owned by root, and the corresponding public key is registered as a read-only deploy key on `codenchips/LuxQuote`.
- `/home/tamliteco/luxquote.app` is mounted into the runner at `/home/runner/luxquote.app`, matching the workflow/deploy script path.

If a new runner token is used, remove/recreate only the runner container. Do not remove Docker volumes.

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
