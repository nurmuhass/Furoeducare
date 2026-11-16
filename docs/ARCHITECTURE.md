# Furoeducare Architecture

Draft for review · 16 November 2026

The [project requirements](PROJECT_REQUIREMENTS.md) define the business rules. This document proposes the initial technical design. Runtime versions, application structure and workflow design choices below are proposals for review.

## Stack and deployment baseline

| Layer | Proposed baseline |
| --- | --- |
| Application | Core PHP 8.5 with the latest stable patch in that branch. Use typed PHP, Composer autoloading and the PHP CLI for operational commands. [PHP supported versions](https://www.php.net/supported-versions.php) |
| Database | MySQL 8.4 LTS with a stable supported patch and the PDO MySQL driver. Use InnoDB, `utf8mb4`, foreign keys, and bound parameters for SQL values. [MySQL release tracks and distributions](https://dev.mysql.com/doc/refman/8.4/en/which-version.html) |
| Dependencies | A current stable Composer 2 release. Commit the dependency lockfile when dependencies are introduced and keep the package set small. [Composer releases and maintenance policy](https://getcomposer.org/download/) |
| Web interface | Server-rendered HTML5, CSS3, Bootstrap 5.3 and vanilla JavaScript. Share navigation, forms, errors and theme controls through PHP layouts and partials. [Bootstrap 5.3 color modes](https://getbootstrap.com/docs/5.3/customize/color-modes/) |
| Environments | Windows with local PHP and MySQL for development. Linux with a supported web server, PHP runtime and HTTPS for production. Use the same PHP and MySQL branches in development and production. Confirm the actual binaries and extensions at setup. |

Laravel, React, Next.js, Vue and a Node.js application backend are outside the technology stack.

## Request handling and project layout

Use a modular monolith with one deployable PHP application. In-process service calls keep related recruitment and approval changes within one MySQL transaction. CLI workers handle email and CV extraction when those features are built, which keeps slow work off the HTTP request path without introducing another application backend.

```mermaid
flowchart LR
    Browser --> Web["HTTPS web server"]
    Web --> Router["PHP front controller and router"]
    Router --> Boundary["Session, authentication and CSRF checks"]
    Boundary --> Controller["Module controller"]
    Controller --> Validation["Input validator"]
    Controller --> Service["Application service"]
    Service --> Policy["Action and resource policy"]
    Service --> Repository["PDO repository"]
    Repository --> Database[("MySQL")]
    Service --> Files[("Private file storage")]
    Controller --> View["Escaped PHP view or controlled file response"]
    Worker["PHP CLI worker"] --> Database
    Worker --> Files
    Worker --> Email["Email transport"]
```

| Area | Responsibility |
| --- | --- |
| `public/` | HTTP entry point and public static assets. The web server exposes this directory as the document root. |
| `app/Modules/` | Feature controllers, validators, services, domain models, policies, repositories and feature views. Introduce directories as the features need them. |
| `app/Core/` | Shared request and response handling, routing, configuration, sessions, rendering and database connection support. |
| `config/` | Non-secret configuration and route wiring. Read secrets and environment-specific settings from environment variables. |
| `resources/views/` | Shared layouts and view components used across modules. |
| `database/` | Ordered migrations and seeders. |
| `bin/` and `tests/` | PHP CLI operations and automated verification. |
| Private storage path | Configurable path outside every web root and alias. Contains CVs, identity files and access-controlled library files. |

Controllers translate requests to validated inputs and select responses. Validators check shape and required fields. Application services coordinate use cases and transactions. Models represent domain state and rules. Policies determine whether an actor can perform an action on a specific resource. Repositories own SQL and data mapping. Views render prepared data using contextual escaping.

## Module boundaries and data ownership

The entities below are conceptual. The schema, endpoints and user interface will be designed in their implementation phases.

| Module | Owns |
| --- | --- |
| Accounts and access | Credentials, public account category, internal staff permissions and account lifecycle. |
| Schools | Institution identity, its single account association, and school approval history. |
| Teacher profiles and directory | Editable professional profile, professional verification, directory approval and an explicit public projection of approved fields. |
| Documents | Private file metadata, CV extraction jobs and drafts, and original CV access requests and grants. |
| Recruitment | Teacher and information requests, school vacancies, applications, shortlists and final placement. |
| Tutoring | Parent tutoring requests and their management workflow. |
| Messaging and notifications | Customer-to-staff conversations and event-driven email delivery. |
| Library | Book metadata, publication state and download access rules. |
| Training and workshops | School requests and management handling. |

Use stable internal identifiers and explicit foreign keys for ownership. For BR-09, resolve an institution to a canonical identity and enforce a unique account association in the database. Base uniqueness on the institution identity so registrations with different email addresses still use the same constraint. The business still needs to agree how schools are matched and how branches or ambiguous duplicates are handled.

Persist approval states with the actor, timestamp and relevant resource. Use service-level actions for state transitions and record consequential staff decisions in an audit trail. Apply indexes to owner and status lookups and paginate the directory and work queues. Store timestamps in UTC and format user-facing times as appropriate for Nigeria.

## Workflow and data boundaries

### Directory and recruitment

- Build the public directory from an explicit projection of approved professional fields for approved, publicly listed teachers. Use that same projection for search results and direct profile views. Keep private contact fields, original CV data, private file identifiers and unrestricted extraction text outside public responses.
- Professional verification, directory approval and school approval have independent persisted state. Enforce those states through policies and service actions on every relevant endpoint, including direct requests.
- Route customer teacher and information requests to management. Route tutoring requests to their submitting parent and authorised staff. Enforce school approval for vacancy posting on the server.
- Allow otherwise eligible applications before professional verification. When finalising placement, verify the teacher's current professional verification status within the same transaction as the placement write. Coordinate concurrent verification and placement updates on the same authoritative record. Shortlisting and placement actions require management permissions.

### CV extraction and access

1. Validate the upload against the configured type and size policy. Use server-side file checks, a generated storage identifier and private storage. Record the uploaded version and enqueue extraction from that version.
2. Process the document with a bounded PHP CLI job. Save structured suggestions as a draft. A failed or repeated job can update its own processing state and leaves the teacher's saved profile intact. The teacher reviews, edits and explicitly saves professional fields.
3. For disclosure to a requester, management reviews the access request and records its decision. A proposed access grant binds the requesting account to a specific CV version. The download policy checks the current grant and relevant privacy conditions at the time of delivery. Teachers receive no request to approve access.
4. Stream authorised files through a controlled download response. Use attachment delivery, private cache controls and opaque storage identifiers. Record access events with minimal metadata. Identity files have their own staff access policy.

Process file storage and database writes with explicit cleanup or recovery for failures between them. Treat uploaded documents and extracted text as untrusted input. Keep raw document contents out of logs and notification messages.

### Messaging, email and library

- Scope internal conversations to the originating customer account and authorised staff. The server checks membership and staff permissions when users read or add messages.
- For configured email events, persist a delivery record in the same transaction as the workflow update. A CLI worker retries transient delivery failures and tracks outcomes. Use a stable event or delivery identifier to prevent duplicate jobs and limit duplicate sends where the transport supports deduplication.
- Deliver published free books to visitors. Apply access policy at download time for managed library items. Knowing a library item identifier confers no download access.
- Payments have no active routes, checkout or processing in the initial release.

## Shared security and interface rules

- Use PHP password hashing and verification, regenerate session identifiers after login and privilege changes, and invalidate sessions on logout. Configure production cookies with `Secure`, `HttpOnly` and an appropriate `SameSite` policy.
- Require CSRF tokens for state-changing browser requests, including forms and signed-in uploads. Enforce authorisation at the server for each action and record, with permissions denied by default. Scope queries to the actor where appropriate.
- Validate input server-side. Bind SQL values using native PDO prepared statements and allowlist any dynamic SQL identifiers, such as sort fields. Escape HTML, attributes and other output for their specific contexts. Keep production error details and sensitive data out of client responses.
- Keep secrets in environment variables. Use separate runtime and migration database privileges and restrict filesystem access for the web and worker processes. Configure backups for MySQL and private storage and verify recovery of both.
- Use a shared layout with semantic landmarks, labelled forms, accessible feedback and visible keyboard focus. Use Bootstrap color variables and `data-bs-theme` for light and dark, with system default following `prefers-color-scheme`. Persist the selected mode in the browser and test public, member and staff layouts in all three modes. [Bootstrap color-mode implementation](https://getbootstrap.com/docs/5.3/customize/color-modes/)

## Database changes and verification

- Run versioned migrations from the CLI and record applied versions. Use seeders for reference data and synthetic development or test records. Keep customer data and production credentials out of seed files.
- Use transactions for multi-table business changes, such as placing a teacher or approving a CV grant with its audit record. Combine constraints and explicit concurrency handling with service validation. Account for MySQL DDL commit behaviour when planning schema changes.
- Unit-test validators, visibility rules, policies and state transitions. Run integration tests against MySQL for migrations, constraints, transactions and concurrent changes. Test the HTTP entry points as each module lands to cover anonymous access, direct requests to gated actions, cross-account access, CSRF and protected file delivery.
- Use the acceptance conditions in the requirements to build the later test plan. Include extraction failure and retries, email test delivery, and manual checks for keyboard access, responsive layout and the three themes.

## Deployment and decisions for review

On Windows, point a local site at `public/` and verify that the project root cannot also be served through a parent document root or alias. This matters when a project lives under an existing XAMPP `htdocs` directory. Confirm the database server reports MySQL before validating migrations.

On Linux, serve `public/` over HTTPS and redirect HTTP to HTTPS. Deploy application and dependencies together. Keep writable storage and logs outside the document root. Run migrations and any enabled worker commands from the CLI. Verify document-root isolation, private downloads and worker health in the deployment environment.

The initial design leaves the precise school identity rules, public field allowlist, professional verification criteria and CV access rules to management and privacy review. Parser and mail transport choices belong to their feature phases, once file inputs and delivery requirements are defined.

The initial foundation's setup and current capabilities are documented in the [README](../README.md). See [decisions](DECISIONS.md), [implementation progress](IMPLEMENTATION_PROGRESS.md) and the [test plan](TEST_PLAN.md) for this phase.

The [database foundation](DATABASE.md) implements account, role, token, audit and settings storage. The migration ledger and CLI are separate from HTTP bootstrap. PHP sessions use file-backed storage for the initial deployment.

The public website routes are GET pages rendered by the Site module. Shared partials supply navigation, cards, forms and the footer. Vanilla JavaScript handles mobile navigation, appearance preferences and browser-only form validation and feedback. Public resource pages use empty states until the relevant services publish live items. The HTTP health route remains a liveness check.

The [authentication module](AUTHENTICATION.md) uses the shared public layout, PDO and file-backed PHP sessions. Its routes handle account activation, password resets and role-specific dashboard access. The Site module continues to render the contact preview and informational pages.

The Teacher module uses the shared session guard and verification tokens. It creates a private, owner-scoped profile after email activation. Section updates preserve other saved sections. Qualifications and experience use related rows, replaced transactionally within each section. Completion derives from saved values and the presence of a CV. The public Site controller does not read onboarding tables.

The teacher document service validates PDF and DOCX for CVs and also accepts JPEG and PNG for supporting professional files. Storage is private and configurable through `TEACHER_DOCUMENT_DIR`. The database stores opaque keys with the file metadata. Owner-only downloads are attachments. A new CV replaces the active record in one transaction. Removal immediately revokes access to the old record. If deleting the private file fails, the record stays marked for deletion and the next document upload or removal for the account retries it. Document extraction and management-controlled publication and CV access are separate phases. [OWASP file upload guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html)
