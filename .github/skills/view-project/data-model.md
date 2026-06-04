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
| `validated` | bool | Default false; locks revision when true |
| `validated_at` | datetime\|null | Last successful validation time |
| `validated_by` | FK → users\|null (nullOnDelete) | User who completed validation |

Unique constraint: `[project_id, revision_number]`

**Relationships:** `project()`, `creator()` (BelongsTo User via `created_by`), `validator()` (BelongsTo User via `validated_by`), `areas()` HasMany ProjectArea.

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
| `product_id` | FK → products\|null (nullOnDelete) | Product origin tracking |
| `code` | string\|null | Product SKU (display only) |
| `ref` | string\|null | Uppercase, max 6 chars |
| `description` | string\|null | Product name (display only) |
| `qty` | int | |
| `type` | string | `ProjectLineType` enum value |
| `unit_price` | decimal\|null | |
| `notes` | string\|null | |
| `approved` | bool | Default false; clean lines may be auto-approved |
| `approved_at` | datetime\|null | Approval time |
| `approved_by` | FK → users\|null (nullOnDelete) | Admin for explicit approvals; null for automatic clean-line approval |
| `sort_order` | int | |

`code` and `description` are copied display values. `product_id` only tracks origin and may become null if the catalogue product is deleted.

**Approval semantics:**
- New and cloned lines default to unapproved.
- Validation auto-approves clean lines with `approved_by = null`.
- Explicit warning approval records `approved_by`.
- Editing a line clears its approval metadata.

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
