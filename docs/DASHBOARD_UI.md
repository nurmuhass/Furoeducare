# Shared dashboard interface

## Scope and design

Review Teacher, School, Parent and Admin dashboards together. Retain Core PHP, Bootstrap and vanilla JavaScript, actual backend records and every existing approval, ownership and staff permission boundary. Use one responsive shell with grouped role navigation and account controls. Shared services retain the selected dashboard context. Public visitors retain the public layout.

Separate shells with matching styles would retain duplicate navigation and interaction logic. A shared shell with role-specific navigation is selected because shared Library, Messages and Notifications pages need consistent context. No backend workflows, account permissions or metrics are invented. School approval restrictions remain server enforced.

## Implementation plan

- [x] Test role navigation, selected-page matching, permission filtering, escaping, shared component states and context selection.
- [x] Add shared navigation/context policy and reusable sidebar/header/shell. Replace repeated Teacher, School and Parent frames; retain existing panels and Admin permission gates.
- [x] Add reusable metric/status/table/search/filter/pagination/empty/error components and accessible form feedback. Adopt them in current overview and list views.
- [x] Add progressively enhanced mobile navigation, confirmation dialog and real submission/read-receipt loading feedback. Keep forms usable without JavaScript; preserve named submit-button values and file submissions.
- [x] Verify real rendered role views, empty/populated/error states, navigation routes, keyboard/mobile behavior, themes, existing foundation and dashboard checks. Document results, files and manual review.

## Files created or modified

| Area | Files |
| --- | --- |
| Shared component and navigation policies | New `app/UI/Components.php`, `app/UI/DashboardNavigation.php` |
| Shared templates | New `resources/views/dashboard/shell.php`, `sidebar.php`, `header.php`, `confirmation.php`; updated `resources/views/layout.php`, `form-fields.php` |
| Interactions and styling | New `public/assets/dashboard.css`, `dashboard.js`; updated `messaging.js` pending read-receipt feedback |
| Context integration | `bootstrap/app.php`, `app/Modules/Auth/AuthSession.php`, Teacher controller signed-in context, Candidate controller effective owner context |
| Module views | Teacher workspace/overview/settings/profile editor; School/Parent dashboard; Admin workspace; Notification feed; Library download history |
| Automated checks | New `tests/dashboard-ui.php`, `dashboard-ui-http.php`, `dashboard-ui-browser.js`, `dashboard-ui-browser.ps1`; existing Admin shell assertion updated |
| Documentation | This guide, README, project requirements, architecture, decisions, implementation progress and test plan |

The existing specialised Teacher content styles and legacy fallback shell remain available; normal authenticated pages use the common shell. There are no new database migrations, dependencies, backend workflows or permission grants.

## Verification record — 23 November 2026

PHP 8.5.12 and an isolated MySQL 8.4.13 schema passed all four role overviews, every sidebar route, common pages including saved resources/download history, server validation, pending-school restrictions, limited-staff denial and multi-role candidate direct-link/error context. The full Admin high-risk suite passed its five groups, including CSRF, confirmation, stale writes, delegation, suspension, revocation, last-Super protection and safe settings/audit/email rendering. Foundation HTTP/configuration/logging/method checks, component/policy/availability, notification and library view checks passed. All 415 PHP files passed lint; Composer strict validation and Git whitespace checks passed.

Isolated Chrome checked all four roles at 360, 768 and 1440 pixels: viewport and document widths matched; landmarks/IDs and selected navigation were consistent; mobile focus/Close/Escape/Tab wrapping and breakpoint recovery passed; confirmation preserved the named action and record fields; loading, cancellation and browser Back recovery passed; light/dark/system mode controls passed. Screenshot artifacts and test logs remain private under `storage/local-preview`. Google Fonts were blocked for deterministic browser checks, exercising the system font fallback. No production sample records were added and the application database was not changed.

Review found and corrected the missing Teacher signed-in shared context, effective owner category on multi-role direct links/errors, breakpoint focus loss and named `action` controls shadowing the form URL property. The existing operational support contact remains used in the shared admin footer. The Admin test was updated to recognise the new shell without changing its permission assertions.

Manual screen-reader testing, physical devices, real file submissions/downloads and simulated message network failure remain deployment review steps. The complete legacy workflow suites were not all rerun in this UI phase.

## Review focus

Multi-role accounts must retain a valid context without granting permissions. Query-string tabs must select only their own link. Staff navigation must never imply a forbidden action. Mobile focus must stay in the open navigation and return when closed. Submission feedback must preserve form payloads and reset after browser Back; asynchronous failures must offer the existing manual fallback.

## Manual review

1. Sign in separately as Teacher, School, Parent and a limited staff member. Open each overview; compare the sidebar, header, notifications, theme and account controls.
2. Follow every grouped role link, then Library, Messages, Notifications and Settings. Shared pages must retain valid originating context; on multi-role candidate/directory pages the shell must follow the backend-selected owner category. Test a direct request detail/profile link after switching dashboards.
3. For a pending school, check restricted recruitment actions remain blocked. For limited staff, try a forbidden management URL directly and expect 403.
4. At 360, 768 and 1440 pixels, open and close the mobile menu, use Tab/Shift+Tab/Escape, inspect wide tables and long text, and switch light/dark/system appearance. Repeat with JavaScript disabled.
5. Submit a valid profile change and an invalid form. Check visible loading feedback, retained field values, error summary, field associations and unchanged button action values. Navigate Back and confirm controls recover.
6. Cancel and confirm a sensitive management action using synthetic records. Only confirmation submits. Verify CSRF and server confirmation/reason checks remain required.
7. Browse empty and populated lists; search/filter, clear filters and paginate. Confirm actual record counts and statuses, readable table headings and helpful empty states. Simulate a failed message read-receipt request and use its manual fallback.
