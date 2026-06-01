---
name: project-lines
description: "Use when adding, editing, or querying ProjectLine records; working with the ProjectLineType enum (Standard/Modified/Custom); implementing line field updates; adding new columns to project lines; understanding line cloning during revision creation; or working with ProjectLine/ProjectArea observers and activity logging."
---

# Project Lines Skill

## When to Use

- Adding a new column/field to `project_lines`
- Changing how line fields are updated in `ViewProject`
- Adding new line operations (new buttons, bulk actions, etc.)
- Working with line type logic (`Standard`/`Modified`/`Custom`)
- Understanding the activity log trail for line events
- Debugging observer behaviour on line save/delete
- Cloning lines during revision creation

---

## Architecture

```
ProjectLine (Model)
├── Belongs to ProjectArea (via project_area_id)
├── Belongs to Product (via product_id — nullable, nullOnDelete)
├── ProjectLineType enum (type column, cast)
└── Observed by ProjectLineObserver

ProjectLineObserver
├── created()    — logs product.added to ActivityLog (active revision only)
├── updating()   — stashes pending field changes
├── updated()    — logs line.updated OR folds into product.added if recent
├── saved()      — calls touchProject() → updateQuietly last_edited_at
└── deleted()    — calls touchProject() → updateQuietly last_edited_at

ProjectAreaObserver::deleting()
└── captures lines snapshot before cascade delete → logs area.deleted
```

**Key files:**
- [ProjectLine.php](../../../app/Models/ProjectLine.php)
- [ProjectLineType.php](../../../app/Enums/ProjectLineType.php)
- [ProjectLineObserver.php](../../../app/Observers/ProjectLineObserver.php)
- [ProjectAreaObserver.php](../../../app/Observers/ProjectAreaObserver.php)
- ViewProject line methods: see [view-project skill](..//view-project/SKILL.md)

---

## ProjectLineType Enum

```php
enum ProjectLineType: string
{
    case Standard = 'standard';  // Unchanged product line
    case Modified = 'modified';  // Product line with edited code/description
    case Custom   = 'custom';    // Blank line — no product source
}
```

`label(): string` — returns display name (`'Standard'`, `'Modified'`, `'Custom'`).

### Type Assignment Rules

| Scenario | Type |
|---|---|
| Line created from product picker (code=SKU, description=product_name unchanged) | `Standard` |
| Line created as blank row (`addBlankLine`) | `Custom` |
| Line cloned from another revision | Preserves source type |
| User edits `code` or `description` to match product | `Standard` (auto-recalculated) |
| User edits `code` or `description` away from product | `Modified` (auto-recalculated) |
| Line has `product_id = null` | `Custom` (no recalculation) |

### `recalculateLineType()` Logic

Called automatically by `updateLineField()` when `code` or `description` changes on a line that has a `product_id`:

```php
// Standard if BOTH fields match product; Modified otherwise
$unchanged = $line->code === $product->sku
    && $line->description === $product->product_name;

$newType = $unchanged ? ProjectLineType::Standard : ProjectLineType::Modified;

if ($line->type !== $newType) {
    $line->update(['type' => $newType->value]);
}
```

Only updates if the type actually changes (avoids unnecessary observer triggers).

---

## Column Reference

| Column | Type | Default | Notes |
|---|---|---|---|
| `id` | bigint PK | — | |
| `project_area_id` | FK → project_areas | — | cascadeOnDelete |
| `product_id` | FK → products\|null | null | nullOnDelete — used for type recalculation only |
| `code` | string\|null | null | Product SKU (display copy) |
| `ref` | string(6)\|null | null | Uppercase, max 6 chars |
| `description` | string | `''` | Product name (display copy) |
| `qty` | unsigned int | 1 | Cast to integer |
| `type` | string | `'standard'` | Cast to `ProjectLineType` enum |
| `unit_price` | decimal(10,2)\|null | null | |
| `notes` | text\|null | null | |
| `status` | string\|null | null | Not yet in use |
| `sort_order` | unsigned int | 0 | Cast to integer |

---

## `updateLineField()` — Rules & Special Cases

Only these fields may be updated: `code`, `ref`, `description`, `qty`, `unit_price`, `notes`.

Validate with `in_array($field, $allowed, true)` before touching the model.

**Special `ref` handling:**
```php
// ref: uppercase, max 6, null when blank
$value = ($value !== '' && $value !== null)
    ? strtoupper(substr((string) $value, 0, 6))
    : null;
```

**Empty string → null for all other fields:**
```php
$line->update([$field => $value !== '' ? $value : null]);
```

**Type recalculation trigger:**
After saving `code` or `description`, if `$line->product_id !== null`, call `recalculateLineType($line)`.

---

## Creating Lines

### From product picker

```php
$area->lines()->create([
    'product_id'  => $product->id,
    'code'        => $product->sku,          // SKU → code
    'description' => $product->product_name, // name → description
    'qty'         => $selection['qty'],
    'type'        => ProjectLineType::Standard->value,
    'sort_order'  => $maxSort,
]);
```

### Blank line

```php
$area->lines()->create([
    'description' => '',
    'qty'         => 1,
    'type'        => ProjectLineType::Custom->value,
    'sort_order'  => $maxSort + 1,
]);
```

---

## Cloning Lines (Revision Creation)

In `createNewRevision()`, lines are copied using `$line->only([...])` to exclude timestamps. The allowed set **must be updated** if you add a new column that should survive cloning:

```php
$newArea->lines()->create($line->only([
    'product_id', 'code', 'ref', 'description', 'qty',
    'type', 'unit_price', 'notes', 'status', 'sort_order',
]));
```

> **Important:** New columns added to `project_lines` must also be added to this `only()` call in [ViewProject.php](../../../app/Filament/Resources/Projects/Pages/ViewProject.php) or they will be lost on revision clone.

---

## Adding a New Column — Checklist

1. **Migration** — add column to `project_lines` table.
2. **Model** — add field to `#[Fillable([...])]` in `ProjectLine`.
3. **`updateLineField()`** — add to `$allowed` array if user-editable from the UI.
4. **Observer** — add to `$trackedFields` in `ProjectLineObserver::updating()` if changes should be activity-logged.
5. **Revision cloning** — add to the `$line->only([...])` call in `ViewProject::createNewRevision()`.
6. **Blade** — add input to the line row grid. Update `grid-template-columns` inline style on both the header row and each line row (both use identical column definitions).

---

## Observer Chain — Activity Logging

### `created` → `product.added`

Fires when a line is created **on the active revision**. Skips revision-cloned lines (checked via `$area->project_revision_id !== $project->active_revision_id`).

Payload: `{ line_id, code, description, qty }`

### `updating` / `updated` → `line.updated`

- `updating()` stashes dirty field changes in `$pendingPayloads[line_id]`.
- `updated()` checks if a `product.added` log exists for this line within the last 5 minutes (`CREATION_WINDOW_MINUTES`).
  - **If yes** — folds the field changes into that log entry (keeps history clean for rapid edits after adding a product).
  - **If no** — creates a new `line.updated` entry.

Payload: `{ code, changes: { field: { old, new } } }`

### `saved` / `deleted` → `touchProject`

Calls `$project->updateQuietly([last_edited_at, last_edited_by])` if the line's area is on the active revision. Uses `updateQuietly()` to bypass `ProjectObserver` and avoid recursion.

### `ProjectAreaObserver::deleting()`

Before a cascade delete wipes lines, captures a snapshot of all lines (code, description, qty — excluding nulls/empty) and logs `area.deleted` with the lines array.

---

## Common Pitfalls

- **Never query lines by `id` alone.** Always verify project ownership:
  ```php
  ProjectLine::whereHas('area', fn ($q) => $q->where('project_id', $this->record->id))
      ->findOrFail($lineId);
  ```
- **`product_id` is nullable** and set to null on product deletion. Don't assume `product_id` is always present; always null-check before loading the related product.
- **`code` and `description` are display copies** — they are not synced back if the product catalogue changes. They represent the product as it was when the line was created/last edited.
- **`type` is stored as a string value** (`'standard'`, `'modified'`, `'custom'`), not the enum name. Use `ProjectLineType::Standard->value` when writing, rely on the cast when reading.
- **Legacy `temp` type** — migrated to `custom` in `2026_05_27_095934`. Don't reintroduce `'temp'`.
- **`ref` max length** is enforced both in the Blade input (`maxlength="6"`) and in `updateLineField()`. The DB column is also `string(6)`.
- **Adding a new user-editable field?** You must update `updateLineField()`, the observer's `$trackedFields`, the cloning `only()` list, and the Blade grid columns — all four. Missing any one of them is a common source of silent data loss.
