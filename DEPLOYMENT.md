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

## SFTP Deployment Checklist

Code is currently synced to the VPS via SFTP. Before running migrations for a structural release, take a database backup:

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

PDF generation uses Spatie Laravel PDF / Browsershot, which requires Node.js, Puppeteer, and a headless Chrome binary inside the `laravel.test` container.

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

Run npm and Puppeteer install/update commands as the `sail` user. Running them as root can create permission problems for the web process.

```bash
docker compose exec -u sail laravel.test npm install
docker compose exec -u sail laravel.test npx puppeteer browsers install chrome-headless-shell
docker compose exec laravel.test php artisan optimize:clear
docker compose exec laravel.test php artisan app:diagnose-pdf-environment
```

If PDFs fail in production, check `storage/logs/laravel.log` for Browsershot errors. A common failure is:

```text
Error: Cannot find module 'puppeteer'
```

That means the container cannot find the Node dependency required by Browsershot. Re-run the npm/Puppeteer commands above inside the `laravel.test` container.

If document-pack uploads or generation fail, verify `qpdf --version` in the container and check `storage/logs/laravel.log`. Uploaded files are checked with `qpdf --check`; corrupt, encrypted, or unsupported PDFs are intentionally rejected.

Optional document-pack environment overrides are:

```dotenv
QPDF_BINARY=qpdf
DOCUMENT_PACK_DISK=local
DOCUMENT_PACK_MAX_UPLOAD_KB=25600
DOCUMENT_PACK_PROCESS_TIMEOUT=60
```

## Database Restore Workflow

When restoring a raw SQL backup into the containerized MySQL service, use this exact sequence to avoid duplicate or stray table errors:

```bash
docker compose exec laravel.test php artisan db:wipe
docker compose exec -T mysql mysql -u sail -ppassword laravel < backup.sql
docker compose exec laravel.test php artisan migrate --force
```

## Deployment Method

Code can be deployed automatically from GitHub by pushing the `production` branch. The workflow in `.github/workflows/deploy-production.yml` connects to the VPS over SSH and runs `scripts/deploy-production.sh`.

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
- runs migrations with `--force`
- clears/rebuilds Laravel caches
- smoke-checks `https://quote.tamlite.co.uk`
- prunes DB backups older than 14 days

### One-Time Server Setup

Production must be a git checkout of `git@github.com:codenchips/LuxQuote.git`. Keep `.env`, `storage/`, and `backups/` out of git. The server also needs SSH access to read the GitHub repo, usually via a read-only deploy key.

There are two SSH links to set up:

1. GitHub Actions -> VPS: create a private key for GitHub Actions, add the public key to the VPS user's `~/.ssh/authorized_keys`, and save the private key as `PRODUCTION_SSH_KEY`.
2. VPS -> GitHub: create a read-only deploy key on the VPS, add the public key to the GitHub repository's deploy keys, and make sure `git fetch origin production` works from `/home/tamliteco/luxquote.app`.

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
| `PRODUCTION_SSH_HOST` | VPS hostname or IP |
| `PRODUCTION_SSH_USER` | SSH user, for example `root` |
| `PRODUCTION_SSH_KEY` | Private key GitHub Actions uses to SSH into the VPS |
| `PRODUCTION_SSH_PORT` | Optional SSH port, defaults to `22` |
| `PRODUCTION_DEPLOY_PATH` | Optional app path, defaults to `/home/tamliteco/luxquote.app` |
| `PRODUCTION_URL` | Optional smoke-check URL, defaults to `https://quote.tamlite.co.uk` |

Manual SFTP deployment should now be treated as a fallback only.

After deploying code that changes PHP config, routes, views, migrations, dependencies, frontend assets, Dockerfiles, or Compose configuration, run the relevant Docker Compose commands above. The document-pack release requires the three new migrations for pack tables and permissions, plus a rebuilt `laravel.test` image containing `qpdf`.
