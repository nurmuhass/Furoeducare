# Furoeducare Test Plan

## Managed recruitment and placement

Run `php tests/placement-workflow.php` to check allowed stage decisions and `php tests/placements.php` for the HTTP tests. Set `APP_ENV=development`, `DB_HOST=127.0.0.1`, `DB_DATABASE=furoeducare_test_placements_<suffix>` and the matching `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` for a disposable empty MySQL 8.4 schema.

1. Stop the local app, run `php bin/database.php migrate`, and restart it. Sign in as staff and move an application on a published vacancy to **Under review**. Open **Start managed placement**. Record screening and release the shortlist. Schedule a future interview in WAT and confirm teacher availability.
2. As the owning school, open **View placements** and select the candidate. Check **Selected** and verify the application still has no Successful decision. As staff, record the offer and start date. Try confirming with professional verification pending or the current CV unaccepted. Complete the verification and CV review, confirm, and check **Successful** on the application. Close the placement and confirm that the application retains **Successful**.
3. As a verified parent, submit a home tutoring request. In the staff tutoring queue, shortlist an available tutor and move the request to **Arrangement pending**. Open **Start managed placement** for that tutor and choose **Home tutoring**. Record the discussion, availability, selection and arrangement. Attempt confirmation before safeguarding.
4. Verify the tutor's identity against approved records, confirm suitable professional reference evidence, agree supervision and boundaries with the responsible adult, and confirm emergency escalation arrangements through the staff process. Record the checklist and an opaque restricted evidence reference. Leave names, ID numbers, addresses and contact details in the authorised evidence system. If the teacher's professional verification revision changes, renew verification and record the checklist again before confirming. The parent should see the confirmed arrangement and the teacher should receive a placement update.
5. Try to start a second placement for the same application or a tutoring request with a current placement. Expect a conflict. Reject a separate pending placement and check that it has no confirmation timestamp. Check that an unrelated requester cannot view or select it and that the teacher cannot confirm it. Confirm that notes and safeguarding evidence are visible only to staff.

## Private tutoring requests

Run `php tests/tutoring-workflow.php` for the permitted stage transitions. Run `php tests/tutoring.php` against an empty, disposable loopback MySQL database named `furoeducare_test_tutoring_<suffix>` for parent and staff HTTP checks. The database suite covers the upgrade of older general and specific-teacher requests.

1. Stop the local application and run `php bin/database.php migrate`. Restart it, sign in as a verified parent and open **Request Lesson Teacher**. Save an incomplete general draft. Open **My Requests** and confirm that it asks for the missing fields before submission.
2. Add a subject, education level, number of learners, objectives, format, preferred weekdays and time ranges, and sessions per week. For home or either format, add a state and city. Check that a street address in the city field or an end time before the start gets a validation message.
3. Open a tutor from **Find Teachers** in a signed-out browser and choose **Request this teacher**. Sign in and check that the chosen public reference appears in the request form. Save and submit the request. Verify that another parent cannot open the draft or submitted request and that it does not appear in Teaching Jobs.
4. As staff, open **Tutoring requests**. Start the review and move to matching. Use the finder to add an available tutor with a compatible lesson format, publish the shortlist to the request, and move to **Arrangement pending**. Confirm that the owner sees the shortlist and updates in their dashboard.
5. From **Arrangement pending**, follow the [managed placement walkthrough](#managed-recruitment-and-placement) to confirm. In a separate request, close it with a reason and confirm it appears in the owner's history.
6. As a school or teacher, try the staff queue URL and confirm access is denied. Check that a second parent cannot see the first parent's notes or notifications.

## Teacher job applications

Run `php tests/application-workflow.php` for the complete transition matrix. Run `php tests/vacancies.php` against an empty, disposable loopback MySQL database named `furoeducare_test_vacancies_<suffix>` for the HTTP workflow. The database suite also tests upgrading existing application and history rows from migration 0034.

1. Run `php bin/database.php migrate` against the local development database. Publish a vacancy. As a teacher with a complete profile, verified email and pending professional verification, open its requirements at `/teaching-jobs` and select **Save job**. Open **Saved Jobs** and verify the link. In a different teacher account, confirm the saved job is absent.
2. Apply from the posting with a short statement. Open **My Applications** and check the candidate reference, posting, statement and professional details. Edit a saved skill in the profile and confirm the submitted skill stays the same. Confirm another teacher can neither open the application detail nor withdraw it.
3. As staff, open **Teaching applications** and move an application to **Under review**. Follow the [managed placement walkthrough](#managed-recruitment-and-placement) for the interview, offer and confirmation.
4. Check **Successful** in the teacher's history after managed confirmation and confirm withdrawal is unavailable. On a different active application, withdraw as the teacher and confirm reapplying is blocked.
5. Sign in as the school. Check that its vacancy page contains no candidate records, statements, contacts or documents. A staff application link and a protected CV link both deny school access.
6. Close the vacancy and confirm it remains in Saved Jobs with its saved title and an unavailable label. Remove that bookmark.

## Teaching vacancies

Run `php tests/vacancies.php` with PHP 8.5 and an empty, disposable MySQL 8.4 database named `furoeducare_test_vacancies_<suffix>` on loopback. The suite uses synthetic accounts and checks ownership, approval, moderation, deadline boundaries, applicant eligibility, application history, duplicate prevention and renewed publication.

1. Run `php bin/database.php migrate` against the local development database. As a pending school, open **Manage teaching vacancies** and save a draft. Confirm that the submit action is unavailable until the school is approved.
2. As the approved school, finish and save all required posting fields, choose a future deadline and select **Submit for review**. Sign in as a different school and verify the original school's vacancy link is inaccessible.
3. In **Vacancy moderation**, start the review, approve the vacancy and open `/teaching-jobs` as a guest. Confirm the approved vacancy is absent. Publish it and verify the posting and deadline now appear while the school is approved.
4. Sign in as a teacher with a verified email and complete profile whose professional verification is pending. Submit an application from the posting and verify it appears in **My Applications**. Reload the posting to confirm the application is already on file. Try the posting as a teacher with an incomplete profile and confirm the apply control is unavailable.
5. As staff, open **Teaching applications**, start reviewing the application and verify the teacher can see the new status in its history.
6. Change a published field as the school. Verify the posting disappears from the public board and the teacher can still open the original posting details from their application history. Resubmit, review and republish the revision. Close the vacancy and verify it disappears again. A deadline that has passed in Nigeria also takes it off the board and closes intake automatically.

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
4. Run `php bin/database.php status` and expect twenty-one applied migrations. In MySQL, run `SELECT slug FROM roles ORDER BY slug;` and expect `admin`, `parent`, `school` and `teacher`. Close the test terminal to clear its overridden environment. Use a fresh disposable schema for subsequent integration runs.

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

## CV extraction

Run `php tests/cv-extraction.php` to test synthetic text PDFs, DOCX and image-only PDFs, absent sections, unusual order, multiple jobs and schema validation. The [teacher onboarding suite](#teacher-onboarding) checks private upload, queue processing, owner-only editable review, manual fallback, confirmed updates, repeat protection, replacement and expiry.

1. Configure development mode with the GD, ZIP, XMLReader and Fileinfo extensions enabled. Run `composer install` and `php bin/database.php migrate`. Start the local web server and sign in as a verified teacher.
2. Open **CV and professional documents**, choose **CV**, and upload a selectable-text PDF with at least two roles and one qualification. Verify that the page shows **Waiting for extraction** and your existing profile stays as saved.
3. From the same configured application, run `php bin/cv-worker.php --once`. Refresh the documents section and open **Review CV suggestions**. Check labels for missing fields and uncertain suggestions. Correct an employer, skip one row, and try saving without the review checkbox.
4. Confirm the review and save. Inspect **Work and teaching experience**, **Education and qualifications**, and **Skills and certifications**. The corrected entries should appear, and an existing biography and its teaching levels should be preserved. Reload the review page to confirm the draft is no longer editable.
5. Upload a DOCX with a different section order and run the worker. Open its draft, then sign in as a different teacher and visit `/dashboard/teacher/cv`. Confirm that only the uploading account sees its draft.
6. Replace the CV with an image-only PDF and run the worker again. The documents page should explain that OCR is unavailable and offer a path to manual editing. A renamed non-document or truncated PDF should be refused before a job is queued.

See [CV extraction and review](CV_EXTRACTION.md) for worker scheduling, supported content and correction details.

## Teacher dashboard

Run `php tests/dashboard.php` for availability validation. The [teacher integration suite](#teacher-onboarding) checks every dashboard page, completion and professional verification statuses, owner-scoped reads, forged update identifiers, protected fields, CSRF, role restrictions and availability persistence.

1. Enable the project’s PHP extensions, run `composer install`, `php bin/database.php migrate` and `php bin/database.php seed`, and start the local server with `php -S 127.0.0.1:8000 -t public bin/dev-server.php` in development mode.
2. Sign in as a verified test teacher or complete Teacher / NYSC registration. Open `/dashboard/teacher`. Check the profile percentage, CV status and professional verification status. A new teacher has a verified email and no professional verification. Application counts and interview invitations show **Unavailable** while recruitment is pending.
3. Open **My Professional Profile**, save a biography and reload. Open **CV and Documents** and verify the upload, status and review links match the current CV. Sign in as a second teacher in another browser profile and check that their biography and documents are separate.
4. In **Availability**, select full-time teaching and online tutoring, save and reload. Both options should remain selected. Add **Temporarily unavailable** and try again. The form should reject the combination and preserve the earlier save. Select only **Temporarily unavailable**, save and verify it after reloading.
5. Open **Teaching Jobs**, **My Applications**, **Placement Opportunities**, **Saved Jobs**, **Digital Library**, **Messages** and **Notifications**. Each should identify the pending feature without presenting working controls for unavailable actions.
6. At approximately 390, 768 and 1440 CSS pixels, check navigation, headings and horizontal scrolling. At narrow widths, open **Menu**, press Escape and check that focus returns to the button. Open the account menu, go to **Settings**, and switch appearance between Light, Dark and System default.

## School registration and review

Run `php tests/school.php` using PHP 8.5 and a fresh loopback MySQL 8.4 development schema named `furoeducare_test_school_<suffix>`. The suite uses temporary mail and session directories and applies and seeds the schema before testing.

1. Run `composer install`, `php bin/database.php migrate` and `php bin/database.php seed`. In development mode, start `php -S 127.0.0.1:8000 -t public bin/dev-server.php`.
2. Open `/register/school`. Enter a new test institution and representative address. Try leaving the address or an acknowledgement blank, then correct the input. Open the private verification email in `storage/mail` and confirm the account. Expect **Pending approval** in **School dashboard**.
3. Open the school profile and save a changed contact detail. Open public resources, **Notifications** and **Settings**. Create and edit a draft under **Recruitment drafts**. Sign in as a second school and try the first school’s draft URL. Expect HTTP 404.
4. From a school session, open `/dashboard/admin/schools` and expect HTTP 403. Open `/dashboard/school/teachers/restricted` and `/dashboard/school/shortlists` and expect HTTP 403. The HTTP suite also checks the two approval-gated POST routes with a valid CSRF token.
5. Sign in as an existing administrator and open **Review school accounts**. Select the test school. Choose **Changes required**, provide a reason, and record the decision. Verify the reason in the school’s notifications. Edit and save the profile as the school to return it to pending.
6. Confirm both school checks, approve the school and check the status. Then suspend it and reload the school dashboard in two browser sessions. Both should be redirected to login and a new sign-in should fail. Check that the reviewer, decision, reason and UTC timestamp appear in the administrator’s review history.
7. Register a second representative email for the same normalized school name, state and city. Confirm the email and verify that the duplicate is rejected. Use the local mail spool for these tests.

See [School and educational institution onboarding](SCHOOL_ONBOARDING.md) for the matching rules and permission matrix.

## Parent registration and account management

Run `php tests/parent.php` on PHP 8.5 and an empty loopback MySQL 8.4 development schema named `furoeducare_test_parent_<suffix>`. The suite creates isolated mail and session directories, applies migrations and seeds roles.

1. Configure the local environment, run `composer install`, `php bin/database.php migrate` and `php bin/database.php seed`. Start `php -S 127.0.0.1:8000 -t public bin/dev-server.php` in development mode.
2. Visit `/register/parent`. Confirm that the form only asks for the parent or guardian’s details. Submit a test account and check that signing in before verification fails. Open the private email in `storage/mail` and confirm the account. Expect `/dashboard/parent`.
3. In **Settings**, save a change to the phone, state or city. Refresh and confirm it persisted. The email should still be the verified account address. Check that **Find Teachers** and **Digital Library** open the public pages.
4. In **Request Lesson Teacher**, enter a subject and optional learning-goal notes. Save a private draft. Open it in **My Requests**, edit it, and review the saved details before submitting. Expect **Submitted** and a message that processing is deferred.
5. Sign in as a second parent in a different browser profile and open the first parent’s request URL. Expect HTTP 404. Direct POST attempts against that request and requests using an invalid session are covered by the automated suite.
6. Visit **Recommended Teachers**, **Saved Teachers**, **Lesson Arrangements**, **Messages** and **Notifications**. Check the informational states.

See [Parent and guardian accounts](PARENT_ACCOUNTS.md) for account and request permissions.

## Administrator verification

Run `php tests/verification.php` with PHP 8.5 and an empty loopback MySQL 8.4 development database named `furoeducare_test_verification_<suffix>`. The suite migrates and seeds its schema and starts an isolated server. It covers role restrictions, CSRF, the four queues, recipient isolation, private attachments, recorded checks, stale reviews, correction resubmission, document replacement, suspension and both recruitment eligibility rules. The school suite covers school transitions and approval checks.

1. Run `php bin/database.php migrate` against the local development schema. Open `/dashboard/admin/verification` in separate Administrator and Teacher sessions. Expect four queue links for the administrator and HTTP 403 for the teacher.
2. As an email-verified Teacher, complete the ten required profile sections, upload a synthetic PDF or DOCX CV, then choose **Submit for verification** on Overview. Confirm **Pending verification** and find the teacher in the administrator’s professional queue.
3. Attempt **Verified** before the CV is reviewed. Expect a validation error. Open **Documents requiring review**, download the CV, choose **Accept document**, enter notes and save. Reload the professional review. Enter notes and try verification with one check omitted to confirm it is refused.
4. Choose **Changes required** and enter a correction note. Check the correction queue and the teacher’s **Notifications**. Edit the profile as the teacher and choose **Resubmit for verification**. Confirm the requested correction has returned to the professional queue.
5. Confirm all professional checks, enter a decision note and verify the teacher. Check the teacher’s Overview and Notifications. Replace the CV and confirm **Pending verification** and a new document queue item. Sign in as a non-administrator and try the private staff download URL. Expect HTTP 403.
6. Open **School approval** and follow the school checks above. Try approving a school twice using two current submissions. Expect the second decision to fail. The automated verification suite exercises the placement gate while the placement workflow is pending.

## Public teacher directory

Run `php tests/directory.php` with PHP 8.5, GD and an empty loopback MySQL 8.4 development schema named `furoeducare_test_directory_<suffix>`. The suite applies migrations, seeds roles and starts a private local PHP server with synthetic fixtures.

1. Run `php bin/database.php migrate` against your local database. As a teacher, save at least one current availability category.
2. As an administrator, open **Manage public teacher listings**, select the teacher, then choose **Manage public listing**. Fill the public fields, confirm documented permission and publish. Leave the name and photo permissions clear for an anonymous professional listing. Then explicitly authorise a test name and upload a synthetic portrait. Check both at the same public reference.
3. In a guest window, browse `/find-teachers`. Filter by subject, level, state or city, qualification, minimum experience and availability. To test pagination, publish at least 13 synthetic matching profiles. Follow **Next page** and **Previous page** and confirm the filters and results are preserved.
4. Open a public profile and verify that it contains only the approved professional fields. Check that a photo URL returns 404 when its authorisation is removed, the teacher pauses availability, or the listing is withdrawn.
5. Select **Request this teacher** while signed out. Sign in as a parent or verify a new parent account, and confirm that the selected teacher reopens. Save the teacher and check **Saved teachers**. Send a short synthetic request and check the owner's request list and the administrator's submitted queue.
6. Repeat with a pending school. Confirm the school can save a private draft and the submitted queue does not show it. Sign in as an unrelated teacher and confirm that bookmark and administrative routes return 403.

## Candidate request and disclosure checks

Run `php tests/disclosure.php` with `APP_ENV=development`, `DB_HOST=127.0.0.1`, `DB_DATABASE=furoeducare_test_disclosure_<suffix>`, and the matching `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD`. Use an empty disposable MySQL 8.4 schema. The suite temporarily starts a local PHP server and uses a synthetic reviewed policy, fixture users and test CV files. Run `php tests/database.php` on its own disposable schema to verify clean install, legacy school and parent backfills, and rollback of the reversible disclosure tables.

1. Stop the application, run `php bin/database.php migrate` against the development database, and restart it using the existing local setup. Sign in as an approved school, open **Candidate requests**, then **Request candidates for a vacancy**. Choose an owned vacancy that has been submitted and create the case. Repeat by selecting a listed teacher through **Find Teachers**. Sign in as a verified parent, submit a general or specific-teacher tutoring request, and open **Candidate requests** from the dashboard.
2. Sign in as an authorised administrator, open Candidate requests, claim the case, add one or more eligible candidates by reference and release the shortlist. Sign back in as the requesting account and check that the shortlist contains the references and public details. As a different school or parent, open the case URL and confirm that access is denied.
3. With disclosure configuration empty, confirm on a candidate case that approval and access are unavailable. Use the isolated suite to exercise lawful-approval paths until a reviewed policy and published notice are ready for use. With a reviewed policy in a test environment, have the recipient acknowledge the notice and request original CV access. Review the scope, exact document and independent consent evidence as the assigned administrator, then approve for a short term. Return as the recipient and download from the case. Verify the download audit entry for that account, document and grant. Check that a profile-only scope cannot download a CV and that a copied download URL grants no access to another person or to a signed-out browser.
4. Revoke the grant as management, or pause disclosure in the teacher's Privacy controls. Verify that the previous recipient can no longer access the CV and that the teacher can view the grant history. Submit a data rights request, view the staff queue, record an outcome, and check that only the requester can see their request and response.

Check recent download records on the test database:

```sql
SELECT grant_id, recipient_id, document_id, created_at
FROM candidate_disclosure_downloads
ORDER BY id DESC
LIMIT 5;
```
