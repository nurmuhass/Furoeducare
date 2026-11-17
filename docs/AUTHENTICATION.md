# Furoeducare Authentication

One account system handles teacher and NYSC member, school and institution, and parent and guardian registration. A verified email is required to sign in. Parent and guardian signup uses `/register/parent` for adult contact details and a password. School and institution signup uses `/register/school` for institutional and representative details. Both use the same protected session draft and verification flow. Teacher and NYSC signup starts at `/register/teacher` with category, name, contact details, password and confirmation, Terms acceptance and Privacy acknowledgement. Administrative roles are assigned through a trusted operational process.

| Role | Dashboard after sign-in | Onboarding |
| --- | --- | --- |
| Teacher / NYSC Member | `/dashboard/teacher` | The verified account opens the private teacher dashboard with profile completion and section-by-section editing. |
| School / Educational Institution | `/dashboard/school` | Collects the school name and starts in pending approval with a restricted dashboard. Email verification enables account access. Management approval is a separate state. |
| Parent / Guardian | `/dashboard/parent` | Parent account entry. Tutoring requests follow in their own phase. |
| Administrator | `/dashboard/admin` | Available to accounts with the seeded administrative role through an explicit grant. |

The application checks assigned roles on each protected request. If an account has more than one assigned role, its default dashboard follows administrator, teacher, school, then parent precedence. A dashboard links to the other roles the account actually holds.

Teacher signup keeps a draft in the initiating private PHP session. The draft contains contact details, policy revisions, a password hash and a digest of the matching verification token. Verification requires a POST with CSRF protection. A teacher opening the link in another browser enters their details, confirms the email address and chooses their password there. An expired or used link offers teacher-specific resend. A fresh link can reuse the original browser's draft for that address. A successful confirmation uses the shared single-use token, saves the teacher profile and role in the same database transaction, and regenerates the session before opening onboarding. The general verification form hands teacher selections off to the same workflow. Previously verified teachers without a profile finish setup from their dashboard.

Registration enforces one account per normalised email address. School accounts also use a normalized institution name, state and city match. School accounts remain pending until an explicit approval is recorded.

## Local setup

1. Configure the development database as described in the [database guide](DATABASE.md). Run `php bin/database.php migrate` and `php bin/database.php seed`.
2. Set `APP_ENV=development` and `APP_URL=http://127.0.0.1:8000` in the ignored `.env`. Start the PHP dev server at that same origin. Use test email addresses ending in `.test` for local verification and reset.
3. Open the relevant `.eml` file in `storage/mail/` and use its link. Verification links open a form for choosing the password and account category. Reset links open the new-password form. Development sends no external mail.

File-backed sessions use `storage/sessions/`. Optional `AUTH_SESSION_DIR` and development-only `AUTH_MAIL_DIR` can point to private paths outside every web root. Restrict them to the runtime user. Treat spool files as credentials until their links expire and remove disposable test mail when finished.

## Production settings

- Set `APP_ENV=production`, `APP_URL` to the canonical HTTPS origin, and `MAIL_FROM` to an authorised sender for the deployment. Configure PHP's `mail()` transport to hand mail to the server's MTA and test delivery to a controlled mailbox. The application creates links from `APP_URL`. Delivery failures are logged as an operational error and users can request a fresh link. [PHP mail documentation](https://www.php.net/manual/en/function.mail.php)
- Serve the application over HTTPS and use private session storage accessible to the application process. Cookies are host-only and use `Secure`, `HttpOnly` and `SameSite=Lax` in production. The local HTTP development setup uses the same cookie settings except `Secure`. [PHP session security settings](https://www.php.net/manual/en/session.security.ini.php)
- Configure web server and proxy request logs to record paths without query strings. Authentication links carry a token in the query string. Auth pages use `Cache-Control: no-store` and `Referrer-Policy: no-referrer`. [OWASP password reset guidance](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html)
- Login limits are five attempts per normalised email and client network, 20 per normalised email across networks, and 30 per network over 15 minutes. Other email-related forms have separate request limits. Rate limiting uses the web server's `REMOTE_ADDR`. Configure trusted reverse proxies to supply the correct peer address at the server. Expired rate-limit buckets can be removed during database maintenance.

## Session and token behaviour

- Passwords use PHP `password_hash`, `password_verify` and `password_needs_rehash`. Registration and reset accept at least 12 characters up to 72 bytes. Passwords are never echoed in a form.
- Login regenerates the PHP session ID and rotates its CSRF token. The session expires after 30 minutes of inactivity or eight hours from login. Logout destroys the current session. Password reset increments the account's authentication version, which revokes every session issued under the old version. Suspension is checked on login and on each protected request.
- Verification links expire after 24 hours. Reset links expire after 30 minutes. MySQL stores SHA-256 token digests. Issuance leaves earlier unexpired links usable. A successful POST consumes the presented token and revokes other outstanding tokens of the same type in the same transaction as its account change. Visiting the email link shows a form before any account change.
- Staff operating on account suspension should update `suspended_at` and increment `auth_version` together. This revokes sessions even if an account is later reinstated. Clear `suspended_at` when reinstating an account and keep the incremented version. The school review workflow applies these updates transactionally for school suspensions.

The [test plan](TEST_PLAN.md#authentication) gives the exact test commands and manual flows.

School account activation uses the same protected session draft and token flow as teachers. Verified schools start pending. A school decision to suspend the account updates the shared authentication state and revokes existing sessions. See [school onboarding and review](SCHOOL_ONBOARDING.md).

The generic email-first entry still accepts previously issued links and sends the mailbox owner to the relevant full-profile confirmation form. The [parent account guide](PARENT_ACCOUNTS.md) covers the restricted account and request actions.
