# Database mapping (evidence-limited)

## Connection and schema evidence

Legacy database access uses MySQLi through `includes/config.php`: environment settings `DB_HOST` (default localhost), `DB_PORT` (3306), `DB_USER`, `DB_PASS`, `DB_NAME`, optional `DB_SSL_CA`; remote database connections require CA TLS verification. Connection charset is `utf8mb4`; PHP timezone is `Africa/Kampala`. README identifies MySQL/MariaDB and states the complete database schema/data are not in the repository. No live database was accessed.

## Verified table/relationship references

| Table | Evidence | Notes / unknowns |
|---|---|---|
| `site_settings` | `includes/config.php:get_setting()` selects `setting_value` by `setting_key` | Only referenced columns verified; constraints/types/indexes unknown. |
| `ventures` | `includes/auth.php` selects `v.*`, filters `id`, checks `status`, uses `cohort_id` | Columns beyond explicit references unknown. |
| `cohorts` | Joined in `includes/auth.php`; explicit `id`, `name`, `start_date`, `end_date` | Relationship implied by `ventures.cohort_id`; FK not verified. |
| `events` | `admin/database/2026_08_04_event_reminders.sql` alters it; references `id` | Existing schema unknown. Added reminder columns are listed below as repository migration intent, not proof that SQL has been applied. |
| `event_reminder_logs` | Same SQL creates table | `event_id` FK references `events(id)` with `ON DELETE CASCADE`; index definitions as below. Actual table existence unknown. |
| `mentor_google_tokens` | `bin/secure-storage.php` references `access_token`, `refresh_token`, `mentor_id` | Table schema and whether encrypted records exist in deployed database unknown. |

## Incremental SQL file observed

`admin/database/2026_08_04_event_reminders.sql` adds to `events`: `reminder_enabled`, `reminder_value`, `reminder_unit`, `reminder_time`, `reminder_method`, `reminder_at`, `reminder_status`, `reminder_sent_at`, `reminder_last_error`, `reminder_attempts`. It creates `event_reminder_logs` (`id`, `event_id`, nullable `registration_id`, `recipient`, `delivery_method`, `status`, `error_message`, `attempted_at`) with indexes `(event_id, attempted_at)`, `recipient`, FK to `events(id)` and cascade delete. This is source evidence only; applied status must be checked in a restored isolated copy. Do not apply, alter, or reverse it as part of this audit.

## Module/table families to inventory from an authorized schema snapshot

Source modules imply data domains including users/staff/roles/permissions, ventures/teams/cohorts, milestones/evidence/visits, sessions/availability/assignments/reports/deliverables, events/registrations/reminders, surveys/questions/responses, interviews, resources/files, referrals, messages, newsletters, investors/matches, grants/tranches/meal data, site settings, and Google tokens. These are domain candidates only; exact table names, columns, FK topology, cardinalities and lifecycle rules are not claimed.

## Required next discovery (no database connection in this audit)

Request an owner-authorized schema-only export or sanitized catalog from the database owner; record server version, engine/collation, tables/views/triggers/routines, columns/defaults/nullability, keys/indexes/FKs, enum values, row counts and representative masked relationships. Validate `events` reminder migration state in a disposable restored copy. Reconcile storage references and database rows without copying confidential data into the repository. Keep all backups and credentials outside this documentation workflow.
