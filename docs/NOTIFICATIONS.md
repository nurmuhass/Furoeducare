# Central notifications

## Design and implementation plan

Use one event-driven PHP notification service and the existing email outbox. Preserve existing dashboard events in `verification_notifications`, extending that legacy-named table into the central notification ledger. A stable source event key plus recipient prevents duplicates. Record the event even when in-app delivery is disabled so retries cannot later create another copy. Email and in-app preferences use the same six categories but are independently configurable. Authentication verification/reset delivery retains its existing essential-email behavior.

The central event service validates presentation fields, category and internal navigation, records channel decisions and queues approved generic email payloads in the caller's business transaction. Existing verification events become a compatibility adapter. Vacancy/application histories, candidate requests, account activation/password reset and existing messaging/placement/training events emit through the central service. Source history IDs or revisions distinguish legitimate repeated updates from retries. No controller inserts notifications directly.

Registered users have an authenticated `/notifications` feed, unread count, owner-only mark-read and mark-all-read actions, cursor pagination and `/settings/notifications` independent channel preferences. A shared header bell shows unread count, including the teacher workspace header. Historical dashboard notification tabs link or redirect to this feed. Notification destinations are internal server-selected paths; every target retains its own fresh authorisation. Ownership applies to feed, count, reading and opening a notification.

1. Extend the ledger in migration 0074, backfill legacy records in 0075 and add in-app preferences in 0076. Backfill unique legacy keys, categories and safe dashboard navigation without duplicating old records or resending emails.
2. Implement pure event/link validation, central event persistence/deduplication, independent channel preference storage and owner-scoped inbox operations. Test validation and policy first.
3. Adapt existing domain events and fill gaps for verification/security, vacancies/applications, candidate requests/shortlists, interviews, disclosure, messaging, placements and training. Reuse outbox retry/idempotency and email preference checks.
4. Add feed/read/open/count/preferences routes, shared bell, dashboard links and accessible paginated views. Preserve compatible email-only preference submissions.
5. Test duplicate and conflicting event keys, independent channels, ownership/CSRF, pagination/count/read actions, target links and transactional rollback. Run migrations and affected workflow regressions, then document setup/results/manual checks and stop for review.

## Setup and operation

Use the existing PHP 8.5/MySQL 8.4 environment. No new Composer package or background process is required. Back up the database, stop application writes, run `php bin/database.php migrate` for migrations 0074–0076 and restart the app. Existing notification records remain in place. Their migration assigns unique legacy keys and categories and sends old links through the authenticated role dashboard. Migration does not send historical email.

The shared site header and teacher workspace show a notification bell for signed-in accounts. Its server-rendered count refreshes on navigation. `/notifications/count` provides an authenticated JSON count for future progressive enhancement; this release does not require polling or WebSockets. Legacy teacher, school and parent Notifications tabs redirect to `/notifications`.

| Route | Behavior |
| --- | --- |
| GET `/notifications` | Owner feed, 20 visible items plus cursor, optional category/unread filters |
| GET `/notifications/count` | Current owner's unread visible count |
| GET `/notifications/open?id=…` | Validate ownership and internal destination, then redirect; does not mark read |
| POST `/notifications/read` | CSRF-protected `read` or `all` action for the current owner |
| GET/POST `/settings/notifications` | Independent in-app/email category preferences |

Mark-all uses the page's visible ID boundary, so later arrivals remain unread. The newest unfiltered page provides Mark all as read. Older/filtered pages explicitly offer marking those and earlier notifications, across categories. Opening a linked page does not grant access to its data; the destination controller still checks current role, ownership, approval and release permissions. External/protocol-relative/encoded/traversal URLs and unrelated actions are rejected by the notification validator.

In-app preferences affect future events. Previously delivered notifications remain until read; opting out does not delete the user's history. Hidden ledger records preserve source identity and stay outside the feed, count, read and open operations. Re-enabling a category does not replay events suppressed earlier. An email opt-out at emission prevents queuing that optional mail, and the existing email worker checks the latest email preference again before sending queued optional messages. Essential verification and password-reset links retain their existing behavior.

Email-only legacy settings submissions write only the email table. Independent settings save both channels atomically. Emission acquires the recipient lock and uses current locking preference reads, so an older business transaction cannot ignore a subsequently committed opt-out. Exact duplicate events return the existing ID and keep read/channel state; a reused key with different presentation reports a conflict and rolls back the caller transaction. The duplicate lookup also uses a current read when another connection committed the event after the caller's original snapshot.

Run `php bin/email-worker.php 50` using the existing local file transport to inspect email. Production continues to use the configured SMTP provider, queue retry rules and scheduled worker. See [email operations](EMAIL.md). Email payloads contain approved navigation/status fields, never arbitrary context, private messages, requirements, CVs or security tokens from dashboard events. Authentication token mail uses its existing encrypted transport.

## Central event contract

`NotificationCatalog::describe()` supplies category, path, template and stable source identity. It uses message IDs, document/grant/disclosure IDs, domain history/audit IDs or record revisions. This distinguishes valid repeated scheduling/approval updates from retries. Source keys are hashed and unique with the recipient. `NotificationEvents::record()` invokes that catalogue; `emit()` is the low-level service for known source keys such as staff intake events. Both join an existing PDO business transaction or create an atomic standalone transaction.

`VerificationEvents::record()` remains an audit-compatible adapter to the central event service. `EmailEvents::staff()` now emits both channels centrally. Its legacy email-notification adapter uses the same catalogue for presentation, avoiding a second event mapping. Account activation emits an Email verified update and optional welcome email. Successful password reset emits a generic Password changed security alert after incrementing the authentication version. Failed/repeated single-use token attempts do not emit successful account changes.

| Event sources | Relevant recipients |
| --- | --- |
| School and teacher/document verification | Owning account |
| Vacancy changes | Owning school |
| Application submission/stages/interviews | Applying teacher; authorised staff receive new-application intake |
| Candidate requests and released shortlists | Requesting account and appropriate management intake |
| CV information decisions/revocation | Approved request recipient |
| Managed messages | Thread owner or management participants |
| Placements/discussions | Teacher and requesting account once the workflow permits release |
| Training requests/decisions/assignment | Owning school and appropriate staff |
| Activation/password changes | Affected account |

Unreleased shortlist candidates and private documents are not exposed by notifications. The existing business services decide recipients and use their normal authorisation and release gates.

## Automated tests

Run `php tests/notification-input.php`, `php tests/notification-catalog.php`, `php tests/notification-render-paths.php`, `php tests/notification-views.php` and `php tests/training-notifications.php` for pure validation, mappings, template navigation and rendering.

`php tests/notifications.php` requires `APP_ENV=development`, `DB_HOST=127.0.0.1`, matching `DB_PORT/DB_USERNAME/DB_PASSWORD`, and an **empty disposable** MySQL 8.4 schema named `furoeducare_test_notifications_<suffix>`. It creates/seeds the schema and starts isolated HTTP sessions. Run `php tests/notification-invariants.php` and `php tests/notification-domain.php` afterward with the same migrated schema environment. The invariant suite uses two connections to reproduce repeatable-read interleavings. The domain suite exercises managed interviews, candidate claim retries and training services. Use a separate empty disposable schema for `php tests/database.php` to verify all 76 migrations and upgrade/rollback/backfill behavior.

## Exact manual verification

1. Apply migrations and start the local server. Sign in separately as teacher, school, parent and administrator. Confirm each dashboard header bell links to `/notifications` and shows that account's unread count. Check a pending school can open its own notification feed.
2. Generate synthetic school/teacher verification decisions, vacancy/application changes, a released shortlist, a CV access decision, a managed message, an interview/placement update and a training decision. Verify relevant recipients and authorised destinations. Unrelated accounts must not receive or see the updates.
3. Open `/settings/notifications`. Enable Messages in-app and disable its email channel. Send a synthetic managed message and verify a feed item without new mail. Reverse the channels and send another message; verify email without a visible feed item. Disable both, emit another event, re-enable and confirm the suppressed event is not replayed. Existing delivered history should remain.
4. Mark one item read, refresh and confirm count decreases. Repeat the same action and confirm its original read timestamp remains. Open a notification link and verify its GET does not change read state. Use Mark all as read, then generate a new event and confirm the new item stays unread. Inspect category/unread filters and More notifications over at least 21 items.
5. Copy a notification ID into another account's `/notifications/open` URL and POST read form with a valid CSRF token. Expect 404 and no change to the owner's item. Send a read/settings POST without CSRF and expect 403. Submit another account ID in preference/read inputs and expect rejection. Verify non-owner query parameters do not select another feed.
6. Repeat the same source event in the isolated test system and verify one ledger row and at most one queued email. Reuse its source key with changed content and expect a conflict/rollback. Follow a destination after losing the relevant role/ownership and verify the target's access controls still deny the underlying record.
7. Complete a synthetic email activation and password reset, including retrying their single-use links. Check exactly one successful dashboard event and optional email per completed action. Run the email worker and inspect generic copy and safe internal links without private payloads.
8. At 360, 768 and 1440 pixels, inspect the bell, focus/labels, unread badges, feed, pagination, settings columns, errors and light/dark/system modes. Template/HTTP tests do not replace this interactive visual review.
