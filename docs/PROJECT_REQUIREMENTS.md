# Furoeducare Project Requirements

Draft for review · 16 November 2026

Furoeducare is an education recruitment and services platform for Nigeria. It helps schools and families discover teachers, gives teachers and National Youth Service Corps (NYSC) members a route into recruitment, and lets Furoeducare management coordinate verification and placement. The initial release is free. Recruitment, placement, training and library services may become paid after formal activation.

The rules below describe the product. The companion [architecture](ARCHITECTURE.md) proposes how to implement them. This phase delivers the two planning documents for review.

## Actors and access

| Actor | Scope |
| --- | --- |
| Visitor | Browse the approved public teacher directory and download published free books without signing up. |
| Teacher / NYSC Member | Upload and review CV data, maintain a professional profile, apply before professional verification, and communicate with Furoeducare. |
| School / Educational Institution | Request teachers or additional information as a registered user. The dashboard is restricted pending management approval. An approved school can post teaching vacancies. Schools can request training or workshops. |
| Parent / Guardian | Request teachers or additional information, submit private tutoring requests, and communicate with Furoeducare. |
| Furoeducare management | Operate staff workflows for school approval, teacher verification, listing approval, recruitment, shortlisting, original CV access, placement, messaging, library access and training. |

The three customer categories are Teacher / NYSC Member, School / Educational Institution, and Parent / Guardian. Management access is an internal staff function.

## Business rules and acceptance conditions

| ID | Requirement | Acceptance condition |
| --- | --- | --- |
| BR-01 | Visitors can browse approved, publicly listed teachers without registering. | An anonymous visitor can open the directory and an approved, listed profile. Profiles without both conditions are unavailable through directory results and direct public profile links. |
| BR-02 | Public profiles disclose a limited set of professional fields. Private contact details, original CVs and identity documents stay private. | The public response, including its markup and any embedded data, contains only approved professional fields. Those responses contain no private contacts, original CVs, identity documents or private file references. |
| BR-03 | Schools and parents register to request a particular teacher or additional information. | A registered school or parent can submit a permitted request to Furoeducare. An anonymous visitor must sign in or register to submit it. Submission creates a request for management to handle. |
| BR-04 | Schools can post teaching vacancies after management approves their accounts. | A school pending approval cannot post through the interface or by submitting a request directly to the server. After management approval, the school can post through the school workflow. |
| BR-05 | Parents can submit private tutoring requests. | A parent can submit a tutoring request to Furoeducare. The request is visible to the submitting parent and authorised management staff. Public pages and unrelated accounts cannot access it. |
| BR-06 | Management controls verification, recruitment, shortlisting, CV access and placement. | Only authorised staff can take the corresponding management actions. Schools can post vacancies and teachers can apply without gaining staff permissions. |
| BR-07 | Teachers can apply before professional verification. Final placement requires professional verification. | An unverified teacher can submit an otherwise eligible application. Final placement is refused while that teacher is unverified and can proceed for an eligible verified teacher through the management workflow. |
| BR-08 | Schools have restricted dashboards until approved. | Before approval, a school sees the restricted dashboard and the server enforces the same restrictions on the underlying actions. |
| BR-09 | Each school has one account in the initial release. | Registration and management approval protect against a second account for the same institution, including concurrent attempts. The institution identity and duplicate-handling policy will be settled before this workflow is implemented. |
| BR-10 | Teachers upload CVs for automatic extraction, review the extracted fields and save editable profiles. | A supported CV triggers extraction. The teacher reviews and corrects suggestions and saves their profile, which remains editable. Only reviewed, saved data can reach the profile publication workflow. An extraction failure is reported and leaves saved profile data intact. |
| BR-11 | Management approves requests to access original CVs. | A requester cannot retrieve an original CV before management grants the applicable access. The approved requester can retrieve the approved CV under the access policy. No per-request approval task is sent to the teacher. |
| BR-12 | Professional profile sharing follows the disclosed privacy policy and applicable legal requirements. | Public listing and managed sharing use the fields, recipients, purposes and controls defined for those uses. |
| BR-13 | Internal messaging connects users with Furoeducare. | A customer can exchange messages with authorised staff in their own conversation. Customer accounts cannot message one another directly or read unrelated conversations. |
| BR-14 | Email notifications support important platform activities. | Configured workflow events generate notifications to the intended recipients. Notification contents follow the privacy policy for the activity. |
| BR-15 | The digital library supports public downloads of free books and admin-managed access. | A visitor can download a published free book without an account. Management controls publication and access. Restricted items require the applicable authorisation. |
| BR-16 | Schools request training and workshops through modules administered by management. | A school can submit a permitted training or workshop request. Authorised staff can view and manage it. |
| BR-17 | Every interface supports light, dark and system default themes. | Public, customer and management interfaces support all three settings. System default follows the operating-system preference. |
| BR-18 | Payment features remain disabled until formally activated. | Initial-release workflows can be completed free of charge and expose no checkout or payment processing. Formal activation is a separate project decision. |

School approval, teacher professional verification, teacher directory approval and original CV access approval are separate business decisions. The stated vacancy and placement gates apply even when actions bypass the interface.

## Engineering requirements

- Use modular, object-oriented Core PHP with MVC-style separation among controllers, models, services, policies, validators, views and database operations. Use Composer for dependency management. HTML5, CSS3, Bootstrap 5 and vanilla JavaScript provide the frontend.
- Use supported stable versions and MySQL with PDO prepared statements. Develop on Windows with local PHP and MySQL support. Deploy on Linux over HTTPS.
- Implement authentication, server-side authorisation, CSRF protection, secure sessions, contextual output escaping and validated uploads. Store secrets in environment variables and private files outside all public web roots.
- Manage database changes with migrations and use seeders for reference and test data. Prefer transactions when a state change writes multiple tables.
- Reuse frontend components across public pages, account dashboards and management screens. Design responsive interfaces with labelled controls, keyboard operation, visible focus and legible contrast across themes.
- Add automated tests for important business rules as their modules are implemented. Keep dependencies focused and review changes to agreed requirements before building them.

## Policy questions for later phases

These questions affect implementation details. The agreed rules above apply while the details are open.

| Area | Decisions to record before the relevant workflow is built |
| --- | --- |
| Public teacher directory and sharing | Exact public and managed-sharing field lists, treatment of identifying details and free text, whether listing requires professional verification, and whether edits to a published profile require renewed approval. |
| School onboarding | How to identify one real-world institution, treat branches and resolve duplicate registrations. Confirm the actions available in the restricted dashboard, including any restrictions on teacher, information or training requests. |
| Recruitment | Verification evidence and decision criteria, vacancy visibility and eligibility, application stages, and the operational meaning of final placement. |
| CV handling | Handling of scanned files, extraction failures and review. Confirm eligible original CV requesters, permitted purposes, the scope and duration of approved access, and retention or deletion rules. |
| Privacy | Define the disclosed privacy policy, lawful grounds and permitted recipients for processing and sharing, retention, and how data rights requests are handled. Assess the applicable requirements, including the [Nigeria Data Protection Act, 2023](https://ndpc.gov.ng/wp-content/uploads/2024/03/Nigeria_Data_Protection_Act_2023.pdf). |
| Notifications and services | Confirm email events and recipients, library distribution rights and access rules, and the training request lifecycle. |

## Project records and phase review

| Document | Purpose | Phase |
| --- | --- | --- |
| `docs/PROJECT_REQUIREMENTS.md` | Product rules, scope and acceptance conditions | Initial documentation |
| `docs/ARCHITECTURE.md` | Technical design and boundaries | Initial documentation |
| `docs/DECISIONS.md` | Agreed decisions and their rationale | Subsequent documentation |
| `docs/IMPLEMENTATION_PROGRESS.md` | Phase status, work completed and blockers | Subsequent documentation |
| `docs/TEST_PLAN.md` | Automated and manual test coverage | Subsequent documentation |

For each task, inspect the current project, state a brief plan, implement the requested scope, and run the available checks. Report the files changed, remaining work, blockers and exact manual test steps. Stop for review before the next phase.

## Teacher and NYSC onboarding

Registration requires a Teacher or NYSC Member category, first and last names, email, phone number, password and confirmation, acceptance of the Terms of Use, and acknowledgement of the Privacy Notice. A verified email opens the private teacher dashboard. Signup and profile completion never trigger public listing.

The private professional profile has ten independently saved sections covering personal and contact information, biography, education and qualifications, work and teaching experience, subjects and teaching levels, skills and certifications, preferred locations, employment and tutoring preferences, availability, and CV and professional documents. Qualifications and work experience support multiple entries. NYSC service details are optional. The dashboard shows completion and missing information. Teachers can save sections before uploading a CV. CV extraction remains a later phase.
