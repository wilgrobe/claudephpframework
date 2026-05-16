# Archived framework migrations

These 15 files used to be the incremental migration chain that ran on
top of the framework baseline. They were folded into the baseline at
`database/migrations/2026_04_20_000000_create_baseline_tables.php` and
moved here for git/audit purposes.

The migrator's `DirectoryIterator` scan is single-level, so files in
this directory are invisible to `discover()` but still preserved.

Existing installs that already ran these migrations have rows in
`schema_migrations` for every file in this directory. Those rows
remain; `migrate` ignores them because there are no matching files
in the scan path. They show up as `(legacy or missing)` in
`migrate --status`.

## What's folded into the baseline

- `theme_preference` column on users (was: 2026_04_28_500000_add_theme_preference_to_users)
- `jobs` table (was: 2026_04_22_100000_create_jobs_table)
- `scheduled_tasks` table + retry-messages + search-reindex-nightly seeds (was: 2026_04_22_100010 + 2026_04_22_100020 + 2026_04_22_110000)
- `payments` table (was: 2026_04_22_120000_create_payments_table)
- `module_status` table with `'unlicensed'` enum value (was: 2026_04_25_300000 + 2026_05_02_100000)
- `system_layouts` table with `discoverability` + `chromed_url` columns + dashboard + search seeds (was: 2026_04_25_330000 + 2026_04_25_340000 + 2026_05_02_310000 + 2026_05_02_500000 + 2026_05_02_550000 + 2026_05_02_600000)
- `system_block_placements` table with `placement_type` + `slot_name` columns + dashboard cell seeds (the cross-cutting `2026_05_02_300000_add_placement_type_and_slot_to_block_placements.php` stays live alongside the baseline because it also ALTERs the `page_block_placements` table from `modules/pages`)
- `container_bootstrap_test` was a smoke-test no-op (was: 2026_04_21_130000)

## Three files stayed live alongside the baseline

- `2026_04_20_000000_create_baseline_tables.php` — the consolidated baseline itself.
- `2026_04_28_500000_grant_admin_orphaned_permissions.php` — backfill that grants the `admin` role permissions added by later module migrations. Idempotent + still useful when modules ship that haven't been retrofitted to inline-grant.
- `2026_05_02_200000_seed_default_policy_pages.php` — seeds Markdown-sourced privacy / terms / cookie policy pages. Complex enough (file I/O + markdown-to-HTML conversion) to keep as its own migration rather than inline.
- `2026_05_02_300000_add_placement_type_and_slot_to_block_placements.php` — cross-cutting; the framework half (system_block_placements) is in the consolidated baseline, but this migration also alters `page_block_placements` from `modules/pages` — it ALTERs there on fresh installs after that module migration creates the table.

## Studying the history

If you ever need to understand the framework schema's evolution —
when `theme_preference` got added to users, when system_layouts
appeared, etc. — read these files in lexicographic order.
