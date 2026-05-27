# Company App — Project Status

_Last updated: 27 May 2026_

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
  id, name, email, password, role (admin|users)

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

project_areas
  id, project_id (FK), name, sort_order

project_lines
  id, project_area_id (FK), code, description, qty, type (standard|temp)
  unit_price, notes, status, sort_order
```

**Key relationships:**
- `Project` → `hasMany` → `ProjectArea` → `hasMany` → `ProjectLine`
- A new Project auto-creates a "Ground Floor" area on creation (model boot hook)
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
- Body: accordion list of `ProjectArea` cards, each collapsible

### Per-area card
Each area header shows the area name, line count, total qty, and £ total. Buttons in the header:
- **Product** → opens the product picker modal (see below)
- **Blank** → adds an empty `ProjectLine` to the area

Each line is a sortable row (Alpine `x-sort`) with inline-editable fields:
`code` · `description` · `qty` · type badge · `unit_price` · `notes` · status (placeholder) · duplicate + delete actions

Lines can be dragged between areas; the `sortLine(lineId, position, targetAreaId)` method handles cross-area moves within a DB transaction.

### Livewire methods on `ViewProject`

| Method | What it does |
|---|---|
| `getAreas()` | Returns `Collection<ProjectArea>` with lines eager-loaded, ordered by sort_order |
| `addArea()` | Validates `newAreaName`, creates area |
| `removeArea(areaId)` | Deletes area (cascades to lines) |
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
| `productPickerProducts` | Paginated products (15/page) filtered by `$productSearch` (name/SKU/description) and `$productTypeFilter` |
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
- Import **truncates** the products table then bulk-inserts in chunks of 500
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

## Known Gaps / Next Steps (as of 27 May 2026)

- [ ] `ProjectLine.status` column exists but is a placeholder (`–`) in the UI — no logic yet
- [ ] No `product_id` FK on `project_lines` — products are referenced only by copied SKU/name
- [ ] No unit price on `Product` model — lines require manual price entry after adding
- [ ] No tests for the Projects resource or ViewProject interactions
- [ ] No Artisan command yet to trigger `ProductImportService` (needs `make:command`)
- [ ] `cover_percentage` / `branch_name` fields exist on Project but are not surfaced in the form yet
- [ ] Project totals (across all areas) not shown at the page level
- [ ] No PDF / export functionality yet
