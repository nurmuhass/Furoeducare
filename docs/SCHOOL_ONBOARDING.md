# School and educational institution onboarding

Schools register at `/register/school`. The form collects the institution name, category, state or FCT, city, address, school email and phone, and the representative’s name, position and sign-in email. Registration uses the shared password and email verification rules. The form records which draft Terms of Use and Privacy Notice the representative accepted and acknowledged.

Verification binds the profile to the address in the email link. On the original browser the representative can confirm the registration draft. Another browser requires the details and a password to be entered again. Verification attempts are rate-limited. Successful verification creates a pending school and opens `/dashboard/school`.

## Institution matching

One registered profile owns each normalized name, state and city combination. Matching ignores punctuation, spacing, case and accents. The database checks uniqueness on both creation and profile updates. Separately named institutions or campuses with the same name in different cities can register individually. Schools with an existing account should ask Furoeducare for help regaining access. When a school uses different names or city spellings, staff still need to check for real-world duplication.

Name-only legacy profiles retain their recorded approval state. A same-name registration is held until the existing account completes its location, so an unknown location cannot bypass duplicate protection. The school must complete the new fields and legal acknowledgements before it can use approved-only features. Completing an older approved profile returns it to review.

## School permissions

| Area | Verified, unapproved account | Verified, approved account with a complete profile |
| --- | --- | --- |
| Overview, profile and status | Available | Available |
| Public resources, teacher listings and library | Available | Available |
| Private recruitment drafts | Save and edit its own drafts | Save and edit its own drafts |
| Settings and review notifications | Available | Available |
| Vacancy publication, active recruitment, restricted teacher details and approved shortlists | Server denies access | Approval check succeeds. The workflows remain unavailable until their modules are built. |

Drafts are stored separately from active recruitment and carry no submission or publication state. Access to profiles, drafts and notifications is scoped to the signed-in school account. Review reasons appear under **Notifications**.

## Administrator review

1. Sign in with an existing administrator role and open **Review school accounts** from `/dashboard/admin`, or go to `/dashboard/admin/schools`.
2. Open a school account. Check the representative’s verified email, profile details and review history.
3. Choose an available decision and enter a reason that the school can read. Approval requires confirmation of both the institution and its representative details. Each decision records the signed-in reviewer, checks and a UTC timestamp. If the profile changes during review, reopen it before deciding.
4. When changes are required, saving a changed profile returns the school to pending approval. Editing an approved institution’s identity, address, contact or representative details also returns it to pending. A rejected account can review feedback and edit its profile. A new administrator decision is required to approve it.
5. Suspension revokes existing sessions and blocks new sign-ins. A subsequent decision can clear a suspension imposed by the school review flow. An independently applied account suspension is preserved.

See the [test plan](TEST_PLAN.md#school-registration-and-review) for the manual checks and automated test command.
