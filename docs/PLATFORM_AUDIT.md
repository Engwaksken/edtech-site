# Platform audit (repository-only)

## Scope and confidence

This is a static audit of the checked-out repository, not a connection to a live site or database. Facts below are directly visible in tracked source or README; runtime behavior, deployed configuration, actual row counts, database version, and production integration state remain unverified.

The public homepage was also fetched from `https://www.edtech.hivecolab.com/` on 2026-10-23. The fetched public page presents the Mastercard Foundation EdTech Fellowship as a growth-stage venture acceleration programme (not an LMS), with About, Programme, Eligibility, Cohorts, FAQs, Resources, Newsletters, Blogs, Contact and Login navigation. It displays the two phases (design for inclusion and learning impact; acceleration for responsible scale), benefits, cohort/venture links and an application CTA. The fetched homepage links `/apply` to an application experience and links several public pages using both extensionless and `.php` URLs. This is a point-in-time unauthenticated observation; no authenticated/live admin behavior was tested.

The currently selected development workspace is `D:\projects\edtech-laravel`, distinct from this legacy source repository. That workspace is Laravel 12.70.0 with a stock starter schema and no EdTech modules currently visible; its existing `docs/SECURITY_REVIEW.md` has a local modification and must be preserved. The workstation CLI is PHP 8.2.12, so it does not satisfy the proposed PHP 8.3+ target. Laravel 13 compatibility has not been demonstrated.

## Runtime and architecture

- A procedural PHP application, not an existing Laravel application. Root PHP scripts are page endpoints; `admin/` contains staff pages and handlers; `includes/` contains shared bootstrap, security, auth, storage, and integrations.
- README states PHP 8.2+, MySQL/MariaDB, Apache 2.4 rewrite/headers (or equivalent), HTTPS, Composer, plus OpenSSL/GD/MySQLi/DOM/mbstring/ZIP requirements. `composer.json` requires PHPMailer 7, Dompdf 3.1.6, PhpSpreadsheet 5.9, PhpWord 1.4, PhpPresentation 0.9, dotenv 5.6 and ext-openssl. Exact deployed versions/extensions are unknown.
- Database access is MySQLi (not ORM), configured via `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`, optional `DB_SSL_CA`; remote DB connections require a CA. Charset is utf8mb4; timezone is Africa/Kampala. DB connection failures log and fail closed with HTTP 503 in web requests.
- No framework router is apparent. URLs are page/handler scripts plus `.htaccess` rewrite behavior. Root and admin code share direct includes and global connection/session state.
- Static review of root `.htaccess` confirms HTTPS/canonical `www` redirects, slash and `.php` canonicalization, internal clean-URL rewrites to existing root/admin PHP files, explicit helper/directory blocking, and routing of selected upload paths through `private-file.php`. Admin has its own rewrite file. These are repository rules only; Apache module/configuration behavior and deployed rules were not exercised.
- No complete schema dump or full database migration history is present per README. One checked-in incremental SQL file exists under `admin/database/`.

## Surfaces and capabilities evidenced

Public/program pages include home, cohorts, FAQs, events, blogs, newsletters, mentors, investors, team, and application. Authenticated venture/mentor pages include dashboard, profile, venture/resources/documents, surveys, referrals, messages, meal data, sessions/calendar, assignments, deliverables, tranches, and event registration. Staff pages cover users/staff/roles, ventures/startups, cohorts/program stages, milestones/evidence/field visits, mentors/sessions, resources, surveys, interviews, referrals, investors/matches, grants, events/attendees, newsletters/bulk email, FAQs/blogs/partners/benefits, site settings, and reports/exports. These names indicate implemented surfaces, not verified production availability.

## Authentication, authorization, session

- Source contains separate venture and admin login/OTP flows, password reset/change flows, and logout handlers (`includes/process-login.php`, `admin/includes/process-login.php`, OTP handlers, and corresponding pages).
- `includes/auth.php` derives venture ID and role from session values, considers listed roles privileged, loads venture/cohort data, and redirects or destroys sessions for invalid/inactive venture accounts. Admin authorization has separate `admin/includes/auth.php`; permission screens/handlers indicate role-permission management.
- Shared security bootstrap is in `includes/security.php`; README describes CSRF protection, secure session settings, and authorization-controlled confidential downloads. Verify details, session cookie policy, OTP expiry/attempt limits, password hashing/cost, recovery enumeration behavior, role matrix, and authorization coverage before porting; not established by this high-level audit.
- Source-level authentication is not a single uniform role gate: `includes/auth.php` normalizes session role candidates and identifies privileged staff, while `includes/grant-process.php` and `includes/process-deliverables.php` contain additional role/admin checks. Password change uses `password_verify` and `password_hash` (`includes/process-change-password.php`); this does not establish that every login/reset path uses the same mechanism or that all authorization paths are covered.

## Data and file handling

- `includes/config.php` references `site_settings`; `includes/auth.php` references `ventures` and `cohorts`. The checked-in event-reminders SQL alters `events` and creates `event_reminder_logs`, with FK to `events(id)` and cascading delete.
- README says full schema/data and uploads are held in authorized private backups, not repository. Therefore table/column inventory, constraints, indexes, data volume, and integrity cannot be asserted comprehensively.
- Upload helper accepts JPEG/PNG/GIF/WebP up to 5 MiB and writes under `uploads/`; README says confidential uploads are protected by tracked `uploads/.htaccess`. Private file routes/helpers exist. Other document/export workflows need per-feature inventory and access testing.
- Source includes encryption utilities for SMTP credentials, Google Calendar tokens, and backups; these are not encryption of all database or upload data. README notes full storage encryption is hosting configuration.

## Messaging, scheduled work, logs, errors

- PHPMailer dependency and `includes/mail-function.php`, plus bulk-email/newsletter/contact and event-reminder paths, establish email capability in source. Actual SMTP host, sender, deliverability, and live credentials are unknown.
- Google Calendar OAuth/connect/callback/disconnect/service files and mentor token table references establish an integration implementation in source. Google project/scopes/consent, current connection, and live operation are unknown.
- CLI/scheduled scripts include root `send_session_reminders.php`, `admin/cron/process-event-reminders.php`, and secure-storage utilities. Scheduler installation/frequency and delivery outcomes are unknown.
- PHP `error_log` usage, checked-in local `error_log` files, application error pages and reminder-log table indicate error recording. Log contents/retention/access controls are not assessed here.

## IMS and other external integrations

- `apply.php` embeds `https://ims.hivecolab.com/opportunity-embed.php?id=1` in an iframe. This is the only IMS behavior verified in the source review: no evidence here of server-to-server synchronization, API credentials, webhook processing, or shared database. Do not alter this integration during migration without owner approval and an independently agreed contract/test plan.
- Google Calendar OAuth is evidenced as above. External fonts/CDN assets are referenced in page markup. No assertion is made that any external service is currently reachable.

## Risks / unknowns to resolve

Obtain a privately authorized schema-only/data inventory and representative masked backup; enumerate all page and handler paths and role access; document live cron jobs, SMTP and Google OAuth configuration, upload volumes/path rules, session/OTP behavior, PHP/MySQL versions, Apache rewrites, and IMS owner/contract. Do not use production credentials or data in initial migration work.

## Backup and recovery readiness (phase gate)

- No database dump is distributed in the legacy repository; README explicitly states the complete schema/data and customer uploads are held in authorized private backups. A repository search found no root-level SQL/dump backup artifact. The `uploads/` directory contains feature folders, but its presence does not establish that its contents are complete or restorable.
- No backup was created, read, restored or verified during this audit. This work has no approved database connection, backup credentials, encryption-key custody evidence or isolated restore target. Backup verification is therefore **blocked pending owner-authorized backup artifacts and an isolated restore environment**.
- Before implementation, obtain a fresh database and uploads backup through the authorized operator, preserve the encryption key separately, checksum the artifacts, restore into a disposable isolated environment, and reconcile schema/row/file counts. Do not treat a successful file copy or encrypted backup file as restore verification.
