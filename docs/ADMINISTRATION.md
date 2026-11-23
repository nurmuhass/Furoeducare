# Administration workspace

## Design and implementation plan

Build one responsive administration shell around the existing verification, recruitment, library, training and messaging modules. Add overview/search, account directories, CV review queue, safe email delivery monitoring, scoped aggregate reports, staff roles, audit browsing and allowlisted settings. Existing workflows and their placement/disclosure safeguards remain authoritative.

The account role `admin` identifies staff accounts; it no longer grants every management permission. Separate staff roles grant explicit read/manage capabilities. Built-in roles are Super Admin, Recruitment Officer, Verification Officer, Library Manager and Training Coordinator. New staff receive no management access until assigned. Migration assigns the oldest active, verified pre-existing administrator as the initial Super Admin only. A trusted local CLI bootstrap supports a fresh installation. Role edits and account suspension must preserve an active Super Admin. Staff cannot change their own assignment or grant permissions they do not hold.

All registered management HTTP routes pass a fail-closed permission gate before their controllers. Read and write capabilities differ, with separate permissions for private document viewing, CV access decisions, account suspension, role management, audit and settings. Sensitive mutations also check current permissions inside transactions. Denied management requests and successful decisions are audited. Existing controller role checks remain an additional boundary.

The shell supplies permission-filtered navigation, global search, notification bell, responsive menu, account controls, theme support and visible workflow links. Existing module pages render inside it. Sensitive forms receive confirmation prompts; new role/account/settings mutations require explicit server-side confirmation, CSRF, a reason and current revisions. No bulk account, role, CV or placement actions are introduced.

Email monitoring exposes operational status, template, attempts and bounded safe failure/provider codes, never token secrets, payloads or private MIME content. Reports aggregate only permitted modules. Audit browsing excludes raw context payloads and shows attributed actions and time. Settings are restricted to an operational admin notice and support contact actually used in the shell; secrets remain environment-owned and payments stay disabled.

1. Add staff role/permission/assignment migrations 0077–0082, deterministic built-ins, one-time existing-admin bootstrap and trusted CLI bootstrap.
2. Implement permission policy and route matrix with deny-by-default tests. Guard all management paths and protect last-Super-Admin suspension across review flows.
3. Build safe scoped read models and metrics, directories/search, email monitoring, reports, CV queue and audit activity.
4. Build transactional staff/account/settings administration with confirmation, stale-write, delegation and last-admin checks. Integrate the shared admin shell and all twenty module destinations.
5. Verify high-risk operations and role restrictions, migration upgrade/rollback, existing workflows and responsive rendering. Record files, results and manual checks; stop for review.

## Setup and initial access

Use the existing PHP 8.5/MySQL 8.4 environment. Back up the database, stop application writes, run `php bin/database.php migrate` to apply migrations 0077–0082, then restart. No runtime dependency is added. Apply these migrations before opening authenticated management pages.

The migration appoints **only the oldest active, verified pre-existing account with the admin account role** as Super Admin. Other existing administrators need explicit staff-role assignment. Accounts created afterward receive no management permissions merely from the admin account marker.

For a fresh installation with no active Super Admin, register and verify the intended person's account through the normal authentication flow, then run this trusted local command from the project root:

```powershell
php bin/staff.php bootstrap verified-account@example.com
```

The command requires an existing active, verified account and refuses once an active Super Admin exists. It adds the staff role, invalidates previous sessions and audits the bootstrap. Sign in again and open `/dashboard/admin`. Further appointments happen in **Staff and roles**: search an existing verified account by email, select roles, supply a reason and explicitly confirm. No shared staff passwords or public administrator registration are introduced.

## Default permission groups

| Role | Default scope |
| --- | --- |
| Super Admin | All explicit management capabilities, including staff, audit, settings and controlled CV decisions |
| Recruitment Officer | Account directories, vacancy/application moderation, tutoring, candidates, CV request queue, interviews/placements, messages and scoped reports |
| Verification Officer | Teacher/school directories, verification decisions, private verification documents and public listing approval |
| Library Manager | Library management and library-scoped overview/reports |
| Training Coordinator | School directory, training programmes/requests, management messages and scoped reports |

Built-in role definitions are protected. Create a custom role to choose another permission set. Custom roles can be edited with a revision check; affected sessions are invalidated. A staff manager can delegate only permissions they currently hold. They cannot edit their own assignment or a role currently assigned to them. Assignments can combine roles, and the server rechecks their current union.

Viewing CV requests is separate from deciding/revoking information access. Ordinary recruitment staff can manage candidates while CV decision controls remain unavailable. Viewing verification queues is separate from downloading private documents. Read-only custom roles see a notice and no mutation forms; server-side permission checks remain authoritative when requests bypass the interface.

Sensitive account/role/settings writes require CSRF, explicit confirmation, a reason and current version/revision. Suspension and staff-role changes invalidate sessions. Restoring an account cannot clear an outstanding teacher/school verification suspension or mark it verified. Staff-account suspension additionally requires staff management permission. A role-row mutex serialises protected security decisions, preserving at least one active, verified Super Admin through assignment and verification suspension paths.

## Module destinations

| Module | Workspace |
| --- | --- |
| Overview and metrics | `/dashboard/admin` |
| Teacher/school/parent management | `/dashboard/admin/accounts?type=teacher`, `school` or `parent` |
| Verification | `/dashboard/admin/verification` and existing school review pages |
| Public listing approval | `/dashboard/admin/listings`, then existing directory editor |
| Vacancies and applications | `/dashboard/admin/vacancies`, `/dashboard/admin/vacancies/applications` |
| Tutoring | `/dashboard/admin/tutoring-requests` |
| Candidate shortlisting | `/dashboard/admin/candidate-requests` |
| CV access queue | `/dashboard/admin/cv-requests` and authorised case review |
| Interviews and placements | `/dashboard/admin/placements` |
| Library | `/dashboard/admin/library` |
| Training | `/dashboard/admin/training` and `/dashboard/admin/training/requests` |
| Messaging | `/dashboard/admin/messages` |
| Email delivery monitoring | `/dashboard/admin/email` |
| Reports and analytics | `/dashboard/admin/reports` |
| Staff/roles | `/dashboard/admin/staff`, `/dashboard/admin/roles` |
| Audit logs | `/dashboard/admin/audit` |
| System settings | `/dashboard/admin/settings` |

Global search covers twelve permission-filtered sources: teachers, schools, parents, CV requests, library resources, training modules, vacancies, applications, tutoring requests, candidate requests, placements and retained message subjects. Private recruitment drafts and expired message records are excluded. It uses escaped substring queries and a composite cursor to avoid skipping equal IDs from different sources. All search projections use one UTF-8 collation, including ASCII references. Account detail is typed and verifies membership before showing the selected profile. Search, status filters and cursor lists retain their query state.

Reports show real status aggregates for accessible domains over inclusive WAT dates, converted to UTC for querying. Overview metrics and navigation use the same permissions. Email monitoring is read-only and shows status, attempts, template, account reference and normalised operational codes; it excludes recipient addresses, payloads, secrets, token material and MIME files. It does not retry Uncertain mail or risk duplicate delivery.

Audit views show actor, action and time with filters and pagination. Raw audit context is retained privately for authorised operational investigation, but is not dumped into the interface. Existing business actions retain their attributed audit/history entries; staff/account/settings decisions add their own reasoned events and management permission denials are logged.

Operational settings currently edit the admin notice and support contact, which are used in the shell. Payment activation and environment credentials are not editable. Unsupported keys are rejected. No bulk account, role, verification, CV disclosure or placement operation is supplied; decisions stay individually reviewed.

Management notifications are permission-aware. Staff intake and message triage alert relevant role holders. When an existing message assignee loses the permission, new alerts fall back to qualified staff; the historical assignment remains visible until a manager deliberately changes it.

## Tests and recorded results

On 23 November 2026, PHP 8.5.12 and isolated MySQL 8.4.13 verification passed:

- `tests/admin-policy.php`: explicit route/read/write/private-document/CV decisions and navigation filtering.
- `tests/admin-readmodel-input.php`: scoped metrics, safe filters/search and WAT report date validation.
- `tests/admin.php`: all five role sets, twenty module destinations, coarse-admin denial, direct URLs, unsupported method overrides, private file HEAD, scoped search, confirmation/CSRF/version checks, self/last-Super protections, delegation, role revocation, safe settings, redacted email/audit and one-time CLI bootstrap.
- `tests/admin-readmodel-search.php`: twelve search sources, mixed-collation unions, scoped routes, draft/retention visibility and cursor queries against the migrated test schema.
- `tests/admin-messaging.php`: permission loss and coarse-role removal trigger qualified message notification fallback.
- `tests/admin-concurrency.php`: two connections contend for the role mutex, then reject removal of the remaining active Super Admin.
- `tests/database.php`: all 82 migrations, 34→82 upgrade, oldest eligible legacy administrator bootstrap, rollback/reapplication, seed transactions, durable failure markers and checksum drift.

The new main suite requires an **empty disposable** loopback schema named `furoeducare_test_admin_<suffix>` and development `DB_*` environment. Supplemental search/messaging/concurrency tests use the same migrated isolated schema without rebuilding it. A guarded explicit reuse mode in the test runner resets synthetic fixtures only; never enable it outside the exact isolated schema named by the test environment. Existing integration fixtures now explicitly appoint their synthetic Super Admins through `tests/support/staff-fixtures.php`; application account creation has no such default.

Headless Chrome rendered the actual shared components with clearly labelled synthetic preview data at 360, 768 and 1440 pixels. Measured viewport/client/scroll widths matched at every size. The mobile menu opening/closing/focus behavior and tablet dark mode were checked. Private screenshots are under `storage/local-preview/admin-browser-preview/`. Interactive production workflows and keyboard checks remain part of the checklist below. No synthetic preview data enters production templates or the application's real database.

## Exact manual review

1. Apply migrations, identify the migrated initial Super Admin or perform trusted bootstrap when appropriate, sign in again and open `/dashboard/admin`. Confirm the notice, notification bell, metrics, search and navigation. Check another coarse administrator with no staff assignment receives 403 for management pages.
2. Appoint one synthetic verified account to each default role. Sign in separately and compare available navigation and counts. Open hidden module URLs directly and attempt forged POST/HEAD requests. Expect denial; confirm `admin.access_denied` audit entries.
3. Search teachers, schools and parents using names/email and status filters. Open a typed profile and try changing its URL type to another category. Check HTML appears escaped. Page through results, listings, CV requests, email deliveries and audit activity without repeated boundary rows.
4. As Recruitment Officer, moderate a synthetic vacancy/application, manage tutoring/candidates and inspect placements. Try deciding or revoking CV access and downloading private verification files. Expect 403. As Verification Officer, review evidence and manage a public listing, while recruitment administration remains denied.
5. As Library Manager and Training Coordinator, use their existing modules inside the shared shell. Verify read-only custom roles have no working mutation controls. Send a message linked to a request. Demote an assigned staff account and verify new owner replies alert qualified triage staff without exposing the thread to the demoted account.
6. As Super Admin, create a custom read-only role and assign it to another verified account. Repeat an old assignment/role form and expect a conflict. Verify the target must sign in again and current permissions apply. Try self changes, granting a permission the actor lacks, altering built-in roles, and removing/suspending the last Super Admin; expect rejection.
7. Suspend a synthetic nonstaff account with confirmation and reason; check immediate session invalidation and audit history. Try restoration while its verification suspension remains active, then resolve through the proper verification workflow. Change the admin notice/support contact, check escaped rendering, and submit unsupported payment/secret settings; expect rejection.
8. Inspect safe delivery states and reports by date/status. Verify private payload/token/provider free text is absent. At 360, 768 and 1440 pixels, navigate the menu and top Close control, keyboard focus/Escape, tables, filters, confirmation cancellation and light/dark/system modes. Use the real deployment's accounts and SMTP settings only after their normal setup checks.
