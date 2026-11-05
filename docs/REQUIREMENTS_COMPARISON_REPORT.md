# Requirements-to-implementation audit

**Assessment basis:** static review of the checked-out PHP repository and its existing audit/mapping documents. This is not a production acceptance test: runtime behavior, database contents, deployed configuration, user workflows and third-party service operation were not verified. “Evidence” below means code surface is present, not that a requirement is fully compliant. No Laravel application or full schema is evidenced in this repository.

## Executive summary

The repository is a substantial existing PHP platform with public program pages, venture/staff portals, content administration, events, mentoring, investor matching, resources, exports, email, Google Calendar hooks and some AWS S3 support. It does **not** establish delivery of all ten specified modules or all the stated security, workflow and service-level acceptance criteria. In particular, the provided source does not substantiate a CMS-driven application/scoring workflow with configurable weighted rubrics and conflict gates; venture/investor directory moderation and safeguarding; WCAG 2.1 AA conformance; malware scanning; automated 24h/1h reminders; or complete audit logging. Several major contractual deliverables (approved architecture/design, UAT sign-off, production go-live, seven manuals, training, warranty) cannot be proven from application source.

**Overall status: Partially evidenced; not demonstrably compliant.** Treat this as a codebase capability comparison, not evidence that contractual milestones were accepted.

## Live public-site spot check (2 November 2026)

Unauthenticated pages were fetched from the production domain. This confirms visible public-facing behavior only; it does not establish admin, authenticated workflows, or accessibility compliance.

- **Homepage:** programme overview, eligibility, programme stages, benefits, cohort/venture showcase, FAQs, application CTA and contact form/contact details are visible. Navigation includes About, Programme, Eligibility, Cohorts, FAQs, Resources, Newsletters, Blogs, Contact and Login. A skip-to-main-content link is exposed in extracted content.
- **Cohorts:** public venture cards are listed with search and category/venture-level filters. This is evidence for public discovery/filtering, but not the full specified directory facets, restricted views, moderation, connection requests or safeguarding controls.
- **Resources:** 16 resources are listed over two pages with type/category filters. Each shown item directs users to “Request Access” through login; role/cohort access rules and download analytics were not tested.
- **FAQs:** FAQ questions appear, but the extracted page showed mostly headings and one short answer; complete answer rendering and application guidance should be checked in a browser.
- **Application and events:** the `/apply` fetch returned only the shared site navigation in the text extraction (consistent with an embedded/external form not captured by this fetch); the application form itself could not be evaluated. The `/events` request returned homepage content rather than an event listing in this fetch, so live event behavior is unconfirmed and the route/redirect should be checked interactively.
- **Accessibility/SEO/analytics:** page extraction cannot validate WCAG 2.1 AA, responsive behavior at actual viewport sizes, metadata, sitemap generation, GA4 events or consent behavior.

These observations supplement but do not replace the source-based findings above. Live fetch does not submit contact/application forms, authenticate, or probe restricted endpoints.

## Module comparison

| Requirement area | Repository evidence | Assessment / gaps requiring validation |
|---|---|---|
| **1.1 Public website** | Public pages for program information, cohorts, FAQs, blogs/newsletters, events, partners and contact exist. Admin pages include FAQs/blogs/partners/site settings. Shared responsive styles are described in README. `includes/header.php` contains GA4 `gtag` configuration. | Public content/admin editing is evidenced, but a general CMS/page builder is not established. No sitemap-generation evidence found. Metadata management, SEO coverage, analytics event tracking, accessible semantics/keyboard/screen-reader behavior and WCAG 2.1 AA audit are unverified. Contact routing and application guidance/document links need workflow testing. |
| **1.2 Online application portal** | `apply.php` embeds the external IMS opportunity form; login/OTP/password flows and venture dashboards exist. Application-related pages/forms exist. Email library and file-upload handling are present. | Application itself is an external iframe integration; no evidence of integrated multi-step wizard, 30-second autosave/resume draft, cohort-configurable conditional questions or role onboarding was found. Verify email verification specifically, upload MIME/size controls and malware scanning (no scanner evidence), status/action timeline, transactional notification coverage and CSV **and** Excel application exports. |
| **1.3 Admin/back office** | Staff/admin surfaces include users, roles/permissions, cohorts/program stages, ventures/startups, milestones, resources, communications, reports and exports. | These support meaningful operations, but application-cycle intake caps/date controls, fully configurable pipeline statuses/tags/bulk actions and fine-grained permission enforcement across every endpoint are not established. No comprehensive immutable actor/timestamp audit trail was evidenced. Export formats and donor-report coverage require inventory/testing. |
| **1.4 Reviewer/scoring** | Survey/interview administration and risk-rating pages include criteria and weighted score calculations; PDF/Excel export helpers exist. | Risk rating is not proof of the requested application-review scoring workflow. No targeted reviewer assignment + mandatory COI gate, configurable weighted application rubric/comment enforcement, cross-reviewer ranking/completion dashboard, score lock/Super Admin override audit, or scoring CSV/PDF acceptance proof was found. |
| **1.5 Venture profiles/documents** | Venture profile/team/document pages; private-file routes; upload paths; `includes/portal-process.php` and `documents.php` contain AWS S3 configuration paths. | Profile and document functionality is evidenced. S3 is conditional on configuration and live backend use is unverified. Date-stamped version history and private/cohort/public visibility with approval workflow are not established. Validate upload authorization, file limits/scanning and version restoration. |
| **1.6 Mentor engagement** | Mentor assignments, messages, sessions/calendar, reports and reminder scripts exist. Google Calendar OAuth/service code is present. | The core mentoring surface is evidenced, but direct messaging permissions, automated reminders, meeting notes/action-item tracking and engagement analytics need workflow verification. Google project credentials, consent/scopes and live calendar sync are unknown. |
| **1.7 Venture directory** | Public/portal venture pages and venture administration exist. | No clear evidence of the specified searchable/filterable directory facets, tiered public/restricted views, featured showcase controls, moderated connection-request workflow, or abuse/safeguarding reporting. Confirm against actual pages/data and access rules. |
| **1.8 Investor directory** | Public investor surface, admin investor management and `admin/investor-matches.php` include match scores/status. | Profiles/matching are evidenced. Focus/ticket/geography/instrument completeness, restricted contact visibility after approval, applicant moderation and connect-request workflow require validation; no complete approval controls demonstrated by the inventory alone. |
| **1.9 Events** | Event listing/registration, admin attendees and reminder cron plus reminder-log SQL are present. | Capacity, confirmation email, attendance and resource-sharing behavior need end-to-end checks. Source indicates reminder capability, but specifically 24-hour and 1-hour schedules, iCal/Google calendar attendee integration and scheduler operation are not verified. |
| **1.10 Content library** | Resources administration and venture resource/download pages exist. | Upload/access-controlled resources are evidenced at a basic level. Module/toolkit/video/recording management, cohort/role permissions, full-text search/tagging, download analytics and engagement reporting are not established. |

## Deliverables and milestones

| Contractual item | Evidence-based status |
|---|---|
| Inception report, confirmed plan, approved architecture | Not evidenced in the application repository; approval/sign-off cannot be inferred. |
| UX/UI wireframes, design system, prototypes and sign-off | Shared styles/assets exist; formal design artifacts and approval are not evidenced. |
| Functional website and platform across all ten modules | Several related capabilities exist, but the gaps above prevent confirming all ten modules or full acceptance. |
| Configured cohorts, application/scoring workflows and RBAC | Cohort/program administration, permissions, surveys/interviews and risk scoring exist; requested application configuration and reviewer controls are not established. |
| Admin dashboards, bulk communication, exports, audit logs | Admin/report/export/bulk-email surfaces exist; complete dashboard scope and comprehensive audit logs are unverified. |
| UAT coordination and formal sign-off | No acceptance records/sign-off evidence found. Tests in repository are technical/security/staging checks, not formal UAT acceptance. |
| Production deployment, go-live and stabilization | Repository configuration mentions production hosting; actual deployment and stabilization evidence is outside source review. |
| Architecture/schema/API/deployment documentation | Repository has migration/audit documentation, but no complete verified schema or API reference is established. README states full schema is held outside the repository. |
| Seven role manuals and staff training (three sessions) | Not evidenced. |
| 30-day post-launch warranty | Contract/service evidence is not in source and cannot be established by code review. |
| Third-party subscriptions (SendGrid, domain, SSL, tools) | PHPMailer and Google Calendar integration code exist; provider credentials/subscriptions, SendGrid specifically, domain ownership and certificate status are unverified. |

Milestone 1–4 are not verifiable as completed/accepted from code alone. The repository contains pieces corresponding to public content, administration, mentoring, events, resources, investor matching and exports. It does not prove milestone approvals, sequence, dates, S3 production setup, UAT/security-test outcomes or go-live. Venture document work appears in both Milestone 2 and 3 in the supplied requirements; clarify whether this is an intentional carry-over or duplicate.

## Priority acceptance checks

1. Obtain role-based staging credentials and execute the complete applicant, administrator, reviewer, mentor, venture, investor and public journeys against a representative sanitized database.
2. Demonstrate application autosave/resume, conditional cohort questions, verified email, notification delivery, upload limits and malware scanning; document any external IMS boundary and owner.
3. Demonstrate reviewer assignment, COI blocking, rubric weights/comments, ranking, score locking/override and immutable audit entries.
4. Test RBAC on direct URLs and handlers; verify audit events, export scopes, private-file access, S3 bucket policy/versioning and virus-scanner integration.
5. Validate public site against WCAG 2.1 AA with automated and manual keyboard/screen-reader checks; verify SEO metadata, sitemap, GA4 consent/event configuration.
6. Test event capacity, confirmation/reminder timing, attendance and calendar exports in staging; verify cron schedule and delivery logs.
7. Collect signed design/architecture, UAT acceptance, deployment/change records, manuals, training attendance, subscription evidence and warranty terms to close deliverable tracking.

## Limitations

No database, production credentials, authenticated live site, third-party consoles, or owner-supplied acceptance artifacts were accessed. Existing documentation also notes that schema, customer uploads and deployed integrations are not fully represented in the repository. Absence of code-search evidence is therefore reported as **not evidenced**, not proof that a capability cannot exist externally.
