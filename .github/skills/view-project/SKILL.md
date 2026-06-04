---
name: view-project
description: "Use when working on ViewProject page, project areas, project lines, validation/approval, revision locking, line sorting/drag-drop, product picker modal, revision management, heartbeat presence, or concurrent editing. Covers the Livewire component, Blade view, observer chain, and all area/line/revision operations."
---

# ViewProject Skill

## When to Use

- Modifying `app/Filament/Resources/Projects/Pages/ViewProject.php`
- Adding new actions, buttons, or inputs to the project view
- Working with project areas (add, remove, sort) or project lines (add, edit, delete, sort, duplicate)
- Adding or changing the product picker modal
- Touching the revision system (create, set active, view history, validation, locking)
- Changing heartbeat / concurrent editing presence behaviour
- Editing `resources/views/filament/resources/projects/pages/view-project.blade.php`

---

## Architecture

```
ViewProject (Livewire + Filament custom View page)
├── ProjectResource::getEloquentQuery()  — scopes by user / admin
├── ViewProject::mount()                 — init viewingRevisionId, heartbeat
├── Computed properties                  — concurrentEditors, projectRevisions,
│                                          productPickerProducts, site/type options
├── Area methods                         — getAreas(), addArea(), removeArea()
├── Line methods                         — addProduct(), addBlankLine(),
│                                          updateLineField(), duplicateLine(),
│                                          deleteLine(), sortLine()
├── Revision methods                     — setActiveRevision(), createNewRevision()
├── Validation lock                      — isViewingRevisionValidated,
│                                          ensureViewingRevisionIsEditable()
├── Product picker methods               — openProductPicker(), closeProductPicker(),
│                                          toggleProductSelection(), addSelectedProducts()
└── heartbeat()                          — upserts ProjectPresence, purges stale records
```

**Key files:**
- [ViewProject.php](../../../app/Filament/Resources/Projects/Pages/ViewProject.php)
- [view-project.blade.php](../../../resources/views/filament/resources/projects/pages/view-project.blade.php)
- [ProjectResource.php](../../../app/Filament/Resources/Projects/ProjectResource.php)
- [ValidationProject.php](../../../app/Filament/Resources/Projects/Pages/ValidationProject.php)
- [ProjectRevisionValidator.php](../../../app/Services/ProjectRevisionValidator.php)
- Data model reference: [data-model.md](./data-model.md)
- Observer reference: [observers.md](./observers.md)

---

## Critical Rules

### 1. Always scope areas and lines to `viewingRevisionId`

Every query for areas or lines **must** filter by the revision the user is currently viewing — never just the project's `active_revision_id`:

```php
// CORRECT
ProjectArea::where('project_revision_id', $this->viewingRevisionId)->get();

// WRONG — bypasses revision history
ProjectArea::where('project_id', $this->record->id)->get();
```

### 2. Always verify line ownership via `whereHas`

Never look up a line by `id` alone. Always ensure it belongs to this project:

```php
$line = ProjectLine::whereHas('area', fn ($q) => $q->where('project_id', $this->record->id))
    ->findOrFail($lineId);
```

### 3. Always verify area ownership when acting on lines

When creating lines or looking up an area to act on, verify the area belongs to the current revision:

```php
$area = ProjectArea::where('id', $areaId)
    ->where('project_revision_id', $this->viewingRevisionId)
    ->firstOrFail();
```

### 4. Product → Line field mapping

When creating a `ProjectLine` from a `Product`:

| Product field | ProjectLine field |
|---|---|
| `$product->id` | `product_id` |
| `$product->sku` | `code` |
| `$product->product_name` | `description` |

`product_id` is nullable origin tracking for type recalculation and product-backed metadata. `code` and `description` remain copied strings and are the display/PDF values.

### 5. `updateLineField` allowed fields

Only these fields may be updated via `updateLineField()`: `code`, `ref`, `description`, `qty`, `unit_price`, `notes`. Validate with `in_array` before updating.

### 6. Never mutate a validated revision

Every schedule mutation must call `ensureViewingRevisionIsEditable()` before changing an area or line. Validated revisions are locked server-side; disabling UI controls is not sufficient authorization.

Editing a line resets its approval metadata. New revisions and cloned lines must start unvalidated/unapproved.

---

## Livewire Properties

| Property | Type | Purpose |
|---|---|---|
| `$viewingRevisionId` | `?int` | Currently viewed revision — initialized to `active_revision_id` in `mount()` |
| `$revisionsModalOpen` | `bool` | Controls revisions modal visibility |
| `$productPickerOpen` | `bool` | Controls product picker modal |
| `$productPickerAreaId` | `?int` | Which area products will be added to |
| `$productSearch` | `string` | Search query string |
| `$productSiteFilter` | `string` | Filter by product site |
| `$productTypeFilter` | `string` | Filter by product type |
| `$productPage` | `int` | Product picker pagination page |
| `$productSelections` | `array<int, array{qty: int}>` | Selected products keyed by product ID |
| `$newAreaName` | `string` | Input for "Add Area" form |
| `isViewingRevisionValidated` | computed bool | Whether the viewed revision is validated and locked |

---

## Adding New Features

### New header action

Add to `getHeaderActions()`. Use `Filament\Actions\Action` (not `Filament\Tables\Actions\Action`):

```php
use Filament\Actions\Action;

Action::make('myAction')
    ->label('My Label')
    ->action(fn () => $this->myMethod())
```

### New line operation

1. Add a `public function myOperation(int $lineId): void` method to `ViewProject.php`.
2. Call `ensureViewingRevisionIsEditable()`.
3. Verify line ownership with `whereHas` (see Rule 2 above).
4. Add a `wire:click="myOperation({{ $line->id }})"` button in the Blade `@foreach($area->lines as $line)` loop.
5. Use `@click.stop` if the button is inside the accordion header (prevents collapse).

### New area operation

1. Add a method `public function myAreaOperation(int $areaId): void`.
2. Call `ensureViewingRevisionIsEditable()`.
3. Scope the area query to `viewingRevisionId` (see Rule 3 above).
4. Wire it in Blade inside the `@forelse($this->getAreas() as $area)` loop.

### New computed property

Use `#[Computed]` attribute. For expensive queries, add `->remember()` or cache if needed. Reset dependent state in `updated*()` lifecycle hooks.

---

## Blade Patterns

### Area accordion

```blade
<div wire:key="area-{{ $area->id }}" x-data="{ open: true }">
    <div @click="open = !open">  {{-- header --}}
        <div @click.stop>  {{-- action buttons that must NOT collapse the accordion --}}
    </div>
    <div x-show="open" x-collapse>  {{-- body --}}
```

- `wire:key` is required on area and line rows for Livewire morphdom diffing.
- Buttons in the header that trigger Livewire calls must use `@click.stop`.

### Sortable lines

```blade
<div
    x-sort="(id, pos) => $wire.sortLine(parseInt(id), pos, {{ $area->id }})"
    x-sort:config="{ group: 'projectLines', animation: 150 }">
    <div wire:key="line-{{ $line->id }}" x-sort:item="{{ $line->id }}">
```

- `group: 'projectLines'` enables cross-area drag-drop.
- `sortLine(lineId, newPosition, targetAreaId)` handles both reorder and move.

### Line type background colours

```blade
{{ match($line->type) {
    \App\Enums\ProjectLineType::Modified => 'bg-amber-50/60 dark:bg-amber-900/10',
    \App\Enums\ProjectLineType::Custom   => 'bg-blue-50/60 dark:bg-blue-900/10',
    default => '',
} }}
```

### Input blur pattern

All inline inputs use blur (not live binding) to minimise re-renders:

```blade
<input
    value="{{ $line->someField }}"
    x-on:blur="$wire.updateLineField({{ $line->id }}, 'someField', $el.value)"
/>
```

---

## Revision System

### `createNewRevision()`

- Clones areas + lines from `$this->viewingRevisionId` (the currently viewed revision, not necessarily active).
- Increments revision number: `max(revision_number) + 1`.
- Sets the new revision as active (`active_revision_id`) and updates `viewingRevisionId`.
- Always logs to `ActivityLog`.
- New revision defaults to `validated = false`; cloned lines do not copy approval metadata.

### Revision validation and locking

- `ProjectRevisionValidator` owns validation rule evaluation and status synchronization.
- A revision is validated only when no unresolved warnings remain and every line is approved.
- Clean lines are auto-approved (`approved_by = null`); warning approval is explicit (`approved_by = admin ID`).
- Validated revisions are locked by `ensureViewingRevisionIsEditable()`.
- `createNewRevision()` remains allowed from a validated revision and creates an editable copy.
- Running validation may invalidate a revision if a new warning appears.

### `setActiveRevision(int $revisionId)`

- Verifies the revision belongs to this project via scoped `findOrFail`.
- Updates both `active_revision_id` (FK) and `revision` (number column) on the project.
- Calls `$this->record->refresh()` then updates `viewingRevisionId`.

### Revision activation

Selecting a revision in the modal activates it via `setActiveRevision()`. The current product decision is that there is no browse-only revision mode in normal UI flow. If a future feature reintroduces browse-only history, it must use a separate method from `setActiveRevision()` and keep all area/line actions scoped to the explicitly viewed revision.

---

## Heartbeat & Presence

```
mount()              → heartbeat() called immediately
wire:poll.30s        → heartbeat() called every 30 seconds
```

`heartbeat()` does two things:
1. Upserts own `ProjectPresence` record (`last_seen_at = now()`).
2. Globally deletes `ProjectPresence` records older than 90 seconds.

`concurrentEditors` computed property returns users with `last_seen_at >= now()->subSeconds(90)` excluding self, with the `user` relationship eager-loaded.

---

## Common Pitfalls

- **`updateQuietly()` on the project from observers** — bypasses `ProjectObserver`, so changes won't trigger `last_edited_at` updates. Area/line observers call `updateQuietly()` intentionally to avoid recursion; this is correct behaviour, don't change it.
- **`ref` field** — stored as uppercase, max 6 chars. The Blade input enforces this client-side; `updateLineField` also enforces it server-side.
- **`ProductLineType::Custom` vs `Modified`** — `Custom` means blank line (no product), `Modified` means a product line whose code/description diverges from the source product. `Standard` means unchanged product line.
- **MySQL TRUNCATE** — don't use `TRUNCATE` on any project-related table; FK constraints will fail. Use `Model::query()->delete()`.
- **`product_id` on lines** — `ProjectLine` may have a `product_id` column for tracking origin but it is **not used for display**. Always use `code` and `description`.
- **Approval is not validation** — `ProjectLine.approved` is per-line state; `ProjectRevision.validated` is synchronized only after evaluating all rules.
- **Do not copy approval fields during revision cloning** — every new revision must be revalidated.
- **Do not add validation rules directly to the Filament page** — add them to `ProjectRevisionValidator` so Run, Approve, Merge, and tests share one source of truth.
