# Furoeducare Implementation Progress

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
