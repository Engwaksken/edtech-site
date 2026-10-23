# Feature mapping (legacy to future Laravel)

Mapping is source-based and intentionally target-oriented only at a high level. Presence of a file is evidence of a code surface, not proof of current usage or production availability. Detailed request/response and authorization inventory remains a discovery task.

Routing evidence: root `.htaccess` canonicalizes HTTPS/host and `.php` URLs, internally maps clean URLs to existing PHP page files, blocks selected internal directories, and routes specific upload subtrees via `private-file.php`; `admin/.htaccess` also contains admin clean-URL rules. Backend processors under `includes/` and `admin/includes/` intentionally remain `.php` endpoints. Preserve and test these distinctions when designing routes; deployed Apache behavior remains unverified.

| Domain | Legacy evidence (representative paths) | Laravel migration concern / validation |
|---|---|---|
| Public site/content | `index.php`, `cohorts.php`, `faqs.php`, `blogs.php`, `blog.php`, `newsletter.php`, `team.php`, `investors.php` | Route/controller + read services; preserve URLs/SEO, content settings, pagination, public/private distinction. |
| Application | `apply.php` | External IMS iframe `https://ims.hivecolab.com/opportunity-embed.php?id=1`; preserve unchanged absent approval; confirm origin/embedding contract. |
| Venture portal | `login.php`, `verify-otp.php`, `dashboard.php`, `profile.php`, `venture.php`, `venture-portal-access.php`; `includes/process-*` | Auth/session migration, ownership scoping and role gates; enumerate all redirects and CSRF/OTP behaviors. |
| Password lifecycle | `forgot-password.php`, `reset-password.php`, `change-password.php`, related `includes/process-*` | Verify hashing, token/OTP lifecycle, rate limits, expiry, notifications, and session invalidation. |
| Events | `events.php`, `register-event.php`, `includes/process-event-register.php`; `admin/events.php`, `admin/event-attendees.php` | Registration uniqueness/capacity/status rules; reminder SQL/cron behavior and email preferences. |
| Mentoring | `sessions.php`, `calendar.php`, `assignments.php`; admin mentor pages; `send_session_reminders.php` | Scheduling conflicts/timezone, permissions, reminders, Google Calendar optional side effects. |
| Venture delivery/grants | `deliverables.php`, `tranches.php`, `meal-data.php`; admin grant/milestone/startup/field-visit pages | Preserve workflow state, evidence approvals, reporting, audit trail and transaction boundaries. |
| Resources/documents | `resources.php`, `resource-file.php`, `documents.php`, `private-file.php`, `includes/private-files.php` | Private storage/access control, path traversal protection, file metadata and download headers. |
| Surveys/interviews | `survey.php`, `interview.php`, related process handlers; `admin/surveys.php`, `admin/interviews.php` | Dynamic form/schema behavior, submissions, reviewer roles, exports and validation. |
| Messaging/communications | `messages.php`, `newsletter.php`, `admin/bulk-emails.php`, `includes/mail-function.php`, `admin/cron/process-event-reminders.php` | SMTP configuration, consent/unsubscribe, retry/idempotency, queue and scheduler migration. |
| Admin and access management | `admin/*`; `admin/includes/auth.php`, `admin/role-permissions.php`, process handlers | Map every page/action to explicit Laravel policies/middleware; test deny cases, not just UI visibility. |
| Reports and exports | `admin/includes/report-engine.php`, `report-schemas.php`, `export-pdf.php`, `export-excel.php`; `blog-pdf.php` | Preserve filters, calculations, file formats, permissions, encoding and potentially large exports. |
| Site configuration | `admin/site-settings.php`, `includes/config.php` | Separate secrets from ordinary settings; preserve encryption/key compatibility and cache invalidation. |

## Inventory caveats

The repository contains numerous additional staff pages and `includes/process-*` handlers; their exact verbs, inputs, and CRUD invariants must be inventoried before implementation. No REST API inventory or route manifest was identified during static review. Do not infer absent APIs from page names. The routes are legacy PHP scripts and rewrite configuration, not Laravel routes.
