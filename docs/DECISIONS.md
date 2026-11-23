# Furoeducare Decisions

## Security review — 23 November 2026

| Decision | Reason |
| --- | --- |
| Apply document content policy at upload, extraction and original download. | Fixes also cover older stored PDF/DOCX files; valid originals remain byte-identical. |
| Refuse active/opaque PDF features and dangerous DOCX package parts conservatively. | Existing superficial structure checks accepted active content. Legitimate object-stream/encrypted PDFs can now require plain-PDF export, DOCX or manual entry; this limitation is disclosed. |
| Share CSP/frame/referrer headers with early bootstrap errors and reject public session directories. | Failure/configuration paths need the same containment as ordinary pages. |
| Record AV/CDR, OS sandboxing, comprehensive retention and production checks as open work. | No malware scanner, legal retention schedule or deployment evidence is available; inventing a policy or claiming these controls would be misleading. |

Evidence, severity, changed files and exact follow-up are in [SECURITY_REVIEW.md](SECURITY_REVIEW.md). The review does not establish that the application is secure.

## Operational reporting and monetisation readiness

- Replace the report page's raw status-table summaries with defined operational measures. Retain the prior internal read-model method for compatibility, but route the page/export through fresh transactionally checked aggregate queries. State metrics identify their registration/publication cohort; event metrics use submission/confirmation/download dates. Dates are inclusive WAT, with explicit all-time selection.
- Require existing `reports.view` plus each data module's read permission for both HTML and CSV. Omit inaccessible datasets; export only fixed aggregate fields, never request descriptions, account contacts or documents. Audit successful GET export requests, not HEAD or presumed file consumption.
- Count active vacancies using public school/deadline eligibility, retain successful confirmations after placement closure, exclude private drafts, and respect message retention boundaries. Keep current-state and historical-event meanings visible.
- Keep initial services free. Future billing/provider/catalogue entities and interfaces are documentation only; no tables, checkout, payment switch or gateway code is installed. Any later charging requires a new approved offer/version, explicit rollout and preservation of existing free requests/entitlements.

## Account settings security and appearance

- Use local storage for guests and MySQL for account preferences. Account choice overrides guest state; resolve it before styles rather than fetching preferences after paint. Native forms preserve no-JavaScript explicit mode changes and return only to validated internal pages.
- Require current-password reauthentication for settings password, email, contact and deactivation actions. Bind security mutations to session auth_version under user locks; rotate the current session after password/email changes and invalidate other sessions/security links.
- Keep the old sign-in email until its owner confirms a short-lived hashed challenge sent to the proposed address. Require same-account authentication, CSRF and current password at confirmation. Recheck database uniqueness at commit and live challenge scope in the mail worker; never put plaintext tokens in queue metadata/audit logs.
- Preserve existing role profile and publication/review policies. Do not create new school accounts from settings or self-publish teachers. Deactivation requests enter existing authorised privacy review, retain cancellation/history and never bypass last-active-Super-Admin protection. Optional notification channels retain their preferences; required verification mail is independent.

## Dashboard consistency review

- Use one shared dashboard shell and role navigation policy, retaining existing module panels and staff route/service permission checks. Public browsing remains public; signed-in library/directory/service pages can retain dashboard context.
- Remember dashboard category only as a presentation preference, validating current account roles on each request. Existing candidate/directory owner-category selection overrides presentation on multi-role workflows, including direct links and errors.
- Retain server-rendered data and ordinary forms. Loading feedback reflects actual submission/read-receipt requests; do not manufacture delays, sample metrics or asynchronous activity. Preserve clicked submit-button values and private file upload/download behavior.
- Use native accessible dialogs with confirmation fallback and safe no-JavaScript forms. Menu interactions manage focus/background access and recover focus across breakpoints. Avoid high-risk bulk actions and new backend workflows.

## Administrative workspace and permissions

- Retain the coarse `admin` account marker and add explicit staff roles/capabilities. No new administrator receives automatic management permissions. Migrate only the oldest active verified legacy administrator to Super Admin; provide a trusted one-time local bootstrap.
- Protect all built-in role definitions. Custom roles support other combinations, with current-permission delegation limits and no self-assignment/role editing. Staff and role changes invalidate affected sessions.
- Guard every registered management route, deny unknown management mappings, separate read/manage and private document/CV permissions, and repeat permission checks inside mutation transactions.
- Serialise role/security changes on the Super Admin role row and require a remaining active verified Super Admin. Keep account restoration independent of professional verification and its suspension claims.
- Reuse existing workflow controllers inside a shared shell. Add actual scoped reads/search/reports, individual reviewed actions, safe read-only delivery monitoring and redacted audit browsing; avoid bulk sensitive decisions.
- Restrict settings to operational notice/support fields used in the shell. Keep payment activation and secrets outside editable settings. Preserve historical message assignment but send new alerts to qualified staff after permission loss.

## Central notifications

- Extend the existing notification ledger and backfill old keys/categories instead of duplicating event records. Migration sends no historical email.
- Retain hidden ledger entries for events suppressed by in-app preferences. They support deduplication without appearing in counts, links or feed pages. Preferences affect future delivery.
- Store a stable source event key per recipient and a presentation fingerprint. Exact retries preserve existing read/channel state. Changed content under the same key conflicts and rolls back the transaction.
- Read preferences and duplicate ledger rows using current locking reads. Keep email-only legacy saves restricted to their channel so another session's in-app changes survive.
- Use typed internal paths and owner-protected open/read operations. Destination services continue to enforce their current access/release rules. GET opening does not mutate read state.
- Mark-all uses a rendered ID boundary and leaves newer arrivals unread. Older/filtered pages label their bounded action explicitly.
- Reuse six existing notification categories for independent channels. Essential authentication token emails stay outside optional dashboard preferences. New generic security/recruitment templates cover successful password changes and status updates.

## Training and workshops

- Represent packages as ordered module membership. Keep module, package and request statuses separate. A published package with an unpublished component is unavailable.
- Preserve module metadata, submitted order and package title on the request. Later catalogue revisions do not change the requested programme.
- Record approval during Discussion, preserving the requested status workflow. Require an active assignee before approval/scheduling and current school approval before submission/approval/scheduling. Reassignment before scheduling clears approval.
- Support explicit reasoned rescheduling, completion after session end with an outcome, and irreversible terminal cancellation/completion. Capture decision details in history JSON as well as the current request fields.
- Use school-scoped submission tokens and payload hashes so exact retries return the committed request and changed payloads conflict. Notifications and email outbox inserts share the request transaction.
- Keep material descriptions public metadata only. No upload/download system or payment collection is added by this phase. Forms use WAT and supported bounds of 20 selected modules, ten future preferred dates and 1–10,000 participants.

## Digital library

- Start with PDF/EPUB materials and JPEG/PNG covers, using maintained PHP extensions already used by the project. Keep materials outside the public document root and serve both originals and covers through PHP.
- Permit unconfirmed private drafts. Require a licence, private evidence reference and explicit current confirmation before publication. Every metadata or file edit withdraws publication and invalidates grants, simplifying content/rights review.
- Use named-account, resource-revision grants with a maximum 365-day lifetime. A pending school's library access is independent of recruitment approval. Future paid material has no download or payment facility.
- Retain a scrubbed resource tombstone when removing books to preserve ownership/history/statistics relationships. Unpublish/archive retain files; removal queues deletion. Grant history and catalogue/history lists use cursors.
- Count authorised GET responses after successful integrity checks. HEAD, rejected requests and staff previews do not inflate library statistics. Guests have a nullable download owner and no fabricated account or tracked IP.

## Transactional email

- Use PHPMailer through SMTP for production and deterministic private MIME files for development. Both transports use the same templates and stable Message-ID.
- Queue database events in the originating transaction and send through a separate CLI worker. Retry known transient failures for up to five total attempts. Mark ambiguous submissions and expired worker leases Uncertain so they can be reconciled with the provider before a resend.
- Encrypt queued authentication tokens with a separate environment key. Keep token hashes for validity checks and clear the encrypted secret on terminal outcomes. Stop workers and drain or expire pending security mail before key replacement.
- Keep optional preferences in six categories and recheck them at dispatch. Verification and reset remain essential to account access. Provider status records relay acceptance and the available provider transaction ID. Later inbox, bounce and complaint state is reviewed through the selected provider's operations.
- Supply all fourteen requested templates. Training request processing remains a later module, so this phase provides its confirmation template and outbox API.
- Managed interview scheduling emits its invitation from the placement event. Updating the companion application status does not enqueue a second interview invitation.

## Managed messaging

- Use one account holder per thread, with its account role recorded. Staff can create a thread for an eligible account. Conversations have no recipient picker for other account holders. New thread links are checked against the account holder's role and source ownership.
- Allow verified pending schools to contact management. Messaging access uses active account and role checks. Recruitment approval continues to govern recruitment actions on the linked screens.
- Make each administrator's unread state independent. Queue access lets staff triage and claim cases, while the assignee controls replies and lifecycle changes. Read receipts for user messages require a management reader other than the account holder.
- Retain closed conversation content for 365 days by default, with an audited staff preservation hold. This initial product choice needs review alongside the platform's retention schedule. Expiry prevents application access and scheduled cleanup removes content. Metadata and audit records follow the wider retention policy.
- Send in-app notifications through the existing notification service. Message bodies and subjects stay out of notifications and audit logs. Use progressive HTML forms and vanilla JavaScript for read acknowledgement.

## Managed recruitment and placements

- Store one placement per employment application and one current tutoring placement per request. A rejected tutoring placement leaves the request available for another attempt. Confirmed or closed records retain their source reservation. Count successful applications against the approved vacancy's positions.
- Let the owner record selection after staff confirms availability. The assigned administrator can also record a selection made outside the platform and remains responsible for offers, final decisions and closure.
- Require the four home tutoring checks at the current professional verification revision. Authorised staff verify identity and professional references through restricted channels and record a reference to the evidence along with the supervision and emergency checks. Use WAT for scheduling and UTC for timestamps.
- Preserve historic Successful applications and Confirmed tutoring requests in their existing records. The upgrade creates no placement or safeguarding decisions for historical records.

## Candidate requests and controlled disclosure

- Link new school cases to an owned vacancy and create parent cases from submitted tutoring requests. Import older school enquiries and require the school to link its vacancy before staff process the case.
- Keep shortlist publication separate from permission to view restricted fields or an original CV. Grant one scope to one eligible account for one candidate and case. Cap professional profile access at seven days and original CV access at 24 hours.
- Support only independently documented specific consent in this implementation. Keep disclosure off until legal review approves the policy and the deployment configures its version and notice. Signup acknowledgements and request notices do not supply the teacher's lawful basis.
- Suspend further disclosure and permanently revoke active grants when a teacher pauses sharing. Expiry, case closure, CV replacement, material profile edits, loss of eligibility and policy changes are checked on access. Management handles rights requests through the private queue.

## Private tutoring requests

- Use a shared parent request workflow for general and specific-teacher requests. Preserve prior general drafts and submissions, and import prior parent directory enquiries with their teacher references and original notes.
- A new specific-teacher request requires the teacher to support home or online tutoring. Submission checks that the teacher is still available in a requested format. Staff can then shortlist that teacher or suitable alternatives.
- The parent can view public tutor information once the request is shortlisted or waiting for arrangements. Confirmation requires a shortlisted tutor who is still available and professionally verified. Confirmed and Closed are terminal in this workflow. Staff can close a submitted request earlier with a parent-visible reason.
- Prefill no exact address and collect no child identifiers. Use state and general area for home or either mode, plus preferred weekdays and up to three time ranges in Nigeria time. Staff notes on status changes are visible to the parent.

## Teaching vacancies

- The application deadline includes the entire selected calendar date in the Africa/Lagos time zone. Expired vacancies leave the public board automatically. A school or administrator can close a published vacancy and retain its history.
- Saving changed content on a published or approved vacancy returns it to draft. Submitting, reviewing, approving and publishing the new version are separate actions. A save with identical published content preserves the original revision.
- Staff progress applications in order through Submitted, Under review, Shortlisted, Interview invited, Offer pending and Successful. Staff can mark an application Unsuccessful once review begins. The applicant can withdraw any active application, including an offer pending. Successful, Unsuccessful and Withdrawn are terminal. The unique vacancy and teacher pair applies after withdrawal and after republication.
- Staff confirm employment in the managed placement workspace, which records Successful on the application in the same transaction as the final decision. Applicants can reach earlier stages while professional verification is pending.
- A random candidate reference identifies the candidate within one application. Store the vacancy and approved professional fields as they were at submission. Leave the profile snapshot missing on older records where it was never collected.
- Saved jobs belong to the teacher account. If a vacancy becomes unavailable, the saved list keeps its previously published title and removes the public link. The teacher can remove the bookmark.
- The applicant and authorised management staff can read application status history. Schools manage their posting and receive its moderation notes. Management retains control of candidate information and shortlisting.

## Initial PHP foundation

16 November 2026

| Decision | Rationale |
| --- | --- |
| Use PHP 8.5, PDO MySQL and Composer PSR-4 autoloading. | This follows the approved stack. Core PHP covers the initial application without third-party PHP runtime libraries. |
| Wire MVC components through explicit constructor injection in `bootstrap/app.php`. | The initial dependency graph fits in one composition file. |
| Read process variables first, then the local flat `.env` file, then documented defaults. | Literal values make credentials predictable. Process settings let deployments supply secrets and select production mode. |
| Use exact-path HTTP routing with POST-body `_method` overrides. | The first routes are static. Browser forms can express PUT, PATCH and DELETE with native HTML. |
| Make `/health` a liveness check. | Operators can verify that PHP, configuration and routing are working independently of database availability. |
| Log failures with a correlation ID and a safe diagnostic message. | Developers can trace a server failure while the public production response stays generic. |

## MySQL foundation

16 November 2026

| Decision | Rationale |
| --- | --- |
| Reuse the PDO connection factory and set each connection to UTC. | Native prepared statements and one timestamp convention apply across migrations, seeds and future modules. |
| Keep one DDL statement per migration and journal its intermediate state. | MySQL commits DDL independently of DML. A durable marker lets operators identify unfinished work after a failure. |
| Keep PHP sessions file-backed for the initial single-host deployment. | The foundation has no database session table. Session initialization and cookie policy belong to the authentication phase. |
| Model roles as assignments and seed only reference roles. | The same identity can hold multiple authorisations. Future registration and management workflows will control which assignments are permitted. |
| Use hard deletion with explicit FK reactions. | No current requirement calls for soft deletion. Audit events survive actor deletion and obsolete token and role assignments are removed. |

## Public website

16 November 2026

| Decision | Rationale |
| --- | --- |
| Share server-rendered public page components and a single theme. | One header, footer, card, breadcrumb and form presentation keep navigation and accessibility consistent. |
| Use editorial classroom photography and a green and warm neutral palette, with Bricolage Grotesque and DM Sans. | The visual identity ties the recruitment pages to teaching and learning in Nigeria. The photographs are generated illustrations of educational settings. |
| Use empty states for listings and browser-only form previews. | Visitors can learn about services and try the interface while operational records and workflows await their implementation phases. |
| Keep the legal pages visibly marked as drafts. | Final wording requires legal review before it can be presented as policy or terms. |

## Shared authentication

16 November 2026

| Decision | Rationale |
| --- | --- |
| Require mailbox verification before account activation and collect the password and new public role during verification. | A person who starts registration for another address cannot assign that address credentials or a role. Existing operational role assignments are preserved. |
| Keep school approval independent of authentication. | New school accounts start pending and their dashboard reads the approval record. School onboarding defines the institution match. |
| Keep PHP sessions file-backed and recheck the account version, suspension and roles on protected requests. | The initial single-host deployment can revoke sessions after password reset or suspension and reflect changes to authorisation. |
| Keep delivered links valid through their expiry and revoke all remaining links of the same type after one succeeds. | Email delivery delays or failed resends cannot invalidate a usable link. |
| Spool development mail in private storage and use the server's PHP mail transport in production. | Local tests can exercise real account links without external email. Production delivery uses the operator's configured sender and MTA. |

## Teacher and NYSC onboarding

| Decision | Reason |
| --- | --- |
| Share the teacher role for Teacher and NYSC Member. | The two categories use the same authorisation. NYSC service batch, state and year are optional profile fields for NYSC members. |
| Record the draft Terms and Privacy Notice revisions at confirmation. | The profile preserves what the user accepted and acknowledged. Both documents remain visibly marked as drafts pending legal review. |
| Require each of the ten sections for full completion. | A CV is the required document. Supporting files, certifications and NYSC details are optional. Teachers can declare that they have no work or teaching experience yet. |
| Accept PDF and DOCX CVs up to 5 MiB and add JPEG and PNG for supporting documents. Cap each account at twenty supporting documents. | File validation and owner-only storage support manual onboarding. Teachers can save profile sections before uploading a CV. |
| Keep one current CV. | Uploading a replacement updates the private file record and removes the old copy. Management access, retention policy and extraction are separate decisions. |

## CV extraction

| Decision | Reason |
| --- | --- |
| Run a private PHP CLI job per CV. | Parser failures and CPU work stay outside the upload and review requests. |
| Use the PrinsFrank PDF parser and PHP ZIP and XMLReader for DOCX. | The readers process supported formats locally and use bounded input. The extraction interface supports later reader or strategy changes. |
| Keep seven-day drafts and merge only at confirmation. | Teachers can correct incomplete suggestions before saving. Existing profile data takes priority by default. |

## Teacher dashboard

| Decision | Reason |
| --- | --- |
| Track professional verification independently from email and publication. | A nullable professional verification timestamp reflects the current status without letting teacher-facing forms grant verification. |
| Allow concurrent teaching and tutoring availability choices. | Teachers can select any compatible combination. Temporarily unavailable is exclusive, and previously saved schedules remain available. |
| Show pending sections and counts as informational. | Unimplemented modules provide context without displaying fabricated recruitment data or controls. |

## School onboarding

| Decision | Reason |
| --- | --- |
| Match by normalized institution name, state and city. | The signup has no official school identifier. The database prevents concurrent duplicates using those fields. |
| Keep recruitment drafts separate from active recruitment. | Pending schools can prepare requirements while the approval policy blocks active or restricted workflows. |
| Record decisions with revision checks. | Administrators review the current profile. A decision records its reason, reviewer and timestamp. |
| Use review decisions as school notifications. | Schools receive their own status updates without a new general-purpose notifications module. |

## Parent accounts

| Decision | Reason |
| --- | --- |
| Collect only adult account information at registration. | A parent profile provides independent identity, contact and consent fields. |
| Separate saved drafts from explicitly submitted requests. | Parents can review their own draft before submission. The submission checks current email verification and the reviewed version. |

## Administrator verification

| Decision | Reason |
| --- | --- |
| Ask teachers to submit a complete profile. Keep application eligibility independent of submission and professional decisions. | Teachers control when staff start review and can apply with a complete profile and verified email. |
| Verify identity, qualifications and professional history or declared lack of experience. Require staff acceptance of the current CV. | Staff confirm professional evidence. Optional supporting documents have their own review decisions and may inform these checks. |
| Return changed professional evidence to review. Keep routine preference and availability changes that preserve completeness outside this rule. | Verification applies to the information staff reviewed. Teachers can keep their scheduling preferences current. |
| Confirm the institution and its representative and contact details before school approval. | Changes to reviewed details return the school to pending approval. |
| Share decision notes with the account owner and retain attributed staff history. | Teachers and schools can make corrections. General audit events store IDs, status changes and checked items. Deleted files stay unavailable while their review record retains the decision. |
| Require a recorded teacher verification decision even if a legacy timestamp is present. | The placement gate requires proof of explicit checks on the current profile revision. |

## Public teacher directory

| Decision | Reason |
| --- | --- |
| Give each teacher one stable, random public reference. | Direct links, bookmarks and enquiries continue to identify the same listing without exposing the account ID. |
| Require documented listing authorisation and explicit permission for the name or portrait. | Staff can publish the professional listing with either optional identity field omitted. |
| Publish a curated field list and read current availability for each listing. | Staff review the public copy. A teacher's availability changes take effect without a second approval. |
| Keep professional verification and directory publication as independent staff decisions. | Staff control directory participation separately from final placement eligibility. |
| Give pending schools private drafts and allow active requests after approval. | Schools can prepare an enquiry without bypassing recruitment restrictions. |
| Keep submitted intake read-only during this phase. | A recorded request can be shown consistently while processing and lesson coordination are developed later. |
