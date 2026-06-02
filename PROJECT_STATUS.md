# Company App — Project Status

_Last updated: 2 June 2026_

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
| PHP | 8.3+ |
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
  id, site, product_name, sku, description, type_name
  length_mm, width_mm, depth_mm, diameter_mm, cut_out_mm
  weight_kg, luminaire_wattage_w, lumens_lm, efficacy_llm_w
  beam_angle_fwhm, emergency_lumen_output, power, em_power
  cct_k, colour_temp, cri, dali, vision_type, emergency_type
  ip_rating, ik_rating, electrical_class, rl_ral

projects
  id, user_id (FK), name, reference_number, customer_name, contractor
  site_location, owner_email, created_by_email, department
  date, revision, visibility (open|private), status (draft|in_progress|complete|cancelled)
  branch_name, cover_percentage, quote_notes, internal_notes, general_notes
  active_revision_id (FK → project_revisions, nullOnDelete)
  last_edited_at (nullable timestamp)
  last_edited_by (FK → users, nullable, nullOnDelete)

project_revisions
  id, project_id (FK), revision_number, created_by (FK → users)
  unique(project_id, revision_number)

project_presences
  project_id (FK), user_id (FK), last_seen_at
  (no timestamps — composite PK implied by upsert)

project_areas
  id, project_id (FK), project_revision_id (FK → project_revisions), name, sort_order

project_lines
  id, project_area_id (FK), code, description, qty, type (standard|temp)
  unit_price, notes, status, sort_order
```

**Key relationships:**
- `Project` → `hasMany` → `ProjectRevision` → `hasMany` → `ProjectArea` → `hasMany` → `ProjectLine`
- `Project::activeRevision()` — BelongsTo the currently active revision
- `Project::activeViewers()` — HasManyThrough User via ProjectPresence (last 90 seconds, excludes self)
- `Project::lastEditor()` — BelongsTo User via `last_edited_by`
- `ProjectRevision::creator()` — BelongsTo User via `created_by`
- A new Project auto-creates revision #1 on creation (model boot hook) and a default area
- `ProjectArea` has computed accessors: `line_total_qty` and `line_total` (qty × unit_price sum)
- `ProjectLine.code` stores the product SKU; `ProjectLine.description` stores the product name — there is **no `product_id` FK** on project lines

---

## Enums

| Enum | Values |
|---|---|
| `UserRole` | `Admin`, `Users` |
| `ProjectStatus` | `Draft`, `InProgress`, `Complete`, `Cancelled` |
| `ProjectVisibility` | `Open`, `Private` |
| `ProjectLineType` | `Standard`, `Temp` (amber highlight in UI) |

---

## Filament Resources & Routes

| URL | Resource | Notes |
|---|---|---|
| `/products` | `ProductResource` | List only — no create/edit pages (data comes from API) |
| `/projects` | `ProjectResource` | List + custom View page |
| `/projects/{id}` | `ViewProject` (custom) | Main working page — see below |
| `/users` | `UserResource` | Admin-only create/edit/list |

**Project visibility scoping** — `ProjectResource::getEloquentQuery()` restricts non-admin users to projects where `visibility = open` OR `user_id = auth user`.

Each Resource follows the **split-file pattern**: `Resource.php` → delegates to `Schemas/XForm.php`, `Tables/XTable.php`, and `Pages/`.

---

## The Project View Page (`/projects/{id}`)

This is the most complex page. It is a custom `ViewRecord` Livewire component with its own Blade template (`resources/views/filament/resources/projects/pages/view-project.blade.php`).

### Layout
- Page heading = project name; subheading = customer · contractor · site · revision
- Header action: **Areas** button → opens a Filament modal to manage area names
- **Concurrent editors banner** (blue): shown when other users have the project open (within 90 s); lists their names; has a Refresh button
- **Viewing old revision banner** (amber): shown when the user is browsing a non-active revision
- Body: accordion list of `ProjectArea` cards, each collapsible

### Per-area card
Each area header shows the area name, line count, total qty, and £ total. Buttons in the header:
- **Product** → opens the product picker modal (see below)
- **Blank** → adds an empty `ProjectLine` to the area

Each line is a sortable row (Alpine `x-sort`) with inline-editable fields:
`code` · `description` · `qty` · type badge · `unit_price` · `notes` · status (placeholder) · duplicate + delete actions

Lines can be dragged between areas; the `sortLine(lineId, position, targetAreaId)` method handles cross-area moves within a DB transaction.

### Revision Management

A **Revisions** header button opens a modal listing all revisions. From there users can:
- **View** any revision (sets `$viewingRevisionId`; the page re-queries areas/lines for that revision)
- **Set Active** — updates `projects.active_revision_id`; amber banner disappears
- **Create New Revision** — copies all areas and lines from the currently viewed revision into a new `ProjectRevision`

All area/line operations (add, edit, delete, sort) are scoped to `$viewingRevisionId`, not the active revision.

### Concurrent Editing Detection

The outer `<div>` has `wire:poll.30s="heartbeat"`. On each poll (and on `mount`):
- `heartbeat()` upserts a `ProjectPresence` row (`project_id`, `user_id`, `last_seen_at = now()`)
- Purges presence rows older than 90 seconds
- `#[Computed] concurrentEditors()` returns other users whose `last_seen_at` is within 90 s

### Livewire methods on `ViewProject`

| Method | What it does |
|---|---|
| `heartbeat()` | Upserts presence, purges stale, refreshes computed |
| `setActiveRevision(revisionId)` | Updates `projects.active_revision_id` |
| `createNewRevision()` | Copies areas+lines from `$viewingRevisionId` into new revision |
| `getAreas()` | Returns areas for `$viewingRevisionId` with lines eager-loaded |
| `addArea()` | Validates `newAreaName`, creates area under `$viewingRevisionId` |
| `removeArea(areaId)` | Deletes area (cascades to lines); checks area belongs to current revision |
| `addProduct(areaId)` | Alias → calls `openProductPicker(areaId)` |
| `addBlankLine(areaId)` | Creates an empty Standard line |
| `updateLineField(lineId, field, value)` | Inline edit — allowlist: `code, description, qty, unit_price, notes` |
| `duplicateLine(lineId)` | Replicates line, inserts after original, re-sequences sort_order |
| `deleteLine(lineId)` | Hard deletes line |
| `sortLine(lineId, newPos, targetAreaId)` | Moves line, handles cross-area in a transaction |
| `openProductPicker(areaId)` | Sets target area, resets picker state, opens modal |
| `closeProductPicker()` | Closes modal, clears selections |
| `toggleProductSelection(productId)` | Adds/removes product from `$productSelections` map |
| `setProductSelectionQty(productId, qty)` | Updates qty for a selected product |
| `addSelectedProducts()` | Creates `ProjectLine` records from selections, then closes modal |

### `#[Computed]` properties

| Property | What it returns |
|---|---|
| `concurrentEditors` | Other users with presence within 90 s |
| `projectRevisions` | All revisions for this project with creator eager-loaded |
| `productPickerProducts` | Paginated products (15/page) filtered by search + type |
| `productTypeOptions` | Distinct non-null `type_name` values for the filter dropdown |

---

## Product Picker Modal

Opened when the user clicks **Product** on any area header. Rendered inline at the bottom of the view template with `z-[9999]` (not teleported — `@teleport` was removed due to a Livewire 4 bug when teleporting conditionally-empty content).

**Features:**
- Live search (250 ms debounce) across `product_name`, `sku`, `description`
- Type filter dropdown (populated from distinct DB values)
- Paginated product list (15 rows, Prev/Next controls)
- Clicking a row toggles a custom checkbox; selected rows highlight in primary colour
- Qty input appears per-row only when that product is selected
- Footer shows count of selected products and an **Add N Products** button (disabled until ≥1 selected)
- On add: creates one `ProjectLine` per selected product (`code` = SKU, `description` = product name, `qty` from picker)

**Livewire state properties:**
```
$productPickerOpen (bool)
$productPickerAreaId (?int)
$productSearch (string)
$productTypeFilter (string)
$productPage (int)
$productSelections (array<int, array{qty: int}>)  — keyed by product_id
```

---

## Product Catalogue

- Data source: external API `POST https://tcms.tamlite.co.uk/api/product_data`
- Import handled by `App\Services\ProductImportService`
- Import **deletes** all existing products then bulk-inserts in chunks of 500 (DELETE used over TRUNCATE to respect FK constraints)
- `site` values seen in factory: `xcite`, `tamlite`, `luxena`
- No create/edit UI — read-only in Filament, imported via Artisan or tests

---

## Test Coverage

| Test file | Area covered |
|---|---|
| `Feature/AuthenticationTest.php` | Login / auth flows |
| `Feature/AdminProductResourceTest.php` | Filament Products list page (admin) |
| `Feature/AdminUserResourceTest.php` | Filament Users CRUD (admin) |
| `Feature/FrontEndProductsTest.php` | Products list for non-admin |
| `Feature/ProductImportTest.php` | `ProductImportService` — happy path, API failure, structure error |
| `Feature/ExampleTest.php` | Smoke test |
| `Unit/ExampleTest.php` | Smoke test |

**Not yet covered by tests:** Projects resource, ViewProject page interactions, product picker, area/line management.

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

---

## Salesforce Integration (updated 29 May 2026)

### Status: Salesforce Opportunities page live; project import UX built

Authentication uses **OAuth2 Client Credentials**. The service is bound as a singleton in `AppServiceProvider`. Gate `view-salesforce` restricts the Salesforce page to Admin users only.

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
| `app/Filament/Pages/Salesforce.php` | Admin-only Filament page showing Opportunities table |
| `resources/views/filament/pages/salesforce.blade.php` | Blade template for the page |
| `config/services.php` | `salesforce` key: `client_id`, `client_secret`, `url` |
| `app/Providers/AppServiceProvider.php` | Singleton binding + gate definition |

### Service methods

| Method | What it does |
|---|---|
| `getAccessToken(): ?string` | Private — POSTs to `{host}/services/oauth2/token`; returns Bearer token or null |
| `fetchOpportunities(int $page, int $perPage, ?string $sortColumn, ?string $sortDirection): array` | SOQL v65.0 query with pagination and sort; returns `['data', 'total']`. Falls back to `ORDER BY CreatedDate DESC` when `$sortColumn` is null (Filament passes null on first load for external `->records()` tables) |
| `searchOpportunities(string $query, int $limit = 10): array` | Typeahead — `WHERE Name LIKE '%…%' ORDER BY Name ASC`; returns `[Id => 'Name (Reference)']` for Select options |
| `getOpportunityById(string $id): ?array` | Fetches a single Opportunity by Id; returns `[Id, Name, Project_Reference_Number__c, Owner.Name, Owner.Email, Account.Name]` or null |

### Salesforce Opportunities page

- Admin-only page at `/salesforce` (navigation icon: cloud, group: Salesforce)
- Displays a Filament `->records()` external-data table of Opportunities
- Columns: Name, Reference Number, Stage, Account, Owner, Close Date, Created Date
- Default sort: **Created Date descending** (both `->defaultSort()` on the table and the service fallback)
- Searchable, sortable, paginated (15 per page)

### "Salesforce Project" toggle on the New Project form

When creating a project, a **Salesforce Project** toggle is available:

- **Toggle OFF** (default): normal free-text project creation
- **Toggle ON**: hides the `name` field and shows a live Salesforce search Select
  - Typeahead searches Opportunity names in real time via `searchOpportunities()`
  - Selecting an Opportunity stores its data as JSON in a hidden `salesforce_pending_data` field
  - A **Confirm & Populate Form** button appears — clicking it populates `reference_number`, `customer_name`, and `owner_email` from the Opportunity data
  - All other form fields become read-only while SF mode is on
  - `name` is extracted from the Opportunity JSON in `mutateFormDataUsing` (because the `name` TextInput is hidden and therefore not dehydrated by Filament)
  - `salesforce_project = true` is saved to the DB via the `projects.salesforce_project` column

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

## Known Gaps / Next Steps (as of 29 May 2026)

- [ ] `ProjectLine.status` column exists but is a placeholder (`–`) in the UI — no logic yet
- [ ] No `product_id` FK on `project_lines` — products are referenced only by copied SKU/name
- [ ] No unit price on `Product` model — lines require manual price entry after adding
- [ ] No tests for the Projects resource or ViewProject interactions (revision management, presence, product picker, area/line management)
- [ ] No Artisan command yet to trigger `ProductImportService` (needs `make:command`)
- [ ] `cover_percentage` / `branch_name` fields exist on Project but are not surfaced in the form yet
- [ ] Project totals (across all areas) not shown at the page level
- [ ] No PDF / export functionality yet
- [ ] Bearer token for Salesforce is fetched fresh on every call — should be cached for its ~1 hour lifetime
- [ ] No tests covering the Salesforce service (`Http::fake()` for auth success, auth failure, query failure)
- [ ] No two-way sync yet — Salesforce projects are imported once at creation; changes in Salesforce are not reflected back

---

## Features completed — 2 June 2026

- **Native TOTP two-factor authentication**: Filament 5's built-in MFA support enabled — no external plugins. Users can set up and manage 2FA (QR code + recovery codes) directly from their profile page. On next login, users who have 2FA enabled are challenged before access is granted.
- **2FA columns on `users` table**: Migration `2026_06_02_074020_add_two_factor_authentication_to_users_table` adds `app_authentication_secret` (encrypted TOTP secret) and `app_authentication_recovery_codes` (encrypted JSON array). Both columns are encrypted at rest via Laravel's built-in encryption and are hidden from model serialization.
- **User model updated**: Implements `HasAppAuthentication` + `HasAppAuthenticationRecovery` interfaces with `InteractsWithAppAuthentication` + `InteractsWithAppAuthenticationRecovery` traits (Filament built-ins). No additional fillable entries needed — traits bypass mass-assignment via direct property assignment.
- **Panel Provider updated**: `->multiFactorAuthentication([AppAuthentication::make()->recoverable()])` registered. 2FA is opt-in by default; add `isRequired: true` to force all users to set it up. MFA challenge screen inherits the panel dark-mode theme automatically.

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
