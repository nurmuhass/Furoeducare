# Furoeducare Implementation Progress

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
