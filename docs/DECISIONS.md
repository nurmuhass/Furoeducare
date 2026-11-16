# Furoeducare Decisions

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
| Keep school approval independent of authentication. | New school accounts start pending and their dashboard reads the approval record. Institution matching remains a separate policy decision. |
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
