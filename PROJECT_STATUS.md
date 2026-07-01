# Company App — Project Status

_Last updated: 30 June 2026_

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
  branch_name, cover_percentage (string, nullable), value (decimal, nullable)
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
  unit_price, notes, status
  validation_flagged (bool), validation_note (nullable string)
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
```

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
- `Product.description` is the display description derived during import: Xcite uses `v_description`; other sites use `product_name + ' ' + v_description`
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
| `DocumentPackItemRole` | `Cover`, `Legal`, `Quote`, `UnpricedSchedule` |
| `DocumentPackItemSource` | `Uploaded`, `Generated`, `Template` (reserved for the future template phase) |

---

## Filament Resources & Routes

| URL | Resource | Notes |
|---|---|---|
| `/products` | `ProductResource` | List only — no create/edit pages (data comes from API) |
| `/projects` | `ProjectResource` | List + custom View page |
| `/projects/{id}` | `ViewProject` (custom) | Main working page — see below |
| `/projects/{id}/validation` | `ValidationProject` | Admin-only validation, warning approval, and duplicate merge |
| `/projects/{id}/output` | `OutputProject` | Quote approval summary, quick PDF/CSV outputs, and document-pack builder |
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
| `updateLineField(lineId, field, value)` | Inline edit — allowlist: `code, ref, description, qty, unit_price, notes` |
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
4. Manually flagged lines should be reviewed before approval.

SKU comparison for validation is case-insensitive and trims surrounding whitespace.

### Validation lifecycle

- New revisions default to `validated = false`.
- New lines default to `approved = false`; cloned lines keep their existing approval state, approver, timestamps, and validation notes.
- **Run Validation** re-evaluates all current rules.
- Lines with no warnings are automatically approved with `approved_by = null`.
- Warning lines remain unresolved until an admin explicitly approves the warning or resolves it by merging duplicates.
- A revision becomes validated only when no unresolved warnings remain and every line is approved.
- Validating records `validated_at` and `validated_by`, then marks the revision **Ready to approve**.
- Validated-but-unapproved revisions remain editable and can be revalidated after edits.
- Once a revision is validated, admins can click **Approve Revision**, confirm the lock modal, and set `project_revisions.status = approved`.
- Approved revisions reject validation and schedule mutation actions server-side.

### Warning actions

| Action | Behavior |
|---|---|
| **Approve** | Explicitly approves all lines affected by that warning and records `approved_at` / `approved_by` |
| **Undo** | Removes explicit approval for the warning; the revision becomes unvalidated if the warning is unresolved |
| **Merge** | Available for duplicate-SKU warnings; keeps the first line, sums quantities, deletes the other duplicates, and approves the remaining line |
| **Match** | Available for price mismatch warnings; updates the quote price to the catalogue RRP and re-runs validation |
| **Flag Issue** | Moves a validated line back into the Issues list for admin review |

Explicit warning approval is distinguished from automatic clean-line approval by `approved_by`: explicit approval has a user ID; automatic approval uses null.

### Validated lines table

The validation page now separates unresolved warnings from resolved lines:

- **Issues** shows only unresolved validation warnings.
- **Validated** shows clean lines and explicitly approved warning lines.
- Each validated row includes status (`Resolved` or `Approved`), quote price, note text, and **Flag Issue** when the revision is not approved.
- Resolution and approval notes are stored on `project_lines.validation_note`.
- Manual flags are stored with `project_lines.validation_flagged = true` and generate a validation issue until resolved.

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

- Data source: external API `POST https://tcms.tamlite.co.uk/api/product_data`
- Import handled by `App\Services\ProductImportService`
- Import **deletes** all existing products then bulk-inserts in chunks of 500 (DELETE used over TRUNCATE to respect FK constraints)
- Import backfills blank project line prices from matching SKUs unless the parent revision status is `approved`.
- API field `SKU` is mapped to local `sku`
- API field `v_description` is stored separately and also used to build local `description`
- Local `description` is derived during import:
  - `site = xcite`: `description = v_description`
  - all other sites: `description = product_name + ' ' + v_description`
- `site` values seen in factory: `xcite`, `tamlite`, `luxena`
- No create/edit UI — read-only in Filament, imported via Artisan or tests

---

## Output Page & Document Packs

The project output page is available at `/projects/{id}/output`. It starts with a three-part status panel and then a two-tab output selector:

- **Quote status** — shows `Approval Required`, `Requested`, or `Approved`, and includes **Request Approval** when the active revision is not approved.
- **Validation** — shows `Passed` or `Not passed`, plus **View Validation**. This entire section is hidden from users without `validation.view`.
- **Datasheet note** — explains that outputs include links to product datasheets and that datasheets can later be embedded when the switches become functional.

The output selector tabs are:

- **Quick Output** — Quote PDF, quote CSV, schedule PDF, and schedule CSV. Visible labels avoid `Priced` / `Unpriced` wording; permissions still enforce the underlying priced/unpriced capabilities.
- **Document Packs** — named, reusable project-level definitions that combine uploaded and generated PDFs into one download.

Primary generation and request actions use Filament's orange primary button convention. The active Output tab uses an orange underline that works in light and dark mode.

### Pack workflow

- Users may save multiple uniquely named packs against a project. Packs carry across revisions.
- Pack items are compact six-across cards. Items are draggable and may be added at the end, removed, or reordered. Stable UUID/item keys keep each role, upload, preview, and filename attached to the correct card after a drag.
- Initial roles are **Cover** and **Legal** (uploaded PDFs), plus **Quote** and **Unpriced Schedule** (generated at output time).
- Uploaded PDFs remain project-level. Generated items always use the revision selected when the pack is generated, not the revision that was active when the pack was saved.
- Uploaded-file cards accept click-to-select and drag/drop. Client-side guards reject non-PDF files before upload, and server-side validation still enforces `mimes:pdf`.
- Uploaded PDFs show a first-page thumbnail that opens the saved document in a new tab. Unsaved uploads/replacements use a local browser preview and preserve the selected filename until the pack is saved.
- Replacement uploads explicitly clear the previous pending upload before starting the next upload so repeated replacements do not alternate against stale Livewire temporary-file state.
- Uploaded files default to a 25 MB limit and are checked with `qpdf --check`; corrupt, encrypted, or unsupported PDFs are rejected before they can be merged.
- Pack generation uses `qpdf` server-side to concatenate every page of each selected document in the saved order. Temporary generated inputs and the merged output are cleaned up after use/download.
- Saving, generating, and deleting packs creates `document_pack.saved`, `document_pack.generated`, and `document_pack.deleted` activity entries.
- The Document Pack footer keeps Delete Pack, Save Pack, and Generate Combined PDF right-aligned with matching button dimensions and non-wrapping labels.
- The `Template` source enum is reserved for a later phase where users can choose system-defined document templates.

### Revision and approval behavior

- A pack containing a Quote cannot be generated until the selected revision has passed validation and has status `approved`.
- The Generate button is disabled with an explanatory message while approval is missing; the download controller and PDF service repeat the check server-side.
- Generated Quote/Schedule cards show revision context as `{label} - {line count} SKU's, {qty total} Items`, plus a `Last modified dd/mm/yy hh:mm` line based on the latest project-line update in the selected revision.
- Quote and unpriced-schedule roles also enforce their underlying output permissions. Cross-project pack/revision combinations return 404/403 rather than leaking data.

### Configuration

`config/document-packs.php` supports:

- `QPDF_BINARY` (default `qpdf`)
- `DOCUMENT_PACK_DISK` (default `local`)
- `DOCUMENT_PACK_MAX_UPLOAD_KB` (default `25600`)
- `DOCUMENT_PACK_PROCESS_TIMEOUT` (default `60` seconds)

## Test Coverage

| Test file | Area covered |
|---|---|
| `Feature/AuthenticationTest.php` | Login / auth flows |
| `Feature/AdminProductResourceTest.php` | Filament Products list page (admin) |
| `Feature/AdminProjectResourceTest.php` | ViewProject server-side revision/project scoping for line actions; paste products, create form gating, status badges, Activity Logs revision display |
| `Feature/AdminProjectValidationTest.php` | Revision validation, validated-lines table, manual flagging, automatic/explicit approval, Undo, Merge, revalidation, and approval locking |
| `Feature/AdminDocumentPackTest.php` | Pack CRUD/order, uploaded PDF validation, revision-aware merge order, permissions, project ownership, cleanup, and quote-approval generation lock |
| `Feature/AdminUserResourceTest.php` | Filament Users CRUD (admin) |
| `Feature/FrontEndProductsTest.php` | Products list for non-admin |
| `Feature/ProductImportTest.php` | `ProductImportService` — happy path, API failure, structure error |
| `Feature/SalesforceServiceTest.php` | `SalesforceService` — OAuth success, auth failure, SOQL query failure, and bearer-token caching via `Http::fake()` |
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
- **Project** — stored `project_name_snapshot`
- **Rev** — stored `revision_number`, formatted through the shared revision label helper as `P0`, `R1`, `R2`, etc.; older rows may show `—`
- **Action Performed** — formatted from `action_type` and `payload`
- **Date & Time** — `created_at`

`ActivityLog` snapshots `revision_number` automatically from the attached project's current `revision` when a row is created. `revision.created` passes the newly-created revision number explicitly so the log row represents the revision that was created, not the previous active revision.

Tracked action types include project create/update/delete, revision creation, area create/delete, product add, line update, and the legacy `line.qty_updated` type.

---

## Salesforce Integration (updated 29 May 2026)

### Status: Salesforce Opportunities page live; project import UX built

Authentication uses **OAuth2 Client Credentials**. The service is bound as a singleton in `AppServiceProvider`. Gate `view-salesforce` restricts the Salesforce page to Admin users only.

Successful bearer-token responses are cached for their reported lifetime, minus a 60-second safety buffer, so normal Salesforce reads and writes do not request a fresh OAuth token for every service call.

Salesforce PDF uploads are tracked per project, revision, and document type. When a quote or schedule PDF is requested with Salesforce upload enabled, the app compares a stable fingerprint of the output data and PDF template against `salesforce_pdf_uploads`; if it matches the last successful upload, no new Salesforce `ContentVersion` is created. Changes to the revision, line content, notes, pricing for quote output, project PDF metadata, or the schedule template produce a new fingerprint and upload a fresh version.

Successful quote/schedule PDF uploads from download routes are recorded in activity history but do not queue Filament success notifications, because PDF responses can display queued notifications late on a later app page. Upload failures still send a danger notification.

### Environment variables required (`.env`)

```dotenv
SALESFORCE_API_KEY=           # Consumer Key from the Connected App
SALESFORCE_CONSUMER_SECRET=   # Consumer Secret
SALESFORCE_BASE_URL=          # e.g. https://your-org.my.salesforce.com
```

### Files

| File | Role |
|---|---|
| `app/Services/SalesforceService.php` | Auth + data methods (singleton) |
| `app/Services/SalesforcePdfUploadTracker.php` | Fingerprints generated PDF output and records the last successful Salesforce upload |
| `app/Filament/Pages/Salesforce.php` | Admin-only Filament page showing Opportunities table |
| `resources/views/filament/pages/salesforce.blade.php` | Blade template for the page |
| `config/services.php` | `salesforce` key: `client_id`, `client_secret`, `url` |
| `app/Providers/AppServiceProvider.php` | Singleton binding + gate definition |

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
| `authenticate(): ?array` | Private — POSTs to `{host}/services/oauth2/token`; returns `['token', 'instanceUrl']` or null |
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

### "Salesforce Project" toggle on the New Project form

When creating a project, a **Salesforce Project** toggle is available:

- **Toggle ON** (default): hides the `name` field and shows a live Salesforce search Select
- **Toggle OFF**: normal free-text project creation
  - Typeahead searches Opportunity names in real time via `searchOpportunities()`
  - Selecting an Opportunity stores its data as JSON in a hidden `salesforce_pending_data` field
  - Opportunity names are normalised to title case before saving
  - A loading indicator appears while the selected Opportunity is fetched
  - A **Confirm & Populate Form** button appears — clicking it populates `reference_number`, `customer_name`, `owner_email`, `cover_percentage`, and `value` from the Opportunity data
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

## Known Gaps / Next Steps (as of 30 June 2026)

- [ ] No two-way sync yet — Salesforce projects are imported once at creation; changes in Salesforce are not reflected back
- [ ] Validation currently covers duplicate SKU, missing SKU, price mismatch, and manual flags; output-readiness and other approval rules remain to be added
- [ ] Document-pack template sources and additional roles (for example case studies) are planned but not yet implemented
- [ ] Review the Output page visually in dark mode across desktop widths
- [ ] Review the Output page in light mode, especially orange actions, tab underline, status chips, and disabled buttons
- [ ] Check mobile/tablet layout for the top status panel and the two document cards
- [ ] Decide whether Include Datasheets switches should stay disabled, become functional, or be hidden until implemented
- [ ] Add browser-level coverage for the Output page layout if visual regressions continue
- [ ] Standardize a shared Blade/CSS helper for Filament-style primary buttons used outside native Filament actions
- [ ] Consider applying the same shared button helper to other custom modals and project-page actions
- [ ] Revisit the Document Packs builder PDF preview behavior for uploaded files that return 404
- [ ] Run a final focused regression pass before deployment:
  - `vendor/bin/sail artisan test --compact tests/Feature/AdminProjectResourceTest.php`
  - `vendor/bin/sail artisan test --compact tests/Feature/AdminDocumentPackTest.php`
  - `vendor/bin/sail artisan test --compact tests/Feature/AdminProjectValidationTest.php`

---

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
- **Document packs**: Projects can store multiple named, ordered packs containing uploaded Cover/Legal PDFs and revision-generated Quote/Unpriced Schedule PDFs. Packs are reusable across revisions and merge into one PDF with `qpdf`.
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
- **Manual validation flagging**: admins can use **Flag Issue** on a validated line to send it back for review. Manual flags are stored on `project_lines.validation_flagged`, and review/resolution text is stored in `project_lines.validation_note`.
- **Approval is now the lock boundary**: running validation marks a revision **Ready to approve**, but does not lock editing. The **Approve Revision** action opens a confirmation modal and then locks the revision by setting `project_revisions.status = approved`. Approved revisions reject validation and schedule edits server-side.
- **Schedule line statuses**: project lines now show status badges in the schedule (`Pending`, `Priced`, `Unpriced`, or `Approved`). Product-picker additions start as `Pending`; paste pricing sets matching/new rows to `Priced`; rows missing from a paste pass become `Unpriced`.
- **Paste pricing across all areas**: the paste modal gained **Paste across all areas**. When enabled, matching SKUs across the whole revision are repriced without changing existing quantities, missing pasted SKUs are created in the target Area, and existing SKUs absent from the paste are marked `Unpriced`. Turning it off limits updates to the selected Area and updates quantities from the pasted data.
- **Salesforce project form improvements**: Salesforce is now the default create mode. Selected Opportunity names are title-cased, a loading indicator appears while fetching, required create fields gate the submit action, and Salesforce data now populates cover and value.
- **Project value and cover changes**: `projects.value` was added as a nullable decimal, and `cover_percentage` is now nullable text to support Salesforce cover values. Project copy actions carry `value` forward.
- **Salesforce Opportunity fetch expanded**: `getOpportunityById()` now fetches `CEF_Cover__c` and `Amount` for create-form population.
- **Product import respects approved locks**: catalogue import can still backfill blank line prices on validated-but-unapproved revisions, but skips revisions whose status is `approved`.
- **Production URL hardening**: production boots with `URL::forceRootUrl(config('app.url'))` and `URL::forceScheme('https')`.
- **Panel access contract added**: `User` now implements Filament's `FilamentUser` contract and allows authenticated users to access the panel.
- **Focused tests expanded**: `AdminProjectResourceTest`, `AdminProjectValidationTest`, and `ProductImportTest` now cover create form gating, title-case Salesforce names, paste repricing modes, status badges, validated-line display, manual flagging, approval locking, and import behavior around approved revisions.

---

## Features completed — 8 June 2026

- **Paste products into Areas**: Area headers now include **Paste** beside **Product** and **Blank**. The modal imports tab-delimited `qty, sku, description, price` rows copied from spreadsheets, ignores the pasted description, handles quoted multiline descriptions, uses the pasted price, and fills the line description from the product catalogue when the SKU exists.
- **Product display description**: Product import now stores API `v_description` and derives local `products.description` from `product_name + v_description`, except Xcite products which use `v_description` only. Product list, product picker, and new project lines use `Product::displayDescription()`.
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

- **Schedule PDF generation**: A printable A4 lighting schedule can now be downloaded for any project revision via a **Schedule PDF** button in the ViewProject header. The PDF is generated server-side using `spatie/laravel-pdf` (Browsershot / headless Chrome) with `->noSandbox()` for Docker compatibility. Output includes a branded Tamlite header, project meta grid, per-area line tables (code, ref, description, qty, optional unit price/line total, datasheet icon), area subtotals, a grand total box, and a quote/general notes block. Line notes render as a full-width `Note:` row below the relevant line. Modified and Custom lines are visually distinguished with coloured left-side rules. Filenames include the document title, project reference, shared revision label (`P0`/`R1`/...), and timestamp. Non-admin users are auth-scoped (Open projects or their own only). Full implementation details in the [PDF Generation](#pdf-generation) section below.

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
