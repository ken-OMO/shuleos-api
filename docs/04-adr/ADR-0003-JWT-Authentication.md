# ADR-0003 — JWT Authentication

> School in Clouds

## Document Information

| Field                | Value                                                 |
| -------------------- | ----------------------------------------------------- |
| ADR                  | ADR-0003                                              |
| Decision             | JWT Authentication and Authoritative Request Identity |
| Status               | Accepted                                              |
| Version              | 1.0                                                   |
| Owner                | Platform Engineering                                  |
| Repository           | `shuleos-api`                                         |
| Effective Date       | 02 August 2026                                        |
| Related Constitution | Engineering Constitution v1.1                         |
| Supersedes           | None                                                  |
| Superseded By        | None                                                  |

## Context

ShuleOS exposes a Laravel API to web, mobile, and offline-capable clients.

The platform processes highly sensitive information, including:

- Learner records
- Guardian information
- Staff records
- Examination results
- Financial records
- Attendance
- Behaviour and discipline
- Uploaded documents
- School configuration
- Subscription information
- Platform administration data

Every protected request must establish one authoritative answer to the question:

> Who is making this request?

Authentication must also support:

- Multi-tenant school access
- Platform-level identities
- Brand-level identities
- School-level identities
- Real-time suspension and lockout
- Role and permission changes
- Email verification
- First-login password replacement
- Email OTP verification
- Two-factor authentication for every new session
- Access-token refresh
- Logout and revocation
- Web and mobile clients
- Offline synchronization
- Background processing
- Safe error handling
- Audit logging

The architecture must not allow different middleware, services, or controllers to interpret identity independently.

Multiple identity sources would create inconsistent authorization decisions and could permit tenant or permission bypass.

## Decision

ShuleOS will use JSON Web Tokens for API access, with Laravel's authentication system serving as the single authoritative request identity mechanism.

A request is authenticated only when:

1. The JWT is valid.
2. The token maps to an existing user.
3. Laravel registers that user as the authenticated identity.
4. The account remains active and permitted.
5. Required session-level two-factor verification has completed.
6. The tenant and governance scope can be resolved safely.
7. The token has not been revoked.
8. The request passes all downstream authorization controls.

JWT parsing alone does not constitute authentication.

No middleware may read token claims and establish a separate identity without registering the resolved user through the framework's approved authentication mechanism.

## Authentication Principle

Authentication produces one authoritative identity.

```text
Bearer Token
    |
    v
JWT Validation
    |
    v
Resolve User
    |
    v
Validate Account State
    |
    v
Register User with Laravel Auth
    |
    v
Resolve Tenant and Governance Scope
    |
    v
Authorization
```

Every downstream component must use the same authenticated identity.

## Authentication Boundaries

Authentication establishes identity.

It does not automatically establish:

- Tenant access
- Permission
- Object ownership
- Subscription eligibility
- Resource state
- Financial authority
- Platform authority

Those decisions belong to later security stages.

The protected request pipeline is:

```text
Request
    |
    v
JWT Authentication
    |
    v
Authoritative Laravel Identity
    |
    v
Account-State Enforcement
    |
    v
Tenant Resolution
    |
    v
Subscription Enforcement
    |
    v
Role and Permission Authorization
    |
    v
Object Ownership and Policy Checks
    |
    v
Business Logic
```

No later stage may proceed when authentication is uncertain.

## Login Lifecycle

The approved login lifecycle is:

```text
Email and Password Submitted
    |
    v
Credentials Validated
    |
    v
User and School State Checked
    |
    v
First-Login Password Requirement Checked
    |
    v
OTP Generated
    |
    v
OTP Sent to Registered Email
    |
    v
OTP Verified
    |
    v
Session Established
    |
    v
Access and Refresh Tokens Issued
```

An access token must not be issued before required OTP verification succeeds.

## School Registration Bootstrap

When a school is registered and activated:

1. ShuleOS creates the initial authorized school administrator account.
2. The user receives a registered email address and temporary password.
3. The first login requires replacement of the temporary password.
4. ShuleOS sends an OTP to the registered email.
5. The user verifies the OTP.
6. Only then may the authenticated platform session be established.
7. The administrator may then create or assign permitted school users and roles.

Temporary credentials must never provide unrestricted platform access.

## Two-Factor Authentication

ShuleOS requires email-based OTP verification when establishing each new authenticated session.

Two-factor verification is separate from password verification.

The first factor is:

- Email or approved username
- Password

The second factor is:

- Time-limited OTP sent to the registered email address

The OTP flow must be:

- Rate-limited
- Time-limited
- Single-use
- Hashed in storage
- Attempt-limited
- Audited
- Invalidated after success
- Invalidated when replaced
- Bound to the intended user and login challenge
- Protected against replay

OTP values must never be logged or stored in plain text.

## Authentication Challenge State

Before two-factor verification, the user is not fully authenticated.

The server may issue a short-lived authentication challenge identifier, but it must not grant access to ordinary protected routes.

A challenge may authorize only narrowly scoped actions such as:

- Submit OTP
- Resend OTP within limits
- Cancel the login attempt

A challenge token must not be accepted as a normal access token.

## Access Tokens

Access tokens authorize short-lived API sessions.

Access tokens should contain only the minimum claims required to validate the token and locate the authoritative server-side identity.

Tokens must not become permanent copies of:

- Roles
- Permissions
- Account state
- School state
- Subscription state
- Sensitive personal information

Security-sensitive state must be re-evaluated from authoritative server-side data on protected requests.

## Token Claims

Permitted claims may include:

- Token identifier
- Subject identifier
- Issued-at time
- Expiration time
- Token type
- Session identifier
- Approved issuer and audience information

Claims must not be trusted as the sole authority for:

- Current roles
- Current permissions
- Current tenant membership
- Current suspension state
- Current subscription state
- Current governance scope

## Refresh Tokens

Refresh tokens permit controlled access-token renewal.

A refresh operation must:

- Validate the refresh token
- Resolve the user again
- Re-check account state
- Re-check school state
- Re-check tenant membership
- Recalculate current roles and permissions
- Re-check session revocation
- Rotate or invalidate the prior token where required
- Produce a new access token
- Record an audit event

Refresh must not simply copy stale authorization claims from the previous token.

## Real-Time Revocation

Access can be revoked on the next protected request.

The following changes must take effect immediately:

- User suspension
- User lockout
- User deactivation
- User archival
- Forced password reset
- Role removal
- Permission removal
- School suspension
- Subscription lock
- Session revocation
- Security incident containment

ShuleOS must not wait only for JWT expiration to enforce these changes.

Approved revocation approaches may include:

- Session records
- Token identifiers
- User security-version counters
- Token blacklists
- Central revocation storage
- Short token lifetime combined with server-side state validation

The final implementation must remain measurable and testable.

## Account States

Authentication must enforce account states including:

- Pending activation
- Email verification required
- Temporary password active
- Password reset required
- OTP verification pending
- Active
- Suspended
- Locked
- Archived
- Deleted where applicable

A state column is incomplete unless the authentication or authorization pipeline enforces it.

## School and Tenant State

A valid user account does not guarantee access when the associated school is unavailable.

Authentication and subsequent access control must respect:

- School activation
- Trial status
- Subscription expiry
- Grace period
- Read-only state
- Locked state
- Archived state
- Brand or governance restrictions

Responses must not reveal unnecessary internal lifecycle details.

## Single Source of Identity

Laravel's authenticated-user mechanism is the authoritative identity source for the request.

Approved application code must use the framework identity rather than:

- Parsing the bearer token repeatedly
- Reading an arbitrary user identifier from headers
- Trusting a user identifier in the request body
- Resolving a second user through custom middleware
- Trusting stale serialized identity objects
- Trusting client-side state

If any middleware cannot retrieve the authoritative authenticated user, it must fail closed.

## Middleware Rules

Authentication middleware must:

- Validate token format
- Validate token signature
- Validate token expiration
- Validate issuer and audience where configured
- Resolve the associated user
- Reject unavailable accounts
- Register the user with Laravel Auth
- Establish safe session context
- Continue only after successful authentication

Tenant and permission middleware must not re-authenticate a separate identity.

## Authorization Dependency

Authorization is valid only when it depends on the authoritative authenticated identity.

Policies, gates, permission middleware, services, and ownership checks must all resolve the same user.

If identity is missing or inconsistent:

- Tenant resolution fails
- Permission resolution fails
- Policy checks fail
- Request processing stops

The system must fail closed.

## Generic Failure Responses

Authentication failures should avoid leaking whether:

- An email exists
- A password was correct
- An account is suspended
- A school is locked
- A subscription has expired
- A user belongs to a specific school

Externally safe responses should be stable and generic.

Detailed reasons may be recorded securely in internal audit or security logs.

## Rate Limiting

Authentication endpoints must be rate-limited.

Rate limiting should consider:

- Source address
- User or email identifier
- Device or client context where appropriate
- School or tenant context where safely known
- Failed attempt frequency
- OTP resend attempts
- OTP verification attempts
- Refresh attempts
- Password reset attempts

Rate limits must not expose account existence through materially different responses.

## Brute-Force Protection

Repeated failed authentication attempts must trigger controlled protections such as:

- Progressive delays
- Temporary challenge lockout
- Account protection controls
- Security alerts
- Additional verification
- Administrator review where justified

Permanent account lockout based solely on unauthenticated traffic should be avoided where it could enable denial-of-service attacks against users.

## Password Storage

Passwords must use the framework's approved adaptive password-hashing mechanism.

Passwords must never be:

- Stored in plain text
- Reversibly encrypted
- Logged
- Included in audit metadata
- Included in tokens
- Returned by API resources
- Sent by email after initial generation

Temporary passwords must be generated securely and replaced at first login.

## Credential Rotation

A password or authentication secret change should invalidate relevant active sessions according to the approved security policy.

Compromised credentials must be rotated immediately.

Rotation events must be audited.

## Logout

Logout must invalidate the active session or token so that it cannot be reused.

The logout endpoint must:

- Require an authenticated session
- Revoke the current token or session
- Clear relevant server-side context
- Record the event
- Return a safe response

Client-side token deletion alone is not sufficient logout.

## Password Reset

Password reset must prove control of the registered recovery channel.

Reset tokens must be:

- Random
- Short-lived
- Single-use
- Hashed in storage
- Bound to the user
- Revoked after use
- Rate-limited

A successful reset should invalidate existing sessions according to policy.

## Email Verification

Email verification proves control of the registered email address.

Email-verification tokens must be:

- Time-limited
- Single-use
- Hashed
- Bound to the intended user
- Audited

Verification does not by itself grant permissions or tenant authority.

## Device and Session Awareness

ShuleOS may maintain server-side session information including:

- Session identifier
- User identifier
- Token identifier
- Created time
- Last activity
- Revocation state
- Device metadata where legally and operationally appropriate
- Approximate source metadata
- Two-factor completion state

Device information must not be treated as a substitute for authentication.

Sensitive device identifiers must not be exposed through API resources.

## Offline Clients

Offline-capable clients may retain limited local authentication state for approved use cases.

Offline authentication must not allow indefinite access.

Offline clients must:

- Respect token expiration
- Store tokens securely
- Limit locally cached sensitive data
- Require re-authentication when policy requires
- Revalidate authorization during synchronization
- Reject operations after account or tenant revocation once connectivity returns
- Display data freshness and sync state

A previously valid offline session does not override current server-side revocation.

## Synchronization Authentication

Every synchronization batch must authenticate through the approved identity mechanism.

The sync endpoint must:

- Validate the access token
- Resolve the authoritative user
- Resolve the current tenant
- Validate device ownership where applicable
- Re-check permissions
- Reject stale or revoked sessions
- Apply idempotency and conflict rules
- Audit accepted and rejected operations

Sync payload identity fields are untrusted.

## Background Jobs

Background jobs must not treat serialized user objects as permanent authentication evidence.

Where a job acts on behalf of a user, it must carry only required identifiers and re-resolve:

- User
- Tenant
- Account state
- Authority where required
- Resource ownership

Jobs performing system-authorized operations must use an explicit system authority model, not a fabricated user identity.

## Webhooks

External webhooks do not use normal user JWT authentication unless a provider explicitly supports an approved signed token model.

Webhook authentication must instead use mechanisms such as:

- Provider signatures
- Shared secrets
- Certificate validation
- Registered merchant identifiers
- Server-generated transaction references
- Replay prevention
- Idempotency keys

Webhook routes must not be accidentally placed behind user JWT middleware when they require provider authentication.

## Service-to-Service Authentication

If ShuleOS later extracts services, service authentication requires a separate ADR.

User JWTs must not automatically become unrestricted service credentials.

Service identity, audience restrictions, key rotation, and authorization must be explicitly designed.

## Secret Management

JWT signing secrets and keys must:

- Be stored outside source control
- Use approved secret storage
- Be rotatable
- Have documented ownership
- Never appear in logs
- Never appear in API responses
- Never be embedded in frontend code
- Be different across environments

Compromise of a signing secret is a security incident.

## Signing Strategy

The selected JWT signing algorithm and key-management model must be documented and reviewed.

The implementation must:

- Reject unsigned tokens
- Reject unexpected algorithms
- Prevent algorithm-confusion attacks
- Validate the configured algorithm explicitly
- Rotate signing material safely
- Support incident-driven revocation

A future move between symmetric and asymmetric signing requires documented review and may require a new ADR.

## Token Storage in Clients

Client applications must store tokens using the most secure practical mechanism for their platform.

Web clients must consider:

- XSS risk
- CSRF risk
- Secure cookies where adopted
- SameSite policy
- HttpOnly protection
- Browser storage exposure

Mobile clients must use approved secure device storage.

The frontend must not log or expose tokens.

The exact browser token-storage model should be documented in the frontend security architecture.

## CORS and Trusted Origins

CORS configuration must use an explicit allowlist.

Production configuration must not permit arbitrary origins with credentials.

Trusted frontend origins must be environment-specific and reviewed.

CORS is not an authentication or authorization control.

## CSRF Considerations

JWT usage does not automatically eliminate CSRF risk.

The risk depends on how tokens are stored and transmitted.

If authentication credentials are sent automatically by the browser, approved CSRF protections must be used.

The final browser session design must be documented and tested.

## Audit Logging

Authentication audit events should include:

- Login success
- Login failure
- OTP generated
- OTP verification success
- OTP verification failure
- OTP resend
- Refresh
- Logout
- Password change
- Password reset
- Email verification
- Session revocation
- Account lockout
- Suspension denial
- Token-reuse detection
- Administrative revocation

Logs must not contain:

- Passwords
- OTP values
- Raw access tokens
- Raw refresh tokens
- Reset tokens
- Signing secrets

## Observability

Authentication monitoring should detect:

- Sudden login-failure spikes
- Repeated OTP failures
- Repeated token refresh failures
- Reuse of revoked tokens
- Unusual session creation
- Excessive sessions per account
- Suspended-account access attempts
- Cross-tenant identity anomalies
- Signing-key or validation errors

Critical events must generate alerts.

## Data Privacy

Authentication collects only data necessary for identity, security, and operations.

Device and source metadata must have:

- A documented purpose
- A retention period
- Access controls
- Privacy review

Authentication logs must not become an uncontrolled source of personal data.

## Alternatives Considered

### Server-Side Cookie Sessions Only

Not selected as the sole API model.

Advantages:

- Straightforward server-side revocation
- Familiar browser security model
- Reduced token handling in some clients

Disadvantages:

- Less convenient for mobile and offline-capable clients
- Requires centralized session storage
- Browser-specific considerations
- More complex across multiple client types

Server-side session state may still support JWT revocation and session governance.

### Opaque Access Tokens

Not selected as the initial primary access-token format.

Advantages:

- Easy centralized revocation
- Minimal client-readable information
- No signed claim misuse

Disadvantages:

- Every request requires token lookup
- Additional centralized storage dependency
- Less portable for distributed validation

Opaque tokens remain a possible future option if evidence supports changing the model.

### Long-Lived JWTs without Server-Side Revocation

Rejected.

This would allow suspended or compromised users to retain access until token expiration and would violate real-time revocation requirements.

### Authorization Claims Stored Permanently in JWT

Rejected.

Roles, permissions, tenant membership, account state, and subscription state can change after token issuance.

The server must use authoritative current state.

### Multiple Authentication Middleware Implementations

Rejected.

Multiple identity sources create silent disagreement and unsafe authorization.

## Consequences

### Positive

- Supports web, mobile, and offline-capable clients
- Provides a consistent bearer-token API model
- Establishes one authoritative request identity
- Enables controlled token refresh
- Supports session-level 2FA
- Supports immediate revocation through server-side checks
- Centralizes authentication behaviour
- Improves auditability

### Negative

- JWT revocation requires server-side state or equivalent controls
- Browser token storage requires careful security design
- Token rotation and key management add operational work
- Email OTP depends on email-provider availability
- Every request may require current account-state checks
- Authentication infrastructure becomes security-critical shared infrastructure

These costs are accepted because authentication integrity is fundamental to ShuleOS.

## Risks and Mitigations

### Risk: Stolen Access Token

Mitigation:

- Short access-token lifetime
- Secure client storage
- TLS
- Server-side revocation
- Session tracking
- Token rotation
- Anomaly detection
- Minimal token claims

### Risk: Stale Role or Permission Claims

Mitigation:

- Resolve current authority server-side
- Recalculate during refresh
- Enforce role changes on the next request
- Avoid treating token claims as current authorization truth

### Risk: OTP Interception

Mitigation:

- Short validity
- Single use
- Attempt limits
- Secure email provider
- User alerts
- Future authenticator-app support
- Risk-based review for sensitive actions

### Risk: Brute Force

Mitigation:

- Rate limiting
- Progressive controls
- Generic responses
- Monitoring
- Auditing
- Challenge lockout

### Risk: Authentication Middleware Disagreement

Mitigation:

- One framework identity
- Central authentication middleware
- Automated contract tests
- Fail-closed downstream middleware
- Removal of duplicate abstractions

### Risk: Signing-Secret Compromise

Mitigation:

- Secret rotation
- Environment separation
- Incident response
- Session revocation
- Restricted secret access
- Key-management review

### Risk: Email Provider Outage

Mitigation:

- Retry queues
- Provider abstraction
- Safe user messaging
- Operational alerting
- Future secondary-factor options
- Recovery procedures

## Security Impact

This ADR defines the foundation upon which all authorization and tenant controls depend.

An authentication defect may invalidate every downstream security decision.

Authentication components therefore require:

- Focused security review
- Automated tests
- Dependency review
- Secret lifecycle management
- Observability
- Incident response readiness
- Conservative failure behaviour

## Tenant Impact

Authentication must establish identity before tenant resolution.

The token must not allow arbitrary tenant selection.

Users with approved access to multiple scopes must perform explicit, authorized tenant switching using a server-issued context.

Tenant switching must:

- Re-evaluate membership
- Re-evaluate permissions
- Be audited
- Reject unauthorized schools
- Avoid trusting client-supplied school identifiers

## Performance Impact

Authentication occurs on every protected request.

Performance must be managed through:

- Efficient token validation
- Indexed user and session lookups
- Carefully designed account-state checks
- Safe caching of non-sensitive reference data
- Bounded permission resolution
- Redis or another approved revocation store where adopted
- Monitoring of authentication latency

Performance optimization must not bypass current-state enforcement.

## Operational Impact

Platform operations must support:

- Signing-secret rotation
- Session revocation
- OTP delivery monitoring
- Email-provider monitoring
- Login-failure monitoring
- Token cleanup
- Security incident containment
- Recovery testing
- Authentication audit retention

Authentication failures must be distinguishable internally while remaining safe externally.

## Implementation Notes

The current ShuleOS backend uses Laravel 12 and JWT authentication.

Implementation must preserve:

- Laravel Auth as authoritative identity
- Centralized authentication contracts
- Safe authentication resources
- Generic failure responses
- Real-time account-state checks
- Refresh-time authority recalculation
- Token revocation on logout
- Explicit OTP challenge state
- Test-protected behaviour

This ADR does not claim every final authentication feature is already implemented.

Email OTP for every new session, school bootstrap activation, secret rotation, and advanced session monitoring will be delivered through the approved roadmap and tested before acceptance.

## Verification

Compliance will be verified through:

- Valid-login tests
- Invalid-credential tests
- Generic-error tests
- Suspended-user tests
- Locked-user tests
- Archived-user tests
- School-state tests
- OTP challenge tests
- OTP expiry tests
- OTP replay tests
- OTP attempt-limit tests
- Refresh tests
- Role-change refresh tests
- Real-time revocation tests
- Logout-reuse tests
- Password-reset tests
- Email-verification tests
- Single-source identity tests
- Tenant-resolution dependency tests
- Offline-sync authentication tests
- Secret-redaction tests
- Rate-limit tests
- Security review
- CI authentication-contract gates

## Constitution Compliance

This decision supports:

- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 14 — No endpoint bypasses the security pipeline
- Rule 17 — Audit important actions
- Rule 18 — Never expose internal exceptions
- Rule 19 — Every security feature is tested
- Rule 20 — Security review before merge
- Rule 28 — TenantContext is mandatory
- Rule 29 — Requests never choose their own tenant
- Rule 32 — Cross-tenant tests are mandatory
- Rule 46 — Frontend authorization never replaces backend authorization
- Rule 47 — Platform roles are protected
- Rule 67 — Security tests are mandatory
- Rule 74 — No secrets committed
- Rule 75 — Merge only after acceptance gates pass
- Rule 88 — Authentication has one source of truth
- Rule 89 — Authorization depends on authenticated identity and fails closed
- Rule 91 — Secrets follow one approved protection standard
- Rule 92 — Account-state flags ship with enforcement
- Rule 93 — Access revocation takes effect on the next request
- Rule 94 — Every module follows the approved architecture
- Rule 95 — Remove duplicate abstractions
- Rule 102 — Security-critical invariants are verified automatically
- Rule 103 — Every dependency is regularly scanned and patched
- Rule 104 — Every secret has a managed lifecycle
- Rule 105 — Every security incident follows a documented response process
- Rule 107 — Production systems are observable
- Rule 108 — Third-party providers remain replaceable through abstraction
- Rule 110 — Architecture rules are enforced by CI whenever possible
- Rule 112 — Every pull request follows documented governance
- Rule 114 — ShuleOS is continuously hardened

## Related ADRs

- ADR-0000 — Architecture Decision Record Process
- ADR-0001 — Modular Monolith Architecture
- ADR-0002 — Multi-Tenant Architecture
- ADR-0004 — Offline-First Architecture
- ADR-0006 — Notification Engine
- ADR-0008 — Resend Email
- ADR-0010 — Role Template System
- ADR-0011 — Multi-Level Tenant Hierarchy

## Implementation Checklist

- [ ] Laravel Auth confirmed as the single identity source
- [ ] Duplicate identity-resolution mechanisms removed
- [ ] JWT validation centralized
- [ ] Access-token lifetime documented
- [ ] Refresh-token lifecycle documented
- [ ] Token revocation mechanism implemented
- [ ] Logout invalidates the active session
- [ ] Account-state enforcement runs on protected requests
- [ ] Role and permission changes take effect on the next request
- [ ] School state is enforced
- [ ] First-login temporary password replacement implemented
- [ ] Email verification implemented
- [ ] Email OTP session challenge implemented
- [ ] OTP values stored hashed
- [ ] OTP rate limits implemented
- [ ] OTP replay protection tested
- [ ] Generic authentication errors verified
- [ ] Password-reset tokens stored hashed
- [ ] Session audit events implemented
- [ ] Signing-secret rotation documented
- [ ] Environment secrets reviewed
- [ ] Offline authentication behaviour documented
- [ ] Sync authentication revalidation implemented
- [ ] Authentication observability implemented
- [ ] CI authentication contract checks implemented
- [ ] Incident-response procedure covers signing-key compromise

## Supersession Status

This ADR has not been superseded.

## Final Decision

ShuleOS will use JWT access and refresh tokens for API authentication while preserving Laravel's authentication system as the one authoritative identity source for every protected request.

A valid token alone is not sufficient access.

Every request must re-establish a valid user, enforce current account and school state, resolve the authorized tenant, and pass downstream authorization.

Every new user session will require email-based OTP verification before normal access tokens are issued.

Authentication, revocation, tenant resolution, and authorization must fail closed whenever identity or security state is uncertain.
