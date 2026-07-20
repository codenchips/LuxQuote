# Company App — Project Status

_Last updated: 20 July 2026_

---

## Project List Defaults — 20 July 2026

- **Project list default restored to all accessible projects**: By default, the Projects table now lists all projects the user can access: Open projects, their own Private projects, and Team-scoped projects for teams they belong to.
- **Profile-controlled project list preference**: The user profile now includes **Project list view**, with **All available projects** plus one option for each team the user belongs to. Choosing a team makes the Projects table open with that Team filter applied by default.
- **Team filter behaviour preserved**: When the Projects table Team filter is active, it remains an exclusive Team filter and shows only Team-visible projects assigned to the selected team(s). Clearing it returns to all accessible projects.
- **Production rollback routines added**: Deploys now write rollback manifests in `backups/` that capture the previous commit and pre-deploy backup paths. `scripts/rollback-production.sh` can roll production back to the previous commit without touching the database, or restore the matching pre-deploy database backup only when `--with-database` is explicitly requested and confirmed.

---

## Friday UI Polish and Deploy Recovery — 17 July 2026

- **Project owner name in project headers**: Project, Validation, Output, and Project History pages now show the resolved project owner full name in brackets after the revision label, for example `P0 (Jamie Engineer)`. Salesforce projects use a cached Opportunity owner lookup; local projects fall back to matching `owner_email` to a local user.
- **Cover creation defaults adjusted**: Enabling Cover on a project now defaults Cover 1, Cover 2, and Cover 3 to `5.00`, `5.00`, and `0.00`. Salesforce-derived Cover still enables deducted Cover and uses the Salesforce Cover value for Cover 1.
- **Validation page final polish**: Validation issue rows now use issue-type icons, compact issue-type badges, aligned price/Cover fields, a softer flag action, disabled flag placeholders for already-flagged issues, and check icons for validated rows.
- **Activity log readability and copying**: Project creation and detail-change log lines now include the project name, project detail changes describe what changed from old value to new value, long action text is truncated in the table, and clicking an action line copies the full action text to the clipboard.
- **Production deploy incident captured**: A GitHub Actions runner hang and Docker iptables-chain failure were recovered without touching volumes. `DEPLOYMENT.md` now documents the volume-safe Docker firewall recovery path and the runner container mount/path requirements.

---

## Output, Team Filtering, and Production Firewall Recovery — 16 July 2026

- **CSV column order aligned with PDFs**: Both priced and unpriced schedule CSV exports now begin with `Area, Ref, Qty, Code, Description`. Existing price, type, notes, status, permission, and validation behaviour is unchanged.
- **Team filter made exclusive**: When one or more Teams are selected on the Projects table, only Team-visible projects assigned to those selected Teams are shown. Open and Private projects are hidden until the Team filter is cleared. The underlying project visibility and Team membership authorization rules are unchanged.
- **Implementation commit**: The CSV ordering and Team-filter changes are committed on `main` as `d0b5c92` (`Teams filter better and CSV Order`).
- **Focused export verification**: Priced and unpriced CSV coverage passed with 2 tests and 15 assertions.
- **Focused Team-filter verification**: Exact selected-Team filtering, cleared-filter behaviour, Team membership access, and Open/Private exclusion passed with focused coverage. The stale second-component filter-persistence expectation from the previously reported wider-suite failure was removed from this focused behaviour test.
- **Production Salesforce incident resolved without key rotation**: After the VPS deployment, JWT Salesforce requests failed with `cURL error 28: Resolving timed out`. The private key existed and was readable; host DNS resolved Salesforce while the `laravel.test` container did not, proving the failure occurred before Salesforce received the JWT assertion.
- **Docker firewall root cause**: The host's `/root/apply_iptables_rules.sh` used global `iptables -F` / `iptables -X` operations and replaced forwarding state, deleting Docker-managed chains required for bridge DNS, NAT, outbound HTTPS, and published ports. Container recreation then failed with `No chain/target/match by that name` for the missing `DOCKER` chain.
- **Production recovery**: Restarting Docker recreated its firewall chains, `docker compose up -d` restored the stack, and the host firewall script was changed to manage only a dedicated `LUXQUOTE_INPUT` chain. It no longer flushes/deletes Docker chains, changes Docker's forwarding policy, or restarts the iptables service while Docker is running. Container DNS and the Salesforce interrogator smoke test then succeeded.
- **Deployment runbook updated**: `DEPLOYMENT.md` now records the Docker/firewall diagnostic sequence, volume-safe recovery commands, and the rule that a container DNS timeout does not justify rotating the Salesforce JWT certificate.

---

## Cover Pricing, Validation, and Activity Search — 13 July 2026

- **Cover data model added**: Projects and project lines now carry `cover_1`, `cover_2`, and `cover_3` percentage fields. Existing project `cover_percentage` values are backfilled into `cover_1` during migration for continuity.
- **Cover is explicitly enabled per project**: Projects now store `has_cover`. Existing projects with any Cover percentage are backfilled as enabled; projects without Cover keep the Cover controls, calculations, and validation hidden.
- **Cover direction added**: Projects store whether Cover is added to or deducted from the entered unit price. The default and migrated production behaviour is **Cover is Deducted**.
- **Net/Total price invariant implemented**: Cover percentages are applied sequentially, and Net is always the lower monetary value while Total is always the higher value. With deducted Cover the stored unit price is Total and Net is calculated downward; with added Cover the stored unit price is Net and Total is calculated upward. The schedule, validation, quote PDF, priced CSV, area totals, and project summary all use the same model methods.
- **Net/Total display order standardised**: Cover-enabled schedules show Net Price before Total Price, with the editable stored value appearing in the correct column for the selected Cover direction. The Net Project Total panel is always immediately left of Project Total.
- **Cover values standardised**: Cover values are treated as two-decimal percentages throughout the UI and database. Typing `4` is normalised to `4.00`; blank line-level Cover values inherit the project defaults and are not flagged as overrides.
- **Cover permission added**: `cover.update` controls whether a user can change Cover percentages. Cover remains price-related, so users must also have `pricing.view` to see Cover fields.
- **Project details Cover fields**: The project details slide-over exposes Has Cover, Cover Direction, and Cover 1–3. Salesforce projects with `CEF_Cover__c` enable Cover automatically, use deducted Cover, populate Cover 1 from Salesforce, and default Cover 2 to `5.00` and Cover 3 to `0.00`.
- **Line-level Cover fields**: New project lines inherit the three project Cover values only when the project has Cover enabled. The project line table can toggle its Notes column into compact Cover inputs without adding another wide table column.
- **Validation Cover issues**: Lines with effective Cover values that differ from the project defaults are listed as validation Issues only for Cover-enabled projects. Cover issue rows show RRP, Unit, calculated Net, and compact Cover inputs so permitted users can review the full calculation in context. Validated/approved rows show Cover as read-only text.
- **Validation layout refreshed**: Issue cards now use consistent type badges/icons and aligned action controls. Price mismatches show RRP and Quote with a separate **Match** action; Cover mismatches show RRP, Unit, Net, and C1–C3; flag actions use a compact red flag control.
- **Issue grouping**: Multiple issues for the same line/SKU are grouped together in validation, ordered as duplicate SKU, price mismatch, Cover mismatch, then manual flag.
- **Issue-specific approval**: Approving one issue on a line no longer approves every issue for that same line. Each explicit approval is tracked by its own approval note; a line moves to Validated only when all unresolved issues are resolved or individually approved.
- **Flag notes**: Flagging an issue or validated line now opens a short-note dialog. The note is stored on the affected line(s), displayed for manual flagged issues, and shown as a `Flag note` on flagged validation issues.
- **Activity-log search improved**: The rendered Action Performed text is now searchable across action labels, payload values, project snapshots, and user email snapshots. Action filters use the same clearer labels, including **Approved and locked** and **Unapproved and unlocked**.
- **Salesforce details save cleanup**: Saving project details no longer attempts a Salesforce PDF upload. Salesforce Amount is only pushed when the project value actually changes and outbound pushes are enabled.
- **Production deploy safety**: The production deploy script now runs pending migrations automatically, records migration status, keeps a full pre-deploy backup, keeps one rolling protected-table data restore file, and has a conservative catastrophic data-loss guard that can restore protected data into the migrated schema before failing loudly.

## Beta Test Prep and Project Workflow — 9 July 2026

- **Project-list filtering**: The projects table supports filtering by the creator's permission group, and table filters persist in the session when users navigate away and back.
- **Technical paste import**: The Paste Products modal now has separate Misos and Technical paste modes. Technical paste validates the pasted structure first, warns that it replaces all existing areas/products in the revision, creates areas from headings, supports tab/comma input, and uses pasted descriptions where supplied while still matching known SKUs to local products.
- **Paste usability polish**: The paste textarea accepts actual Tab key input, displays tabs as a visible arrow marker, and strips trailing tab markers before import so manual edits remain workable.
- **Quote/schedule PDF polish**: Quote and schedule PDFs now show `Sales Engineer` using the project owner email instead of the generated timestamp, and line columns are ordered as Ref, Qty, Code, Description, then the remaining existing columns.
- **Edited SKU safety fix**: Editing a line code now re-checks the product catalogue. A matching SKU refreshes the line's product link, description, type, and price from the product table; an unknown SKU blanks the description and sets price to zero.
- **Dashboard recents cleanup**: Recent quote/schedule panels now show each project once per panel, using the latest generated item for that project.
- **User/session visibility**: The Users page now shows an indicator for users who are currently logged in/active.
- **Activity-log readability**: Long note contents are hidden from activity-log change summaries, repeated `to the schedule` wording was removed, and columns were tightened so action text gets the available space.
- **Production health-check tuning**: Docker health checks gained retry behaviour to reduce false positive alerts while still catching stopped/missing core services.
- **Release tracking**: `./deploy-production` now updates `CHANGELOG.md` as part of the version bump flow, recording the visible app version and commit subjects included between `origin/production` and `main`.

---

## Recent Production Watch — updated 8 July 2026

- **PDF 500s reported on the VPS**: The immediate production failure seen on 6 July was environment drift in the PDF runtime. `app:diagnose-pdf-environment` first failed with `mkdir(): Invalid path`, then with Puppeteer unable to find the expected Chrome binary. Recovery was to set `LARAVEL_PDF_TEMP_PATH=/var/www/html/storage/app/browsershot`, clear config, create/chown the temp directory, and install Puppeteer's `chrome-headless-shell` as the `sail` user with the cache under `/home/sail/.cache/puppeteer`.
- **Production deploy runner incident**: GitHub Actions jobs were stuck waiting for the `self-hosted, luxquote-production` runner. After runner recreation, follow-up failures were caused by runner-side SSH trust/key state: `known_hosts` needed GitHub and the deploy key expected at `/root/.ssh/luxquote_github_repo_deploy` needed to be available inside the runner container. `DEPLOYMENT.md` records the recovery checklist.
- **Primary stability concern**: Quote, schedule, datasheet-inclusive PDFs, and document packs are still generated synchronously in web requests. The deploy and cron smoke tests verify Browsershot, qpdf, legal-page merge, and basic production health, but they still do not replace queued/background generation for long-running datasheet or document-pack jobs.

## Production Monitoring Added — 7 July 2026

- **PDF smoke tests added**: `tests/Feature/PdfSmokeTest.php` exercises the real Browsershot and qpdf path for the PDF diagnostic command, schedule PDF, quote PDF, and generated document-pack merge. These tests are intended for local/CI/deploy confidence, not unattended production cron.
- **Production-safe health command added**: `app:production-health-check` checks app boot, database connectivity, cache, storage writability, standard legal PDF presence, `qpdf`, a tiny Browsershot render, and merging that generated PDF with the legal page. It does not mutate business data or contact Salesforce.
- **Cron heartbeat wrapper added**: `scripts/production-health-check.sh` runs Docker Compose, MySQL, local/public HTTP, and Artisan health checks, then pings an optional `HEALTHCHECK_PING_URL` with `/start`, success, or `/fail`. `DEPLOYMENT.md` documents the cron entry and suggested external monitoring services.
- **ntfy PDF alert wrapper added**: `scripts/production-pdf-health-check-ntfy.sh` runs `app:production-health-check --pdf-only` and posts failures to `https://ntfy.sh/LuxQuotePdfs` by default. Recommended cadence is hourly because it starts headless Chrome and runs qpdf checks.
- **ntfy login alert wrapper added**: `scripts/production-login-health-check-ntfy.sh` requests `https://quote.tamlite.co.uk/login`, verifies the response contains `LuxQuote`, and posts failures to `https://ntfy.sh/LuxQuoteLogin`. Recommended cadence is every 10 minutes.
- **Additional ntfy focused alert wrappers added**: `scripts/production-disk-health-check-ntfy.sh`, `scripts/production-docker-health-check-ntfy.sh`, `scripts/production-database-health-check-ntfy.sh`, and `scripts/production-salesforce-health-check-ntfy.sh` post failures to `LuxQuoteDisk`, `LuxQuoteDocker`, `LuxQuoteDatabase`, and `LuxQuoteSalesforce` respectively. `DEPLOYMENT.md` records the crontab lines.
- **Emergency reset CGI reference added**: `scripts/emergency-reset-webhook.cgi` mirrors the cPanel CGI reset flow without committing the live secret. It shows the newest backup timestamp/size and requires an explicit database restore yes/no choice. `emergency_recover.sh` now respects `LUXQUOTE_AUTO_DB_RESTORE=0` to prevent an automatic DB restore when the CGI operator chooses restart-only recovery.

---

## Beta Prep and UI/PDF Stabilisation — 8 July 2026

- **Product API migration**: Product import now consumes `https://tcms.tamlite.co.uk/api/luxquote_data` with the current fields `id`, `product`, `type`, `sku`, `description`, `cost`, and `site`. The importer maps `product` to `product_name`, `type` to `type_name`, `cost` to `price`, and stores `description` as-is in both `description` and `v_description`.
- **Product import safety**: Rows without SKU or product are skipped, duplicate SKUs in the same API payload are ignored after the first occurrence, and approved revisions remain locked against import-driven price backfills.
- **Product admin polish**: The products table column selector now only exposes fields populated from the current API. The Products page records successful imports in `app_settings.products_last_pulled_at` and displays `Last Product Data Pull` beside the Fetch Products action.
- **App versioning**: A tracked `VERSION` file now provides the visible beta version label, shown in the expanded left sidebar. `APP_VERSION` may override it for a pinned environment value, but normal production deploys should use the tracked file.
- **Automatic version bump and changelog on deploy**: The local `./deploy-production` helper bumps `VERSION`, prepends a `CHANGELOG.md` entry listing commits included in the release, commits both on `main`, fast-forwards `production`, and pushes the production branch. Default bump is the beta suffix; `VERSION_BUMP=patch|minor|major|none` overrides it.
- **Browser PDF delivery fix**: PDF generation now returns short-lived authenticated prepared download URLs under `/pdf-downloads/{token}/{filename}` for browser-driven opens/downloads. This avoids blob UUID filenames and prevents the current app tab being replaced when opening PDFs in a new tab.
- **PDF template polish**: Quote/schedule PDFs no longer show the `Schedule by Area` subheading, area headers are more compact, custom/modified line accent bars were removed, and PDF dates now use non-padded ordinal day formatting such as `8th Jul 2026`, with generated timestamps retaining the time.
- **Activity logs readability**: Activity rows stay on one line, colour the action verb/data portions, format enum-ish values as title case, and use an abbreviated project name when a local project has no Salesforce reference number.
- **Approval workflow polish**: Approved revisions are highlighted with a clearer header badge, approvers can unapprove a revision when needed, unapproval is logged, and stale edit attempts against a newly-approved revision surface a friendly locked-revision toast instead of a raw 403 page where the UI can intercept it.
- **Project header/nav polish**: Project page navigation actions were moved into the top header area and aligned with the app chrome, leaving the project title area cleaner.

---

## Features completed — 2 July 2026

- **Production emergency recovery documented**: Added `emergency_recover.sh` and `luxquote_restore_to_last_deploy.sh` to the deployment runbook. The recovery path recreates Docker Compose containers without deleting volumes, clears Laravel caches, health-checks `https://quote.tamlite.co.uk`, and can fall back to restoring the newest `backups/*.sql.gz` database backup when that restore is explicitly acceptable.
- **Salesforce JWT bearer auth support**: Salesforce authentication can now be switched between the existing OAuth2 Client Credentials flow and JWT bearer flow with `SALESFORCE_AUTH_METHOD`. The existing integration service methods are unchanged; JWT signing uses PHP OpenSSL and focused Salesforce service/PDF/validation/interrogator tests cover the preserved behavior.

---

## What This App Is

An internal quoting and project management tool for a lighting company (Tamlite / Xcite brands). Users create **Projects**, organise them into **Areas** (rooms/floors), and build a **line-item schedule** of lighting products against each area. The product catalogue is pulled from an external API and held locally.

---

## Tech Stack

| Layer | Package / Version |
|---|---|
| Framework | Laravel 13 |
| Admin panel | Filament 5 |
| Reactive UI | Livewire 4 + Alpine.js |
| PHP | 8.5 |
| Tests | PHPUnit 12 |
| Dev runtime | Laravel Sail (Docker) |
| Bundler | Vite |
| Formatter | Laravel Pint |

All commands must be prefixed with `vendor/bin/sail`. Default theme is **dark mode**. The Filament panel is served at the root path (`/`).

---

## Data Model

```
users
  id, name, email, password
  app_authentication_secret (text, nullable — encrypted TOTP secret)
  app_authentication_recovery_codes (text, nullable — encrypted JSON array of recovery codes)
  role (admin|users)

products
  id, site, product_name, sku, price, description, v_description, type_name
  length_mm, width_mm, depth_mm, diameter_mm, cut_out_mm
  weight_kg, luminaire_wattage_w, lumens_lm, efficacy_llm_w
  beam_angle_fwhm, emergency_lumen_output, power, em_power
  cct_k, colour_temp, cri, dali, vision_type, emergency_type
  ip_rating, ik_rating, electrical_class, rl_ral

projects
  id, user_id (FK), name, reference_number, customer_name, contractor
  site_location, owner_email, created_by_email, department
  date, revision, visibility (open|private), status (draft|in_progress|complete|cancelled|archived)
  branch_name, has_cover (bool), cover_direction (added|deducted)
  cover_percentage (legacy string, nullable), cover_1, cover_2, cover_3, value (decimal, nullable)
  quote_notes, internal_notes, general_notes
  active_revision_id (FK → project_revisions, nullOnDelete)
  last_edited_at (nullable timestamp)
  last_edited_by (FK → users, nullable, nullOnDelete)

project_revisions
  id, project_id (FK), revision_number, created_by (FK → users)
  validated (bool), validated_at (nullable timestamp), validated_by (nullable FK → users)
  status (draft|approved)
  unique(project_id, revision_number)

project_presences
  project_id (FK), user_id (FK), last_seen_at
  (no timestamps — composite PK implied by upsert)

project_areas
  id, project_id (FK), project_revision_id (FK → project_revisions), name, sort_order

project_lines
  id, project_area_id (FK), product_id (nullable FK → products, nullOnDelete)
  code, ref, description, qty, type (standard|modified|custom)
  unit_price, cover_1, cover_2, cover_3, notes, status
  validation_flagged (bool), validation_note (nullable string: flag reason and/or explicit approval notes)
  approved (bool), approved_at (nullable timestamp), approved_by (nullable FK → users)
  sort_order

document_packs
  id, project_id (FK), name
  created_by (nullable FK -> users), updated_by (nullable FK -> users)
  unique(project_id, name)

document_pack_items
  id, document_pack_id (FK), role, source_type, sort_order
  file_disk, file_path, original_filename (nullable)
  configuration (nullable JSON)

activity_logs
  id, user_id (nullable FK), project_id (nullable FK), action_type
  user_email_snapshot, project_name_snapshot, revision_number (nullable)
  payload (JSON, nullable), created_at

salesforce_pdf_uploads
  id, project_id (FK), project_revision_id (FK), document_type
  fingerprint_hash, filename
  salesforce_content_version_id, salesforce_content_document_id, salesforce_url
  uploaded_at, timestamps
  unique(project_id, project_revision_id, document_type)

app_settings
  id, key, value (JSON), timestamps
```

Known app setting keys:
- `salesforce_push_disabled` — global pause for outbound Salesforce writes; read-only Salesforce pulls still work
- `products_last_pulled_at` — timestamp of the last successful product API import shown on the Products page

**Key relationships:**
- `Project` → `hasMany` → `ProjectRevision` → `hasMany` → `ProjectArea` → `hasMany` → `ProjectLine`
- `Project::activeRevision()` — BelongsTo the currently active revision
- `Project::activeViewers()` — HasManyThrough User via ProjectPresence (last 90 seconds, excludes self)
- `Project::lastEditor()` — BelongsTo User via `last_edited_by`
- `ProjectRevision::creator()` — BelongsTo User via `created_by`
- `ProjectRevision::validator()` — BelongsTo User via `validated_by`
- `ProjectLine::approver()` — BelongsTo User via `approved_by`
- `Project::documentPacks()` is a HasMany relationship to project-level named packs, ordered by name
- `DocumentPack::items()` returns the ordered pack contents; deleting a pack removes its uploaded files
- A new Project auto-creates revision #0 on creation (model boot hook) and a default area. Revision #0 displays as **P0**; subsequent revisions display as **R1**, **R2**, and so on
- `ProjectArea` has computed accessors: `line_total_qty` and `line_total` (qty × unit_price sum)
- `Product.description` is copied directly from the current product API `description` field, with no site-specific manipulation. `Product.v_description` mirrors that same API description for compatibility with existing display helpers.
- `ProjectLine.product_id` is nullable origin tracking for product-backed lines; `code` stores the copied SKU and `description` stores the copied product display description used for schedule/PDF output
- `ActivityLog.revision_number` snapshots the project revision number at the time of the event; older rows may be null

---

## Enums

| Enum | Values |
|---|---|
| `UserRole` | `Admin`, `Users` |
| `ProjectStatus` | `Draft`, `InProgress`, `Complete`, `Cancelled`, `Archived` |
| `ProjectRevisionStatus` | `Draft`, `Approved` |
| `ProjectVisibility` | `Open`, `Private` |
| `ProjectLineType` | `Standard`, `Modified`, `Custom` |
| `DocumentPackItemRole` | `Cover`, `Legal`, `CustomPdf`, `StandardLegalPage`, `Quote`, `UnpricedSchedule` |
| `DocumentPackItemSource` | `Uploaded`, `Generated`, `Template` |

---

## Filament Resources & Routes

| URL | Resource | Notes |
|---|---|---|
| `/products` | `ProductResource` | List only — no create/edit pages (data comes from API) |
| `/projects` | `ProjectResource` | List + custom View page |
| `/projects/{project}` | `ViewProject` (custom) | Main working page — project URLs use `reference_number`; legacy numeric IDs still resolve as a fallback |
| `/projects/{project}/validation` | `ValidationProject` | Admin-only validation, warning approval, and duplicate merge |
| `/projects/{project}/output` | `OutputProject` | Quote approval summary, quick PDF/CSV outputs, and document-pack builder |
| `/pdf-progress/{token}` | `ProjectPdfController::progress` | Authenticated polling endpoint for PDF-generation progress messages |
| `/pdf-downloads/{token}/{filename?}` | `ProjectPdfController::download` | Authenticated, user-scoped prepared PDF download/open URL for browser-generated outputs |
| `/projects/{project}/document-packs/{documentPack}` | `DocumentPackController` | Authenticated combined-PDF download for a selected revision |
| `/users` | `UserResource` | Admin-only create/edit/list |
| `/activity-logs` | `ActivityLogResource` | Admin-only history table |
| `/salesforce` | `Salesforce` page | Admin-only Salesforce Opportunities table |

**Project visibility scoping** — `ProjectResource::getEloquentQuery()` restricts non-admin users to projects where `visibility = open` OR `user_id = auth user`.

Each Resource follows the **split-file pattern**: `Resource.php` → delegates to `Schemas/XForm.php`, `Tables/XTable.php`, and `Pages/`.

---

## The Project View Page (`/projects/{id}`)

This is the most complex page. It is a custom `ViewRecord` Livewire component with its own Blade template (`resources/views/filament/resources/projects/pages/view-project.blade.php`).

### Layout
- Page heading = project name; subheading = customer · contractor · site · revision
- Header action: **Areas** button → opens a Filament modal to manage area names
- **Concurrent editors banner** (blue): shown when other users have the project open (within 90 s); lists their names; has a Refresh button
- Body: accordion list of `ProjectArea` cards, each collapsible

### Per-area card
Each area header shows the area name, line count, total qty, and £ total. Buttons in the header:
- **Product** → opens the product picker modal (see below)
- **Paste** → opens a paste modal for importing spreadsheet rows into the area
- **Blank** → adds an empty `ProjectLine` to the area

Each line is a sortable row (Alpine `x-sort`) with inline-editable fields:
`code` · `ref` · `description` · `qty` · type badge · `unit_price` · `notes` · status (placeholder) · duplicate + delete actions

Lines can be dragged between areas; the `sortLine(lineId, position, targetAreaId)` method handles cross-area moves within a DB transaction.

### Revision Management

The initial project state is stored as revision number `0` and labelled **P0** (pre-revision). Creating the first revision produces **R1**, followed by **R2**, **R3**, etc. Display code must use `ProjectRevision::label()` / `labelForNumber()` rather than prepending `R` directly.

A **Revisions** header button opens a modal listing all revisions. From there users can:
- **Select** any revision — activates it by updating `projects.active_revision_id`, updates the project `revision` number, and refreshes `$viewingRevisionId`
- **Create New Revision** — copies all areas and lines from the current revision into a new `ProjectRevision`, then makes that revision active

New revisions always start **unvalidated** and copied lines keep their approval metadata when cloned. This preserves explicit approval decisions for accepted warnings, such as missing SKUs.

Approved revisions are locked against schedule editing. All mutating area/line methods call `ensureViewingRevisionIsEditable()` server-side, and visible inline controls are disabled. Users can create a new revision from an approved revision and continue editing the new copy.

All area/line operations (add, edit, delete, duplicate, sort) are scoped to `$viewingRevisionId` and must also verify project ownership. Cross-project or cross-revision Livewire IDs must fail server-side.

### Concurrent Editing Detection

The outer `<div>` has `wire:poll.30s="heartbeat"`. On each poll (and on `mount`):
- `heartbeat()` upserts a `ProjectPresence` row (`project_id`, `user_id`, `last_seen_at = now()`)
- Purges presence rows older than 90 seconds
- `#[Computed] concurrentEditors()` returns other users whose `last_seen_at` is within 90 s

### Livewire methods on `ViewProject`

| Method | What it does |
|---|---|
| `heartbeat()` | Upserts presence, purges stale, refreshes computed |
| `setActiveRevision(revisionId)` | Activates a project revision and updates `projects.revision` |
| `createNewRevision()` | Copies areas+lines from `$viewingRevisionId` into new revision |
| `getAreas()` | Returns areas for `$viewingRevisionId` with lines eager-loaded |
| `findAreaInViewingRevision(areaId)` | Private helper; verifies area belongs to current project and revision |
| `findLineInViewingRevision(lineId)` | Private helper; verifies line belongs to current project and revision |
| `addArea()` | Validates `newAreaName`, creates area under `$viewingRevisionId` |
| `removeArea(areaId)` | Deletes area (cascades to lines); checks area belongs to current revision |
| `addProduct(areaId)` | Alias → calls `openProductPicker(areaId)` |
| `addBlankLine(areaId)` | Creates an empty Custom line |
| `updateLineField(lineId, field, value)` | Inline edit — allowlist: `code, ref, description, qty, unit_price, notes, cover_1, cover_2, cover_3` |
| `duplicateLine(lineId)` | Replicates line, inserts after original, re-sequences sort_order |
| `deleteLine(lineId)` | Hard deletes line |
| `sortLine(lineId, newPos, targetAreaId)` | Moves line, handles cross-area in a transaction |
| `openProductPicker(areaId)` | Sets target area, resets picker state, opens modal |
| `closeProductPicker()` | Closes modal, clears selections |
| `toggleProductSelection(productId)` | Adds/removes product from `$productSelections` map |
| `setProductSelectionQty(productId, qty)` | Updates qty for a selected product |
| `addSelectedProducts()` | Creates `ProjectLine` records from selections, then closes modal |
| `openPasteProductsModal(areaId)` | Opens the paste-products modal for an Area |
| `closePasteProductsModal()` | Closes the paste modal and clears paste state |
| `addPastedProducts()` | Parses pasted rows and creates `ProjectLine` records |
| `ensureViewingRevisionIsEditable()` | Private guard; aborts mutating calls against approved revisions |

### `#[Computed]` properties

| Property | What it returns |
|---|---|
| `concurrentEditors` | Other users with presence within 90 s |
| `projectRevisions` | All revisions for this project with creator eager-loaded |
| `productPickerProducts` | Paginated products (15/page) filtered by search + site + type |
| `productSiteOptions` | Distinct non-null `site` values for the site filter dropdown |
| `productTypeOptions` | Distinct non-null `type_name` values for the type filter dropdown, scoped by selected site |
| `isViewingRevisionValidated` | Whether `$viewingRevisionId` is approved and locked |

---

## Validation & Approval

The admin-only validation page is available at `/projects/{id}/validation`. It validates the project's **active revision** using `App\Services\ProjectRevisionValidator`.

### Current validation rules

1. A SKU should be unique within an Area. Repeating the same SKU in different Areas is allowed.
2. Every non-empty line SKU should exist in the local `products` catalogue.
3. Product-backed quote prices should match the product catalogue RRP unless explicitly approved.
4. Explicit line-level Cover values should match the project Cover defaults unless explicitly approved.
5. Manually flagged lines should be reviewed before approval.

SKU comparison for validation is case-insensitive and trims surrounding whitespace.

### Validation lifecycle

- New revisions default to `validated = false`.
- New lines default to `approved = false`; cloned lines keep their existing approval state, approver, timestamps, and validation notes.
- **Run Validation** re-evaluates all current rules.
- Lines with no warnings are automatically approved with `approved_by = null`.
- Warning lines remain unresolved until an admin explicitly approves that specific warning or resolves it by merging duplicates, matching price, or correcting Cover values.
- A revision becomes validated only when no unresolved warnings remain and every line is approved.
- Validating records `validated_at` and `validated_by`, then marks the revision **Ready to approve**.
- Validated-but-unapproved revisions remain editable and can be revalidated after edits.
- Once a revision is validated, admins can click **Approve Revision**, confirm the lock modal, and set `project_revisions.status = approved`.
- Approved revisions reject validation and schedule mutation actions server-side.

### Warning actions

| Action | Behavior |
|---|---|
| **Approve** | Explicitly approves all lines affected by that warning and records `approved_at` / `approved_by`; approval is issue-specific when one line has multiple warnings |
| **Undo** | Removes explicit approval for the warning; the revision becomes unvalidated if the warning is unresolved |
| **Merge** | Available for duplicate-SKU warnings; keeps the first line, sums quantities, deletes the other duplicates, and approves the remaining line |
| **Match** | Available for price mismatch warnings; updates the quote price to the catalogue RRP and re-runs validation |
| **Flag Issue** | Opens a note dialog, stores the flag note, and moves the issue/validated line into the Issues list for admin review |

Explicit warning approval is distinguished from automatic clean-line approval by `approved_by`: explicit approval has a user ID; automatic approval uses null. For lines with multiple warnings, explicit approvals are tracked as issue-specific `Approved: ...` notes in `project_lines.validation_note`.

### Validated lines table

The validation page now separates unresolved warnings from resolved lines:

- **Issues** shows only unresolved validation warnings.
- **Validated** shows clean lines and explicitly approved warning lines.
- Each validated row includes status (`Resolved` or `Approved`), quote price, read-only Cover values when Cover is enabled, note text, and a flag action when the revision is not approved.
- Resolution and approval notes are stored on `project_lines.validation_note`.
- Manual flags are stored with `project_lines.validation_flagged = true`; the entered flag reason is stored in `project_lines.validation_note` and shown on the validation issue until resolved.

### Key files

| File | Role |
|---|---|
| `app/Services/ProjectRevisionValidator.php` | Single source of truth for rule evaluation and revision validation status |
| `app/Filament/Resources/Projects/Pages/ValidationProject.php` | Admin actions: run, approve warning, undo, merge, match price, approve revision |
| `resources/views/filament/resources/projects/pages/validation-project.blade.php` | Validation summary and warning list |
| `tests/Feature/AdminProjectValidationTest.php` | Validation, approval, merge, revalidation, and locking coverage |

---

## Product Picker Modal

Opened when the user clicks **Product** on any area header. Rendered inline at the bottom of the view template with `z-[9999]` (not teleported — `@teleport` was removed due to a Livewire 4 bug when teleporting conditionally-empty content).

**Features:**
- Live search (250 ms debounce) across `product_name`, `sku`, `description`
- Site and Type filter dropdowns (Type options are scoped by selected Site)
- Paginated product list (15 rows, Prev/Next controls)
- Clicking a row toggles a custom checkbox; selected rows highlight in primary colour
- Qty input appears per-row only when that product is selected
- Footer shows count of selected products and an **Add N Products** button (disabled until ≥1 selected)
- On add: creates one `ProjectLine` per selected product (`product_id` = product ID, `code` = SKU, `description` = `Product::displayDescription()`, `unit_price` = product price, `qty` from picker)

**Livewire state properties:**
```
$productPickerOpen (bool)
$productPickerAreaId (?int)
$productSearch (string)
$productSiteFilter (string)
$productTypeFilter (string)
$productPage (int)
$productSelections (array<int, array{qty: int}>)  — keyed by product_id
```

---

## Paste Products Modal

Opened when the user clicks **Paste** on an area header. It is rendered inline in `view-project.blade.php` and uses a textarea labelled **Paste product data** plus **Cancel** and **Add Products** actions.

Expected pasted data is tab-delimited with four fields:

```
qty    sku    description    price
```

Rules:
- The first row is data; there is no header row.
- The delimiter between fields is always a tab.
- The pasted description column is discarded. It may be quoted, unquoted, contain punctuation, or span multiple lines inside quotes.
- The parser uses tab-delimited CSV parsing so quoted multiline descriptions stay attached to their row.
- `qty` is imported into `ProjectLine.qty`.
- `sku` is imported into `ProjectLine.code`.
- `price` is imported into `ProjectLine.unit_price` and overrides any product catalogue price for that pasted line.
- When the SKU exists in `products`, `product_id` is set and `ProjectLine.description` is copied from `Product::displayDescription()`.
- When the SKU does not exist, the line is still added with blank description and `Custom` type so validation can flag the missing SKU.
- Product-picker additions start with `ProjectLine.status = Pending`.
- Pasted rows that match or create lines set `ProjectLine.status = Priced`.
- Existing lines that are missing from the pasted SKU set are marked `Unpriced`.
- **Paste across all areas** is enabled by default. In this mode, matching SKUs across the whole revision are repriced without changing their existing quantities, and new pasted SKUs are added to the target Area.
- Turning **Paste across all areas** off scopes updates to the selected Area and updates existing line quantities from the pasted rows.
- The modal's **Add Products** button enables immediately when text is pasted, via Alpine state, without waiting for a Livewire re-render.

---

## Product Catalogue

- Data source: external API `POST https://tcms.tamlite.co.uk/api/luxquote_data`
- Import handled by `App\Services\ProductImportService`
- Import **deletes** all existing products then bulk-inserts in chunks of 500 (DELETE used over TRUNCATE to respect FK constraints)
- Import backfills blank project line prices from matching SKUs unless the parent revision status is `approved`.
- Current API fields are `id`, `product`, `type`, `sku`, `description`, `cost`, and `site`.
- API field `product` maps to local `product_name`.
- API field `type` maps to local `type_name`.
- API field `cost` maps to local `price`.
- API field `description` is stored as-is in both local `description` and `v_description`; no title-casing, concatenation, or site-specific description manipulation is applied.
- API field `id` is ignored locally.
- Rows without SKU or product are skipped, and duplicate SKUs in the same payload are ignored after the first occurrence.
- `site` values commonly include `Tamlite` and `xcite`; badge styling applies their brand colours where those names appear.
- No create/edit UI — read-only in Filament, imported via Artisan or tests
- A successful import stores `app_settings.products_last_pulled_at`, which is shown on the Products page as `Last Product Data Pull`.

---

## Output Page & Document Packs

The project output page is available at `/projects/{id}/output`. It starts with a three-part status panel and then a two-tab output selector:

- **Quote status** — shows `Approval Required`, `Requested`, or `Approved`, and includes **Request Approval** when the active revision is not approved.
- **Validation** — shows `Passed` or `Not passed`, plus **View Validation**. This entire section is hidden from users without `validation.view`.
- **Datasheet controls** — quote and schedule generation can include merged datasheets when the Include datasheets switches are enabled.

The output selector tabs are:

- **Quick Output** — Quote PDF, quote CSV, schedule PDF, and schedule CSV. Visible labels avoid `Priced` / `Unpriced` wording; permissions still enforce the underlying priced/unpriced capabilities.
- **Document Packs** — named, reusable project-level definitions that combine uploaded and generated PDFs into one download.

Primary generation and request actions use Filament's orange primary button convention. The active Output tab uses an orange underline that works in light and dark mode.

### PDF generation UX

- Quote, schedule, dashboard output, and document-pack PDF links open through a shared generation dialog instead of leaving the user on a blank browser tab.
- The dialog shows staged progress for normal single-PDF/document-pack generation so fast responses do not jump straight from a low percentage to complete.
- When datasheets are included, the app passes a short progress token with the PDF request and polls `/pdf-progress/{token}` for live messages.
- Datasheet progress is written server-side as the legacy Tamlite endpoint streams JSON chunks such as `step`, `total`, and `message`.
- Progress is scoped by authenticated user ID plus token; one user cannot read another user's generation status.
- Progress percentages are monotonic in the browser so fallback animation cannot fight streamed datasheet progress.
- Browser-driven PDF opens/downloads request `pdf_delivery_link=1`. The server generates the PDF once, stores a short-lived copy under `storage/app/pdf-downloads`, and returns an authenticated `/pdf-downloads/{token}/{filename}` URL so the browser sees a real filename instead of a blob UUID.
- Prepared PDF URLs are user-scoped, reusable for 10 minutes, and old prepared files are cleaned opportunistically after 30 minutes.

### Datasheet PDF embedding

- Include Datasheets is now functional for quote and schedule PDFs.
- The app POSTs a form-encoded payload to `https://tamlite.co.uk/ci_index.php/download_schedule`; the `skus` field is a JSON string because the legacy endpoint does not accept a pure JSON request body.
- The endpoint generates a combined datasheet PDF under the configured public base URL, then the app downloads it and appends it after the quote/schedule PDF with `qpdf`.
- Quote/schedule content always stays first; datasheets are appended afterwards.
- Merged files use filenames ending in `-with-datasheets.pdf`.
- Salesforce PDF upload fingerprints include the Include Datasheets state so unchanged outputs are not uploaded again, while datasheet-inclusive outputs are tracked separately from non-datasheet outputs.

### Pack workflow

- Users may save multiple uniquely named packs against a project. Packs carry across revisions.
- Pack items are compact six-across cards. Items are draggable and may be added at the end, removed, or reordered. Stable UUID/item keys keep each role, upload, preview, and filename attached to the correct card after a drag.
- New pack items can be **Custom PDF** (uploaded), **Standard Legal Page** (app-owned template), **Quote**, or **Schedule**. Existing saved **Cover** and uploaded **Legal** items remain supported for legacy packs, but are no longer offered in the new-document dropdown.
- Uploaded PDFs remain project-level. Generated items always use the revision selected when the pack is generated, not the revision that was active when the pack was saved.
- Uploaded roles require a PDF before a selected item can be saved. Blank newly-added upload cards with no role/file may still be discarded as incomplete.
- Uploaded-file cards accept click-to-select and drag/drop. Client-side guards reject non-PDF files before upload, and server-side validation still enforces `mimes:pdf`.
- Uploaded PDFs show a first-page thumbnail that opens the saved document in a new tab. Unsaved uploads/replacements use a local browser preview and preserve the selected filename until the pack is saved.
- Replacement uploads explicitly clear the previous pending upload before starting the next upload so repeated replacements do not alternate against stale Livewire temporary-file state.
- Uploaded files default to a 25 MB limit and are checked with `qpdf --check`; corrupt, encrypted, or unsupported PDFs are rejected before they can be merged.
- Pack generation uses `qpdf` server-side to concatenate every page of each selected document in the saved order. Temporary generated inputs and the merged output are cleaned up after use/download.
- Saving, generating, and deleting packs creates `document_pack.saved`, `document_pack.generated`, and `document_pack.deleted` activity entries.
- The Document Pack footer keeps Delete Pack, Save Pack, and Generate Combined PDF right-aligned with matching button dimensions and non-wrapping labels.
- Template items currently support the standard legal page PDF configured by `LEGAL_PAGE_PDF` / `config/document-packs.php`.

### Revision and approval behavior

- A pack containing a Quote cannot be generated until the selected revision has passed validation and has status `approved`.
- The Generate button is disabled with an explanatory message while approval is missing; the download controller and PDF service repeat the check server-side.
- Generated Quote/Schedule cards show revision context as `{label} - {line count} SKU's, {qty total} Items`, plus a `Last modified dd/mm/yy hh:mm` line based on the latest project-line update in the selected revision.
- Quote and schedule roles also enforce their underlying output permissions. Cross-project pack/revision combinations return 404/403 rather than leaking data.

### Configuration

`config/document-packs.php` supports:

- `QPDF_BINARY` (default `qpdf`)
- `DOCUMENT_PACK_DISK` (default `local`)
- `DOCUMENT_PACK_MAX_UPLOAD_KB` (default `25600`)
- `DOCUMENT_PACK_PROCESS_TIMEOUT` (default `60` seconds)

`config/services.php` also carries the datasheet endpoint and public base URL used by `ProjectDatasheetPdfService`.

## Test Coverage

| Test file | Area covered |
|---|---|
| `Feature/AuthenticationTest.php` | Login / auth flows |
| `Feature/AdminProductResourceTest.php` | Filament Products list page (admin) |
| `Feature/AdminProjectResourceTest.php` | ViewProject server-side revision/project scoping for line actions; paste products, create form gating, status badges, Activity Logs revision display/search/filtering, project reference-number URLs, output URLs, PDF progress endpoint, and datasheet PDF merge flow |
| `Feature/AdminProjectValidationTest.php` | Revision validation, validated-lines table, manual flagging, automatic/explicit approval, Undo, Merge, revalidation, and approval locking |
| `Feature/AdminDocumentPackTest.php` | Pack CRUD/order, uploaded PDF validation, revision-aware merge order, permissions, project ownership, cleanup, and quote-approval generation lock |
| `Feature/BadgeStyleTest.php` | Shared badge palette rules, including brand colours and deterministic fallback colours |
| `Feature/AdminUserResourceTest.php` | Filament Users CRUD (admin) |
| `Feature/FrontEndProductsTest.php` | Products list for non-admin |
| `Feature/ProductImportTest.php` | `ProductImportService` — happy path, API failure, structure error |
| `Feature/SalesforceServiceTest.php` | `SalesforceService` — OAuth success, auth failure, SOQL query failure, and bearer-token caching via `Http::fake()` |
| `Feature/SalesforcePushControlTest.php` | Persistent global Salesforce push pause/resume behavior and outbound-write blocking |
| `Feature/PdfPreparedDownloadTest.php` | Prepared PDF download URLs, inline filename headers, expiry, and user scoping without database refresh |
| `Feature/ExampleTest.php` | Smoke test |
| `Unit/ExampleTest.php` | Smoke test |

Focused Salesforce service tests can be run before Salesforce credential or environment changes with:

```bash
vendor/bin/sail artisan test --compact tests/Feature/SalesforceServiceTest.php
```

**Remaining project test gaps:** product picker UI flow, revision activation UI, presence heartbeat, and broader validation/PDF browser coverage. Document-pack generation and ordering have focused feature coverage.

---

## Projects List Table

- Excludes archived projects
- Columns: reference, name, customer, owner email, department, date, revision (badge), status (badge), visibility (badge), **Last Edited** (relative time, tooltip shows full datetime + editor name), **presence icon** (users icon with tooltip listing who's actively viewing)
- Auto-refreshes every 60 s via `->poll('60s')` (Livewire morphdom diff — no visible flicker)

---

## Last Edited Tracking

Three model observers automatically update `projects.last_edited_at` and `projects.last_edited_by`:

| Observer | Trigger |
|---|---|
| `ProjectObserver` | Any meaningful change to the `projects` row (skips `last_edited_at`, `last_edited_by`, `active_revision_id`, timestamps) |
| `ProjectAreaObserver` | Area saved or deleted — only if the area belongs to the **active revision** |
| `ProjectLineObserver` | Line saved or deleted — only if its area belongs to the **active revision** |

Line update history includes validation flags and validation notes, so manual validation review changes appear in activity logs.

---

## Activity Logs

The admin-only history table is available at `/activity-logs` via `ActivityLogResource`.

Columns:
- **Who** — current user name when available, falling back to `user_email_snapshot`
- **Reference** — Salesforce/project reference number when available; local projects without a reference show an abbreviated project name
- **Rev** — stored `revision_number`, formatted through the shared revision label helper as `P0`, `R1`, `R2`, etc.; older rows may show `—`
- **Action Performed** — formatted from `action_type` and `payload`
- **Date & Time** — `created_at`

`ActivityLog` snapshots `revision_number` automatically from the attached project's current `revision` when a row is created. `revision.created` passes the newly-created revision number explicitly so the log row represents the revision that was created, not the previous active revision.

Tracked action types include project create/update/delete, revision creation, approval/unapproval, area create/delete, product add, line update, PDF generation, and the legacy `line.qty_updated` type. Display formatting keeps log rows single-line, colour-codes positive/negative verbs, and formats enum-like payload values as user-facing title case.

---

## Application Timezone

The app should run with:

```dotenv
APP_TIMEZONE=Europe/London
```

`Europe/London` is intentional rather than a fixed `GMT+1` offset because PHP will automatically switch between GMT and BST. After changing timezone config on any environment, clear cached config before checking visible timestamps.

Local verification:

```bash
vendor/bin/sail artisan optimize:clear
vendor/bin/sail artisan config:show app.timezone
vendor/bin/sail artisan tinker --execute 'echo now()->format("Y-m-d H:i T").PHP_EOL;'
```

## Salesforce Integration (updated 2 July 2026)

### Status: Salesforce Opportunities page live; project import UX built

Authentication supports both **OAuth2 Client Credentials** and **OAuth2 JWT Bearer** flow. `SALESFORCE_AUTH_METHOD=client_credentials` preserves the original behavior, while `SALESFORCE_AUTH_METHOD=jwt_bearer` signs a JWT assertion with PHP OpenSSL and exchanges it for the same `access_token` / `instance_url` shape used by the existing service methods. The service is bound as a singleton in `AppServiceProvider`. Gate `view-salesforce` restricts the Salesforce page to users with `salesforce.view`.

Successful bearer-token responses are cached for their reported lifetime, minus a 60-second safety buffer, so normal Salesforce reads and writes do not request a fresh OAuth token for every service call.

Salesforce PDF uploads are tracked per project, revision, and document type. When a quote or schedule PDF is requested with Salesforce upload enabled, the app compares a stable fingerprint of the output data and PDF template against `salesforce_pdf_uploads`; if it matches the last successful upload, no new Salesforce `ContentVersion` is created. Changes to the revision, line content, notes, pricing for quote output, project PDF metadata, or the schedule template produce a new fingerprint and upload a fresh version.

Successful quote/schedule PDF uploads from download routes are recorded in activity history but do not queue Filament success notifications, because PDF responses can display queued notifications late on a later app page. Upload failures still send a danger notification.

### Environment variables required (`.env`)

```dotenv
SALESFORCE_API_KEY=           # Consumer Key from the Connected App
SALESFORCE_CONSUMER_SECRET=   # Consumer Secret
SALESFORCE_BASE_URL=          # e.g. https://your-org.my.salesforce.com
SALESFORCE_AUTH_METHOD=client_credentials # or jwt_bearer
SALESFORCE_JWT_SUBJECT=       # Integration user username for JWT sub
SALESFORCE_JWT_AUDIENCE=      # e.g. https://test.salesforce.com or https://login.salesforce.com
SALESFORCE_JWT_PRIVATE_KEY=   # Optional inline PEM key, supports escaped \n or base64 PEM
SALESFORCE_JWT_PRIVATE_KEY_PATH= # Optional path to PEM key; preferred for production secrets
```

### Files

| File | Role |
|---|---|
| `app/Services/SalesforceService.php` | Auth + data methods (singleton) |
| `app/Services/SalesforcePdfUploadTracker.php` | Fingerprints generated PDF output and records the last successful Salesforce upload |
| `app/Filament/Pages/Salesforce.php` | Admin-only Filament page showing Opportunities table |
| `resources/views/filament/pages/salesforce.blade.php` | Blade template for the page |
| `config/services.php` | `salesforce` key: auth mode, credentials, JWT subject/audience/private key config, base URL |
| `app/Providers/AppServiceProvider.php` | Singleton binding + gate definition |

### JWT bearer handoff — 2 July 2026

JWT bearer support was added without replacing the existing Client Credentials flow. `SalesforceService::authenticate()` now branches internally based on `SALESFORCE_AUTH_METHOD`, but the public service methods and callers still use the same `['token', 'instanceUrl']` shape. This preserves the current Opportunity search/import, approval-time Opportunity Amount update, PDF upload, upload dedupe, and `salesforce:interrogate` behavior.

Local key/certificate files were generated at:

```text
storage/app/salesforce/server.key
storage/app/salesforce/server.crt
```

The private key remains local and is referenced through:

```dotenv
SALESFORCE_JWT_PRIVATE_KEY_PATH=storage/app/salesforce/server.key
```

The public cert was sent to the Salesforce admin for upload to the Connected App. Current cert fingerprint for admin comparison:

```text
SHA256 57:54:B0:54:FB:D2:35:25:60:EA:C7:5C:5E:98:AE:84:43:5B:CE:AA:1B:04:E0:43:E8:12:DB:93:81:E5:EA:DB
```

Production Salesforce should use JWT bearer mode once the connected app and integration-user permissions are ready. Local environments may still use `SALESFORCE_AUTH_METHOD=client_credentials` when testing against the older sandbox setup. Do not commit `.env`; keep only `.env.example` in source control. `SALESFORCE_CONSUMER_SECRET` is still present for fallback Client Credentials mode but is not required for JWT bearer mode.

Useful JWT smoke test:

```bash
vendor/bin/sail artisan optimize:clear
vendor/bin/sail artisan salesforce:interrogate --limit=1 --format=json
```

The command performs:

```text
GET /services/data/v65.0/sobjects/Opportunity/describe
SELECT <all Opportunity field names from describe> FROM Opportunity LIMIT 1
```

Historical JWT smoke-test status from the 2 July handoff:

- JWT authentication now succeeds with the production Connected App API key.
- Salesforce returns a valid `access_token` and `instance_url`.
- `GET {instanceUrl}/services/data/` returns `200`.
- The org supports API versions including `v65.0`, `v66.0`, and `v67.0`; the hardcoded `SalesforceService::API_VERSION = 'v65.0'` is not the blocker.
- The smoke test fails because the integration user cannot access `Opportunity`: `sObject type 'Opportunity' is not supported` / `NOT_FOUND`.

Next action for the Salesforce admin:

```text
JWT auth is working, but the integration user cannot access the Opportunity object.

Please check the integration user's license/profile/permission sets:
- API Enabled
- Read access to Opportunity
- Read field access to Id, Name, StageName, CreatedDate, Amount, Project_Reference_Number__c, CEF_Cover__c, Owner.Name, Owner.Email, Account.Name, IsClosed, IsWon
- Update access to Opportunity.Amount
- Create access to ContentVersion
- Read access to ContentVersion and ContentDocumentLink
- Permission to link Salesforce Files to Opportunities via FirstPublishLocationId
```

If the generic console output is unhelpful, check the real Salesforce response in:

```bash
tail -n 60 storage/logs/laravel.log
```

### Inspecting live Opportunity fields

Use the interrogator command with structured output when comparing Salesforce fields before changing project population mappings:

```bash
vendor/bin/sail artisan salesforce:interrogate --limit=5 --format=json > storage/app/salesforce-opportunities.json
```

On production, run the same command through Docker Compose from `/home/tamliteco/luxquote.app`:

```bash
docker compose exec -T laravel.test php artisan salesforce:interrogate --limit=5 --format=json > salesforce-opportunities.json
```

Useful local parsing examples:

```bash
jq '.[0] | keys' storage/app/salesforce-opportunities.json
jq '.[] | {Id, Name, StageName, Amount, Project_Reference_Number__c, CEF_Branch__c, CEF_Cover__c}' storage/app/salesforce-opportunities.json
```

Use `--format=ndjson` for one JSON object per line when grepping or importing into spreadsheet/data tools.

### Service methods

| Method | What it does |
|---|---|
| `authenticate(): ?array` | Private — uses the configured auth method to POST to `/services/oauth2/token`; returns `['token', 'instanceUrl']` or null |
| `soqlQuery(array $auth, string $soql): ?array` | Private — runs an authenticated SOQL query against API v65.0 and returns decoded JSON or null |
| `getOpportunities(int $page, int $perPage, ?string $search, ?string $sortColumn, ?string $sortDirection, array $fields): LengthAwarePaginator` | SOQL Opportunity table query with pagination, search, allowlisted sort columns, and `ORDER BY CreatedDate DESC` fallback |
| `searchOpportunities(string $query, int $limit = 10): array` | Typeahead — `WHERE Name LIKE '%…%' ORDER BY Name ASC`; returns `[Id => 'Name (Reference)']` for Select options |
| `getOpportunityById(string $id): ?array` | Fetches a single Opportunity by Id; returns `[Id, Name, Project_Reference_Number__c, CEF_Cover__c, Amount, Owner.Name, Owner.Email, Account.Name]` or null |
| `fetchProjects(): array` | Simple Opportunity fetch used by the Artisan interrogator command |
| `fetchAllOpportunityFields(int $limit = 25): array` | Describes Opportunity fields dynamically, then fetches all fields for interrogation/debugging |

### Salesforce Opportunities page

- Admin-only page at `/salesforce` (navigation icon: cloud, group: Salesforce)
- Displays a Filament `->records()` external-data table of Opportunities
- Columns: Reference, Project Name, Stage, Amount, Created Date, Owner
- Default sort: **Created Date descending** (both `->defaultSort()` on the table and the service fallback)
- Searchable, sortable, paginated (`10`, `25`, or `50` rows per page)
- Opportunity stage labels display the exact value returned by Salesforce. Styling may vary by label, but the app must not rename API values such as `Details`, `Design`, or `Quotation`.
- The page title includes a permission-gated Salesforce push switch for users with `salesforce.manage-push`. The switch is global and persistent in `app_settings`.

### "Salesforce Project" toggle on the New Project form

When creating a project, a **Salesforce Project** toggle is available:

- **Toggle ON** (default): hides the `name` field and shows a live Salesforce search Select
- **Toggle OFF**: normal free-text project creation
  - Typeahead searches Opportunity names in real time via `searchOpportunities()`
  - Selecting an Opportunity stores its data as JSON in a hidden `salesforce_pending_data` field
  - Opportunity names are normalised to title case before saving, while preserving all-caps acronyms at the start of words
  - A loading indicator appears while the selected Opportunity is fetched
  - A **Confirm & Populate Form** button appears — clicking it populates `reference_number`, `customer_name`, `owner_email`, `cover_1`, and `value` from the Opportunity data
  - Salesforce-derived fields become read-only while SF mode is on, while notes and visibility remain editable
  - `name` is extracted from the Opportunity JSON in `mutateFormDataUsing` (because the `name` TextInput is hidden and therefore not dehydrated by Filament)
  - `salesforce_project = true` is saved to the DB via the `projects.salesforce_project` column
  - The create action is visually disabled until name, customer, and reference fields are populated

### `salesforce_project` DB flag (added 29 May 2026)

- Migration: `2026_05_29_094932_add_salesforce_project_to_projects_table` — boolean, default false
- `Project` model: added to `#[Fillable]` and cast as `'boolean'`

### Edit-mode locking for Salesforce projects

When **editing** a project where `salesforce_project = true`:

- The Salesforce toggle is **disabled** (locked ON — cannot be switched off)
- The Salesforce search Select and Confirm button are **hidden** (create-only)
- The project `name` field is **visible but read-only** (so users can see it)
- All other form fields are **read-only** (enforced by the existing `->readOnly()` condition that checks `salesforce_project === true` from the loaded record)

These edit-mode rules apply everywhere the `ProjectForm` is used: the list page slide-over and the ViewProject "Details" button.

---

## Known Gaps / Next Steps (as of 17 July 2026)

- [ ] Continue Cover pricing review after beta feedback, especially how Cover values should appear in quote/schedule outputs and approval summaries
- [ ] Move long-running PDF/document-pack generation toward queued jobs with polling/download links so browser/proxy timeouts and remote datasheet delays do not surface as user-facing 500 errors
- [ ] Add structured logging around PDF generation with project reference, revision, document type, include-datasheets flag, progress token, qpdf step, datasheet endpoint result, and exception class/message
- [ ] Add a runner maintenance/checklist script that recreates `luxquote-production` with the GitHub deploy key, `known_hosts`, labels, and `/home/tamliteco/luxquote.app` checkout mount intact
- [ ] Review VPS resources and Docker health: memory/swap, disk pressure, MySQL restart history, Apache proxy timeout, and whether long PDF requests are being killed or timed out
- [ ] Add off-server database backup/restore verification and keep emergency recovery strictly volume-preserving unless a deliberate restore is chosen
- [ ] No two-way sync yet — Salesforce projects are imported once at creation; changes in Salesforce are not reflected back
- [ ] Validation currently covers duplicate SKU, missing SKU, price mismatch, and manual flags; output-readiness and other approval rules remain to be added
- [ ] Additional document-pack roles/templates (for example case studies) are planned but not yet implemented
- [ ] Review the Output page visually in dark mode across desktop widths
- [ ] Review the Output page in light mode, especially orange actions, tab underline, status chips, and disabled buttons
- [ ] Check mobile/tablet layout for the top status panel and the two document cards
- [ ] Add browser-level coverage for the Output page layout if visual regressions continue
- [ ] Standardize a shared Blade/CSS helper for Filament-style primary buttons used outside native Filament actions
- [ ] Consider applying the same shared button helper to other custom modals and project-page actions
- [ ] Revisit the Document Packs builder PDF preview behavior for uploaded files that return 404
- [ ] Run a final focused regression pass before deployment:
  - `vendor/bin/sail artisan test --compact tests/Feature/AdminProjectResourceTest.php`
  - `vendor/bin/sail artisan test --compact tests/Feature/AdminDocumentPackTest.php`
  - `vendor/bin/sail artisan test --compact tests/Feature/AdminProjectValidationTest.php`
  - `vendor/bin/sail artisan test --compact tests/Feature/SalesforcePushControlTest.php tests/Feature/BadgeStyleTest.php`

---

## Features completed — 6 July 2026

- **Salesforce push pause switch**: The Salesforce page includes a permission-gated push switch backed by `app_settings`. Pull/search remains available, but when pushes are paused the app skips outbound Salesforce PDF uploads and Opportunity Amount updates.
- **Salesforce push switch persistence**: The push switch now reads/writes the persisted app setting directly, remains in the page heading action area, and stays in its chosen state across logout/login.
- **Project details access**: The projects table now has a pencil/details action beside the copy action, and locked projects still expose Details in read-only mode rather than hiding the panel completely.
- **Projects table laptop layout**: Project-list columns were tightened again to prioritise the project name and avoid horizontal scrollbars on laptop-width screens.
- **Shared badge styling**: Reusable badge styling now keeps labels compact, standardises identical app labels, applies Tamlite/Xcite brand colours where applicable, and gives unknown labels deterministic colours without changing the displayed words.
- **Standard legal PDF page**: Quote and schedule PDF downloads now append `resources/documents/legal/full-legal-page.pdf` immediately after the generated quote/schedule pages.
- **Legal-before-datasheets order**: When datasheets are included, merge order is generated quote/schedule PDF, standard legal page, then datasheets.
- **Document-pack template role**: The document-pack builder now includes **Standard Legal Page** as an app-owned template document, separate from the existing uploaded Legal PDF role.
- **Document-pack selector cleanup**: New pack items no longer offer Cover or uploaded Legal in the dropdown; **Unpriced Schedule** is labelled **Schedule**, and **Custom PDF** is available for uploaded one-off documents.
- **Salesforce PDF fingerprints**: Salesforce duplicate-upload fingerprints include the standard legal PDF hash so changes to the legal page trigger a fresh upload.
- **Schedule legal blurb**: The generated quote/schedule template now includes the short legal blurb once at the end of the generated document body, grouped into the final line-item table so it stays with the last schedule rows more reliably.
- **Output datasheet info cleanup**: The Output page datasheet info panel no longer shows the external **Learn more** action.
- **Production PDF diagnostics**: Production PDF troubleshooting was expanded after finding temp-path and Puppeteer Chrome-cache failures on the VPS.
- **Docker service port hardening**: MySQL and Redis host port bindings are loopback-only in `compose.yaml` so they are not exposed publicly by Docker.

## Features completed — 1 July 2026

- **Project reference URLs**: Project routes now prefer `reference_number` in URLs, for example `/projects/20930`, while legacy numeric database IDs still resolve as a fallback.
- **Datasheet embedding**: Include Datasheets is functional for quote and schedule PDFs. The app calls the legacy datasheet endpoint, downloads the generated datasheet PDF, and merges it after the quote/schedule output.
- **Datasheet endpoint compatibility**: The datasheet request is sent as form data with `skus` as a JSON string, matching the legacy endpoint's expected shape and avoiding blank `.pdf` output.
- **Salesforce PDF upload tracking**: Quote and schedule PDF uploads use `salesforce_pdf_uploads` fingerprints to avoid duplicate uploads for unchanged outputs; datasheet-inclusive outputs are fingerprinted separately.
- **PDF generation dialog**: PDF-generating links now show a modal with progress messaging before opening/downloading the generated file.
- **Live datasheet progress**: Datasheet generation streams progress into the modal through `/pdf-progress/{token}` and uses monotonic browser-side progress to avoid the bar moving backwards.
- **Single-PDF progress polish**: Non-streaming PDF downloads use staged fallback progress messages so fast downloads do not appear stuck and then abruptly complete.
- **Timezone correction**: The app timezone is set to `Europe/London` via `APP_TIMEZONE`, so visible times follow BST/GMT correctly.
- **Output PDF notifications**: Successful Salesforce upload notifications from quote/schedule PDF routes were removed to avoid late, misleading notifications; failures still notify.

## Features completed — 30 June 2026

- **Output page rework**: The Output page now uses a status-panel-first layout with Quote status, permission-aware Validation, and datasheet guidance above **Quick Output** / **Document Packs** tabs.
- **Output permissions display**: Validation status and the View Validation action are hidden from users without `validation.view`; underlying output, pricing, document-pack, and approval checks remain enforced server-side.
- **Output copy and actions**: Visible labels avoid `Priced` / `Unpriced` wording, the quote card now reads `Quote with pricing.`, datasheet switches say `Include datasheets`, and primary actions use Filament's orange primary button convention.
- **Document-pack controls**: Generate Combined PDF uses the orange primary style; Delete Pack, Save Pack, and Generate Combined PDF are right-aligned, consistently sized, and do not wrap.
- **Dashboard polish**: Dashboard project-table status and visibility badges no longer wrap; the Rev column is narrower and the freed space is assigned to Status and Visibility.
- **Product picker polish**: The Add Products button in the project product-picker modal uses the same Filament orange primary button convention when enabled.
- **Focused verification**: `AdminProjectResourceTest` and `AdminDocumentPackTest` were run during the Output rework; Pint and `git diff --check` passed after later copy/style adjustments.

---

## Features completed — 23 June 2026

- **P0 revision model**: New projects now begin at revision number `0`, displayed as **P0**. The first user-created revision is **R1**, followed by R2, R3, etc. Shared label helpers prevent UI, activity-log, filename, and export code from manually prepending `R`.
- **P0 PDF metadata**: Quote and schedule PDFs omit all revision labels while at P0, use **Project Location**, and no longer display Contractor.
- **Document packs**: Projects can store multiple named, ordered packs containing uploaded Custom PDFs, the standard legal template, and revision-generated Quote/Schedule PDFs. Legacy saved Cover/Legal items remain supported, but the current new-document dropdown no longer offers them.
- **Revision-aware generation**: Generated pack documents use the revision chosen at download time; project-level uploaded PDFs remain unchanged between revisions.
- **Quote approval safety**: Packs containing a Quote show why generation is blocked and disable the Generate action until the selected revision is validated and approved. Permission and approval checks are repeated by the download controller/service.
- **Drag/drop state fix**: Document cards use stable associative keys so reordering keeps each dropdown, role description, and uploaded file aligned with the same document.
- **Output page layout**: Quote Approval sits above tabs for **Quick PDF/CSV Output** and **Document Packs**. Tabs have a rounded dark segmented style, pack selectors match project-detail input depth, and disabled Quote/Priced CSV actions share one visual treatment.
- **Security and audit trail**: Added `output.manage-document-packs` and `output.produce-document-packs`, project/revision ownership checks, role-specific output permission checks, upload validation, and activity messages for pack save/generate/delete events.
- **Docker runtime**: Sail now builds from the project-owned `docker/8.5` runtime with `qpdf` installed; the MySQL test-database initializer is also project-owned so the runtime is reproducible outside `vendor/`.
- **Focused coverage**: `AdminDocumentPackTest` covers pack persistence/order, merge order, invalid uploads, permissions, ownership, cleanup, and quote approval. `AdminProjectResourceTest` covers the tab layout and active/default output state.

---

## Features completed — 10 June 2026

- **Validation page split into Issues and Validated tables**: unresolved warnings now stay in **Issues**, while clean lines and approved/resolved warning lines move to **Validated** with status, quote price, and notes.
- **Manual validation flagging**: admins can use **Flag Issue** on a validated line to send it back for review. Flagging now requires a short reason. Manual flags are stored on `project_lines.validation_flagged`, and the flag reason plus review/resolution text is stored in `project_lines.validation_note`.
- **Approval is now the lock boundary**: running validation marks a revision **Ready to approve**, but does not lock editing. The **Approve Revision** action opens a confirmation modal and then locks the revision by setting `project_revisions.status = approved`. Approved revisions reject validation and schedule edits server-side.
- **Schedule line statuses**: project lines now show status badges in the schedule (`Pending`, `Priced`, `Unpriced`, or `Approved`). Product-picker additions start as `Pending`; paste pricing sets matching/new rows to `Priced`; rows missing from a paste pass become `Unpriced`.
- **Paste pricing across all areas**: the paste modal gained **Paste across all areas**. When enabled, matching SKUs across the whole revision are repriced without changing existing quantities, missing pasted SKUs are created in the target Area, and existing SKUs absent from the paste are marked `Unpriced`. Turning it off limits updates to the selected Area and updates quantities from the pasted data.
- **Salesforce project form improvements**: Salesforce is now the default create mode. Selected Opportunity names are title-cased with leading acronyms preserved, a loading indicator appears while fetching, required create fields gate the submit action, and Salesforce data now populates cover and value.
- **Project value and cover changes**: `projects.value` was added as a nullable decimal, and `cover_percentage` is now nullable text to support Salesforce cover values. Project copy actions carry `value` forward.
- **Salesforce Opportunity fetch expanded**: `getOpportunityById()` now fetches `CEF_Cover__c` and `Amount` for create-form population.
- **Product import respects approved locks**: catalogue import can still backfill blank line prices on validated-but-unapproved revisions, but skips revisions whose status is `approved`.
- **Production URL hardening**: production boots with `URL::forceRootUrl(config('app.url'))` and `URL::forceScheme('https')`.
- **Panel access contract added**: `User` now implements Filament's `FilamentUser` contract and allows authenticated users to access the panel.
- **Focused tests expanded**: `AdminProjectResourceTest`, `AdminProjectValidationTest`, and `ProductImportTest` now cover create form gating, title-case Salesforce names, paste repricing modes, status badges, validated-line display, manual flagging, approval locking, and import behavior around approved revisions.

---

## Features completed — 8 June 2026

- **Paste products into Areas**: Area headers now include **Paste** beside **Product** and **Blank**. The modal imports tab-delimited `qty, sku, description, price` rows copied from spreadsheets, ignores the pasted description, handles quoted multiline descriptions, uses the pasted price, and fills the line description from the product catalogue when the SKU exists.
- **Product display description**: Product list, product picker, and new project lines use `Product::displayDescription()`. This originally supported the older `v_description` feed; as of 8 July 2026 the importer copies the current API `description` field directly into both `description` and `v_description`.
- **Price mismatch validation workflow**: Validation now flags quote price vs RRP mismatches, shows RRP/Quote inputs, and provides **Match** to update the quote price to RRP. Row buttons and price inputs have been aligned to a consistent height.
- **Revision approval status**: Validated revisions can now be marked **Approved** via the validation page. `project_revisions.status` tracks `draft|approved`; approval is blocked until validation passes and resets to draft if later validation finds issues.

---

## Features completed — 4 June 2026

- **Revision validation and approval workflow**: Active revisions can be checked from `/projects/{id}/validation`. Current rules flag duplicate SKUs within an Area and SKUs missing from the product catalogue.
- **Persistent validation state**: `project_revisions` now stores `validated`, `validated_at`, and `validated_by`. `project_lines` stores `approved`, `approved_at`, and `approved_by`.
- **Warning actions**: Admins can explicitly **Approve** warnings, **Undo** approval, or **Merge** duplicate-SKU lines by summing quantities and deleting duplicates.
- **Revision approval locking**: Approved revisions reject schedule mutations server-side and disable inline editing controls. Validated-but-unapproved revisions remain editable, and creating a new revision from an approved revision produces an editable, unvalidated copy while preserving line approval decisions.
- **Validation service and tests**: Rule evaluation is centralized in `ProjectRevisionValidator`; focused validation coverage lives in `AdminProjectValidationTest.php`. Full suite: 46 tests / 125 assertions.

---

## Features completed — 3 June 2026

- **Server-side revision scoping hardened for project lines**: `ViewProject` now routes mutating area/line actions through scoped helpers that verify the target record belongs to both the current project and `$viewingRevisionId`. This covers line edit, duplicate, delete, sort/move, blank line creation, selected product insertion, and area removal. Cross-project or cross-revision Livewire IDs now fail server-side. Focused coverage lives in `tests/Feature/AdminProjectResourceTest.php`.

- **Activity Logs revision column**: `activity_logs.revision_number` added and displayed as a **Rev** column in `/activity-logs`. New log rows snapshot the project revision number automatically; `revision.created` explicitly stores the newly-created revision number. Older rows may show `—` if they predate the column.

---

## Features completed — 2 June 2026

- **Schedule PDF generation**: A printable A4 lighting schedule can now be downloaded for any project revision via a **Schedule PDF** button in the ViewProject header. The PDF is generated server-side using `spatie/laravel-pdf` (Browsershot / headless Chrome) with `->noSandbox()` for Docker compatibility. Output includes a branded Tamlite header, project meta grid, per-area line tables (code, ref, description, qty, optional unit price/line total, datasheet icon), compact area subtotals, a grand total box, and a quote/general notes block. Line notes render as a full-width `Note:` row below the relevant line. Filenames include the document title, project reference, shared revision label (`P0`/`R1`/...), and timestamp. Non-admin users are auth-scoped (Open projects or their own only). Full implementation details in the [PDF Generation](#pdf-generation) section below.

- **Native TOTP two-factor authentication**: Filament 5's built-in MFA support enabled — no external plugins. Users can set up and manage 2FA (QR code + recovery codes) directly from their profile page. On next login, users who have 2FA enabled are challenged before access is granted.
- **2FA columns on `users` table**: Migration `2026_06_02_074020_add_two_factor_authentication_to_users_table` adds `app_authentication_secret` (encrypted TOTP secret) and `app_authentication_recovery_codes` (encrypted JSON array). Both columns are encrypted at rest via Laravel's built-in encryption and are hidden from model serialization.
- **User model updated**: Implements `HasAppAuthentication` + `HasAppAuthenticationRecovery` interfaces with `InteractsWithAppAuthentication` + `InteractsWithAppAuthenticationRecovery` traits (Filament built-ins). No additional fillable entries needed — traits bypass mass-assignment via direct property assignment.
- **Panel Provider updated**: `->multiFactorAuthentication([AppAuthentication::make()->recoverable()])` registered. 2FA is opt-in by default; add `isRequired: true` to force all users to set it up. MFA challenge screen inherits the panel dark-mode theme automatically.

---

---

## PDF Generation

### Engine

- Package: `spatie/laravel-pdf ^2.11` + `spatie/browsershot` (Puppeteer / headless Chrome)
- Document-pack merger/validator: system `qpdf` binary, invoked through Symfony Process
- **`->noSandbox()` is required** for all PDF generation inside Docker/Sail containers
- `.env` values: `LARAVEL_PDF_NODE_BINARY=/usr/bin/node`, `LARAVEL_PDF_NPM_BINARY=/usr/bin/npm`
- All PDF Blade views use **inline `<style>` only** — no external CSS, no Vite/Tailwind CDN dependency
- CSS `-webkit-print-color-adjust: exact` ensures background colours render in headless Chrome

### Files

| File | Role |
|---|---|
| `resources/views/pdfs/layouts/master.blade.php` | Base A4 layout: `@page` margins, sticky footer with CSS page counter, `@yield` slots |
| `resources/views/pdfs/schedule.blade.php` | Schedule document: header, per-area tables, subtotals, grand total, notes |
| `app/Http/Controllers/ProjectPdfController.php` | Auth + revision resolution + PDF streaming |
| `routes/web.php` | `GET /projects/{project}/pdf/schedule` (auth middleware) → `projects.pdf.schedule` |
| `app/Services/DocumentPackPdfService.php` | Resolves uploaded/generated pack items, validates uploads, and merges PDFs in saved order |
| `app/Http/Controllers/DocumentPackController.php` | Project/revision authorization, combined-PDF download, and generation activity log |
| `config/document-packs.php` | qpdf path, storage disk, upload limit, and process timeout |

### Schedule document layout (A4 portrait)

The schedule table uses a compact A4 portrait layout with the Notes column removed. Line notes render as a separate row below the line, spanning all table columns and starting with bold `Note:`.

| Col | Width | Source |
|---|---|---|
| Code | 12% | `ProjectLine.code` (SKU) |
| Ref | 5% | `ProjectLine.ref` |
| Description | 52% | `ProjectLine.description` |
| Qty | 6% | `ProjectLine.qty` |
| Unit Price | 9% | `ProjectLine.unit_price` (quote PDFs only) |
| Line Total | 9% | `qty × unit_price` (quote PDFs only) |
| Datasheet | 5% | Icon-only external datasheet link when the SKU exists in the local catalogue |

Blank lines with no SKU render empty schedule cells so placeholder rows do not show partial data.

### Auth / access rules

- Route is behind `auth` middleware
- Non-admins may only download PDFs for **Open** projects or projects they own
- A `?revision=X` query parameter selects any revision; defaults to `active_revision_id`
- The ViewProject **Schedule PDF** button automatically passes the currently-viewed `$viewingRevisionId`
- P0 PDFs omit `Rev:` / `Revision`; R1+ PDFs use `ProjectRevision::label()`
- Schedule and quote metadata uses **Project Location** and does not display Contractor
- Document-pack routes additionally require `output.produce-document-packs`; generated item roles enforce their own Quote/Unpriced permissions

---

## Features completed — 29 May 2026

- **Salesforce Opportunities page**: Admin-only page listing all Salesforce Opportunities in a sortable, searchable, paginated Filament table. Default sort is Created Date descending (fixed via service-level fallback — Filament passes `null` for `$sortColumn` on first load with external `->records()` tables).
- **"Salesforce Project" toggle on New Project form**: Toggle switches the creation form into SF mode — hides the free-text name field, shows a live Salesforce typeahead Select, and a Confirm button that pre-populates `reference_number`, `customer_name`, and `owner_email` from the selected Opportunity. All other fields are locked read-only while in SF mode.
- **`name` dehydration fix**: Filament excludes `->hidden()` fields from form state. Fixed by storing the selected Opportunity as JSON in a `Hidden::make('salesforce_pending_data')` field (which IS dehydrated) and extracting `name` from it in `mutateFormDataUsing`.
- **`salesforce_project` DB flag**: New boolean column (`default false`) on `projects`. Saved to DB when creating via the toggle. `Project` model updated with fillable entry and boolean cast.
- **Edit-mode locking for Salesforce projects**: Toggle is disabled (locked ON), search Select and Confirm button are hidden, all form fields are read-only. Name field remains visible (read-only) so the user can see the project name. Applies across both the list-page slide-over and the ViewProject Details edit action.

---

## Features completed — 28 May 2026

- **Activity log completeness**: All 8 `ProjectLine` fields tracked (`code`, `ref`, `description`, `qty`, `unit_price`, `notes`, `type`, `status`)
- **History noise reduction**: Adding a product row (blank → fill fields) collapses into a single history entry instead of 7 separate ones — uses a 5-minute creation window merge keyed on `line_id` in the `product.added` payload
- **Area events in history**: Area creation (`area.created`) and deletion (`area.deleted`) are both logged; deletion captures the full line list before the DB cascade removes them (uses `deleting()` not `deleted()`)
- **Area delete dialog**: Replaced browser `window.confirm()` with the same styled Alpine.js modal used for line deletion — stores `confirmDeleteAreaId` + `confirmDeleteAreaName` in `x-data`
- **User profile page**: Full-panel profile page (not auth-style) accessible from the user menu — fields: name, password, area code, job role. `isSimple: false` on `->profile()` gives full sidebar layout
- **JobRole enum** (`app/Enums/JobRole.php`): `SalesEngineer`, `TradeSalesEngineer`, `Technical`, `ProductDesign` — easy to extend with more cases
- **Display names in UI**: Project owner column and history "Who" column now show the user's display name instead of their email address
- **Salesforce integration foundation**: OAuth2 client credentials auth working; `InterrogateSalesforce` command printing live Opportunity records from Salesforce to the terminal

# Bump Bump
