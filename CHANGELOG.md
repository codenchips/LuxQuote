# Changelog

## 0.2.7 - 2026-09-04

Version: `0.2.6` -> `0.2.7`

Changes included:

- `0656b8c` Resources, Add to Pack and document pack templates
- `25ebc4e` Document packs preview ok.  History retains 3 months only
- `cbaa224` Hardening

## 0.2.6 - 2026-09-03

Version: `0.2.5` -> `0.2.6`

Changes included:

- `9cf9378` docs updated
- `1a0cd8f` Optional legal page and no cover option if no tenders
- `d8fbdd3` hardening
- `60f7a2d` filenaming convention tweak
- `c94acd6` housekeeping temp file cleanups

## 0.2.5 - 2026-09-01

Version: `0.2.4` -> `0.2.5`

Changes included:

- Production deployment of the application changes prepared in `0.2.4` after reconciling the previously divergent `main` and `production` histories.
- The `0.2.5` commit itself changes only `VERSION` and this changelog; no second copy of the migrations or application changes was introduced.
- Added group-controlled post-login landing pages with access-checked Dashboard fallback and preserved intended URLs.
- Made the Filament sidebar start collapsed for each authenticated session.
- Added production database automation: compressed three-hourly backups retained for 48 hours and one daily backup retained for 14 days.
- Expanded emergency recovery to select a backup and choose reset-only, restore-only, or reset-and-restore, with validation, locking, maintenance mode, and visible results.
- Added deploy-owned Laravel maintenance mode around the database snapshot, code update, build, and forward migrations, including workflow cleanup after cancellation or failure.

## 0.2.4 - 2026-09-01

Version: `0.2.3` -> `0.2.4`

Changes included:

- Added GBP/EUR project display selection across project screens, validation, activity text, and generated documents; this is display-only and performs no currency conversion.
- Changed new-project revision numbering from `P0, R1, R2...` to `P1, P2, P3...` without rewriting existing projects.
- Made revision labels visible from `P1` on Quote and Schedule output and standardised timestamp-free exported filenames as `Reference-Project-Name-TL-Type-Pn` (`LS`, `PQ`, or `DP`).
- Reset **Design Complete** projects to **In Progress** when a new editable revision is created.
- Added the Salesforce public **Visits** calendar CLI and FullCalendar month/week/day interface, compact date navigator, business-hour/weekend defaults, all-day lanes, multi-day spans, and readable event styling.
- Added permission-controlled Salesforce Event viewing, creation, editing, and confirmed deletion with field-level metadata checks and graceful fallbacks for differing live Salesforce access.
- Added Calendar activity history with `Calendar` as Reference and green Created, blue Updated, and red Deleted action labels.
- Reorganised sidebar navigation into Salesforce, Admin, and Users sections; moved Products, Specials, History, Teams, and Visits to their requested groups.
- Polished Dashboard project tables with white project text, a roomier green Design Complete badge, and removal of the redundant Visibility column.
- Fixed Document Pack generated items so every Quote/Schedule uses the revision selected when generation starts.
- Added focused Calendar, Salesforce service, permissions, navigation, dashboard, authentication, output, revision, and filename regression coverage.
- Added the backup, emergency recovery, login default, and deployment-maintenance work described in `0.2.5`; `0.2.5` is the production-visible version after branch-history reconciliation.

## 0.2.3 - 2026-08-04

Version: `0.2.2` -> `0.2.3`

Changes included:

- `b2756f1` Specials interface
- `c04ef55` Numric validation, health check hardening
- `069bbdb` Paste Technical does not default existing SKU's

## 0.2.2 - 2026-08-03

Version: `0.2.1` -> `0.2.2`

Changes included:

- `e429c27` Restore Archived
- `96d6a76` sf cli commands
- `f7cadc8` Add Tenders!  Phase 1
- `f5588d6` Tenders Phase 2
- `adc8488` Enhanced keyboard controls on areas tables. "no offer" special condition.
- `ca86882` delete blanks without comfirmation
- `a83114a` Pick a Tender PDF By Area, Pick an area
- `a771f0b` Project Locking
- `5e329a8` Lock timeout fix
- `b541b67` Version number format change
- `20dc709` zero rrp lines flag in validation cover footer optional cover when no tenders
- `b44ab0e` Salesforce error checking

## 0.1.0-beta.12 - 2026-07-23

Version: `0.1.0-beta.11` -> `0.1.0-beta.12`

Changes included:

- `84f8791` Deployment runner fixes
- `fd10271` Salesforce Uploads Schedules and quotes as single filename by Rev - leave versioning to Saflesfoce ContentVersion

## 0.1.0-beta.11 - 2026-07-22

Version: `0.1.0-beta.10` -> `0.1.0-beta.11`

Changes included:

- `d3caf1d` Recovery script for deploy issues
- `b182921` Cover 550
- `0a4c038` Cover at 550 and show owner name on projects. tweaked log display.
- `7d1bc28` project list setting in profile and rollback script
- `40dfe91` Archive logs

## 0.1.0-beta.10 - 2026-07-17

Version: `0.1.0-beta.9` -> `0.1.0-beta.10`

Changes included:

- `d0b5c92` Teams filter better and CSV Order
- `0a5cf1b` Docs updated

## 0.1.0-beta.9 - 2026-07-16

Version: `0.1.0-beta.8` -> `0.1.0-beta.9`

Changes included:

- `1f7581f` Status docs updated
- `40b7ff5` SF Get Account CLI only
- `a6f9d8c` Teams!
- `f3d41f1` Cover fields (passive)
- `1d35d6c` Cover fields doc'd
- `de2bf82` log search tweaks
- `1b7e072` Cover maths and validation layout
- `e2cbb54` Engineer Name of PDFs (check SF User permissions)
- `6f006d6` Hardened SF failuire responses
- `6ef84eb` NET always LOWEST .  Tests updated
- `0925e45` Area rename and copy
- `9e4bb34` Branch name included

## 0.1.0-beta.8 - 2026-07-09

Version: `0.1.0-beta.7` -> `0.1.0-beta.8`

Changes included:

- `a9cfd7b` Added changelog to deployment

Deployment changelog entries are generated by `./deploy-production`.

Each production deploy records the new visible app version and the commit
subjects included between the previous `production` branch and `main`.
