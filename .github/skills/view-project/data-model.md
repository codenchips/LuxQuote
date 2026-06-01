# Data Model Reference — ViewProject

## Project

| Column | Type | Notes |
|---|---|---|
| `id` | int | |
| `user_id` | FK → users | Owner |
| `name` | string | Page title |
| `active_revision_id` | FK → project_revisions (nullOnDelete) | Currently active revision |
| `revision` | int | Mirrors `active_revision.revision_number` (denormalized) |
| `last_edited_at` | datetime\|null | Set by observers |
| `last_edited_by` | FK → users\|null (nullOnDelete) | Set by observers |

**Relationships:**
- `activeRevision()` — BelongsTo ProjectRevision
- `revisions()` — HasMany ProjectRevision
- `activeViewers()` — HasManyThrough User via ProjectPresence (last 90s, excludes self)
- `lastEditor()` — BelongsTo User via `last_edited_by`

**Booted hook:** creates revision_number=1 + default area on model creation.

---

## ProjectRevision

| Column | Type | Notes |
|---|---|---|
| `id` | int | |
| `project_id` | FK → projects | |
| `revision_number` | int | Unique per project |
| `created_by` | FK → users | |

Unique constraint: `[project_id, revision_number]`

**Relationships:** `project()`, `creator()` (BelongsTo User via `created_by`), `areas()` HasMany ProjectArea.

---

## ProjectArea

| Column | Type | Notes |
|---|---|---|
| `id` | int | |
| `project_id` | FK → projects | |
| `project_revision_id` | FK → project_revisions | Always filter by this |
| `name` | string | |
| `sort_order` | int | |

**Relationships:** `revision()` BelongsTo ProjectRevision, `lines()` HasMany ProjectLine.

**Computed accessors:**
- `line_total_qty` — sum of `qty` across all lines
- `line_total` — sum of `qty × unit_price` across all lines

---

## ProjectLine

| Column | Type | Notes |
|---|---|---|
| `id` | int | |
| `project_area_id` | FK → project_areas | |
| `code` | string\|null | Product SKU (display only) |
| `ref` | string\|null | Uppercase, max 6 chars |
| `description` | string\|null | Product name (display only) |
| `qty` | int | |
| `type` | string | `ProjectLineType` enum value |
| `unit_price` | decimal\|null | |
| `notes` | string\|null | |
| `sort_order` | int | |

**No `product_id` FK** — `code` and `description` are plain strings copied from the product at line creation.

**Enum — ProjectLineType:**
| Value | Label | Meaning |
|---|---|---|
| `standard` | Standard | Unchanged product line |
| `modified` | Modified | Product line with edited code/description |
| `custom` | Custom | Blank line, no product source |

---

## ProjectPresence

| Column | Type | Notes |
|---|---|---|
| `project_id` | FK → projects | Composite key (upsert target) |
| `user_id` | FK → users | Composite key (upsert target) |
| `last_seen_at` | datetime | Updated on each heartbeat |

`$timestamps = false` — no `created_at`/`updated_at`.
