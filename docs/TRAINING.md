# Training and workshops

## Design for this phase

Use the existing Core PHP modules, PDO transactions, shared views, school approval, managed messaging and transactional notification outbox. No new runtime dependency or payment facility is needed.

The public catalogue lists published modules and packages. A package is an ordered group of modules. A package is available only while it and every included module are published. Module edits return published content to draft. Existing requests retain the exact module details and package title submitted by the school.

Only active, email-verified, approved schools with complete profiles can submit institutional requests. A school selects one or more modules or a published package, supplies participant count, location, future preferred dates and requirements, and receives an opaque request reference. Submission tokens prevent duplicate browser submissions. Schools can read their own requests after submission and communicate with management even if approval later changes. Scheduling rechecks current school approval.

Staff manage Submitted → Under Review → Discussion → Scheduled → Completed, with cancellation from any active stage. They assign an active administrator, record an approval while Discussion remains the status, then schedule the approved session with start/end, delivery format and location. Scheduling requires an assigned active staff member and current school approval. Completion records an outcome after the scheduled end. Cancellation records a reason. All decisions check a request revision and write attributed history, audit and school notifications in one transaction. Reassignment invalidates approval before scheduling; scheduled sessions can be rescheduled through an explicit staff action with a reason. Completed and Cancelled requests are immutable.

Training materials are descriptive metadata only in this phase. Public metadata must be suitable for publication and must not contain private file locations or credentials. Delivery formats are on-site, online and hybrid. Dates and session times use Africa/Lagos in forms and UTC in storage. Participant counts support 1–10,000, selections at most 20 modules, and preferred dates at most ten unique future calendar dates. These are implementation bounds, not pricing rules.

The existing school-to-management messaging system gains a training request context. Ownership is checked against the requesting school. Teachers, parents and other schools cannot link that request. Notifications use the existing in-app feed and configurable email outbox with generic content and authenticated request links. No private requirements, material metadata or outcomes enter email bodies.

## Implementation plan

1. Add migrations 0067–0073 for modules, packages/membership, requests, request module snapshots, decision history and the messaging context. Add pure validation/status tests before implementation.
2. Implement catalogue and package persistence, approval-gated idempotent school intake, private ownership reads, staff assignment/approval/scheduling/outcomes and transactional events.
3. Add public, school and administrator views/controllers/routes using existing components and themes. Wire dashboard navigation and replace the static training landing page.
4. Connect training messaging and safe email notifications. Add isolated HTTP tests for role/approval restrictions, snapshots, ownership, invalid transitions, scheduling, retries, notifications and messaging. Extend migration checks and run regressions.
5. Record results, setup and exact manual verification. Stop for review before the next phase.

## Setup and routes

Use the existing PHP 8.5/MySQL 8.4 setup. No extra Composer dependency, private-file directory, upload endpoint or payment provider is needed for training. Back up the database, stop application writes, run `php bin/database.php migrate` to apply migrations 0067–0073, and restart the app. The schema contains no sample training programmes.

| Audience | Route |
| --- | --- |
| Public catalogue | `/training-workshops` |
| Published module/package | `/training-workshops/module?id=…`, `/training-workshops/package?id=…` |
| School intake and tracking | `/dashboard/school/training` |
| Staff catalogue and packages | `/dashboard/admin/training` |
| Staff requests and decisions | `/dashboard/admin/training/requests` |

School and administrator dashboards link to their training workspaces. Visitors have a school registration link. Modules and packages paginate independently and preserve their search. Intake shows a bounded initial set of choices and includes currently selected published items even when they fall outside that set. Package editors retain member order. Required fields validate on the server and validation responses preserve entered values and submission tokens.

School approval is required for new submissions, approval decisions and scheduling. A verified school can still track its own previously submitted requests and contact management if its approval changes. Staff can assign an active administrator before scheduling; assignment during Scheduled is disabled. Rescheduling records a reason and checks the current school and assignee again. Decision notes and outcomes are shared with the requesting school. Completion and cancellation close the decision workflow permanently.

Each action stores actor, UTC timestamp, from/to statuses, reason and structured decision details. Request module snapshots preserve metadata, revision and submitted order. There is no soft deletion or removal of training requests in this phase. Foreign keys protect referenced schools/modules/packages; deleting staff accounts preserves decisions through nullable staff references. The messaging context is validated against the school owner. Email event payloads contain authenticated paths only.

Notifications use the existing in-app feed and `training_confirmation` email template/category. Run `php bin/email-worker.php 50` locally using the development file transport to inspect generic messages. Production uses the existing configured SMTP worker and notification preferences. Administrative submissions and assignments use staff paths; school events use the owner tracking path. Requirements, material descriptions, notes and outcomes stay inside authenticated interfaces. Do not include confidential content in public material metadata.

Migration rollback must run in reverse order. The messaging enum rollback is practical only when no training conversations use the new context; resolve those records through an approved retention/migration process before rollback. Do not remove active contexts merely to force a rollback. Reset only disposable development databases following `DATABASE.md`.

## Automated verification

- `php tests/training-input.php` checks metadata, selections and allowed transitions.
- `php tests/training-notifications.php` checks school/staff authenticated email links, safe generic payloads and existing training preferences. `php tests/message-input.php` checks messaging inputs.
- `php tests/training.php` requires `APP_ENV=development`, `DB_HOST=127.0.0.1`, an **empty disposable** MySQL 8.4 database named `furoeducare_test_training_<suffix>` and matching `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`. It migrates/seeds the schema and uses synthetic accounts/programmes with isolated HTTP sessions. Loopback socket access is required.
- After the main suite, retain the same disposable database environment and run `php tests/training-invariants.php`. It requires the current migrated schema and scoped CRUD/TRIGGER privileges, creates synthetic fixtures and removes its temporary failure trigger in `finally`. It verifies snapshot order, stable retries, changed-payload conflict, transaction rollback on outbox failure, choices beyond the initial 200 and independent cursors.
- `php tests/database.php` uses its own empty disposable schema and checks all 73 migrations, prior releases, rollback/reapplication and failure-marker behavior. `php tests/foundation.php` checks startup, public static routes, configuration errors and logging without a database.

## Exact manual review

1. Run migrations and start the local PHP server using README. Sign in as an administrator and open **Manage training catalogue**. Create a module with every required field, prerequisites and public materials description. Verify the draft is absent from the signed-out catalogue, then Publish and inspect its full information. Enter synthetic HTML and verify it displays as text.
2. Create a second published module. Create a package, select both modules and set their positions. Save and Publish. Check public package order. Edit a published module and verify it and its dependent package disappear from public information until the module is republished.
3. As a pending school, attempt a request and verify denial. Repeat as a teacher, parent and unverified account. Approve a complete school profile through the existing review workspace, sign in as that school, select modules or a package and submit 24 participants, a general institutional location, future preferred dates and requirements. Submit the same saved form again and verify one request and one set of alerts. Change a field with the same token and expect a conflict.
4. Open the submitted request as the owning school and compare its snapshots with the original selection. Edit the catalogue and verify the request still shows the original content and order. Copy its tracking URL into a different school account and expect 404. Check staff-only pages with a school account and expect 403.
5. As staff, move Submitted to Under Review and Discussion. Try scheduling before approval and assignment. Assign an active administrator, record an approval note and schedule future start/end times, format and location in WAT. Check UTC storage and WAT presentation. Use an earlier saved decision form after another update and expect a conflict. Withdraw school approval or suspend the assigned administrator on synthetic fixtures and verify scheduling is refused.
6. Reschedule with a reason and verify the update in history. Completion before end must fail. After a synthetic session has ended, supply its outcome and complete. Create another request and cancel with a reason. Confirm that terminal requests reject further decisions. Notes and outcomes should appear only to authorised staff and the owning school.
7. From the request detail, open **Message management about this request** as the school and send a message. Reply using the staff messaging workflow. Verify the training context and unread notification. Another school, teacher or parent must not link this request. Check the school/staff notification feeds and run the local email worker; inspect generic content, correct recipient and authenticated route.
8. Check catalogue cards, search, independent pagination, request forms, package ordering, staff decisions and history at 360, 768 and 1440 pixels. Use keyboard navigation and inspect labels, focus, errors and light/dark/system modes. This interactive visual review is separate from automated HTTP and template-rendering checks.
