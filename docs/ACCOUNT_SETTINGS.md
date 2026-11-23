# Account settings and appearance

## Design and scope

Use one authenticated `/settings/account` page, existing role-specific profile editors and existing notification/privacy services. Account preferences are stored in MySQL; guest appearance stays local. The server supplies the signed-in appearance before styles load, and the early theme script resolves System Default. No remote preference fetch is needed before paint.

Sensitive changes require the current password, CSRF and rate limiting, with current account/version checks inside transactions. Password changes invalidate other sessions and pending security links. Email changes retain the old login until an expiring single-use link for the proposed address is confirmed by the same authenticated account with reauthentication. Requests and tokens are scoped to account and version; duplicate addresses are checked again at confirmation. GET links never mutate account state. Security events and audit entries contain no passwords or token plaintext.

Phone updates remain in the owned role profile and retain teacher verification/school review rules. School organisation editing updates the existing account. Teacher settings link to the existing publication/disclosure and availability controls; there is no self-publication. Deactivation is a reauthenticated management-reviewed request, with cancellation and an audit trail, rather than immediate deletion or bypassing last-Super-Admin protection.

## Implementation plan

- [x] Add migrations for account preferences, pending email changes, deactivation requests and optional staff contact details.
- [x] Implement settings validation, reauthentication, version-bound password/email changes, token cancellation/expiry and secure queued email.
- [x] Add settings page/routes, role profile/phone controls, privacy and notification links, request cancellation and dashboard entry links.
- [x] Integrate server/account appearance throughout public and authenticated layouts with guest storage and progressive form controls.
- [x] Test settings ownership, CSRF, reauthentication, duplicate/expired/replayed/mismatched email links, sessions, review restrictions, appearance modes and migration upgrade/rollback; record results and manual steps.

## Files created or modified

| Area | Files |
| --- | --- |
| Settings module | New `app/Modules/Settings/{AccountSettings,AppearancePreferences,SettingsController,SettingsInput,SettingsValidation}.php` and `Views/{account,email-change}.php` |
| Database | New migrations 0083 account preferences, 0084 email challenges, 0085 linked deactivation requests, 0086 optional staff contact details |
| Routing/context | New `config/settings-routes.php`; `bootstrap/app.php`, `Core/View.php`, `Core/Response.php`, `UI/DashboardNavigation.php` |
| Authentication and profile safeguards | `AuthSession.php`, `AuthController.php`, `TeacherRepository.php`, `SchoolRepository.php` |
| Email/notifications | `Email/Outbox.php`, `EmailWorker.php`, `EmailTemplates.php`, `Notification/NotificationInput.php` |
| Appearance and settings entry points | `public/assets/theme.js`; new `resources/views/appearance-picker.php`; layout/header/footer/admin shell/dashboard sidebar/header; Teacher settings and School/Parent dashboard views |
| Tests | New settings input/views/HTTP, appearance DB/JavaScript/browser and settings browser harness; updated migration/admin/dashboard/email/navigation test assumptions and browser harness |
| Documentation | This guide, project requirements, architecture, decisions, database, email, implementation progress, test plan and README |

## Checks and results

Verified on 23 November 2026 with PHP 8.5.12, MySQL 8.4.13 and isolated Chrome. The seven settings HTTP groups passed four-role ownership/CSRF/strict fields, all theme choices/public account attributes, owned phone/review rules, password hashing/session/reset invalidation, actual encrypted queued mail delivery and the full email token lifecycle, deactivation review/cancellation/sole-admin preservation and rate limiting. Appearance DB tests passed defaults, choices, isolation, invalid/inactive accounts, session-version rejection, audit and cascade behavior.

All 86 migrations passed fresh/previous-release bootstrap, upgrade, settings-table assertions, rollback/reapplication, transactions, seeding, failure markers and checksum checks. Foundation public/configuration/logging/method checks, settings validation/rendering, notification navigation/catalog/views, dashboard components, mail cryptography, PHP syntax, Composer strict/platform and whitespace checks passed. Static security review found no blocking issue; its preference-version hardening was implemented and tested.

Browser checks passed four role settings plus public home, login, registration, Library and Messages at 360/768/1440 pixels (27 page/viewport combinations). They exercised explicit light/dark and system controls, native account persistence triggers, local guest storage, synchronised controls, no page overflow, unique field IDs, keyboard menu/breakpoint focus, confirmation/submitter/loading/Back behavior and primary-button contrast. Google Fonts were blocked for deterministic checks, exercising the system font fallback. Rendered HTML/screenshots and logs remain private under `storage/local-preview`.

The first integration run exposed a mixed email-column collation query, fixed by explicitly matching the existing users email collation. Applied migration files remained immutable. Later runs passed. The browser and pure view tests also cover form-local password errors and safe appearance return destinations. No application database or production account was changed. Complete legacy workflow suites were not all rerun; physical-device, screen-reader and deployed SMTP/DNS checks remain manual release review steps.

## Setup

Back up the development database and apply `php bin/database.php migrate` using the supported PHP 8.5 environment. Migrations 0083–0086 are required before opening authenticated pages after this upgrade. No runtime dependency is added. Configure the existing mail transport and encryption key; development can use `MAIL_TRANSPORT=file` with a private mail directory. Run `php bin/email-worker.php 50` outside the HTTP request to process queued verification mail. Never serve the mail directory or copy production token-bearing messages into public storage.

Open `/settings/account` from any dashboard. Existing profile editors stay available: teacher professional profile/availability, school organisation profile and parent profile. Admin staff have their own optional display name/contact record, without receiving new staff permissions. Settings forms derive the target account from the session, reject unexpected identity fields, and do not accept an editable account ID.

Public/dashboard appearance controls save natively and return to the current safe internal page. Token confirmation URLs and unsafe/external return destinations fall back to account settings. Authenticated page responses are private/no-store because they now include personal appearance and account controls. Guest browsing does not require database-backed preferences.

## Email and deactivation policy

An email-change link expires after 30 minutes. It must be opened by the same authenticated account and confirmed by POST with CSRF and the current password. Opening a link while signed out remembers only a fixed confirmation destination through login; arbitrary return URLs are not accepted. Old login email remains current until confirmation. Confirmation changes the existing user row, retains its roles and verified status, rotates the current session, and invalidates other sessions/reset links/pending changes. Pending emails do not reserve an address; the existing users uniqueness constraint is checked at confirmation. Cancellation, replacement, expiry, suspension or an account-version change makes a challenge unusable.

The existing email outbox permits the proposed address only for the new encrypted `email_change` template and only when a matching active version-bound request exists. The worker checks that request again before sending and clears encrypted secrets under the existing completion/retry policy. Optional security updates continue through the central notification service and respect independent channel preferences. Required verification mail is independent of those optional preferences. Private documents are never attached.

Deactivation creates a linked restriction request in `/dashboard/admin/privacy-requests`, visible only to staff with the existing privacy permission. It does not delete or suspend the account. Cancelling a pending request closes its linked review entry and retains both records. Management review is shown as Reviewed; it does not imply that suspension/deletion occurred. Implementing any subsequent account suspension still uses the existing staff permissions, decision audit and last-active-Super-Admin protections.

## Exact manual review

1. Apply migrations on the backed-up local database, start the configured PHP server and background mail worker, and sign in separately under each account category. Open `/settings/account`; verify role profile links, owned phone details, notification/privacy controls and appearance selection.
2. Signed out, choose Dark in the public appearance control, reload, and check login/registration and Library. Choose Light and System Default too. For a signed-in account, save a different mode, reload the public home page and dashboards, then sign in to that account in another browser. The stored account choice must override a different guest-local choice before styles display. Change the device mode while using System Default.
3. Open Library, Messages, Notifications, a long form/table and a confirmation dialog in both light and dark modes at 360/768/1440 pixels. Check focus, text contrast, native fields and modal background. Disable JavaScript and use the authenticated appearance form's Save button; explicit Light/Dark still apply server-side. System detection and guest local preferences require JavaScript.
4. With two sessions for one test account, submit an incorrect current password, then a valid new password. Verify errors retain no password values, the current session continues with regenerated credentials, and the second session must sign in again. Try an outstanding reset link and expect refusal. Forge another account ID or unowned phone role and expect rejection without a change.
5. Request a new email using the current password. Check the old address still signs in. Run the local worker, open the private development verification message, and follow its link. GET alone must not change email. Confirm with the current password; check the same account/roles now use the new verified address and other sessions expire. Repeat with the wrong account, expired/replayed/cancelled/replaced links and an address taken by another account before confirmation; each must fail safely.
6. Update each owned contact phone. For a professionally verified teacher, confirm existing evidence-review rules apply. For an approved school, confirm changed contact details return the same school to review; organisation editing must not create another account. Check teacher privacy pause/resume and availability links: they must not self-publish or recreate expired disclosure grants.
7. Submit a deactivation request with reason, current password and explicit confirmation. Verify the request in the authorised management privacy queue, and that the account remains active. Try a duplicate, cancel a pending request, then review another using separate authorised staff. Confirm retained history and accurate Reviewed/Cancelled state. A sole Super Admin request must not automatically disable that account.
8. Change independent notification channels and check optional updates respect them while the email-change verification still queues. Inspect authorised audit/delivery views: settings decisions should be attributable without passwords, token plaintext, or private mail payloads. Check native confirmation cancellation, loading feedback and Back navigation.
