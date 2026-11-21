# School user invitations and school-code login

## Audit and design

The existing AdministratorUserService creates inactive accounts without sending an
invitation. It already rejects platform roles and delegations that exceed the
actor's permissions. The new invitation path reuses that role policy rather than
opening legacy user mutation routes. No teacher, learner, parent or academic
profile is fabricated from a role selection.

SchoolBusinessWriteAccess provides a serialized, authoritative subscription check.
It locks the school; subscription DML uses that lock through the existing trigger.
Its effective ACTIVE, documented TRIAL and GRACE_PERIOD rules are reused, including
school-local dates. Missing, future, expired, pending, suspended and locked
subscriptions do not authorize invitations. This does not grant a subscription.

SchoolAdminActivationService already binds challenge nonce, school, user, IP,
user agent and purpose, and rotates OTP into an activation credential. The new
path reuses it for all invited school roles. Invited users are rechecked against
subscription eligibility at challenge creation, OTP verification and activation.
School lock precedes challenge/user locks. Resend consumes earlier challenges,
rotates the temporary password and increments invitation/auth generations.
Temporary credentials expire after 24 hours; resends have a 60-second cooldown.
Invitation mail runs after commit, rechecks the current generation, and never
returns a password through the API. A transport exception logs only record IDs;
API success is not proof of inbox delivery. No persistent mail outbox is added.

Audit found an existing discrepancy: frontend/controller said 12 characters but
the activation service actually enforces 16, mixed case, numbers and symbols.
The frontend now displays the existing enforced policy. No backend password rule
is weakened. The login form stays mounted during submission so failures remain
visible instead of disappearing behind its hydration screen.

## Identity and isolation

A separate User row belongs to each school, even when two accounts share an email.
New invited accounts use normalized email as username and require school code.
Sign-in accepts `school_code`, `username` (email), and password; it never accepts
`school_id`. The school code is a selector, not a secret or authorization proof.
There is no unauthenticated school directory for enumeration. Unknown school and
invalid credentials have the same unauthorized response. No cross-school fallback
occurs. Existing generated non-email usernames retain the transitional sign-in
path; ambiguous matches always fail closed. Platform login remains in its
separate school-null namespace. OTP mail names the school and code.

The migration replaces global username/email uniqueness with case-folded
school-scoped indexes and separate platform indexes, preserving globally unique
non-email legacy usernames. Case-only conflicts cause atomic migration failure.
Existing records are not merged or reassigned. Rollback refuses duplicate global
identities rather than deleting records. Invitation emails are capped at 100
characters to match the existing username column. Existing platform onboarding
and legacy creation paths may still conservatively reject globally reused emails;
this change enables reuse through the new school invitation path only.

JWT middleware verifies the token's school claim against current user membership,
as well as the existing auth generation and account checks. Tenant middleware
continues deriving scope from the authenticated user. Invitation handlers reject
client ownership overrides captured before tenant middleware overwrites input.
Pending identities cannot change username/email via ordinary profile updates.
Frontend query keys remain identity-scoped; HTTP session epochs reject late
responses after switching accounts. Challenge school identity is checked before
frontend acceptance of an activation result.

## API and UI

`/api/admin/school/user-invitations` requires school administrator membership,
`access_administrator_portal`, `create_school_users`, and `activate_school_users`.
The service rejects platform identities even if they possess these permission names.

- GET `/`: selected school code and up to 100 pending invited accounts.
- GET `/roles`: active, tenant-safe roles the administrator may delegate.
- POST `/`: first_name, last_name, email and role_id; no caller credentials or tenant.
- POST `/{user}/resend`: pending member of this same school only.

The school administrator has an Invite school users page. It displays server
eligibility failures, refresh/retry and pending activation; resend requires an
explicit confirmation. Other business forms remain on hold. No deployment or
production migrations are authorized by the local verification scripts.

## Validation

Run SchoolUserInvitationIsolationTest with SchoolAdminActivationTest,
AuthenticationContractTest, AuthenticationThrottleIsolationTest,
AdministratorUsersTest, PublicSchoolRegistrationTest and SchoolBusinessWriteAccessTest
against the isolated PostgreSQL regression database. New tests cover same-email
identities, wrong school code, client tenant override, foreign/platform roles,
foreign resend/list, expiry, cooldown, replay, audit rollback, membership/JWT
mismatch and subscription expiry before activation. Existing subscription
concurrency tests cover the shared locking primitive; no new concurrent mail
outbox test is claimed.

Frontend contract tests cover forbidden invitations, no tenant/credential
payloads, late school responses, challenge school mismatch and preserved login
errors. The synthetic browser test exercises code/email sign-in, OTP, activation
password indicators, invitation creation and confirmed resend.

## Final verification and delegation correction

The standard global, active system Teacher role may be delegated through the
invitation path by an authorized school administrator without personally holding
the reviewed teacher-only permission names. The exception uses an explicit
permission list; it does not grant those permissions to the administrator.
Platform roles, foreign school roles, non-system Teacher lookalikes and additional
unreviewed permissions remain blocked. Legacy user creation/role-update paths
retain their original subset policy. School Admin is access authority; the person
may be a headteacher or director. Job titles are not used as authorization claims.

Cooldown timestamps are bound directly as ISO strings with UTC offsets, avoiding
Eloquent's offset-free serialization into the new PostgreSQL timestamptz column.
The test covers immediate, 59-second and 60-second resends with a Nairobi database
session timezone.

Windows focused verification: 84 backend tests, 521 assertions; 52 frontend tests;
ESLint and production build passed; one synthetic invitation browser test passed.
Manual local verification completed against Laravel: trial eligibility, Teacher
invitation delivery, school code/email sign-in, OTP, permanent password activation
and access to Mansoor academy's teacher workspace. The authorized local trial is
not a paid subscription or production provisioning. Full regression and CI are
still pending for this feature. Other business forms remain on hold.
