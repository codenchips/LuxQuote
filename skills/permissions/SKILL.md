# Permissions Skill

Use this skill whenever you add, change, review, or debug user-visible functionality that may need authorization.

This includes:

- Filament resources, pages, tables, forms, actions, and navigation items.
- Livewire methods that mutate projects, revisions, validation state, products, users, groups, or exports.
- Routes and controllers that download PDFs, CSVs, schedules, quotes, or other project data.
- Any UI that displays or edits pricing, quote values, cover values, totals, or price-based output.
- Any new role, group, permission, or admin/user-management behavior.

## Required Context

Read `PERMISSIONS.md` before making changes. It documents the current model, permission matrix, pricing rule, tests, and update checklist.

## Core Rules

1. Check `App\Enums\PermissionKey` before inventing a new permission.
2. Prefer existing permission keys when the behavior already belongs to a known capability.
3. Add new permission keys only when a feature needs distinct authorization.
4. When adding a permission, update all of:
   - enum case in `PermissionKey`
   - `label()`
   - `category()`
   - `description()` when helpful
   - `defaultGroups()`
   - `PERMISSIONS.md`
   - focused tests
5. Hide UI affordances with `$user->can('permission.key')`, but also enforce server-side guards.
6. Never rely on hidden buttons alone for security.
7. Keep the `Permissions` Filament resource read-only unless explicitly asked to make permission keys editable.

## Pricing Rule

Treat `pricing.view` as the global price visibility switch.

Users without `pricing.view` must not see:

- price columns
- line totals
- project totals
- quote values
- cover/value fields
- priced exports
- quote PDF controls
- price mismatch edit controls

Users without `pricing.update` must not be able to change prices, even if a request is sent manually.

## Testing Checklist

For permission-sensitive changes:

- Add or update a test for one allowed group.
- Add or update a test for one denied group.
- Include server-side denial tests for mutating methods, routes, or exports.
- Run the smallest relevant test file(s) through Sail.
- Run Pint after PHP changes.

Recommended commands:

```bash
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan test --compact tests/Feature/AdminPermissionGateTest.php tests/Feature/AdminPermissionResourceTest.php
```
