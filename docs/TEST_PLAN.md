# Furoeducare Test Plan

## Initial PHP foundation

Run `composer validate --strict`, `composer check-platform-reqs` and `composer test` from the project root with the supported PHP/Composer setup in the [README](../README.md). Lint tracked PHP source with `php -l`. Add the applicable business-rule tests from the [requirements](PROJECT_REQUIREMENTS.md) when each business module is implemented.

### Automated checks

| Coverage | Expected outcome |
| --- | --- |
| HTTP home and assets | `GET /` returns HTML and escapes the configured app name. `GET /assets/app.css` returns CSS. |
| Liveness and methods | `GET /health` returns HTTP 200, uncached JSON. `HEAD /health` omits the body. 404 and 405 are distinguished and 405 includes `Allow`. |
| Router and form overrides | GET, POST, PUT, PATCH and DELETE dispatch directly. POST forms can override with PUT, PATCH or DELETE. Query values and GET inputs cannot override, and array input is rejected. |
| Environment and failure handling | Process settings take precedence. Quoted local values parse and malformed input fails. A bad production database port yields a generic 500 and a correlated error record containing the setting name and excluding its value. |
| Public boundary | Requests under the development web root cannot retrieve `.env`, Composer configuration or application logs. |

### Manual checks on Windows

1. Start the development server using the README. In another PowerShell window, run `curl.exe -i http://127.0.0.1:8000/health` and check for 200 and `{"status":"ok"}`. Run `curl.exe -i http://127.0.0.1:8000/missing` and expect 404. Run `curl.exe -i -X POST http://127.0.0.1:8000/health` and expect 405 with `Allow: GET, HEAD`.
2. Open `http://127.0.0.1:8000/` in a browser. Tab to the skip link and theme selector. Switch between Light, Dark and System. Reload after each choice and verify it persists. In System mode change the OS or browser-emulated color preference and verify the page follows. Switch to a narrow viewport and check that the heading and controls stay visible.
3. Stop the server with Ctrl+C. From the project root, set an invalid database port and start it again.

   ```powershell
   $env:APP_ENV = 'production'
   $env:DB_PORT = 'invalid'
   php -S 127.0.0.1:8000 -t public bin/dev-server.php
   ```

4. In a second PowerShell window run `curl.exe -i http://127.0.0.1:8000/health`. Expect 500 and `Internal Server Error`. Run `Get-Content .\storage\logs\app.log -Tail 1` from the project root. Match its `request_id` to the response header and confirm it identifies `DB_PORT`. Stop the server and clear the two test variables with `Remove-Item Env:APP_ENV, Env:DB_PORT` before restarting.
5. If using Apache or Nginx, repeat the home, health, 404 and 405 requests against the configured hostname. Verify the host serves `public/` and that configuration, private paths and parent-site URLs fail with 403 or 404.

## MySQL foundation

Use MySQL 8.4 LTS and PHP 8.5. The integration suite creates and rolls back tables in an empty disposable schema on loopback. It checks that the configured schema is empty and uses a name such as `furoeducare_test_run1`. The test leaves the applied schema and its reference roles for inspection.

1. In a local MySQL 8.4 administrator session, create the disposable schema and grant schema privileges to a dedicated test identity.

   ```sql
   CREATE DATABASE furoeducare_test_run1 CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
   CREATE USER 'furoeducare_test'@'127.0.0.1' IDENTIFIED BY RANDOM PASSWORD;
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
       ON furoeducare_test_run1.* TO 'furoeducare_test'@'127.0.0.1';
   ```

2. Open a dedicated PowerShell terminal from the project root. Select the test schema and supply the test password as a process environment variable. The example assumes port 3307.

   ```powershell
   $env:APP_ENV = 'development'
   $env:DB_HOST = '127.0.0.1'
   $env:DB_PORT = '3307'
   $env:DB_DATABASE = 'furoeducare_test_run1'
   $env:DB_USERNAME = 'furoeducare_test'
   $furoTestPassword = Read-Host 'Test MySQL password' -AsSecureString
   $env:DB_PASSWORD = [pscredential]::new($env:DB_USERNAME, $furoTestPassword).GetNetworkCredential().Password
   php tests/database.php
   ```

3. Expect passing checks for a fresh migration and repeated runs, seed idempotency, transaction commit and rollback, uniqueness and foreign-key rules, timestamp updates, reversible rollback, protection for irreversible migrations and reapplication, failed seed transaction rollback, interrupted apply and rollback markers, and checksum detection.
4. Run `php bin/database.php status` and expect fourteen applied migrations. In MySQL, run `SELECT slug FROM roles ORDER BY slug;` and expect `admin`, `parent`, `school` and `teacher`. Close the test terminal to clear its overridden environment. Use a fresh disposable schema for subsequent integration runs.

For manual checks of migration, seeding and safe local development reset, see the [database guide](DATABASE.md).

## Public website

1. From the project root, run `composer test`, then start the development server with `php -S 127.0.0.1:8000 -t public bin/dev-server.php`.
2. Open the home page and use the header and footer to visit About us, Our services, Find teachers, Teaching jobs, Digital library, Training and workshops, Contact us, Log in, Create an account, Privacy policy and Terms of use. Expect a page-specific title and heading. The privacy and terms pages both show **Draft awaiting legal review**. The four resource pages explain the empty state and give a next step.
3. Use a browser's responsive view at 390 × 844, 768 × 1024 and 1440 × 980 CSS pixels. Check that the hero and grids reflow without a horizontal scrollbar, the photographs display, and the desktop navigation becomes a Menu button at narrower widths. Open the menu, focus a link, press Escape and verify focus returns to Menu. Start at the top of a page and press Tab to reveal the skip link. Activate it and check focus moves to the main content.
4. In the footer, select Dark and navigate to another page to check persistence. Try Light and System default. In System default, switch the operating-system or browser-emulated color scheme and verify the page follows it.
5. Open Contact us and click **Preview enquiry** with the fields empty. Check the visible field errors and focus on the first invalid field. Enter sample details such as `Nkiru Example` and `nkiru@example.test`, choose a topic and enter a sample message. Submit to see the checking and ready states. Start another check and change a field while it is running. The pending feedback should clear.
6. Verify that Log in and Create an account open the live forms described in the authentication section. Contact us remains a preview.

## Authentication

Use PHP 8.5 and MySQL 8.4 LTS. Apply all migrations and seed the roles before using the authentication routes. The [authentication guide](AUTHENTICATION.md) explains environment settings, the private development mail spool and production mail requirements.

### Automated checks

From a dedicated PowerShell session, provision an empty loopback database with a name such as `furoeducare_test_auth_run1` and grant the test MySQL account the schema permissions shown in the [database guide](DATABASE.md). Set `APP_ENV=development`, `DB_HOST=127.0.0.1`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` for that database, then run the following.

```powershell
php tests/authentication.php
composer test
```

The authentication suite applies and seeds the empty test schema, starts local HTTP servers and uses temporary mail and session paths. It checks duplicate emails, invalid input, CSRF, email verification and resend, incorrect passwords, unauthorised routes, correct role redirects, school approval, expired and reused reset links, session revocation, both expiration limits, suspension, rate limiting and production cookie headers. Use a fresh disposable database for each run.

### Manual verification

1. Configure a local development database and run `php bin/database.php migrate` and `php bin/database.php seed`. Set `APP_URL=http://127.0.0.1:8000` in `.env` and run `php -S 127.0.0.1:8000 -t public bin/dev-server.php`.
2. Go to `http://127.0.0.1:8000/register/teacher`. Register with `teacher@example.test` and a unique test password. Before using the email link, verify the credentials do not sign you in.
3. Open the newest `.eml` file in `storage/mail/`. Follow the verification link and click **Verify email and continue**. Expect the teacher dashboard. Visit `/dashboard/admin` and expect HTTP 403. Log out, revisit `/dashboard/teacher` and verify that sign-in rejects a wrong password and accepts the correct password.
4. Register and verify a school account with a different test email and a school name. Sign in and confirm **Pending management approval**. Register and verify a parent account and confirm its redirect to `/dashboard/parent`.
5. With a teacher session signed in, open a second browser profile, visit `/forgot-password`, and request a link for the same teacher. Open the reset email from the local spool and set a new password. Try submitting the link again, retry the old password, and reload the dashboard in the first profile. Expect a rejected link, a rejected password and a redirect to login. Sign in with the new password.
6. Inspect the session cookie in browser developer tools. Check for `HttpOnly` and `SameSite=Lax`. In an HTTPS production-like deployment, check that it also has `Secure`. The automated suite verifies the expiry and suspension paths without a long idle wait.

## Teacher onboarding

Use PHP 8.5 with PDO MySQL, Fileinfo and Zip enabled, and MySQL 8.4 LTS. For files up to 5 MiB, set `upload_max_filesize = 5M` and `post_max_size = 6M` or larger in the active `php.ini`. Configure the Apache or Nginx request body limit to allow the multipart overhead. Use a private writable parent directory for `TEACHER_DOCUMENT_DIR`. It defaults to `storage/teacher-documents`.

For the automated suite, create an empty disposable MySQL schema named `furoeducare_test_teacher_<suffix>` and grant it the privileges in the [database setup](DATABASE.md#local-mysql-setup). In a fresh PowerShell window, set `APP_ENV=development`, `DB_HOST=127.0.0.1`, and the schema's `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` using `$env:` assignments. Run `php tests/teacher.php`. The suite applies and seeds the empty schema and runs real HTTP requests with isolated mail, session and document directories.

### Manual verification

1. From the project root, run `composer install`, `php bin/database.php migrate`, `php bin/database.php seed` and `php -S 127.0.0.1:8000 -t public bin/dev-server.php`. Set `APP_ENV=development` and `APP_URL=http://127.0.0.1:8000` for local testing.
2. Open `/register`, choose **Register as a teacher or NYSC member**, and select **NYSC Member**. Inspect the draft Terms of Use and Privacy Notice links. Try submitting without each required checkbox and with nonmatching passwords. Correct the inputs, register with a new test email, and confirm that login fails while the email is pending.
3. Open the new `.eml` in `storage/mail`. Follow the verification URL in the original browser and submit **Verify email and continue**. Expect `/dashboard/teacher` and **1 of 10 sections complete**. Register a second address as **Teacher**, then open that link in a separate browser profile. Verify that this browser asks for the registration details and password again, then opens onboarding without the NYSC fields.
4. Save a biography and a draft qualification with its institution missing. Switch sections and reload. Check that the biography and draft persist and the qualification lists its missing institution. Complete it, add a second qualification and save two work entries. Attempt to save an end year before its start year. Verify the error and reload to see the previously saved entries. Complete the remaining fields, leaving optional NYSC details blank if desired.
5. Under **CV and professional documents**, upload a valid PDF CV and a supporting document. Confirm the profile reaches 100% after all sections are complete. Download both while signed in. Upload a replacement DOCX CV and verify the prior link returns 404. Remove the CV and confirm that completion drops to 90%. A renamed non-document file should produce an upload error.
6. Use the second teacher account to open the first teacher's document URL and expect 404. Use an anonymous window for the same URL and expect a redirect to login. Visit `/find-teachers` and confirm neither profile nor its contact details or files appear. Use the theme selector and a narrow viewport to check the forms in light, dark and system modes.
