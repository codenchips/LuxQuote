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

The left navigation groups permission-controlled features as follows:

- `Salesforce`
  - `Projects`: the Salesforce opportunity/project list (`salesforce.view`). This is separate from the main LuxQuote Projects resource.
  - `Visits`: the Salesforce public calendar interface (`calendar.view`).
- `Admin`
  - `History`: the global activity log (`activity-log.view`).
  - `Specials`: special order code management (`specials.manage`).
  - `Products`: the product catalogue (`products.view`).
- `Users`
  - `Users`: create/edit users and assign them to a group.
  - `Groups`: create/edit permission groups and assign permissions.
  - `Teams`: create/edit teams and manage their membership (`teams.manage`).

Navigation placement does not grant access; each page and resource keeps its existing server-side permission guard.

The `Permissions` resource still exists, but it is hidden from the left navigation with:

```php
protected static bool $shouldRegisterNavigation = false;
```

The permissions catalogue remains directly accessible to authorized users if linked manually, and tests can still render it. It is read-only by design.

The live **History** page and linked **Archived Logs** page are both controlled by `activity-log.view`. Archived Logs searches and displays retained audit snapshots from `activity_log_archives`; it does not appear as a separate left-sidebar navigation item and does not grant any additional project access or mutation capability.

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
| Manage project tenders | x | x |  | x | x |
| Mark design complete | x | x |  | x | x |
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
| Edit cover percentages | x |  | x |  | x |
| Produce priced schedule | x |  | x |  | x |
| Produce quote | x |  | x |  | x |
| Manage document packs | x | x | x | x | x |
| Produce document packs | x | x | x | x | x |
| View output history | x | x | x | x | x |
| Request quote approval | x |  | x |  | x |
| View products list page | x |  |  |  | x |
| Import / fetch products | x |  |  |  |  |
| Manage special order codes | x |  |  |  |  |
| View company calendar | x | x | x | x | x |
| Create company calendar events | x | x | x | x | x |
| Update company calendar events | x | x | x | x | x |
| Delete company calendar events | x | x | x | x | x |
| View Salesforce projects list page | x |  |  |  | x |
| Manage Salesforce push switch | x |  |  |  |  |
| View users list page | x |  |  |  |  |
| Create users | x |  |  |  |  |
| Edit users | x |  |  |  |  |
| Delete users | x |  |  |  |  |
| Manage teams | x |  |  |  |  |
| Manage groups / permissions | x |  |  |  |  |

## Document Pack Permissions

Document packs deliberately separate editing from output:

- `output.manage-document-packs` allows a user to create, rename, reorder, update, and delete packs, uploaded Custom PDFs, and template/generated pack entries.
- `output.produce-document-packs` allows a user to request the merged PDF download.
- `output.history.view` allows a user to see the Output page History tab and regenerate previously generated Quote/Schedule PDFs from the logged output options.

These permissions do not bypass the permissions of generated contents:

- A Quote role also requires `pricing.view` and `output.produce-quote`.
- A Schedule role also requires `output.produce-unpriced-schedule`.
- A pack containing a Quote cannot be generated until the selected revision is validated and approved.

The builder currently offers **Custom PDF**, **Standard Legal Page**, **Quote**, and **Schedule** for new items. Legacy saved **Cover** and uploaded **Legal** items remain supported, but they are not offered in the new-document dropdown.

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

Cover percentages and calculated net prices are price-related. Users need `pricing.view` to see Has Cover, Cover Direction, Cover values, Net Price, and Net Project Total. They also need `cover.update` to change the project Cover configuration, project line Cover overrides, or unresolved validation issue values. Validated/approved rows show Cover values as read-only text.

Cover controls and calculations are active only when `projects.has_cover` is true. Enabling Cover on a project defaults Cover 1, Cover 2, and Cover 3 to `5.00`, `5.00`, and `0.00`. Blank line-level Cover values inherit the project Cover defaults. Validation should only flag Cover when the effective line values differ from the project defaults, and it must not emit Cover issues for projects without Cover enabled.

For Cover-enabled projects, Net is always the lower amount and Total is always the higher amount, regardless of Cover Direction. With deducted Cover, the stored `project_lines.unit_price` is Total; with added Cover, it is Net. All price-visible schedule, validation, PDF, CSV, and project-total displays must derive their labelled Net/Total values through the `ProjectLine` Cover calculation methods.

Project currency is display-only. The `projects.currency` value changes whether price-visible screens and PDFs display `£` or `€`; it does not convert stored numeric prices, run exchange-rate maths, or change Salesforce Amount payload values. Currency controls are still price-related UI, so they follow `pricing.view` visibility and project-detail edit guards rather than having a separate permission.

Validation flagging is controlled by `validation.flag-lines`. Flagging an issue or validated line must collect a short note, store it against the affected line(s), and keep the same server-side editable-revision guard as other validation mutations.

## Salesforce Push Control

The Salesforce page includes a global persistent push switch controlled by `salesforce.manage-push`.

Users with `salesforce.view` can still search and import Salesforce projects. Users with `salesforce.manage-push` can pause or resume outbound Salesforce writes. The switch stores its global state in `app_settings` and must stay where it was set across logout/login and page refreshes. When pushes are paused, the app must not upload quote/schedule PDFs or update Opportunity Amount values, but read-only Salesforce pulls remain available.

## Calendar

The **Salesforce → Visits** page is controlled by `calendar.view`. Every default permission group receives this capability, while custom groups can omit it. It reads room bookings from the configured Salesforce Visits public calendar and displays them in month, week, and day views.

Creating an event requires `calendar.create`. Clicking or dragging across empty calendar slots opens a create modal pre-filled with the selected date and time; a single timed-slot click defaults to a one-hour event. Salesforce field createability is checked where metadata is available, and the server always assigns the event to the configured Visits public calendar.

Updating an existing event requires `calendar.update`. The modal keeps Salesforce Owner and Created By read-only, validates that the event still belongs to the configured Visits calendar, and allows changes to Subject, Location, Type, dates, times, and all-day status.

Deleting an event requires the separate `calendar.delete` permission. The event modal shows a red Remove event action only to permitted users and requires a second explicit confirmation. Immediately before deleting, the server verifies that the event still belongs to the configured Visits public calendar. Missing Salesforce delete rights, stale or inaccessible records, paused Salesforce pushes, and transport failures leave the event intact and return a user-friendly error.

Salesforce field permissions and transport failures are treated defensively. Calendar reads retry without optional Owner/Created By relationships, Type, or Location when those fields are unavailable, while malformed individual records are skipped without losing valid events. Event describe metadata makes non-updateable fields read-only in the modal. If metadata or Salesforce connectivity is unavailable, Type changes are disabled, lists fall back to an empty state with one notification, and other edits use Salesforce as the final authorization check. Failed create/update/delete requests keep the action open, return a user-friendly message, and do not record completed activity.

Successful calendar creates, updates, and deletions are appended to the global activity history as `calendar.created`, `calendar.updated`, and `calendar.deleted`. Each entry snapshots the acting user, calendar name and ID, Salesforce event ID, subject, dates, and times. History rows use `Calendar` as their Reference and display `Action - Subject - Dates / Times`, with Created in green, Updated in blue, and Deleted in red. Failed Salesforce actions are not recorded as completed calendar activity.

## Special Order Codes

The **Specials** page is controlled by `specials.manage`. Special order codes are local rules used when users type or paste a code that needs special quote/schedule handling rather than a product catalogue match.

Each special order code defines the canonical code and description to use on project lines, whether the line requires validation approval, whether it appears on Schedule PDFs, and whether it appears on Quote PDFs.

The initial special is `NO OFFER`, which is matched case-insensitively with or without spaces. It uses the description "No equivalent Tamlite offering available.", does not require approval, appears on schedules, and is excluded from quotes.

## Teams

Teams are independent of permission groups. A user may belong to many teams, and a team may contain many users. Team membership controls visibility for projects marked as team-scoped; it does not grant or remove application capabilities.

The Teams resource is controlled by `teams.manage`. Users with that permission can create, edit, and delete teams and manage team membership. Users can see their own team memberships on the profile page.

The profile page includes a user-owned **Project list view** preference. The default is **All available projects**, which uses the normal project access query. A user may instead choose one team they belong to, which applies that Team filter by default when they open the Projects table. This is a display preference only; it does not grant access to projects outside the normal Open, Private, and Team visibility rules.

Project visibility supports:

- `Open` — all logged-in users who can view projects may see it.
- `Private` — only the project owner and admins may see it.
- `Team` — the project owner, admins, and members of the selected team may see it.

The project page **Design Complete** status toggle is controlled by `projects.mark-design-complete`. It changes only the visible project status and does not unlock approved revisions, change line validation, grant pricing access, or change output permissions.

## Project Tenders

Project Tenders are local links between a Project and Salesforce contractor Accounts that may tender for the project. The project page **Tenders** modal is visible from the project page, while adding, removing, and choosing a primary tender Account uses `projects.manage-tenders` and repeats that guard server-side.

The first implementation stores Salesforce Account ID and Account name locally in `project_tenders`. It only reads Salesforce `Account` records; it does not create, update, or delete Salesforce `Tender__c` records yet.

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
