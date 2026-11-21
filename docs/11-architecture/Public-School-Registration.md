# Public school registration with owner approval

Public POST `/api/school-registration-requests` accepts school name, operating
mode and the initial administrator's first name, last name and email. School
email, phone and physical/postal addresses are optional. It stores only a pending
request. It creates no school, user, subscription or authentication session and
sends no email. It returns the same generic 202 response for an accepted request,
a duplicate pending email and an email already belonging to a user.

Names are trimmed and stripped of markup before validation; administrator emails
are normalized to lowercase. Pending emails have a database partial unique index;
retries cannot overwrite the existing request. The public route permits five
requests per hour per Laravel throttle identity. Public listing/status lookup and
public approval routes do not exist. Submitted ownership, status, credentials and
role identifiers cannot authorize provisioning.

Authenticated platform review routes at
`/api/admin/platform/school-registration-requests` require platform administration
and `onboard_schools`, plus the existing administrator portal identity checks.
The owner can list pending, approved or rejected requests, then POST
`/{registration}/approve` or `/{registration}/reject` (reason required).

The owner must confirm that the applicant represents the school. Submission is
not email or school-identity verification. Approval locks the request row and
calls the existing PlatformSchoolOnboardingService within the same transaction.
School/account creation, request status and decision audit commit atomically.
The existing invitation callback runs only after the outer transaction commits.
Review responses never return passwords, usernames or authentication tokens.
Repeated identical reviews are idempotent; conflicting decisions return 409.
An unavailable administrator email blocks approval without changing the request.

Approved schools enter the Schools directory with lifecycle state `onboarding`.
Approval does not claim active product use, complete setup or paid subscription.
Rejected requests create no school account. A rejected applicant can submit a new
request, subject to the same rate limits. Decisions are retained in this table;
retention and application-status notifications are separate future work.

No public endpoint reuses or weakens platform onboarding authorization. Existing
school routes retain tenant middleware. Other business forms remain on hold.

Validation: run PublicSchoolRegistrationTest together with the existing platform
onboarding security and invitation delivery suites against the isolated PostgreSQL
test database. Apply only this new migration to that test database first.
