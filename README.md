# LuxQuote

A Laravel + Filament application for managing quotes and projects.

## Current Project Workflow

- Projects are built from Areas and schedule lines, with the Filament panel served at `/`.
- Schedule lines can be added from the product picker or pasted from tab-delimited pricing data.
- Pasted pricing can update one Area or all Areas in the active revision, marking lines as `Priced` or `Unpriced`.
- Admin validation separates unresolved **Issues** from **Validated** lines.
- Running validation marks a revision ready to approve; clicking **Approve Revision** locks the revision.
- Salesforce project creation is the default create mode and can populate reference, customer, owner email, cover, and value from the selected Opportunity.

## Stack

- PHP 8.5 / Laravel 13
- Filament v5 (admin panel)
- Livewire v4
- Tailwind CSS v4
- Laravel Sail (Docker)

## Getting Started

```bash
vendor/bin/sail up -d
vendor/bin/sail artisan migrate --seed
vendor/bin/sail npm run build
```

Open the app: `vendor/bin/sail open`

## Daily Development

### Start everything

```bash
vendor/bin/sail up -d
vendor/bin/sail npm run dev   # optional: enables hot-reload for CSS/JS changes
```

### Stop

```bash
vendor/bin/sail stop
```

## Production Deployment

Production runs on a cPanel / WHM VPS inside Docker Compose / Laravel Sail containers. Do not run bare PHP, Artisan, Composer, or npm commands on the host server; execute them through Docker Compose against the `laravel.test` container.

See [DEPLOYMENT.md](DEPLOYMENT.md) for the production command map, reverse proxy notes, database restore workflow, and PDF/Puppeteer requirements.

## Frontend Assets (CSS / JS)

This project uses Vite with Tailwind CSS v4. Custom styles are loaded alongside Filament's own CSS via `->assets()` in `AdminPanelProvider`.

### Two workflows:

**Option A — `npm run dev` (recommended while actively writing CSS/blade)**
- Vite serves assets from `localhost:5173` with hot-reload
- Changes appear instantly without rebuilding
- When you stop the dev server the `public/hot` file is cleaned up automatically

**Option B — `npm run build` (use before committing / sharing / after stopping dev)**
```bash
vendor/bin/sail npm run build
```
Compiles and fingerprints assets into `public/build/`. Required for production.

### Page looks unstyled after a restart?

The `public/hot` file was likely left behind by a previously crashed dev server, causing Vite to try loading assets from `localhost:5173` (which isn't running). Fix:

```bash
rm -f public/hot && vendor/bin/sail npm run build
```

## Running Tests

```bash
vendor/bin/sail artisan test --compact
```

Filter to a single test:

```bash
vendor/bin/sail artisan test --compact --filter=testName
```

## Useful Commands

| Task | Command |
|------|---------|
| Run migrations | `vendor/bin/sail artisan migrate` |
| Fresh migration + seed | `vendor/bin/sail artisan migrate:fresh --seed` |
| Clear all caches | `vendor/bin/sail artisan config:clear && vendor/bin/sail artisan view:clear` |
| List routes | `vendor/bin/sail artisan route:list --except-vendor` |
| Fix code style | `vendor/bin/sail bin pint --dirty` |
| Tinker | `vendor/bin/sail artisan tinker` |
