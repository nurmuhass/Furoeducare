# Operational reports

## Design and implementation plan

Extend `/dashboard/admin/reports` with real permission-scoped operational measures and aggregate CSV export. Keep the initial launch free. Future service catalogue, prices, orders, payments, billing and invoices are a documented design only, with no applied billing schema, gateway, activation flag or payment check added to current workflows.

The existing raw status summaries are not sufficient for downloads, training requests, retained message activity or successful placements after closure. The operational page will use explicit measures, date bases and definitions instead. Count registrations/submissions/events within inclusive WAT date ranges, and clearly distinguish current-state teacher/vacancy measures from historical events. Provide an explicit all-time view. Do not imply that current status is a historical point-in-time snapshot.

- [x] Implement a validated report period and transactionally permission-checked aggregate query service.
- [x] Build the shared report page and aggregate-only CSV export with matched filters, safe spreadsheet cells, private response headers and export audit events.
- [x] Document future monetisation contracts, data relationships, states and a formally approved rollout that preserves existing free services.
- [x] Verify real SQL counts, WAT boundaries, permission subsets, exports/audits and free-launch restrictions; update required project documents and manual review steps.

## Files created or modified

| Area | Files |
| --- | --- |
| Report query, dates and CSV | New `app/Modules/Admin/OperationalReports.php`, `ReportPeriod.php`, `ReportCsv.php` |
| Controller, routes and access | `AdminController.php`, `StaffRoutes.php`, `config/admin-routes.php`, `bootstrap/app.php`; `Core/Request.php` optional scalar-query validation |
| Interface | New `app/Modules/Admin/Views/reports.php`; shared `Views/workspace.php` report branch |
| Tests | New `tests/report-period.php`, `report-csv.php`, `operational-reports.php`, `reporting-http.php`; shared browser harness now accepts a requested synthetic page list |
| Documentation | This guide, new `MONETISATION_DESIGN.md`, requirements, architecture, decisions, progress, test plan and README |

## Recorded verification — 23 November 2026

On PHP 8.5.12 and isolated MySQL 8.4.13 schemas, date/CSV unit checks and real SQL fixtures passed all 16 measures, inclusive WAT boundaries, current teacher/approval/public vacancy rules, withdrawn applications, closed confirmed placements, draft exclusions, download/training events, retained messages, all-time counts and fresh permission omission/revocation. Export audit uses the same locked permission-scoped transaction.

The HTTP suite passed four groups: guest/direct GET/HEAD role denial; exact aggregate HTML/UTF-8 CSV permissions and audit; strict/malformed-array dates, defaults/all-time, method rejection and live permission revocation; and free-launch protections. The free-launch test exercised real registration with queued verification and an actual anonymous original-PDF download, checked HEAD produces no download event while GET records an anonymous event, rejected a payment-setting injection without changes, verified absent checkout/payment routes and unchanged 86-migration schema, and retained reserved/restricted/direct-file controls.

The report page passed isolated Chrome checks at 360/768/1440 pixels: no horizontal page overflow, light/dark/system controls, field/landmark IDs, selected navigation, focus and breakpoint recovery, confirmation/submitter/loading/Back behavior and primary-button contrast. PHP syntax checks passed all 440 files, Composer strict validation and whitespace checks passed, and foundation public/configuration/logging/method tests passed. Static review found no actionable issue. Exact concurrent revocation timing and the precise 365-day retention instant were reviewed statically rather than separately stress-tested.

Test-driven review corrected initially overbroad vacancy eligibility and array-shaped filter defaults. A sandbox loopback restriction was resolved by running the existing isolated database tools with their normal loopback permission; no server/application data change was needed. Test-only assumptions for a nonstandard HTTP method and repeated login rate limits were corrected without weakening application protections. Final logs and synthetic screenshots remain under private `storage/local-preview/reporting-*.log` and browser fixture directories. No application database was changed, and full legacy recruitment/CV workflow suites were not all rerun in this phase. Physical devices, screen readers and production-scale query profiling remain manual release checks.

## Access and privacy

Both the screen and `/dashboard/admin/reports/export` require `reports.view`. Each measure also requires its underlying module read permission. The service rechecks active staff and current permissions inside one transaction; permission-hidden datasets are omitted, not shown as invented zeroes. Exports contain metric labels/counts/definitions and date metadata only. They never export names, emails, phone numbers, request descriptions, message bodies, CVs or administrative notes.

CSV export records an attributed `reports.exported` audit event with its period and included metric keys. It records an accepted export request, not proof that the browser saved/read the file. HEAD requests are authorised but do not create download audit events. Payments remain outside operational settings and cannot be enabled through the interface.

## Measure definitions

The default period is the first day of the current month through today in Africa/Lagos. From/to dates are inclusive WAT calendar days, translated to a UTC half-open range for stored timestamps. Both dates are required when selecting a range; malformed, reversed, partial or array-shaped filters are rejected. Dates support years 1000–9998. All time removes date bounds. Exports use the same period selection; live data may change between viewing and exporting, so each snapshot carries its generation time.

| Measure | Underlying permission | Date basis and counting rule |
| --- | --- | --- |
| Registered teachers | `teachers.view` | Teacher/NYSC profile creation; verified email and teacher account role, including suspended registrations |
| Available teachers | `teachers.view` | Same registration cohort; currently active teacher/account, availability now or valid start date on/before today and a compatible teaching mode |
| Verified teachers | `teachers.view` | Same registration cohort; currently active and professionally verified |
| Available and verified teachers | `teachers.view` | Intersection of current availability and professional verification in that cohort |
| Approved schools | `schools.view` | Approval timestamp; currently approved school-role account, verified email and no account/school suspension, including valid legacy approvals |
| Registered parents | `parents.view` | Parent profile creation; verified email and parent role, including suspended registrations |
| Active vacancies | `vacancies.view` | Publication timestamp; currently published and publicly eligible through complete approved active school, with deadline on/after today |
| Submitted applications | `applications.view` | Application creation; all persisted applications, including withdrawn/historical statuses; no draft applications exist |
| Successful placements | `placements.view` | Confirmation timestamp; confirmed placements including subsequently closed placements, not selection or offers |
| Submitted tutoring requests | `tutoring.view` | Submission timestamp; submitted/later states, excluding private drafts |
| Library downloads | `library.view` | Recorded download event time; events rather than unique people/books or proof of reading |
| Training requests | `training.view` | Request creation; all submitted/later states including cancellations; no training drafts exist |
| Message threads | `messages.view` | Conversation creation; retained accessible conversations only |
| Messages sent | `messages.view` | Message creation; messages within retained accessible conversations only |
| Candidate requests | `candidates.view` | Request creation; submitted/later states, excluding private drafts |
| CV requests | `cv_requests.view` | Request creation; controlled disclosure requests covering professional information or original CV, regardless of decision |

Retained conversations are not purged and are either on preservation hold, not closed, or closed less than 365 days ago. Reporting honours that existing access boundary; retained message totals can decrease after retention processing. All counts use real database queries in one consistent read view after current staff/module checks. A user with `reports.view` but no data permissions receives an explanatory empty state and a header-only CSV.

## Setup and tests

No migration, seeder, payment switch, runtime dependency or billing table is added. Use the existing PHP 8.5/MySQL 8.4 application and assigned staff roles. Open `/dashboard/admin/reports`, select dates or All time, then Export aggregate CSV. UTF-8 CSV includes seven columns: metric, count, from/to WAT dates, date basis, definition and UTC generation time. Spreadsheet formula-like cells are neutralised, fields are quoted using standard CSV escaping, and download responses are private/no-store.

Run `php tests/report-period.php` and `php tests/report-csv.php` for pure validation/serialization checks. Run `php tests/operational-reports.php` and `php tests/reporting-http.php` with a fully migrated disposable development loopback schema named `furoeducare_test_admin_<12 hex characters>` or `furoeducare_test_settings_<12 hex characters>` and matching `DB_*` variables. Tests create unique fixtures and remove only their own data; do not point them at an application schema or reset another test run. The HTTP suite prints a private synthetic report preview for optional browser rendering.

## Manual review

1. Sign in as Super Admin, open `/dashboard/admin/reports`, compare the default month-to-today dates with the WAT calendar, and verify every count's stated basis against authorised module records.
2. Pick a single day and compare records at WAT midnight/end-of-day. Try invalid/reversed/partial dates and request `from[]=2020-01-02&to[]=2020-01-02`; expect 422 rather than a silently changed period. Select All time and return to This month.
3. Export CSV for the same range. Open it in a text editor and spreadsheet: check the seven aggregate columns, UTF-8 text, filter dates and generation time. Confirm there are no names, contacts, message bodies, document references or request notes. Changes between snapshots should be explainable by the generation times.
4. Repeat as Library Manager or another limited staff member: only permitted metrics should appear. Try direct export as a teacher, bare administrator or staff without `reports.view`; expect denial. Revoke a data/report permission while an existing session is open and repeat; current permissions must apply.
5. Check authorised audit activity after one export. Expect one `reports.exported` event containing period and metric keys; HEAD should not create an export event. The event does not establish that the file was read.
6. At 360/768/1440 pixels, check date inputs, cards, scrollable definitions table, keyboard focus and light/dark appearance. Downloads should not leave a false form-loading state.
7. With controlled synthetic accounts/resources, confirm registration, recruitment submission, authorised CV access and free-library downloads still use their existing free workflows. Attempt `payments_enabled=1` through system settings; it must be rejected. Reserved `future_paid` library files remain unavailable with no checkout; restricted resources still require their existing grant. Review [future monetisation design](MONETISATION_DESIGN.md) as a proposal only.
