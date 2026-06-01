# Observer Reference — ViewProject

## ProjectObserver

**Registered in:** `AppServiceProvider`

**`META_KEYS`** (changes to these do NOT count as meaningful edits):
```php
['last_edited_at', 'last_edited_by', 'active_revision_id', 'updated_at', 'created_at']
```

**`updating()` logic:**
1. Checks if any dirty key is NOT in `META_KEYS`.
2. If a meaningful change exists, sets `last_edited_at = now()` and `last_edited_by = auth()->id()` on the project.
3. Skips if only meta keys changed (prevents infinite loops from `updateQuietly()` calls in area/line observers).

---

## ProjectAreaObserver

**Fires on:** `created`, `updated`, `deleted`

**Action:** Calls `$area->project->updateQuietly(['last_edited_at' => now(), 'last_edited_by' => auth()->id()])` when the area belongs to the **active** revision.

**Key check:**
```php
if ($area->project_revision_id === $area->project->active_revision_id) {
    $area->project->updateQuietly([...]);
}
```
Uses `updateQuietly()` to bypass `ProjectObserver` (avoids recursion). This means `ProjectObserver::updating()` will NOT fire for these updates — correct by design.

---

## ProjectLineObserver

**Fires on:** `created`, `updated`, `deleted`

**Action:** Same pattern as `ProjectAreaObserver` — calls `updateQuietly()` on the grandparent project if the line's area belongs to the active revision.

**Check:**
```php
$area = $line->area;
if ($area->project_revision_id === $area->project->active_revision_id) {
    $area->project->updateQuietly([...]);
}
```

---

## Observer Chain Summary

```
User edits line field
    → ProjectLine::update()
    → ProjectLineObserver::updated()
    → $project->updateQuietly([last_edited_at, last_edited_by])
    → ProjectObserver is SKIPPED (updateQuietly)
    → last_edited_at updated silently ✓

User edits project metadata (e.g. name, notes)
    → Project::update()
    → ProjectObserver::updating()
    → Meaningful key found → sets last_edited_at + last_edited_by inline
    → update() proceeds ✓
```
