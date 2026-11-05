# EdTech Website Requirements — Owner Response Matrix

**Purpose:** Please reply to each item below to confirm whether the requirement is delivered, partially delivered, planned, or not delivered. Where delivered, provide a link, screenshot, test evidence, configuration record, or acceptance document. This matrix records a repository and unauthenticated public-site review; it is not a production acceptance certification.

**Owner / organization:**  
**Date:**  
**Environment reviewed (production/staging):**  
**Respondent / role:**

## Status key

- **Delivered** — available and tested against the requirement.
- **Partially delivered** — some behavior exists; identify what remains.
- **Planned** — not yet delivered; include owner and target date.
- **Not delivered** — out of scope or not being implemented; explain/confirm scope change.
- **Needs verification** — owner to arrange a demonstration or provide evidence.

For every item, please include: **status, evidence or demo location, limitations/gaps, responsible person, and target date for any remaining work.**

## 1.1 Public website

Public pages for program information, eligibility, cohorts, FAQs, resources and contact are visible. Admin content pages and GA4 code are present in source. The live site was not accessibility-audited; sitemap generation and analytics event tracking were not established.

| Requirement | Owner status / response |
|---|---|
| CMS-managed program, eligibility, timeline, partner and FAQ pages | |
| Calls for applications with guidance and document links | |
| News, announcements and stakeholder updates | |
| Contact/enquiry forms route to the correct administrators | |
| Mobile-responsive design | |
| WCAG 2.1 AA tested and compliant | |
| SEO metadata management and generated sitemap | |
| GA4 installed and required events verified | |

## 1.2 Online application portal

The public Apply link is visible, but the application experience was not captured in the live page fetch; repository documentation identifies an external IMS embed. Please confirm which system owns each workflow and provide a test account or demonstration.

| Requirement | Owner status / response |
|---|---|
| Secure registration and email verification; role-based onboarding | |
| Multi-step application wizard | |
| Autosave at least every 30 seconds and resume draft | |
| Cohort-configurable questions and conditional fields | |
| Upload file-type/size validation and malware/virus scanning | |
| Applicant dashboard with current status, actions and timeline | |
| Transactional confirmation and status emails | |
| Application exports in CSV and Excel | |

**External application/IMS system, owner, URL, integration boundary and support contact:**

## 1.3 Admin portal and back office

Admin pages exist for users/roles, cohorts/program stages, ventures, communications and reporting. The full workflow and permission coverage were not tested.

| Requirement | Owner status / response |
|---|---|
| Cohort/cycle configuration, date controls and intake caps | |
| Custom statuses, tags, filters and bulk pipeline actions | |
| Bulk communication for all named user groups | |
| Fine-grained staff permissions enforced on every action | |
| Complete audit trail with actor, timestamp and action | |
| Multi-format exports for reporting/donor accountability | |

## 1.4 Reviewer portal and scoring

Survey/interview and risk-rating/scoring code exists, but this does not establish the specified application-review workflow.

| Requirement | Owner status / response |
|---|---|
| Secure reviewer accounts and targeted assignments | |
| Mandatory conflict-of-interest declaration before application access | |
| Configurable weighted rubric and mandatory qualitative comments | |
| Weighted totals, cross-reviewer ranking and completion tracking | |
| Score lock; Super Administrator override with audit record | |
| Live per-reviewer and overall completion dashboard | |
| Scoring reports exportable as PDF and CSV | |

## 1.5 Venture profiles and document management

Venture profiles/documents and conditional AWS S3 code paths are present. Production storage configuration, version history and visibility approvals were not verified.

| Requirement | Owner status / response |
|---|---|
| Profile covers team, solution, traction and impact | |
| Secure document vault uses AWS S3 in production | |
| Date-stamped uploads and document version history | |
| Private/cohort/public visibility controls | |
| Administrative approval required for public profiles | |

## 1.6 Mentor engagement and communication

Mentor assignment, messaging/session pages and Google Calendar integration code are present. Live service configuration and complete session workflows were not verified.

| Requirement | Owner status / response |
|---|---|
| Mentor accounts and administrator-controlled venture assignment | |
| In-platform mentor/venture messaging | |
| Session scheduling with Google Calendar integration | |
| Automated session reminders | |
| Meeting notes, action items and engagement tracking | |
| Notifications for messages, sessions and pending actions | |

## 1.7 Venture directory

The live cohorts page displays venture cards and basic search/category/level filters. The full directory access, moderation and safeguarding requirements were not demonstrated.

| Requirement | Owner status / response |
|---|---|
| Search/filter by sector, geography, stage, cohort and focus area | |
| Public/restricted visibility aligned to profile approval | |
| Admin-curated featured ventures and cohort showcases | |
| Moderated connection requests and approval workflow | |
| Safeguarding and abuse-reporting mechanisms | |

## 1.8 Investor directory

Investor pages, administration and matching surfaces exist in source. Please confirm production behavior and access controls.

| Requirement | Owner status / response |
|---|---|
| Profiles include focus, ticket size, geography and instruments | |
| Search/filter for ventures and administrators | |
| Contact details restricted until administrator approval | |
| Connect-request workflow with notification and moderation | |
| Investor account application approval/moderation | |

## 1.9 Events module

Event registration/admin/reminder code exists. In the live spot check, `/events` returned homepage content; please confirm the correct public route and demonstrate the event flow.

| Requirement | Owner status / response |
|---|---|
| Public/private event listings and capacity limits | |
| Registration/RSVP and confirmation emails | |
| Reminders at 24 hours and 1 hour before each event | |
| Calendar view and iCal/Google Calendar integration | |
| Attendance tracking and post-event resources | |
| Production scheduler/cron installed and monitored | |

## 1.10 Program content library

The live Resources page lists 16 resources across two pages, with type/category filters and login-required access requests. Role/cohort rules and analytics were not tested.

| Requirement | Owner status / response |
|---|---|
| Manage modules, toolkits, templates, videos and recordings | |
| Role- and cohort-based access controls | |
| Full-text search, categorization and tagging | |
| Download tracking and usage analytics | |

## 2. Contractual deliverables

| Deliverable | Status (delivered / partial / planned / not delivered) | Evidence or link / acceptance date | Owner response / gap |
|---|---|---|---|
| Inception report, confirmed implementation plan and approved architecture | | | |
| Wireframes, full design system and prototypes, signed off before development | | | |
| Functional website and integrated platform covering all ten modules | | | |
| Configured application/scoring workflows, cohorts, rubric and RBAC | | | |
| Admin dashboards, bulk communications, exports and audit logs | | | |
| UAT coordination and formal sign-off | | | |
| Production deployment, go-live support and stabilization | | | |
| Architecture, database schema, API reference and deployment guide | | | |
| User manuals for seven roles | | | |
| Staff training sessions (day-to-day management, content, troubleshooting) | | | |
| 30-day post-launch warranty and bug-fix support | | | |
| Third-party subscriptions (SendGrid, domain, SSL and tools) | | | |

## 3. Milestones and acceptance

For each milestone, provide the completion date, demonstration/acceptance evidence, open defects, and approver. The source review cannot establish milestone completion or sign-off.

| Milestone | Completion date | Evidence / demo | Accepted by / date | Open items |
|---|---|---|---|---|
| 1 — Website, application portal and core admin; requirements, architecture and UX/UI | | | | |
| 2 — Scoring, dashboards, venture document vault and mentor engagement | | | | |
| 3 — Content library, venture/investor directories, events and document vault | | | | |
| 4 — Integration, UAT/security, AWS go-live, docs, manuals, training and warranty | | | | |

**Clarification requested:** Venture Profiles & Document Vault appears in both Milestones 2 and 3 in the supplied scope. Confirm whether this is intentional or a duplicate, and define the acceptance boundary for each occurrence.

## Owner confirmation

Please confirm whether the table above is complete, identify any incorrect findings, and attach or link supporting evidence. “Available in code” should not be treated as accepted until the workflow has been demonstrated and signed off against the stated acceptance criteria.

**Overall owner assessment:** ☐ Fully delivered ☐ Partially delivered ☐ Not delivered ☐ Requires joint verification

**Owner comments / material corrections:**


**Name / role:**  
**Signature (if required):**  
**Date:**
