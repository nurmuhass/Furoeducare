# Furoeducare Implementation Progress

## Comprehensive security review — 23 November 2026

Implemented for review. [SECURITY_REVIEW.md](SECURITY_REVIEW.md) covers all requested audit areas, confirmed findings/severity, remediation, open risks, file inventory and exact manual steps.

Fixed active PDF and dangerous DOCX acceptance, legacy stored-original policy bypass, archive/file-link handling, response filename/header containment, browser security headers and public session-directory configuration. No schema changes or production records were involved.

Security regressions passed: 78 workflow checks; 50 file checks; authentication, core HTTP and foundation suites; extraction/library-file controls; full administrative and account-settings suites; focused permission/input/view/crypto checks. An OS filesystem-symlink test was skipped for unavailable Windows privilege. Production web/TLS/SMTP, malware scanning, OS parser confinement, comprehensive retention and concurrency assurance remain open. See the review for test sequencing and explicit limits. This phase stops for review.

## Operational reporting and future monetisation design

Implemented 16 real operational report measures with explicit date bases, inclusive WAT filters/all-time selection, human definitions and aggregate-only CSV. Report/export access requires fresh reporting and module permissions; a consistent database snapshot and attributed export audit preserve privacy. Active vacancy counts match public eligibility, confirmed placements remain successful after closure, and private drafts/expired message records are excluded. Future catalogue, pricing, order/payment, billing, invoice/provider and transaction-audit architecture is documented only. All offered services remain free; no migration, dependency, billing table, activation switch or gateway was added.

Verified on 23 November 2026 with PHP 8.5.12 and isolated MySQL 8.4.13. SQL/date/CSV tests and all four HTTP reporting/free-launch groups passed, including malformed query shapes, WAT boundaries, permission revocation, HEAD/GET audits, actual anonymous free downloads and registration, rejected payment activation and unchanged schema. Chrome checks passed the real rendered report at 360/768/1440 widths with themes, focus, form/modal behavior and no overflow. All 440 PHP files, Composer strict validation, Git whitespace and foundation regressions passed. Static review found no actionable issue. No application database was changed. Full prior recruitment/CV workflows were not all rerun; production-scale profiling and manual accessibility/device checks remain. Exact file inventory, seven manual steps and definitions are in [OPERATIONAL_REPORTS.md](OPERATIONAL_REPORTS.md); the inactive future proposal is [MONETISATION_DESIGN.md](MONETISATION_DESIGN.md).

## Account settings and persistent appearance

Implemented MySQL-backed Light/Dark/System account preferences, local guest preferences, early theme resolution and shared native appearance controls across public/authentication pages, dashboards and shared services. `/settings/account` now joins profile/contact, password, verified email change, notifications, appearance, privacy and deactivation request controls. Password/email changes require reauthentication and invalidate other sessions/security links. Phones preserve teacher evidence and school approval rules; deactivation is linked to existing authorised privacy review. No new runtime dependency or production data change was introduced. Migrations 0083–0086 must be applied before using authenticated pages.

Verified on 23 November 2026 using PHP 8.5.12, isolated MySQL 8.4.13 and isolated Chrome. All seven settings HTTP groups, appearance DB/JavaScript checks, all 86 migration fresh/upgrade/rollback/failure checks, the five-group admin high-risk regression, foundation tests and focused view/input/navigation/cryptography checks passed. Browser checks passed four role settings and five public/shared page types at three widths (27 combinations), including themes, account-vs-guest controls, focus/dialog/form payload/loading/Back behavior, contrast and overflow. The settings suite covers safe appearance return paths and existing account ownership/session restrictions. Static security review found no blocker and its session-version hardening was added.

The first settings run exposed a mixed-collation email lookup, fixed with explicit comparison using the existing users email collation without changing applied migrations. Full legacy workflow suites were not all rerun. Physical-device, screen-reader and deployed SMTP/DNS checks remain release review items. File inventory, setup, test reproduction and eight exact manual steps are in [ACCOUNT_SETTINGS.md](ACCOUNT_SETTINGS.md); logs/screenshots remain private under `storage/local-preview`.

## Shared dashboard usability and consistency

Teacher, School, Parent and Admin now use one reusable responsive shell, grouped role navigation and common Library, Messages, Notifications and Settings links. Metrics, statuses, tables, filters/search, pagination, form errors, empty states, loading feedback and confirmation dialogs share components. Existing module records and permission/ownership/approval checks remain authoritative. Teacher notifications now receive the actual unread count. Shared request pages honour their backend-selected owner category; keyboard focus recovers when mobile/desktop navigation changes.

Verified on 23 November 2026 using PHP 8.5.12, isolated MySQL 8.4.13 and isolated Chrome. Four-role HTTP/navigation/shared-service/validation/restriction and multi-role direct-link checks passed, as did the complete five-group admin security regression. Browser checks passed all four roles at 360/768/1440 widths for overflow, landmarks/IDs, selected navigation, keyboard focus, breakpoint recovery, confirmations, preserved submitter payloads, loading/Back recovery and themes. Foundation, component, dashboard availability, admin policy, notification/library view tests, 415-file PHP lint, Composer strict validation and whitespace checks passed. No migration or dependency was added; the application database remains untouched. Full legacy workflow suites were not all rerun. Exact file inventory, reproduction commands, limitations and seven manual steps are in [DASHBOARD_UI.md](DASHBOARD_UI.md).

## Complete administration workspace

The shared administration shell now hosts all twenty requested management areas and the existing data-rights workspace. Staff have explicit permission-based navigation, scoped overview/search/reports, account directories and reviewed suspension controls, safe email monitoring, audit activity, protected custom-role administration and allowlisted operational settings. Existing workflows retain their verification/disclosure/placement safeguards and now have central HTTP gating plus service-level permission checks.

| Area | Files created or modified |
| --- | --- |
| Staff policy, gate, protected mutations and reads | `app/Modules/Admin/` |
| Interface | `resources/views/admin-shell.php`, layout integration, `public/assets/admin.css`, `public/assets/admin.js`, Admin views/controller/routes |
| Routing/shared context | Router guard support, Request form-key validation, bootstrap shared admin context, auth route replacement and safe domain errors |
| Staff bootstrap | `bin/staff.php` |
| Permissions and upgrade | Migrations 0077–0082 |
| Existing management services | Verification/school suspension, directory, vacancies/applications, candidate/disclosure, tutoring, placements, library, training, messaging, privacy and staff email recipient selection |
| Tests | Admin policy/read-model/main/search/messaging/concurrency tests, migration suite, explicit staff fixture helper and updated legacy synthetic staff assignments |
| Documentation | ADMINISTRATION.md, README, requirements, architecture, decisions, database relationships, test plan and this record |

Verified with PHP 8.5.12 and isolated MySQL 8.4.13 schemas on 23 November 2026. Administration HTTP checks passed all five role definitions, coarse-role denial, twenty module destinations, direct/override/private-file requests, navigation, search, CSRF/confirmation/stale versions, self/last-Super protections, delegation/revocation, settings restrictions, safe email/audit views and trusted bootstrap. All 82 migration bootstrap/upgrade/legacy-role selection/rollback/reapplication and failure-marker/checksum/seed checks passed. Focused search, assigned-message permission loss and two-connection role-mutex protections passed. Read/model validation and strict component/confirmation tests passed.

The first HTTP run identified a too-strict test expectation for unsupported verbs (405 safely rejects them); the next reproduced a real mixed-collation UNION search error, corrected with explicit UTF-8 projection. Their logs remain private alongside final results under `storage/local-preview/admin-*.log`. The final expanded search regression passed all twelve sources, mixed collations, scoped routes, private draft exclusions, message retention and cursor queries on the same migrated disposable schema.

Final verification passed syntax checks for all 406 PHP files, administration policy/input and notification view tests, all four foundation HTTP/configuration/logging/method-override groups, Composer strict validation and platform requirements, and Git whitespace checks. Legacy integration fixtures were updated and linted; every complete legacy workflow suite was not rerun in this phase.

The interactive browser preview tool timed out twice. Isolated headless Chrome then rendered the actual shared components using clearly labelled synthetic preview data. Exact 360, 768 and 1440 viewport/client/scroll widths matched without horizontal overflow, tablet dark mode rendered correctly, and a top mobile Close control was added and checked. Screenshots are private under `storage/local-preview/admin-browser-preview/`; no fake production records or real application database changes were made. Deployment needs migrations and deliberate staff appointment/bootstrap. Exact setup and eight manual steps are in [ADMINISTRATION.md](ADMINISTRATION.md).

## Central notifications

The Notification module now supplies one event catalogue and transactional emission service, owner-scoped paginated feed/read/open/count endpoints, independent in-app/email settings and a shared dashboard header bell. Existing event records are retained in the extended legacy ledger. Account/security, verification, recruitment, candidate, disclosure, messaging, interview/placement and training hooks use the central event flow. Authentication token email retains its existing dedicated encrypted transport.

| Area | Files created or modified |
| --- | --- |
| Event service, catalogue, access, inbox, validation and settings | `app/Modules/Notification/` |
| Routes and shared rendering | `config/notification-routes.php`, `bootstrap/app.php`, `app/Core/View.php`, `resources/views/notification-bell.php`, shared header |
| Dashboard navigation and legacy tabs | Teacher enum/controller/workspace, parent/school controllers and dashboard views, shared administrator dashboard |
| Domain adapters/events | VerificationEvents, AuthService, EmailEvents/Templates, vacancy/application, candidate, placement, tutoring and school repositories |
| Database | Migrations 0074–0076 |
| Tests | Notification input/catalog/render-paths/views/integration/invariants/domain suites; legacy training mail, migration and parent/school/teacher expectations |
| Documentation | NOTIFICATIONS.md, README, requirements, architecture, decisions, database diagram, email guide, test plan and this progress record |

Verified on 23 November 2026 with PHP 8.5.12 and isolated MySQL 8.4.13 schemas. Main notification integration passed, including four roles, header count/feed/settings, ownership and CSRF, independent channels, safe links, stable/conflicting keys, transaction rollback, bounded mark-all, pagination, activation and password reset. Supplemental two-connection tests passed committed preference changes, legacy email-only saves and duplicates committed after an older snapshot. Real domain tests passed managed interview delivery, repeated candidate claims and training source revisions/role paths. A reproduced stale duplicate lookup was corrected with a current locking read.

All 76 migration bootstrap/upgrade/backfill/rollback/reapplication and failure-marker/checksum tests passed, including preservation of legacy notifications without historical mail. Pure input/catalog/navigation/rendering and legacy training-mail tests passed. Foundation startup/configuration/logging checks, affected PHP lint, Composer strict/platform checks and Git whitespace checks passed. Parent/school/teacher legacy-tab assertions were updated and linted; their complete workflow suites were not rerun in this phase.

Private evidence logs: `storage/local-preview/notifications-integration.log`, `notifications-invariants.log`, `notifications-domain.log` and `notifications-database.log`, all with exit status zero. The initial snapshot concurrency regression is preserved separately in `notifications-invariants-before-fix.log`.

Setup and eight exact manual verification steps are in [NOTIFICATIONS.md](NOTIFICATIONS.md). Deployment requires the migrations and existing email worker. Interactive responsive/appearance review remains a manual step.

## Training and workshops

The current phase adds a public module/package catalogue, school-owned training requests, staff assignment/approval/scheduling/outcomes and managed request messaging. The design and implementation plan are recorded in [TRAINING.md](TRAINING.md). Payments remain disabled.

Implementation sequence: migrations 0067–0073 and pure validation/status checks; catalogue and transactional intake/decisions; public/school/staff interfaces and dashboard navigation; generic email/in-app events and school-owned messaging; isolated migration and workflow verification. Existing changes from earlier phases remain in the working tree.

| Area | Files created or modified |
| --- | --- |
| Training domain and shared-layout interfaces | `app/Modules/Training/` |
| Routes, bootstrap and static landing replacement | `config/training-routes.php`, `bootstrap/app.php`, `app/Modules/Site/resources.php` |
| Dashboard navigation | School and shared administrator dashboard views |
| Messaging | MessageInput, MessageContext, MessagingController and conversation view context labels/links |
| Email | EmailEvents and generic training template wording |
| Database | Migrations 0067–0073 |
| Tests | Training input, notification, HTTP and invariant suites; message input, migration and foundation expectations |
| Documentation | README, TRAINING.md, requirements, architecture, decisions, database diagram, progress and test plan |

Verified on 23 November 2026 using PHP 8.5.12 and isolated MySQL 8.4.13 schemas. The full HTTP workflow passed all three groups. Supplemental checks passed for preserved snapshot order, stable/conflicting retries, full request/decision rollback on forced outbox failure, selected choices beyond the first 200 and independent catalogue cursors. All 73 migration bootstrap/upgrade/rollback/reapplication, failure-marker/checksum, seed and transaction checks passed. Pure input/notification/message tests, foundation regressions, PHP syntax, Composer strict/platform checks and Git whitespace checks passed. Twenty-six representative view states rendered without warnings, including escaped metadata.

Evidence is retained privately in `storage/local-preview/training-http.log`, `training-invariants.log`, `training-database.log` and `training-foundation.log`. Deployment still requires applying migrations and running the existing email worker. Interactive responsive/appearance inspection remains in the manual checklist. Full setup and eight exact manual steps are in [TRAINING.md](TRAINING.md).

## Digital library

The Library module provides a shared catalogue and resource details for public visitors, teachers, schools and parents. Staff manage bounded PDF/EPUB materials, reencoded JPEG/PNG covers, classification, rights metadata, publication, named-account restricted grants and download counts. Public file responses recheck publication, rights expiry and account grants. Free downloads work without registration; future paid downloads stay disabled.

| Area | Files created or modified |
| --- | --- |
| Module and reusable views | `app/Modules/Library/` |
| Routing, configuration and cleanup | `config/library-routes.php`, `bootstrap/app.php`, `app/Core/Config.php`, `.env.example`, `bin/library-cleanup.php` |
| Database | Migrations 0061–0066 for resources, tags, grants, saves, downloads and file-removal ledger |
| Dashboard integration | Teacher dashboard enum/controller/workspace, school/parent dashboard views and shared administrator dashboard |
| Existing landing page | `app/Modules/Site/resources.php` removes the superseded static library entry |
| Tests | `tests/library.php`, `tests/library-roles.php`, `tests/library-input.php`, `tests/library-files.php`, `tests/library-views.php`, `tests/support/library-fixtures.php`, migration/foundation/teacher regression expectations |
| Documentation | README, `DIGITAL_LIBRARY.md`, database diagram, requirements, architecture, decisions and test plan |

Code review prompted a read-only removed-resource screen, paginated grants, safe unavailable saved-item controls and deferred post-commit cleanup failures. Safe relative EPUB manifest links have regression coverage.

Verified on 23 November 2026 with PHP 8.5.12 and isolated MySQL 8.4.13 schemas:

- `tests/library.php` passed all three HTTP groups, covering uploads/publication, free guest access, real 13-resource pagination, owned saves/history, independent grants, expiry/revocation, revision invalidation, paid denial, EPUB replacement, stale forms, live account/role restrictions, scrubbed tombstones and physical file cleanup.
- `tests/library-roles.php` passed teacher and pending-school free/restricted access, account history, complete 53-record grant pagination, role-loss logout/re-login and archive/removal controls.
- `tests/library-input.php`, `tests/library-files.php` and `tests/library-views.php` passed metadata, document/image structure, private path/integrity and view safety checks.
- `tests/database.php` passed all 66 migrations, prior-release upgrades, rollback/reapplication, checksum/failure-marker, transaction and seed checks.
- `tests/teacher.php`, `tests/dashboard.php` and `tests/foundation.php` passed regression checks, including actual dashboard navigation, shared library redirects, safe configuration errors and logging.
- Changed PHP syntax checks, Composer strict validation/platform checks and Git whitespace checks passed.

The private verification outputs are retained in `storage/local-preview/library-suite-final.log`, `library-supplemental-final.log`, `library-database-final.log` and `library-teacher-regression.log`. Earlier library runs exposed two test assumptions: metadata edits accidentally resetting access, and expecting 403 after shared authentication invalidated a roleless account. The corrected main suite passed in a fresh schema.

Browser automation timed out twice before a usable surface was returned, so interactive responsive and appearance-mode inspection remains a manual review step. No fixture books or grants were inserted into the application's real database. Deployment needs migrations, private storage permissions, upload size configuration and periodic cleanup. See [exact setup and manual verification](DIGITAL_LIBRARY.md).


## Transactional email

The Email module now supplies fourteen reusable HTML/text templates, a MySQL outbox, delivery attempts, SMTP and private file transports, account preferences and a background worker. Authentication token issuance and implemented business events commit their mail records in the same transaction. Safe email content directs recipients to authenticated screens. Failure classifications, retry limits, stable event keys, provider acknowledgements and uncertain-state handling support operational review.

| Area | Files |
| --- | --- |
| Delivery, templates, queue and preferences | `app/Modules/Email/`, `config/email-routes.php` and `bin/email-worker.php` |
| Configuration and dependency | `.env.example`, `app/Core/Config.php`, `composer.json` and `composer.lock` |
| Existing workflows | Auth issuance/activation, verification events, candidate/tutoring submissions, vacancy/application events and dashboard settings |
| Database | Migrations 0058–0060 |
| Tests | `tests/email.php`, `tests/email-crypto.php`, `tests/email-interview.php`, `tests/support/smtp-fixture.php`, queue-aware authentication test support and migration tests |
| Setup and operations | `docs/EMAIL.md`, README, authentication guide, requirements, architecture, decisions, database reference and test plan |

The training confirmation template is available for the later training request module. The deployment still needs SMTP credentials, sender DNS, the shared encryption key and a scheduled worker. Provider acknowledgement is recorded, while later delivery details are reviewed in the provider's reporting.

Verified on PHP 8.5.12 with Sodium and isolated MySQL 8.4.13 schemas. The final email integration suite passed for file delivery, actual loopback SMTP acceptance/4xx/5xx/disconnect outcomes, bounded retries, preferences and ownership, encrypted and consumed tokens, transactional rollback, duplicate events, safe logs and uncertainty after a post-send persistence failure. A focused test confirmed one teacher invitation for managed scheduling. Authentication, teacher onboarding and the 60-migration upgrade/rollback suites passed. PHP syntax, Composer validation/platform and Git whitespace checks passed. Production provider delivery, sender DNS and scheduled execution remain deployment checks.

## Managed messaging

Account holders can open private threads with management, link eligible requests, read messages and receive generic notifications. Staff can search and filter conversations, claim responsibility, reply, update status and place a preservation hold. The thread inbox and history use pagination. Read acknowledgements support vanilla JavaScript and ordinary forms.

| Area | Files |
| --- | --- |
| Workflow, access and rendering | `app/Modules/Messaging/`, `config/messaging-routes.php`, `bootstrap/app.php` and `public/assets/messaging.js` |
| Database and retention | Migrations 0055–0057 and `bin/message-retention.php` |
| Existing entry points | Teacher, parent, school and administrator dashboards, plus request, information request, vacancy, application and placement views |
| Tests | `tests/message-input.php`, `tests/messaging.php`, `tests/database.php` and `tests/parent.php` |
| Documentation | Requirements, architecture, decisions, database reference, this progress log, test plan and the draft privacy notice |

The initial retention period is 365 days from closure, with an audited staff hold. The deployment needs a scheduled run of the cleanup command and a backup retention policy. The reviewed operational schedule can replace this initial setting. Messaging notifications use the existing in-app notification service.

Verified on PHP 8.5.12 and isolated MySQL 8.4.13 databases. The messaging HTTP suite passed for all three account roles, source ownership, CSRF, script escaping, private notifications, independent read cursors, staff assignment, status changes, send retries, filters, message pagination, account rate limits and retention. A second run also verified the read receipt case for an account with both teacher and administrator roles. The 57-migration upgrade and rollback suite, parent dashboard regression, input checks and HTTP foundation suite passed. PHP lint passed for 282 files and JavaScript syntax and Git whitespace checks passed. Interactive browser review and deployment scheduling remain manual checks.

See the [manual messaging walkthrough](TEST_PLAN.md#managed-messaging) for setup, access checks, pagination and retention verification.


## Managed recruitment and placement

The managed workspace now covers school employment and private tutoring, including scheduling, selection, final decisions, status updates and home tutoring safeguards. Existing application and tutoring records keep their own status histories. Staff work from the source applications or tutoring requests, and participants track the released placement stages in their dashboards.

| Area | Files |
| --- | --- |
| Placement rules and workspace | `app/Modules/Placement/`, `config/placement-routes.php` and `bootstrap/app.php` |
| Source and participant integrations | The Vacancy, Parent, Teacher, School, Verification and Auth modules |
| Database | `0053_create_placements.php` and `0054_create_placement_history.php` |
| Tests | `tests/placement-workflow.php`, `tests/placements.php`, `tests/database.php`, `tests/vacancies.php` and `tests/tutoring.php` |

The placement HTTP suite passed on PHP 8.5.12 and an isolated MySQL 8.4 schema. It confirmed employment and home tutoring, exercised rejected and withdrawn placements, and checked approval, availability, professional and safeguarding restrictions, duplicate prevention and participant privacy. The vacancy, tutoring and foundation HTTP suites, all three workflow tests, and the 54-migration upgrade and rollback suite also passed. PHP lint passed for 264 files.

See the [test plan](TEST_PLAN.md#managed-recruitment-and-placement) for the local walkthrough and test configuration.

## Private tutoring requests

Parents can start general requests or use a public teacher reference. Both use private drafts, owner-only tracking, recommendations and confirmed arrangements. Staff manage submitted requests in the tutoring queue, search for compatible tutors, shortlist candidates and confirm professionally verified tutors. Status changes record history and in-app notifications.

| Area | Files |
| --- | --- |
| Parent and staff workflow | `app/Modules/Parent/`, `config/parent-routes.php`, `bootstrap/app.php` and administrator dashboard links |
| Directory integration | `DirectoryController.php`, `DirectoryInteractions.php` and `PublicDirectory.php` |
| Database | Migrations 0039–0043 extend tutoring requests, preserve existing records and add candidates and request history. |
| Tests | `tests/tutoring-workflow.php`, `tests/tutoring.php`, `tests/parent.php`, `tests/directory.php` and `tests/database.php` |
| Documentation | Project requirements, architecture, decisions, database reference, parent account guide, progress, test plan and the draft privacy notice |

Verified on Windows with PHP 8.5.12 and isolated MySQL 8.4.13 schemas. The tutoring workflow, tutoring HTTP, parent, directory, database and HTTP foundation suites passed. The database suite verified all 43 migrations, preserved earlier tutoring requests and tested rollback of the reversible tables.

See the [private tutoring test plan](TEST_PLAN.md#private-tutoring-requests) for the manual walkthrough.

## Teacher job applications

The application flow now captures an optional statement, an internal candidate reference and a limited copy of the teacher's saved professional fields. Teachers can save jobs and track or withdraw applications. Staff can advance candidates through review, interview, offer and verified final placement.

| Area | Files |
| --- | --- |
| Application flow | `app/Modules/Vacancy/`, `app/Modules/Verification/TeacherEligibility.php`, `config/vacancy-routes.php` and `bootstrap/app.php` |
| Teacher dashboard | `TeacherController.php`, `DashboardPage.php` and the applications, saved jobs and overview views |
| Storage | Migrations 0035–0038 extend applications, backfill references and add saved jobs. The existing application history table retains every valid transition. |
| Tests | `tests/application-workflow.php`, `tests/vacancies.php`, `tests/database.php` and `tests/teacher.php` |
| Documentation | Project requirements, architecture, decisions, database reference, implementation progress and test plan |

Verified on Windows with PHP 8.5.12 and isolated MySQL 8.4.13 schemas. The application transition matrix, vacancy HTTP, teacher, dashboard, database and foundation suites passed. The database suite verified all 38 migrations, upgraded legacy applications without changing their timestamps or history, and checked rollback and reapplication.

See the [manual application walkthrough](TEST_PLAN.md#teacher-job-applications).

## Teaching vacancies

The public job board, school vacancy workspace, staff moderation and teacher application history are implemented.

| Area | Files |
| --- | --- |
| Vacancy module and routing | `app/Modules/Vacancy/`, `config/vacancy-routes.php`, `bootstrap/app.php`, `app/Modules/Site/resources.php` |
| Dashboard integration | `SchoolController.php`, the school and administrator dashboard views, `TeacherController.php`, `DashboardPage.php` and the teacher jobs, applications and overview views |
| Eligibility and storage | `app/Modules/Verification/TeacherEligibility.php` and migrations 0031–0034 |
| Automated tests | `tests/vacancies.php`, `tests/database.php`, `tests/teacher.php`, `tests/school.php` and `tests/foundation.php` |
| Documentation | Project requirements, architecture, decisions, database reference, implementation progress and test plan |

Verified on Windows using PHP 8.5.12 and isolated MySQL 8.4.13 schemas. The vacancy, database, school, teacher, dashboard, CV extraction and HTTP foundation suites passed. The database suite applied all 34 migrations and exercised rollback and reapplication. The vacancy suite also confirmed that a school rename preserves the advertised employer on an existing revision and that text changes such as `01` to `1` are saved.

See the [vacancy test plan](TEST_PLAN.md#teaching-vacancies) for the manual walkthrough.

## Public teacher directory

The Find Teachers page reads explicitly authorised public listings and live availability. It supports anonymous search, filter-preserving pagination, public profiles, private bookmarks and managed requests for a particular teacher. Staff can approve a listing, authorise its optional name or portrait, withdraw it, and read submitted requests. Pending schools can save private drafts.

| Area | Files in this phase |
| --- | --- |
| Directory and approval | `app/Modules/Directory/`, `config/directory-routes.php`, `bootstrap/app.php`, `app/Modules/Site/resources.php` and dashboard links |
| Authentication | Auth session, sign-in, mail, registration and verification forms for the selected teacher reference |
| Data | Migrations 0027–0030 for public listings, filter tags, bookmarks and private teacher requests |
| Presentation and checks | `public/assets/app.css`, `tests/directory.php`, and updated database and foundation suites |

Verified on Windows with PHP 8.5.12, GD and an isolated MySQL 8.4.13 instance. `tests/directory.php` passed all three groups: publication and public field privacy, visibility and filtered pagination, and authentication continuation and private account actions. The database, authentication and HTTP foundation suites also passed. The existing preview database and demo records were left intact.

The [manual test plan](TEST_PLAN.md#public-teacher-directory) walks through publication, search, authentication and private requests. Submitted requests are available to administrators for later processing.

## Administrator verification

The administrator dashboard links to separate teacher, school, document and correction queues. Administrator decisions store the reviewer, decision notes, checks and timestamp. Each decision writes its audit record and recipient notification in the same transaction. Admin downloads use the private attachment service and are audited. A corrected or replaced CV never grants professional verification on its own.

| Area | Files in this phase |
| --- | --- |
| Review module | `app/Modules/Verification/`, `config/verification-routes.php`, `bootstrap/app.php`, and administrator dashboard navigation |
| Teacher and school | Role controllers, repositories, review screens and owner dashboards in `app/Modules/Teacher/` and `app/Modules/School/` |
| Schema | Migrations 0022–0026 for teacher verification state and review records, document reviews, school review checks and notification events |
| Tests | `tests/verification.php`, plus updated database, authentication, teacher and school suites |

All 35 automated test groups passed on PHP 8.5.12 and MySQL 8.4.13, including the fresh-schema migration, staff authorization, stale and invalid decisions, suspension, notification ownership and placement eligibility. All 163 PHP files passed syntax checks. The existing local preview database was upgraded through migration 0026, and HTTP checks confirmed all four demo logins and administrator-only access to the queues.

The [manual test plan](TEST_PLAN.md#administrator-verification) covers all four queues and the teacher and school review paths. The application and final placement policies are available for the future recruitment service. Verification notifications are available in-app. Email delivery for these events is pending.

## Initial PHP foundation

Completed for review · 16 November 2026

The project now boots from `public/index.php`. `GET /` renders the shared layout, and `GET /health` returns uncached liveness JSON. The bootstrap wires the public site controller, view and router through constructor injection, reads environment configuration and records request failures with a correlation ID. PDO MySQL connections are opened on demand.

### Files in this phase

| Area | Files |
| --- | --- |
| Configuration | `composer.json`, `composer.lock`, `.env.example`, `.gitignore`, `config/routes.php` |
| Entry points and web serving | `.htaccess`, `public/.htaccess`, `public/index.php`, `bootstrap/app.php`, `bin/dev-server.php` |
| Core PHP | `app/Core/Config.php`, `ConfigurationException.php`, `Database.php`, `ErrorHandler.php`, `HttpException.php`, `Logger.php`, `Request.php`, `Response.php`, `Router.php`, `View.php` |
| Site and shared layout | `app/Modules/Site/Controllers/SiteController.php`, `app/Modules/Site/Views/home.php`, `resources/views/layout.php`, `public/assets/app.css`, `public/assets/theme.js` |
| Setup, tests and project record | `README.md`, `tests/foundation.php`, `docs/ARCHITECTURE.md`, `docs/DECISIONS.md`, `docs/IMPLEMENTATION_PROGRESS.md`, `docs/TEST_PLAN.md` |

### Verification performed

| Check | Result |
| --- | --- |
| Windows runtime and Composer | PHP 8.5.12 and Composer 2.11.2 were verified using a separate local runtime. XAMPP's bundled PHP was 8.1.25. Composer installation, strict validation and platform requirements passed. The sandbox blocked optional Packagist filter metadata. The lock contains only platform requirements. |
| PHP syntax and frontend behavior | All 18 project PHP files passed `php -l`. A JavaScript harness verified theme selection, persistence, and system color updates. |
| `composer test` | Four groups passed. The suite covered HTTP startup and routes, safe production configuration failures and correlated logs, native verbs and POST overrides, and environment parsing and precedence. |
| Apache and PHP FastCGI | A temporary loopback configuration passed `httpd -t`. HTTP checks covered the home and health routes, 404, 405, CSS, a blocked dotfile, and a forbidden project file on the parent host. |

The [test plan](TEST_PLAN.md) gives exact manual steps, including browser checks. A live MySQL connection, Linux Nginx execution and interactive browser review remain unverified. No business workflow is part of this phase.

## Database foundation

Completed for review · 16 November 2026

The database CLI applies versioned MySQL 8.4 migrations, reverses the latest applied migration and runs transactional seed files. The identity schema covers users, roles, role assignments, password reset and email verification tokens, audit events and non-secret settings. The ledger records checked-in migration checksums and interrupted operations. The [database guide](DATABASE.md) includes the ER diagram, foreign-key rules and local reset procedure.

### Files in this phase

- Added `bin/database.php`, `app/Core/Database/MigrationRunner.php`, and `app/Core/Database/SeedRunner.php`.
- Added `database/migrations/0001_create_users.php` through `0007_create_system_settings.php`, and `database/seeders/0001_roles.php`.
- Added `tests/database.php` and `docs/DATABASE.md`.
- Updated `app/Core/Database.php`, `README.md`, `docs/ARCHITECTURE.md`, `docs/DECISIONS.md`, `docs/TEST_PLAN.md`, and this record.

### Verification

All 30 project PHP files passed linting. Composer strict validation and the four existing foundation test groups passed. The diagram matches the seven migration-defined tables and five foreign keys. The CLI usage check returned exit code 2 for a missing command. On a disposable MySQL Community Server 8.4.13 instance, all seven database integration groups passed. The tests covered fresh migration, repeated migration and seeding, transaction commit and rollback, uniqueness and foreign keys, UTC timestamps, all seven reverse migrations, reapplication, failing seed rollback, dirty apply and rollback markers, and checksum drift. With a dedicated schema-level test account, the CLI also passed status, migration, seeding and rollback checks. A second disposable schema was backed up, dropped, recreated and seeded through the documented procedure. The rebuilt database contained the four reference roles.

## Public website

Completed for review · 16 November 2026

The public site includes the homepage, About us, Our services, Find teachers, Teaching jobs, Digital library, Training and workshops, Contact us, Log in, Create an account, Privacy policy and Terms of use. A shared PHP layout renders the header, navigation, footer, theme picker and common components. Service listings have empty states. The account and contact forms show validation and loading feedback within the browser. Both legal pages are visibly marked as drafts awaiting legal review.

### Files in this phase

- Updated `app/Core/View.php`, `app/Modules/Site/Controllers/SiteController.php`, `config/routes.php`, and `bin/dev-server.php`.
- Updated `app/Modules/Site/Views/home.php` and added `about.php`, `services.php`, `resource.php`, `contact.php`, `access.php` and `legal.php` in the same directory.
- Added `app/Modules/Site/resources.php` and `app/Modules/Site/service-cards.php`.
- Updated `resources/views/layout.php` and added `header.php`, `footer.php`, `service-card.php`, `breadcrumb.php` and `preview-form.php` in the same directory.
- Updated `public/assets/app.css` and `public/assets/theme.js`. Added `public/assets/public.js`, `public/assets/classroom.jpg` and `public/assets/educators.jpg`.
- Extended `tests/foundation.php`. Updated `README.md`, `docs/ARCHITECTURE.md`, `docs/DECISIONS.md`, `docs/TEST_PLAN.md` and this record.

### Verification

- All 43 project PHP files passed linting. Composer strict validation, JavaScript syntax checks and the four HTTP foundation groups passed. The HTTP suite covers all public routes, the draft labels, resource empty states, JPEG delivery and rejection of direct POST requests to the preview forms.
- The in-app Chromium browser checked all 12 pages at 390 × 844, 768 × 1024 and 1440 × 980 CSS pixels. Each page had one main heading and no horizontal overflow.
- Browser interactions verified mobile menu expansion and Escape, focus return to Menu, the skip link, active-page indication, the link to the teacher empty state, and the footer link to the draft privacy page.
- Browser tests verified contact field errors, first-invalid-field focus and checking and ready feedback, as well as the sign-in and registration previews and the three account categories. A controlled-timer check verified that editing a field clears a pending preview.
- Appearance checks covered light, dark, retention across navigation and the System default selection. Desktop and mobile homepage screenshots were captured.

The [test plan](TEST_PLAN.md#public-website) gives reproducible manual checks. The privacy policy and terms await legal review.

### Image assets

The built-in imagegen tool generated these illustrative photographs. Both were encoded as JPEG for the site.

- `public/assets/classroom.jpg`

  > Photorealistic editorial photograph for the hero of a modern Nigerian education recruitment website. Wide 4:3 frame designed to fill a rounded card. In a welcoming, credible contemporary primary school classroom in southern Nigeria, an adult Black Nigerian woman teacher in a warm cream blouse and subtle green patterned skirt leans toward two Black Nigerian pupils aged 9 to 11 working together at a light wood desk, listening and smiling naturally. The lead teacher is in left-centre, pupils in centre-right, all faces comfortably inside centre 80%, natural unposed concentration. Soft real window light, warm cream painted classroom, sage green accents, modest books and classroom equipment, documentary photography with fine texture and natural skin tones, eye-level wide medium composition. Credible educational setting and nuanced expressions. Quiet warm palette, soft drop-off, shot on 35mm. Fill most of the frame with the human interaction and some contextual room. No visible written words, lettering, signage, logos, watermarks, text overlays, borders, or website elements.

- `public/assets/educators.jpg`

  > Photorealistic editorial photograph for secondary pages on a professional Nigerian education website. Landscape 4:3. Three Black Nigerian educators, adult women and men in their late twenties to forties, collaborating around a pale wood desk at a professional development workshop in an airy school library in Nigeria. The primary subject is a thoughtful woman holding an open notebook with another teacher leaning in beside her; colleagues and shelves create context. Candid natural gestures, comfortable modern everyday professional clothes in relaxed forest green, warm clay and cream, soft daylight, modern calm real editorial photography, warm neutral palette and natural skin texture. Keep faces in middle 70% for flexible crops. No visible readable words, logos, borders, illustrations, or overlays.

## Shared authentication

Completed for review · 16 November 2026

Account activation starts with an email link. The mailbox owner chooses a password and a public role, and a new school account starts pending management approval. Login and logout use the shared PHP session and CSRF protection. The dashboard guard checks the role, email verification, suspension and authentication version for each protected request.

### Files in this phase

- Added the `app/Modules/Auth/` module with `AuthController.php`, `AuthService.php`, `AuthInput.php`, `AuthSession.php`, `RateLimiter.php`, `AuthMailer.php`, `Role.php`, `EmailToken.php` and the form, dashboard and problem views.
- Added `config/auth-routes.php` and database migrations `0008_add_user_auth_state.php`, `0009_create_school_onboarding.php` and `0010_create_auth_rate_limits.php`.
- Updated `.env.example`, `bootstrap/app.php`, `config/routes.php`, `public/index.php`, and `app/Core/Config.php`, `Request.php` and `Response.php`.
- Updated `resources/views/header.php`, `public/assets/app.css` and the public legal draft. Removed the replaced account preview in `app/Modules/Site/Views/access.php`. The contact preview still uses the shared public preview component.
- Added `tests/authentication.php` and `tests/support/http.php`. Updated the database and HTTP foundation tests.
- Added `docs/AUTHENTICATION.md`. Updated `README.md` and the architecture, database, decisions, test plan and implementation records.

### Verification

PHP 8.5.12 and an isolated MySQL Community Server 8.4.13 instance ran all six authentication test groups, seven database test groups and four HTTP foundation groups successfully. The authentication suite passed on its own after a parallel loopback request timed out. The current PHP sources passed syntax checks, and Composer strict validation passed. A separate MySQL upgrade test applied three authentication migrations to the original seven-migration schema and preserved the seeded fixture's existing password hash and teacher role.

Tests cover registration uniqueness, mailbox-owned activation, invalid inputs, incorrect passwords, CSRF, denied roles, all four redirects, independent school approval, expiring and single-use links, previously delivered links, global and client-scoped login limits, logout, password-reset revocation, suspension and reinstatement, idle and absolute session expiry, and the production cookie headers.

Production delivery still needs a deployment mail test. Matching different email accounts to one institution remains an open business policy. The [test plan](TEST_PLAN.md#authentication) lists the exact manual checks.

## Teacher and NYSC onboarding

Implemented for review · 16 November 2026

Teacher and NYSC Member registration captures contact details, a password and confirmation, Terms of Use acceptance and Privacy Notice acknowledgement. Email confirmation creates a private profile and opens the teacher dashboard. Onboarding saves each of ten sections independently and shows missing information. It includes owner-only CV and supporting-document upload, download, replacement and removal.

### Files in this phase

| Area | Files |
| --- | --- |
| Teacher module | Added `app/Modules/Teacher/TeacherController.php`, `TeacherInput.php`, `TeacherRepository.php`, `TeacherDocuments.php`, `Section.php` and `ProfileCompletion.php`. Added `Views/dashboard.php`, `registration.php`, `fields.php`, `profile-section.php`, `repeatable.php` and `documents.php` within the module. |
| Shared authentication | Added `app/Modules/Auth/AuthAccess.php`. Updated `AuthController.php`, `AuthMailer.php`, `AuthService.php`, `AuthSession.php` and `Views/form.php` in the same module. |
| Routing and environment | Added `config/teacher-routes.php`. Updated `config/auth-routes.php`, `bootstrap/app.php`, `public/index.php`, `app/Core/Config.php`, `app/Core/Request.php`, `.env.example`, `composer.json` and `composer.lock`. |
| Database | Added `database/migrations/0011_create_teacher_profiles.php`, `0012_create_teacher_qualifications.php`, `0013_create_teacher_experiences.php` and `0014_create_teacher_documents.php`. |
| Frontend and tests | Updated `public/assets/public.js`, `tests/foundation.php`, `tests/authentication.php`, `tests/database.php` and `tests/support/http.php`. Added `tests/teacher.php` and `tests/support/auth-browser.php`. |
| Project documentation | Updated `README.md`, `docs/PROJECT_REQUIREMENTS.md`, `docs/ARCHITECTURE.md`, `docs/AUTHENTICATION.md`, `docs/DATABASE.md`, `docs/DECISIONS.md`, `docs/TEST_PLAN.md` and this record. |

### Verification

- All four teacher onboarding groups passed on Windows with PHP 8.5.12. They covered the required identity and policy inputs, expiring and single-use verification links, same-browser renewal and cross-browser confirmation, role restrictions, partial saves, multiple qualifications and work entries, optional NYSC fields, completion tracking, genuine multipart uploads, PDF and DOCX downloads, access isolation, replace/delete, and non-publication.
- On disposable MySQL Community Server 8.4.13 schemas, all six shared authentication groups and all seven database groups passed. Fresh migration applied all fourteen versions.
- All four HTTP foundation check groups passed. Composer strict validation, platform checks, PHP syntax checks, JavaScript syntax and a repeatable-row focus and limits check passed.

The [manual test steps](TEST_PLAN.md#teacher-onboarding) cover browser appearance and the full workflow. Interactive browser testing and production mail delivery were outside this verification. The Terms of Use and Privacy Notice await legal review. CV extraction remains a separate phase.

## Assisted CV extraction

Implemented for review · 16 November 2026

New CV uploads queue a private extraction job. A PHP CLI worker parses supported text-based PDFs and DOCX files and saves a schema-validated draft. Teachers review editable suggestions, correct or skip entries, and confirm before profile data is added. Existing records are preserved. Scanned and unreadable CVs lead back to manual editing. A draft expires after seven days, and confirmation clears its JSON.

### Files in this phase

| Area | Files |
| --- | --- |
| Extraction | Added `app/Modules/Teacher/Cv/CvExtractor.php`, `RuleBasedExtractor.php`, `IsolatedCvExtractor.php`, `CvContent.php`, `CvParser.php`, `CvDraft.php`, `CvJobs.php` and `ExtractionFailure.php`. |
| Worker and database | Added `bin/cv-worker.php`, `bin/cv-parse.php` and `database/migrations/0015_create_teacher_cv_extractions.php`. Updated `composer.json` and `composer.lock`. |
| Teacher workflow | Updated `TeacherController.php`, `TeacherDocuments.php`, `TeacherInput.php` and `TeacherRepository.php` under `app/Modules/Teacher/`. Added `Views/cv-review.php` and `Views/cv-status.php`, and updated `Views/documents.php` and `Views/repeatable.php`. Updated `bootstrap/app.php`, `config/teacher-routes.php` and `public/assets/public.js`. |
| Tests | Added `tests/cv-extraction.php` and `tests/support/cv-fixtures.php`. Extended `tests/teacher.php` and `tests/database.php`. |
| Documentation | Added `docs/CV_EXTRACTION.md`. Updated `README.md`, `docs/PROJECT_REQUIREMENTS.md`, `docs/ARCHITECTURE.md`, `docs/DATABASE.md`, `docs/DECISIONS.md`, `docs/TEST_PLAN.md` and this record. |

### Verification

Twenty-three test groups passed on PHP 8.5.12 and disposable MySQL 8.4.13 schemas. The synthetic fixtures cover selectable-text PDFs, DOCX, image-only PDF, missing fields, unusual section order and multiple employers. HTTP tests cover private uploads, queue processing, manual fallback, owner and role boundaries, edited confirmation, preservation of saved values, duplicate submission, replacement and expiry. All fifteen migrations apply from an empty schema. Composer strict validation and platform checks, PHP syntax, and JavaScript syntax passed.

The [manual test plan](TEST_PLAN.md#cv-extraction) records the complete correction flow. Interactive browser review and production worker scheduling remain unverified.

## Teacher / NYSC dashboard

Implemented for review · 16 November 2026

Verified teachers sign in to Overview. The workspace provides all twelve requested pages with a responsive sidebar, account menu and theme-aware layout. The overview reads profile completion, professional verification and CV presence from the signed-in account. The profile, document review, availability and settings pages use their existing data and actions. Pending recruitment and service features show informational pages and unavailable metrics.

Availability allows any compatible combination of teaching and tutoring preferences. Temporarily unavailable is exclusive. The existing date and schedule data remain readable. Profile updates are owner-scoped and professional verification and publication timestamps cannot be changed by the teacher form.

### Files in this phase

| Area | Files |
| --- | --- |
| Dashboard and data | Added `app/Modules/Teacher/DashboardPage.php` and `database/migrations/0016_add_teacher_professional_verification.php`. Updated `TeacherController.php`, `TeacherInput.php` and `TeacherRepository.php` within the Teacher module. |
| Templates and frontend | Added `Views/workspace.php`, `overview.php`, `availability.php`, `settings.php` and `pending.php` in the Teacher module. Updated `Views/dashboard.php`, `profile-section.php` and `cv-review.php`, `resources/views/layout.php` and `public/assets/public.js`. Added `public/assets/teacher-dashboard.css` and `teacher-dashboard.js`. |
| Tests | Added `tests/dashboard.php`. Updated `tests/teacher.php` and `tests/database.php`. |
| Documentation | Updated `README.md`, `docs/PROJECT_REQUIREMENTS.md`, `docs/ARCHITECTURE.md`, `docs/AUTHENTICATION.md`, `docs/DATABASE.md`, `docs/CV_EXTRACTION.md`, `docs/DECISIONS.md`, `docs/TEST_PLAN.md` and this record. |

### Verification

All 25 automated test groups passed on PHP 8.5.12 and disposable MySQL 8.4.13. The tests cover all dashboard pages, saved status indicators, cross-account reads and writes, forged profile fields, CSRF and role restrictions, legacy and multiple availability preferences, and CV extraction and account regressions. All sixteen migrations applied to an empty schema. PHP, Composer, and JavaScript checks passed.

The in-app browser showed no horizontal overflow at 390, 768 and 1440 CSS pixels on the availability, profile and overview pages. Mobile and account menus closed on Escape and returned keyboard focus to their controls. Settings applied light, dark and system appearance.

The [test plan](TEST_PLAN.md#teacher-dashboard) includes the manual verification steps.

## School registration and review

Implemented for review · 16 November 2026

The school registration form collects the institutional and representative details and opens a restricted account after email verification. A unique normalized name, state and city identifies each complete school record. Legacy schools with no location reserve their existing name until the profile is completed.

Verified schools can edit their own profile and drafts, browse public resources, view settings and read school review notifications. Approval-gated routes deny publication, active recruitment, restricted teacher information and approved shortlists while the profile is unapproved. These recruitment workflows remain outside this phase.

Administrators can approve, reject, request corrections or suspend a school. Reviews use the current profile revision and retain the reviewer, reason and timestamp. Suspension updates shared authentication and revokes old sessions.

### Files in this phase

| Area | Files |
| --- | --- |
| School module | Added `app/Modules/School/SchoolRegistration.php`, `SchoolController.php`, `SchoolRepository.php`, `SchoolInput.php`, `SchoolPolicy.php`, `SchoolStatus.php` and `InstitutionAlreadyRegistered.php`. Added `Views/registration.php`, `dashboard.php`, `profile-fields.php`, `legal-fields.php`, `drafts.php`, `review-list.php` and `review.php`. |
| Shared application | Added `app/Modules/Auth/LegalNotice.php` and `resources/views/form-fields.php`. Updated `app/Modules/Auth/AuthController.php`, `AuthService.php`, `AuthInput.php`, `AuthSession.php`, `AuthMailer.php`, `Role.php`, `Views/form.php` and `Views/dashboard.php`, plus `app/Modules/Teacher/TeacherController.php`, `TeacherInput.php` and `Views/fields.php`. |
| Routing and storage | Added `config/school-routes.php` and migrations `0017_expand_school_onboarding.php`, `0018_create_school_reviews.php` and `0019_create_school_recruitment_drafts.php`. Updated `config/auth-routes.php` and `bootstrap/app.php`. |
| Tests | Added `tests/school.php` and `tests/support/school-fixtures.php`. Updated `tests/foundation.php`, `tests/authentication.php` and `tests/database.php`. |
| Documentation | Added `docs/SCHOOL_ONBOARDING.md`. Updated `README.md`, `docs/PROJECT_REQUIREMENTS.md`, `docs/ARCHITECTURE.md`, `docs/AUTHENTICATION.md`, `docs/DATABASE.md`, `docs/DECISIONS.md`, `docs/TEST_PLAN.md` and this record. |

### Verification

All 30 automated test groups passed on PHP 8.5.12 and disposable MySQL 8.4.13. The school suite covers form and legal validation, email/session activation, matching modern and legacy institutions, owner-only drafts, forged fields, direct approval gates, decisions and history, suspension and restoration, and verification throttling. All 19 migrations applied from an empty schema. All 124 PHP files passed linting. Composer strict and platform checks and the Git whitespace check passed.

The [test plan](TEST_PLAN.md#school-registration-and-review) gives the manual verification steps. Verification email used the local mail spool. Production email delivery and interactive browser review remain unverified for this phase.

## Parent registration and account management

Implemented for review · 16 November 2026

Parents and guardians register with their own contact details and legal acknowledgements. Email confirmation creates the private profile and opens the dashboard. The workspace provides all eleven requested pages, including editable account settings and links to public teacher and library pages.

Parents can save and edit private tutoring drafts and submit a reviewed draft while their email is verified. Requests are scoped to the account owner. Submitted details stay on file for a later processing phase. Recommendations, saved teachers, arrangements, messaging and notifications show informational states.

### Files in this phase

| Area | Files |
| --- | --- |
| Parent module | Added `app/Modules/Parent/ParentRegistration.php`, `ParentController.php`, `ParentInput.php`, `ParentRepository.php` and the five parent views under `app/Modules/Parent/Views/`. |
| Shared signup | Added `app/Modules/Auth/ProfileRegistration.php`, `RegistrationConflict.php`, `app/Support/NigerianStates.php` and `resources/views/legal-acceptance.php`. Updated the authentication controller, service, role, account and registration views, and privacy revision. Updated the school signup adapter, duplicate registration exception, state options and legal fields view. |
| Routing, schema and frontend | Added `config/parent-routes.php` and migrations `0020_create_parent_profiles.php` and `0021_create_parent_tutoring_requests.php`. Updated `bootstrap/app.php`, `app/Modules/Site/Views/legal.php` and `public/assets/app.css`. |
| Tests | Added `tests/parent.php` and `tests/support/parent-fixtures.php`. Updated `tests/support/auth-browser.php` and the foundation, authentication, school, teacher and database suites. |
| Documentation | Added `docs/PARENT_ACCOUNTS.md`. Updated `README.md`, `docs/PROJECT_REQUIREMENTS.md`, `docs/ARCHITECTURE.md`, `docs/AUTHENTICATION.md`, `docs/DATABASE.md`, `docs/DECISIONS.md`, `docs/TEST_PLAN.md` and this record. |

### Verification

All 34 automated test groups passed on PHP 8.5.12 and disposable MySQL 8.4.13 schemas. The parent tests cover adult-only data, validation and consent, verification and resend, cross-browser activation, all dashboard tabs, public links, account and request ownership, stale draft rejection, replay, legacy accounts, and removal of email verification. All 21 migrations applied from an empty schema. PHP lint passed for all 142 files. Composer and Git whitespace checks passed.

The [test plan](TEST_PLAN.md#parent-registration-and-account-management) gives reproducible manual steps. Verification used the private local mail spool. Interactive browser review and production email delivery remain unverified.

## Candidate requests and controlled disclosure

- Approved schools can open a case from an owned vacancy or carry a directory selection into that case. Submitted parent tutoring requests create their linked case automatically. Existing eligible records are backfilled and old school enquiries can be attached to an owned vacancy.
- Recruitment staff claim and process cases, prepare shortlists, and release candidate references to the requesting account. Restricted profile snapshots and original CVs each require a separate management decision. An issued grant records the recipient, scope, exact profile revision or CV, policy and evidence references, and expiry. Downloads and decisions are auditable.
- Teachers can pause and resume disclosure and view their grant history. A pause revokes outstanding grants. Registered users can submit privacy rights requests for staff resolution.
- Real disclosure is disabled until Furoeducare publishes and configures a reviewed policy and notice. See [operational policy](DISCLOSURE_POLICY.md) for activation and evidence requirements.

| Area | Files |
| --- | --- |
| Candidate and disclosure workflows | `app/Modules/Disclosure/`, `config/candidate-routes.php`, `config/privacy-routes.php` and `bootstrap/app.php` |
| Existing workflows | Directory, school, parent, teacher and administrator navigation, plus tutoring case mirroring and private navigation from the site's legal notice |
| Policy and storage | `.env.example`, `app/Core/Config.php`, migrations 0044–0052 and [operational policy](DISCLOSURE_POLICY.md) |
| Tests | `tests/disclosure.php`, `tests/database.php`, `tests/directory.php` and the [manual walkthrough](TEST_PLAN.md#candidate-request-and-disclosure-checks) |

The disclosure, migration, foundation, directory, tutoring HTTP and tutoring transition suites passed on PHP 8.5.12. The database-backed tests used isolated MySQL 8.4.13 schemas. The disclosure suite covers forged identifiers, unauthorised direct URLs, expired or revoked grants, stale policy forms, duplicate grants, replaced CVs and privacy requests. Lint passed for 247 PHP files.
