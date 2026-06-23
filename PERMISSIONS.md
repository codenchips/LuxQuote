# Permission System

Company App uses database-backed permission groups with a fixed application permission catalogue. The goal is to keep authorization checks stable in code while allowing admins to manage which groups receive each capability.

## Core Concepts

### Permissions

Permissions are fixed capability keys defined in `App\Enums\PermissionKey`.

Examples:

- `projects.view`
- `projects.update-lines`
- `pricing.view`
- `pricing.update`
- `validation.merge-lines`
- `output.produce-quote`
- `output.manage-document-packs`
- `output.produce-document-packs`
- `permissions.manage`

Do not create arbitrary permission keys from the UI. A permission is useful only when the codebase knows what behavior it controls.

### Groups

Groups are database records in `permission_groups`. A group is a bundle of permissions assigned to users through `users.permission_group_id`.

Default system groups are created by the permissions migration:

- `admin`
- `user`
- `sales`
- `technical`
- `manager`

Admins can create additional groups in the Filament Users area and choose from the fixed permission catalogue.

### Users

Users are assigned to one permission group on the User create/edit form. The legacy `users.role` column remains as a compatibility fallback, but day-to-day authorization should use permissions and groups.

`User::isAdministrator()` returns true when either:

- `users.role` is `admin`
- the assigned permission group slug is `admin`

`User::hasPermission()` returns true for admins before checking the assigned group. This gives Admin users unrestricted access.

## Filament UI

The Users navigation group contains:

- `Users`: create/edit users and assign them to a group.
- `Groups`: create/edit permission groups and assign permissions.

The `Permissions` resource still exists, but it is hidden from the left navigation with:

```php
protected static bool $shouldRegisterNavigation = false;
```

The permissions catalogue remains directly accessible to authorized users if linked manually, and tests can still render it. It is read-only by design.

## Authorization Flow

All permission gates are registered in `App\Providers\AppServiceProvider`.

Each `PermissionKey` case is registered as a Laravel Gate:

```php
$user->can('pricing.view')
$user->can('projects.update-lines')
$user->can('revisions.approve')
```

Legacy gate aliases also exist for older code paths, for example:

- `view-products` maps to `products.view`
- `import-products` maps to `products.import`
- `view-users` maps to `users.view`

New code should use the dotted permission keys from `PermissionKey`.

## Current Default Matrix

| Capability | Admin | User | Sales | Technical | Manager |
|---|---:|---:|---:|---:|---:|
| View projects | x | x | x | x | x |
| Create projects | x | x |  |  | x |
| Edit project details | x | x |  |  | x |
| Edit project areas / line items | x | x |  | x | x |
| Create project revisions | x | x |  |  | x |
| View project history | x | x | x | x | x |
| View global history | x |  |  |  | x |
| View validation page | x |  | x | x | x |
| Run validation | x |  |  | x | x |
| Edit validation line items | x |  |  | x | x |
| Flag validation line items | x |  |  | x | x |
| Merge validation line items | x |  |  | x | x |
| Approve validation line items | x |  |  |  | x |
| Approve and lock project revision | x |  |  |  | x |
| View output page | x | x | x | x | x |
| Produce unpriced schedule | x | x | x | x | x |
| View prices | x |  | x |  | x |
| Edit prices | x |  | x |  | x |
| Produce priced schedule | x |  | x |  | x |
| Produce quote | x |  | x |  | x |
| Manage document packs | x | x | x | x | x |
| Produce document packs | x | x | x | x | x |
| Request quote approval | x |  | x |  | x |
| View products list page | x |  |  |  | x |
| Import / fetch products | x |  |  |  |  |
| View Salesforce projects list page | x |  |  |  | x |
| View users list page | x |  |  |  |  |
| Create users | x |  |  |  |  |
| Edit users | x |  |  |  |  |
| Delete users | x |  |  |  |  |
| Manage groups / permissions | x |  |  |  |  |

## Document Pack Permissions

Document packs deliberately separate editing from output:

- `output.manage-document-packs` allows a user to create, rename, reorder, update, and delete packs and their uploaded PDFs.
- `output.produce-document-packs` allows a user to request the merged PDF download.

These permissions do not bypass the permissions of generated contents:

- A Quote role also requires `pricing.view` and `output.produce-quote`.
- An Unpriced Schedule role also requires `output.produce-unpriced-schedule`.
- A pack containing a Quote cannot be generated until the selected revision is validated and approved.

The UI hides unavailable roles and disables blocked generation, while Livewire methods, the download controller, and the merge service enforce the same rules server-side. Pack and revision IDs must belong to the current project; non-admin users remain limited to Open projects or projects they own.

## Global Pricing Rule

`pricing.view` is the global switch for price visibility.

If a user does not have `pricing.view`:

- Hide price columns and project totals.
- Hide priced outputs such as quote PDF and priced CSV.
- Hide pricing-related project detail fields such as cover and value.
- Avoid showing price mismatch controls on validation screens.

If a user has `pricing.view` but not `pricing.update`:

- Price values may be visible.
- Price fields must be read-only.
- Server-side mutation methods must reject price updates.

Any code that allows `pricing.update` should assume `pricing.view` is also required.

## Adding New Functionality

When adding any new user-facing page, action, export, mutation, button, table column, form field, or route, review permissions as part of the feature work.

Use this checklist:

1. Decide whether an existing permission controls the behavior.
2. If no existing permission fits, add a new case to `App\Enums\PermissionKey`.
3. Add the permission label, category, description if needed, and default group assignments in `PermissionKey::defaultGroups()`.
4. Add or update UI visibility checks using `$user->can('permission.key')`.
5. Add or update server-side guards with `abort_unless()`, resource authorization, or action visibility as appropriate.
6. If the feature exposes prices, apply the global `pricing.view` and `pricing.update` rules.
7. Update tests to cover at least one allowed group and one denied group for meaningful behavior.
8. Update this document when the permission matrix or behavior changes.

## Testing

Focused permission tests live in:

- `tests/Feature/AdminPermissionGateTest.php`
- `tests/Feature/AdminPermissionResourceTest.php`
- `tests/Feature/AdminDocumentPackTest.php`

Related feature tests should be updated when permission behavior changes, especially:

- `tests/Feature/AdminUserResourceTest.php`
- `tests/Feature/AdminProjectResourceTest.php`
- `tests/Feature/AdminProjectValidationTest.php`
- `tests/Feature/AdminProductResourceTest.php`

Run focused tests with:

```bash
vendor/bin/sail artisan test --compact tests/Feature/AdminPermissionGateTest.php tests/Feature/AdminPermissionResourceTest.php
```

Run related coverage with:

```bash
vendor/bin/sail artisan test --compact tests/Feature/AdminUserResourceTest.php tests/Feature/AdminProjectResourceTest.php tests/Feature/AdminProjectValidationTest.php tests/Feature/AdminProductResourceTest.php
```

After PHP changes, run:

```bash
vendor/bin/sail bin pint --dirty --format agent
```

## Troubleshooting

If the User edit form or Groups page reports a missing `permission_groups` or `permissions` table, run:

```bash
vendor/bin/sail artisan migrate --no-interaction
```

If an Admin user is unexpectedly denied access, check both:

- `users.role`
- assigned `permissionGroup.slug`

Admin access is intentionally unrestricted through `Gate::before()` and `User::isAdministrator()`.
