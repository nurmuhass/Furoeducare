# Transactional email

Account settings add the essential encrypted `email_change` template. Its proposed-address recipient is permitted only when an active account/version-bound challenge matches; the worker rechecks the live record before delivery. Cancelled, expired, replaced or password-version-invalidated challenges are suppressed. Old sign-in email remains current until authenticated confirmation. Settings alerts use the existing independent notification preferences. See [email-change security and setup](ACCOUNT_SETTINGS.md).

Furoeducare queues email in MySQL and delivers it from `bin/email-worker.php`. SMTP and development MIME files use PHPMailer, currently locked to version 7.2.0. Production uses a provider's SMTP endpoint. The application records the provider's relay acknowledgement and transaction ID when supplied. [PHPMailer releases](https://github.com/PHPMailer/PHPMailer/releases)

## Configuration

Use PHP 8.5 with `openssl`, `pdo_mysql` and `sodium` enabled, then run `composer install` and apply migrations 0058–0060. The web process and worker must use the same database, canonical `APP_URL` and `MAIL_ENCRYPTION_KEY`.

| Setting | Purpose |
| --- | --- |
| `MAIL_TRANSPORT` | `file` for development, `smtp` for production or local SMTP testing. |
| `MAIL_FROM` | Sender address authorised by the provider. Development defaults to `no-reply@furoeducare.test`. |
| `MAIL_SMTP_HOST`, `MAIL_SMTP_PORT` | Provider host and port. A local SMTP capture service can use `127.0.0.1` and its configured port. |
| `MAIL_SMTP_USERNAME`, `MAIL_SMTP_PASSWORD` | SMTP credentials supplied through environment variables. |
| `MAIL_SMTP_ENCRYPTION` | `tls` for STARTTLS or `smtps` for implicit TLS. `none` is allowed in development for a local capture service. |
| `MAIL_TIMEOUT` | SMTP timeout of 1–30 seconds, default 15. |
| `MAIL_ENCRYPTION_KEY` | Base64 encoding of 32 random bytes. Encrypts queued verification and reset tokens. Required by the authentication mailer and worker. |
| `AUTH_MAIL_DIR` | Optional private development spool path. Its parent must exist outside every web root. Defaults to `storage/mail`. |
| `APP_LOG_FILE` | Private JSON application log for safe error classifications and outbox IDs. |

Generate a key locally, copy it into the ignored `.env`, and keep the production value in the deployment's environment or secret manager:

```powershell
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Keep the current key available until its pending authentication messages have been processed or expired. Changing the key while encrypted messages are pending makes those messages unreadable. Never copy keys, SMTP passwords, token links or development MIME files into source control or operational tickets.

## Local delivery

Set `APP_ENV=development`, `APP_URL=http://127.0.0.1:8000`, `MAIL_TRANSPORT=file` and the generated key. Start the application as documented in the README. Register a synthetic account, then run the worker in a second PowerShell terminal:

```powershell
php bin/email-worker.php 50
```

Open the resulting `.eml` file in the private spool directory and follow its verification link. A reset request follows the same process. The worker writes one deterministic MIME file per event with text and HTML alternatives. For a local SMTP testing service, set `MAIL_TRANSPORT=smtp`, its loopback host and port, empty credentials and `MAIL_SMTP_ENCRYPTION=none`. The automated suite includes a local PHP SMTP fixture that accepts or rejects synthetic deliveries.

## Processing and operations

Run the worker at least once per minute through Windows Task Scheduler in development or cron/systemd on Linux. For Windows, select the PHP 8.5 executable, set arguments to the absolute `bin/email-worker.php` path followed by a batch size, and set the working directory to the project root. For Linux, this example assumes the installation is at `/var/www/furoeducare` and its runtime environment is available to cron:

```cron
* * * * * cd /var/www/furoeducare && /usr/bin/php bin/email-worker.php 100 >> /var/log/furoeducare-email-worker.log 2>&1
```

The worker claims due records with `FOR UPDATE SKIP LOCKED` and commits the claim before contacting SMTP. Each claim has a five-minute lease and a durable attempt record. Concurrent workers can process different records. Transient failures retry up to five total attempts with delays of 60, 120, 240 and 480 seconds. Known permanent SMTP failures finish as Failed. A worker reports counts and returns a nonzero exit status when a batch has failed or uncertain deliveries. Monitor queue age, exhausted failures, uncertain records and worker exit status.

Inspect recent delivery metadata using an authorised database account:

```sql
SELECT id, template, status, attempts, available_at, provider_status, provider_id, last_error_code
FROM email_outbox
ORDER BY id DESC
LIMIT 50;
```

| Outbox status | Meaning |
| --- | --- |
| `pending` | Waiting for an initial attempt or scheduled retry. |
| `processing` | Claimed by a worker under a lease. |
| `accepted` | SMTP relay acknowledged the message, or the development file was stored. |
| `failed` | Permanent failure or retry limit reached. |
| `suppressed` | Recipient eligibility, token validity or notification preferences prevented delivery. |
| `uncertain` | Submission outcome cannot safely be established, including a lost acknowledgement, expired worker lease or failure to persist a known submission. |

Stable event keys prevent duplicate outbox rows and accepted records are skipped on subsequent runs. A stable Message-ID accompanies retries. SMTP cannot guarantee exactly-once inbox delivery. Uncertain records require operator reconciliation with provider logs before any separate resend. They are never automatically resubmitted. Relay acceptance cannot establish whether a message reached the inbox, bounced later or was opened. Provider dashboards supply those later delivery details. The SMTP adapter stores a relay transaction ID when the relay exposes one.

Errors contain outbox ID, attempt number and a fixed classification. SMTP replies, email bodies, token links and credentials stay out of logs. Verification/reset tokens are encrypted while queued and cleared on terminal outcomes. The worker rechecks the original token's current expiry and consumption state, the account's address and suspension state, and optional email preferences before delivery. Each attempt remains in `email_delivery_attempts` for operational diagnosis. Include outbox addresses, safe payloads and attempt metadata in the platform's approved retention schedule and backup handling.

## Events, templates and preferences

| Template | Current event source |
| --- | --- |
| Account verification | Teacher, school, parent and generic registration or resend, committed with token issuance. |
| Welcome | Successful account activation. |
| Password reset | Eligible reset request, committed with token issuance. |
| School approval | Recorded school review result. |
| Teacher verification result | Recorded professional verification result. |
| New teacher request | Submitted school candidate case or parent tutoring request, sent to active management accounts. |
| Vacancy approval or rejection | Vacancy moderation decision, sent to the owning school. |
| New application | New vacancy application, sent to active management accounts. |
| Interview invitation | Application interview invitation or managed placement discussion scheduling. |
| CV request result | Managed candidate information decision or access revocation. |
| New internal message | Message event, sent to the account holder, assignee or management triage recipients. |
| Tutoring request update | Recorded tutoring status change. |
| Placement confirmation | Managed confirmation, sent to the teacher and requesting account. |
| Training confirmation | Training intake, staff assignment and request decisions. Schools and staff receive their own authenticated request routes with generic content. |
| Recruitment update | Candidate request progress, released shortlists and application status changes. |
| Security alert | A successfully completed password change. |

Business events queue on the business transaction's PDO connection. `Outbox::enqueue()` takes a stable event key, account ID, template and approved safe payload. Reusing a key with different content is rejected. Only internal code creates these events. Templates accept fixed copy, validated internal navigation, and a whitelist of status labels. Business emails contain generic updates and protected dashboard links. Original CVs, identity files, message bodies and staff notes are excluded from email content and attachments.

Account holders manage six optional categories independently for email and in-app delivery at `/settings/notifications`. The [central notification service](NOTIFICATIONS.md) checks channel choices while recording the source event. Email preferences are also rechecked at dispatch, so an opt-out suppresses pending optional mail. Verification and reset messages remain available for essential account access. Dashboard links use the configured origin and the destination still enforces authentication and ownership.

## Sender DNS and provider setup

Verify the sending domain in the selected provider, authorise the `MAIL_FROM` address and enable provider-side DKIM signing. Publish the provider's exact selector CNAME or TXT records under `<selector>._domainkey`. The published value is a public key or provider hostname. Keep any private signing key in the provider's protected configuration. [Provider DKIM setup](https://docs.aws.amazon.com/ses/latest/dg/send-email-authentication-dkim.html)

Publish the provider's SPF policy for its required envelope-sender domain and configure the provider's custom MAIL FROM/bounce domain if using SPF alignment. Merge an additional provider into the existing SPF policy for that hostname. The provider supplies the correct include mechanism and any required MX record. [Provider SPF setup](https://docs.aws.amazon.com/ses/latest/dg/send-email-authentication-spf.html)

Publish a DMARC TXT policy at `_dmarc.<sending-domain>`. A monitoring example for a domain and reporting mailbox controlled by Furoeducare is shown below. Replace the example domain, confirm the mailbox receives reports and inspect authenticated test messages. DMARC requires an aligned SPF or DKIM result against the visible From domain. After reviewing reports and confirming legitimate senders, the domain owner can change the policy to quarantine or reject. [DMARC alignment and policy](https://docs.aws.amazon.com/ses/latest/dg/send-email-authentication-dmarc.html)

```dns
_dmarc.example.com TXT "v=DMARC1; p=none; rua=mailto:dmarc@example.com"
```

Check DNS with `Resolve-DnsName` on Windows or `dig` on Linux, then inspect the received message's Authentication-Results for SPF, DKIM and DMARC outcomes. Test delivery through the selected provider to controlled mailboxes and check its acceptance, bounce and complaint reporting before production use.
