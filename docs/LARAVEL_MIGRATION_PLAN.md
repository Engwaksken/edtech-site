# Laravel migration plan

## Guardrails

No schema change, production access, credential rotation, IMS change, package installation, deployment, deletion, or destructive operation is authorized by this plan. Treat all target behavior and infrastructure as subject to owner approval.

## Phase 0 — protect and validate recovery (first)

1. Identify the authorized database and upload backup process with the system owner; do not run it from this audit.
2. In an isolated, access-controlled environment, validate that a backup can be restored into a disposable database and that uploaded/private files can be restored to a separate disposable path. Record timestamps, checksums, encryption-key custody, restore duration, and validation results without exposing secrets.
3. Do not proceed until restore success and required encryption-key availability are confirmed. Preserve originals; never overwrite source data.
4. Build isolated staging with synthetic or masked data, separate credentials, mail sink, non-production Google OAuth project/calendar, and blocked/disabled external side effects by default. IMS embed can remain unmodified only where explicitly approved; avoid production calls.

## Phase 1 — compatibility and discovery

- Confirm target PHP 8.3 runtime/extensions, Composer platform requirements, and Laravel 13 support matrix for PHP 8.3 against authoritative release requirements before selecting a Laravel version. The repository currently documents PHP 8.2 minimum; this is not proof that all code/dependencies work on 8.3 or Laravel 13.
- Inventory actual schema, collations, SQL modes, indexes, constraints, triggers, views, stored routines, data volumes, uploads, session/auth semantics, scheduled commands, and environment-specific Apache rewrites from authorized artifacts.
- Run syntax/security/environment checks already documented in README; baseline all page flows and role-specific behavior in isolated staging. Record baseline screenshots/API responses and representative data relationships.
- Review installed dependencies and extension/runtime compatibility using lockfile/platform checks. No package additions are part of this planning/audit phase.

## Phase 2 — design and controlled implementation (separate approval required)

- Create Laravel architecture mapping only after discovery: explicit routes, middleware, controllers/form requests/resources, Eloquent models, policies, services, queued jobs/notifications, scheduled commands, storage disks, and configuration.
- Preserve existing database semantics initially; prefer a compatibility-first application cutover over schema redesign. Migrations, auth/policy changes, storage changes, dependency changes, deletion/rename, and deployment each require human approval and reviewed plans.
- Keep IMS iframe behavior and external contract unchanged unless separately authorized. Reproduce Google Calendar, SMTP, private downloads, encryption, and cron effects only after staging credentials and owner sign-off.
- Add feature tests for each mapped workflow and authorization boundary, regression/security tests, and import/restore reconciliation checks. Validate idempotency for jobs and safe retry semantics.

## Phase 3 — rehearsal and cutover readiness

- Rehearse backup, restore, deployment, data reconciliation, upload serving, queue/scheduler behavior, and rollback in isolated staging with production-like but non-sensitive volume.
- Define objective acceptance gates, maintenance window, accountable operators, monitoring, communications, and a go/no-go decision. Freeze writes only under an approved operational plan.
- Preserve the legacy application and data unchanged during parallel validation; avoid dual writes unless explicitly designed and tested.

## Rollback and preservation

- Rollback must restore the prior application artifact/configuration and continue using the preserved legacy database/uploads; it must not require reverse destructive migrations.
- Before any eventual cutover, define how writes made after cutover are reconciled (or prevent them during the rollback window), how secrets/keys remain available, and how DNS/web-server routing is reverted. Test this exact procedure in staging.
- Retain verified backups, encryption keys, original code/artifacts and audit evidence according to owner retention/security policy. Never delete or overwrite legacy artifacts as part of migration without explicit approval.

## Exit criteria

Restore is proven; PHP 8.3/Laravel 13 compatibility is demonstrated; schema and all endpoints/workflows are mapped; auth/role and sensitive-file tests pass; IMS/external integration owners approve; staging regression and rollback rehearsals pass; and a human-approved change/deployment plan exists.
