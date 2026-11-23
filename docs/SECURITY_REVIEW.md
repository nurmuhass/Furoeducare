# Security review — 23 November 2026

This is a source review and targeted regression assessment of the current working tree, not a security certification or a production penetration test. Confirmed weaknesses were reproduced with synthetic files or isolated fixtures and fixed. No real personal documents, production database or live email provider were used. Existing unrelated working-tree changes were preserved.

## Scope and method

Reviewed authentication, sessions, reset and verification tokens, CSRF, database queries, output escaping, uploads and downloads, roles and administrative permissions, object ownership, public directory projection, controlled disclosure, placements, messaging, rate limiting, errors, logs, secrets and retention. Tests used PHP 8.5.12 and isolated loopback MySQL 8.4.13 schemas with all 86 existing migrations. No migration or production data change was required.

Severity considers both impact and prerequisites: **High** can expose recipients to dangerous original documents through a normal upload; **Medium** needs a specific configuration, local filesystem capability or a downstream reader; **Low** is additional containment. No remote code execution or successful account takeover was demonstrated. Passing a fixture proves that case, not every possible payload.

## Confirmed findings and remediation

| ID | Severity | Finding and evidence | Remediation and status |
| --- | --- | --- | --- |
| SEC-01 | High | A valid, selectable-text PDF containing an automatic JavaScript action passed CV/library structure validation and extraction. Original distribution could expose a recipient's PDF reader to active content. | **Fixed:** bounded PDF lexical checks reject raw and escaped active-content names, embedded files, external actions, encryption and opaque object streams. Extraction revalidates before parsing. Synthetic action and escaped-name tests changed from failing to passing. This is a conservative format policy, not a malware detector. |
| SEC-02 | High | DOCX validation ignored additional archive parts. External templates, embedded objects/macros, traversal names, oversized unused parts, DTD/entity declarations and symlink entries could accompany an otherwise ordinary CV. | **Fixed:** inspect the whole ZIP directory and every XML/relationship part; reject dangerous names, links, encryption, duplicate names, active relationships and executable parts. Bound entry count, individual expansion and total expansion. Disable DTD/entity loading. Preserve ordinary HTTP/HTTPS/mailto hyperlinks. Seven malicious package variants and valid DOCX controls pass. |
| SEC-03 | Medium | Teacher private reads followed a key symlink or nonregular file if an attacker already had write access to private storage. Random identifiers alone did not prevent this. | **Fixed within PHP's limits:** reject symlink/nonregular keys; compare opened file identity, regular-file mode and expected length; bound reads; set restrictive upload permissions. Nonregular-file test passes. Actual filesystem symlink test is skipped on this Windows runtime; concurrent directory replacement still requires OS permissions to prevent. |
| SEC-04 | Medium | EPUB validation accepted ZIP symlink metadata, potentially dangerous to a downstream reader/extractor. The server does not extract EPUB archives. | **Fixed:** reject symlink entries while retaining existing traversal, encryption and expansion checks. Synthetic archive test passes. EPUB content is not comprehensively sanitized; see open risks. |
| SEC-05 | Medium | Upload-only fixes would leave previously stored active PDF/DOCX originals downloadable. Reproduced with old synthetic teacher and library files. | **Fixed:** validate the exact bytes read before serving stored teacher PDF/DOCX and library PDF originals. DOCX validation uses a bounded private temporary snapshot. Invalid teacher originals return unavailable before successful disclosure logging; invalid library files fail before download recording. Valid files transfer byte-for-byte unchanged. No batch retrospective scan was performed. |
| SEC-06 | Medium | HTML and bootstrap-failure responses lacked a shared CSP/frame policy and explicit referrer suppression. This reduced containment and could disclose token-bearing URLs through browser referrers. No separate XSS exploit was demonstrated. | **Fixed:** shared self-origin script CSP, object/base/form/frame restrictions, frame denial, no-referrer and nosniff; remove PHP version response header. Apply to normal responses and early errors. Keep stricter attachment sandbox headers. HTTP assertions pass; actual browser CSP enforcement was not exercised in this phase. |
| SEC-07 | Medium | Configuring session storage beneath `public/` created session files there rather than rejecting the unsafe location. Exploitation depends on unsafe deployment configuration and static-file serving. | **Fixed:** reject lexical public paths and resolved public ancestors before creation and recheck after creation. The failing configuration now returns a generic 500 without creating the directory; normal authentication/session tests still pass. Web-server aliases and filesystem races remain deployment responsibilities. |
| SEC-08 | Low | Teacher attachment filenames were trusted after their upload-time validation. | **Fixed:** sanitize again at response construction; add nosniff and attachment sandbox CSP. Existing private/no-store and recipient authorization remain enforced. |

### Compatibility and document-processing limits

Some otherwise legitimate PDFs use compressed object streams (`ObjStm`), encryption or text resembling forbidden PDF names. They are now refused conservatively. The interface explains re-exporting a plain text PDF, uploading DOCX or completing the profile manually. PDF/DOCX remain supported formats; this restriction is an explicit security tradeoff, not a claim that rejected documents are malicious. Scanned PDFs still use the documented OCR-unavailable/manual fallback.

The extraction subprocess has shell-free arguments, a 128 MiB PHP memory limit, a 30-second parent deadline and bounded schema-validated output. It is **not an OS sandbox**: filesystem/network access and native-library vulnerabilities are not contained by these measures alone.

## Coverage and conclusions

| Area | Evidence reviewed or executed | Conclusion and boundary |
| --- | --- | --- |
| Authentication/passwords | `AuthService`, `AuthInput`; duplicate case-normalized accounts, incorrect password behavior, reauthentication and suspension tests | PHP password APIs and server validation are used; no plaintext password storage found in reviewed paths. No exhaustive credential-stuffing or distributed load test. |
| Sessions | `AuthSession`; HTTP cookie rotation, old-cookie refusal, idle/absolute expiry, suspension/reset invalidation and production cookie attributes | Strict cookie-only sessions, regeneration and account-version checks operate in tested cases. Production Secure/HttpOnly/SameSite attributes verified as headers, not over real HTTPS. |
| Reset/email verification | Expiry, replay, email binding, password replacement, session revocation; encrypted pending email changes and stale worker suppression | Tokens are expiring/single-use in tested flows; GET email-change inspection does not consume the challenge. Concurrent claims and live provider delivery were not stress-tested. |
| CSRF | Authentication HTTP tests and administrative/settings regression suites; mutating controllers reviewed | Missing/invalid CSRF is refused in tested mutations. SameSite cookies supplement rather than replace token checks. Not every route was independently fuzzed. |
| SQL injection/database access | PDO connection and query callers; native prepared statements, disabled emulated/multiple statements, validated date/search input and fixed identifier allowlists | No confirmed SQL injection in reviewed callers. No automated exhaustive SQL-injection scanner, production grants, remote DB TLS or database-server configuration assessment. |
| XSS/browser containment | Escaping helper, public/dashboard/settings templates, JS DOM sinks; escaped-field view tests and new HTTP header assertions | Reviewed user text is escaped or assigned using textContent. No confirmed stored/reflected XSS found. CSP still permits inline styles and approved CSS/font origins; it is not proof of complete XSS prevention. |
| Uploads/extraction | 50 security file checks plus extraction/library-file suites | SEC-01–05/08 addressed. MIME/extension/content/size, private storage, archive expansion and image re-encoding reviewed. No antivirus/CDR or adversarial native codec fuzzing. |
| Downloads | Real original-CV service and HTTP transfers; stored malicious-file denial; library integrity checks | Controllers authorize delivery; approved originals remain unchanged. No public original-file URL is introduced. Production aliases/ACLs remain unverified. |
| Public teacher privacy | Explicit projection, anonymous HTML markers and photo withdrawal tests | Private account IDs, contacts, address, CV, profile sections and staff evidence are excluded in tested responses. Name/photo require their publication authorizations. |
| School/parent ownership | Cross-school vacancy read/edit/submit; cross-parent tutoring, candidate case, conversation and linked context tests | Unrelated IDs are denied by both service checks and representative HTTP endpoints. Pending schools cannot submit an active recruitment case. Not every possible ID/action combination was enumerated. |
| Roles/admin permissions | `StaffAccess`, `StaffRoutes`, `StaffManagement`; admin-policy and full admin regression | Verified active staff need explicit permissions; unknown management routes fail closed. Reviewed delegation, no-self-change, immutable built-ins, last-Super protection and session-version invalidation. Production staff assignments not inspected. |
| Candidate information/CV grants | 78 workflow checks: recipient, scope, expiry, revision/document, policy, current case, suspension, revocation and privacy withdrawal | Profile-only grants cannot download originals; copied identifiers do not grant access. Exactly one matching audit/download record per successful tested transfer; denied transfers add no success record. Concurrent download/revocation race not executed. |
| Placement checks | Complete unverified teacher denied; complete verified current-review teacher eligible; stale review denied in database transactions | Final eligibility requires current professional checks and accepted CV. The full placement state-machine lifecycle was not rerun in this phase. |
| Messaging/request ownership | Owner-scoped reads/sends/context binding; escaped bounded text; full admin and message-input tests | No unrestricted teacher-to-school/parent chat introduced; unrelated conversations and contexts denied. Retention legal holds and production scheduler operation not verified end to end. |
| Rate limiting | Authentication/security/admin suites and controller/throttle source review | Login and sensitive-operation scopes exist and representative limits are tested. Proxy IP trust, distributed concurrency, denial-of-service capacity and all per-route limits require deployment/load validation. |
| Error handling/logging | Production malformed-config HTTP test and correlated application log; structured logger/error handler review | Generic failures avoid stack/secret output. Application errors minimize exception detail. Access logs can still record query tokens; see open item SEC-O2. |
| Secrets/environment | Tracked-file check; redacted configuration inspection; encryption tests | Only `.env.example` is tracked among `.env`, storage and vendor paths checked. Local mail encryption/disclosure activation is not configured and gated services remain unavailable; this does not establish production readiness. Git history, external secret stores and backup copies were not exhaustively scanned. |
| Personal data/retention | Privacy controls, deactivation requests, message retention, CV draft cleanup and durable removal queues reviewed | Controls exist but there is no complete approved retention/erasure schedule demonstrated for all personal data and backups. See SEC-O3. |

## Open risks and required follow-up

| ID | Priority | Outstanding action / owner |
| --- | --- | --- |
| SEC-O1 | Medium; before production document distribution | Operations/security: add maintained malware scanning and a quarantine/reviewer process, assess CDR and an OS-confined parser service. Inspect EPUB reader-active/external content before publication. Current lexical/structural checks are incomplete defenses. Teacher private JPEG/PNG originals are attachment-only and are not re-encoded like public portraits/covers. |
| SEC-O2 | Medium; before public traffic | Operations: redact query strings and credentials from web/proxy/CDN/error/access logs; restrict ACLs, rotation and retention. Local PHP development access logs contain synthetic token-bearing request URLs. Browser no-referrer does not redact server logs. Do not publish local test artifacts or mail spools. |
| SEC-O3 | Medium; policy and operations | Management/privacy lead: approve purpose-based retention and deletion/legal-hold rules for profiles, identity/CV originals, grants, audit records, mail, logs and backups. Existing seven-day extraction drafts and configurable message retention are not a complete policy. Demonstrate scheduled cleanup, rights-request resolution and backup expiry/restoration handling. No arbitrary blanket deletion was added. |
| SEC-O4 | Medium; deployment verification | Operations: verify `public/` as the only web root, no private aliases, private session/upload/temp/mail/log ACLs, HTTPS/proxy trust, TLS/HSTS policy, least-privilege runtime DB account, separate migration principal, SMTP TLS, backup protection, native runtime patch levels and actual scheduled workers. Validate production Apache/Nginx behavior independently of the PHP development router. |
| SEC-O5 | Low; requires private-storage write access | Engineering/operations: teacher originals have size/identity checks but no persisted content hash, unlike library files. Same-length local replacement is not reliably detected. Add a migration/backfill design for digest verification if this threat is in scope; maintain exclusive storage ownership meanwhile. |
| SEC-O6 | Further assurance | Security: browser CSP compatibility/enforcement, larger malicious-file corpus, OS symlink/junction/race tests, simultaneous token/grant/revocation tests, comprehensive endpoint authorization fuzzing and independent penetration testing remain unperformed. |

Example query-free web access formats, to adapt and validate outside this repository:

```nginx
log_format furo_safe '$request_method $uri $status $body_bytes_sent $request_time';
access_log /var/log/nginx/furoeducare-access.log furo_safe;
```

```apache
LogFormat "%m %U %>s %B %D" furo_safe
CustomLog /var/log/apache2/furoeducare-access.log furo_safe
```

These formats omit query arguments. Do not append raw request lines, query-bearing referrers, cookies or authorization headers. Review other logs separately; an access format alone does not protect reverse-proxy/error/provider logs. File location, access control and retention still need configuration. See [Nginx log module](https://nginx.org/en/docs/http/ngx_http_log_module.html) and [Apache log format directives](https://httpd.apache.org/docs/2.4/mod/mod_log_config.html).

## Executed checks

Commands below use the supported PHP installation, not the older default XAMPP PHP. Database suites need disposable test-schema credentials supplied through environment variables; never copy production credentials into tests.

| Check | Result |
| --- | --- |
| `php tests/security-core.php` | PASS: normal/error headers, seven nonpublic path refusals, generic production configuration failure and unsafe session-directory refusal. |
| `php tests/security-auth.php` | PASS: verification expiry/binding/replay; duplicate accounts; session rotation/CSRF/idle/absolute expiry/production cookies; reset expiry/replay/session revocation; suspension; encrypted challenge/stale delivery checks. Rerun after session-directory fix. |
| `php tests/security-workflows.php` | PASS: 78 service/HTTP privacy, ownership, grant and placement checks. |
| `php tests/security-files.php` | PASS: 50 checks; filesystem symlink creation explicitly SKIPPED for missing Windows privilege. ZIP symlink metadata and nonregular-file tests executed. |
| `php tests/cv-extraction.php`, `php tests/library-files.php` | PASS: valid PDF/DOCX/EPUB controls, Unicode, missing/reordered/multiple sections, scanned fallback, private paths and integrity. |
| `php tests/foundation.php` | PASS: public routes, legal draft labels, safe errors/logging, HTTP verbs/form overrides and environment parsing. |
| `php tests/admin.php` | PASS: all five reported groups, including staff scopes/delegation, role changes, last-Super protection, monitoring and trusted CLI bootstrap. Ran after file/header fixes; targeted authentication suite reran after the later session-path guard. |
| `php tests/settings.php` | PASS: all seven reported groups, including sensitive changes/session invalidation, privacy and organization ownership. Same sequencing as admin suite. |
| Focused policy/input/view/crypto checks | PASS: admin-policy, settings-input, settings-views, email-crypto, message-input, library-input and notification-input. |
| `php tests/reporting-http.php` | PASS: scoped report access, permission revocation and CSV; free registration/verification queue, anonymous original PDF download, restricted/reserved resource denials and disabled payments after file-policy changes. |
| `php tests/report-csv.php`, `php tests/report-period.php` | PASS: formula-safe/escaped CSV and strict bounded date input. |
| PHP lint / Composer configuration and platform | PASS: all 446 PHP files under application, CLI, configuration, migration, public, view and test directories; `composer validate --strict`; `composer check-platform-reqs`. |
| `git diff --check` | PASS; existing unrelated dirty files preserved. |
| `composer audit --locked --format=json` | PASS: returned zero known advisories and zero abandoned packages. Point-in-time Composer metadata check, not a PHP/MySQL/OS or unknown-vulnerability scan. |

An initial standalone `notification-invariants.php` invocation refused to run without its specifically named migrated test schema. It did not assess application behavior; this review does not count that suite as passed. Full empty-schema teacher/library/placement suites and every legacy suite were not rerun. Fresh migration/rollback testing was not repeated because this phase changes no schema.

## Reproducing tests safely

1. Install locked dependencies using a supported PHP CLI with PDO MySQL, Fileinfo, ZIP, XMLReader and GD. Run `php tests/security-core.php`, `php tests/security-files.php`, `php tests/cv-extraction.php`, `php tests/library-files.php` and `php tests/foundation.php` from the project root. HTTP tests start/stop their own loopback server; allow local process/network access.
2. Create a disposable, empty `furoeducare_test_admin_<12 hex characters>` schema on loopback MySQL. Set development `APP_ENV`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in the current shell. Run `php tests/admin.php` once to migrate/seed its guarded empty schema, then `php tests/security-auth.php`. The security suite creates uniquely identified fixtures and removes its own records; it does not reset a schema.
3. For workflow tests, additionally set synthetic `DISCLOSURE_POLICY_VERSION`, `DISCLOSURE_POLICY_APPROVAL_REF`, HTTPS `DISCLOSURE_PRIVACY_NOTICE_URL` and `DISCLOSURE_PRIVACY_CONTACT`. Use clearly fictitious test values, then run `php tests/security-workflows.php` on the same migrated disposable schema. These values are test fixtures, not legal approval. Do not activate real disclosures without the reviewed policy described in [DISCLOSURE_POLICY.md](DISCLOSURE_POLICY.md).
4. Never run reset/migration fixtures against real users or a shared production schema. Preserve private test logs only as needed, then remove them through the local test environment's retention process. Run `composer validate --strict`, `composer check-platform-reqs` and `composer audit --locked` separately.

## Exact manual verification steps

Use synthetic accounts: two teachers, two approved schools, one pending school, two parents and staff with different permissions. Use synthetic CVs only.

1. Publish a teacher with authorized public professional fields but name/photo permission disabled. In a signed-out browser inspect the page and network response: no contacts, address, CV link, certificates, private profile data or staff notes. Enable name/photo deliberately, then withdraw publication, availability and photo authorization in turn; confirm hidden results and inaccessible photo URLs.
2. In school A, create a vacancy; copy its URL/ID into school B and attempt view/edit/submit. Repeat with parent A's tutoring request, candidate case and conversation as parent B. Expect 403/404 without unrelated content. Try a direct active request submission as the pending school; it must be refused even if its UI is bypassed.
3. As a limited staff member, try staff-role changes, original-document review and CV decisions without their individual permissions. Expect server refusal. As an authorized staff member, verify no self-escalation, unauthorized delegation or removal of the last active Super Admin. Confirm audit history for permitted changes.
4. Complete a teacher profile and accept its CV but leave professional verification pending. Try final placement confirmation: it must fail. Record all required current checks, verify professionally and follow the managed placement process. A requester selection alone must not confirm placement; changing the profile revision must invalidate stale verification.
5. With a synthetic approved disclosure policy, request an original CV as eligible recipient A. Approve a short-lived, document-specific grant as staff. Download as A; verify the exact original and matching grant/recipient/document audit record. Try the URL as guest/B, a profile-only grant, expiry, revocation, replacement of the CV and teacher privacy withdrawal. Each must deny access, without a successful download record.
6. Upload normal synthetic text PDF/DOCX and run the worker; verify editable suggestions/manual fallback. Use the harmless generated active-action and external-template fixtures in `tests/security-files.php`; upload and old-file download must refuse them. Re-export unsupported PDFs or use DOCX/manual entry; do not test real malicious payloads on a workstation.
7. Inspect response headers for home, login, dashboard, 404, bootstrap 500 and attachments. Verify framing/inline script execution is blocked in an actual browser and external local scripts still support navigation and themes. This browser check remains pending in this review.
8. Under production HTTPS, inspect Secure/HttpOnly/SameSite cookies; log in and verify session ID changes, log out and replay the prior cookie. Test reset/verification expiry and replay, missing CSRF, suspension, idle timeout and password-change invalidation. Inspect private application logs for correlated generic errors without credentials/tokens.
9. On the actual Apache/Nginx deployment, request `/.env`, `/.git/config`, `/storage/...`, `/vendor/...` and configured private-file aliases directly; verify denial. Confirm no directory listings and no private root exposure through another virtual host. Check log query redaction and OS ACLs, including temp files and session paths.
10. Have operations/privacy owners demonstrate restricted DB grants, SMTP TLS, secret rotation, worker schedules, quarantine/scanning, retention/legal holds, rights-request handling and backup expiry/restore procedures. Record evidence before production sign-off.

## Files created or modified for this review

- Added `app/Core/SecurityHeaders.php`; integrated it in `Response.php`, `ErrorHandler.php` and `public/index.php`.
- Hardened `app/Modules/Auth/AuthSession.php` private storage placement.
- Hardened `Teacher/Cv/CvContent.php`, `Teacher/Cv/RuleBasedExtractor.php`, `Teacher/TeacherDocuments.php`, `Library/LibraryFiles.php` and `Library/LibraryService.php`.
- Added `tests/security-core.php`, `tests/security-auth.php`, `tests/security-files.php`, `tests/security-workflows.php`; extended the synthetic PDF fixture helper in `tests/support/cv-fixtures.php`.
- Added this review; updated project requirements, architecture, decisions, progress, test plan, CV/library documentation and README. Other dirty files predate this review and are not attributed to it.

Reference guidance: [OWASP file upload controls](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html) and [OWASP HTTP security headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html). These inform layered controls and deployment follow-up; they do not certify this implementation.
