# Archived framework migrations

These migrations were folded into the consolidated baseline on 2026-06-03
(migration cleanup, Stage 1 — framework). Kept for git/audit history; they
are NOT discovered by the Migrator (it scans this directory's parent only,
one level deep).

The live framework migration set is now just three files:
  - `0001_framework_schema.php`  — create base tables (regenerated from the
                                   live working DB via SHOW CREATE TABLE, so
                                   every accreted column is included)
  - `0500_framework_data.php`    — populate base tables (seed logic; the
                                   `$this->db->prepare()` → `pdo()->prepare()`
                                   bug that crashed fresh installs is fixed)
  - `9999_finalize_admin_permissions.php` — grant admin every non-SA-only
                                   permission, running LAST so it catches
                                   module-added permissions

Absorbed (schema → 0001, seeds → 0500): the original `2026_04_20` baseline,
the page/menu/page_sections column ALTERs, `create_health_notes`,
`add_placement_type_and_slot`, `seed_default_policy_pages`,
`grant_admin_orphaned_permissions` (→ 9999).
No-ops on a fresh install (archived): `drop_tenant_block_usage`,
`retire_v1_theme_keys`.
Apex-specific, removed from the per-tenant framework baseline:
`ensure_knowledgebase_apex_tables` (the KB module creates its own tables) and
`seed_core_features_page` (builder marketing content — should not seed into
every tenant; re-home as an apex-only seed if the apex needs it).
