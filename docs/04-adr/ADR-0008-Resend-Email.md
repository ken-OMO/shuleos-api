# ADR-0008 — Resend Transactional Email Provider

> School in Clouds

## Document Information

| Field                | Value                                                  |
| -------------------- | ------------------------------------------------------ |
| ADR                  | ADR-0008                                               |
| Decision             | Use Resend as the Initial Transactional Email Provider |
| Status               | Accepted                                               |
| Version              | 1.0                                                    |
| Owner                | Platform Engineering                                   |
| Repository           | `shuleos-api`                                          |
| Effective Date       | 02 August 2026                                         |
| Related Constitution | Engineering Constitution v1.1                          |
| Supersedes           | None                                                   |
| Superseded By        | None                                                   |

## Context

ShuleOS requires reliable transactional email for security, identity, subscription, school administration, finance, teaching, and communication workflows.

Email use cases include:

- School registration
- Initial administrator account creation
- Temporary-password delivery
- Email-address verification
- Login OTP delivery
- Password reset
- Password-change confirmation
- Session and security alerts
- Subscription invoices
- Subscription-payment confirmation
- Licence activation
- Subscription expiry warnings
- Subscription renewal confirmation
- School notices
- Staff invitations
- Guardian invitations
- Approval workflows
- Report availability
- Notification fallback
- Operational alerts

Email delivery must be:

- Secure
- Tenant-aware
- Queue-based
- Idempotent
- Auditable
- Observable
- Localized
- Provider-independent at the domain layer
- Resistant to duplicate sends
- Capable of tracking delivery outcomes
- Safe for learner and staff data

ShuleOS has selected Resend as the initial transactional email provider.

The provider must remain an implementation detail beneath the centralized Notification Engine.

## Decision

ShuleOS will use Resend as the initial provider for transactional email delivery.

All email will be initiated through the ShuleOS Notification Engine.

Business domains must not call Resend directly.

The implementation will use a provider adapter that translates internal email-delivery requests into Resend API requests and translates provider responses and webhook events into ShuleOS delivery states.

```text
Business Domain
    |
    v
Notification Intent
    |
    v
Notification Engine
    |
    v
Email Channel
    |
    v
Resend Adapter
    |
    v
Resend API
    |
    v
Recipient Mail Server
```

Resend is the approved initial provider, not an irreversible platform dependency.

## Architectural Boundaries

The Notification Engine owns:

- Recipient resolution
- Tenant validation
- Authorization
- Template selection
- Localization
- Tenant branding
- Message classification
- Idempotency
- Queue dispatch
- Retry policy
- Delivery-state normalization
- Audit logging
- Suppression rules

The Resend adapter owns:

- API authentication
- Request construction
- Provider request submission
- Provider response parsing
- Provider identifier storage
- Provider error normalization
- Webhook signature verification
- Webhook event normalization

Business domains own:

- The event that caused the notification
- The business meaning of the notification
- Safe template data
- Whether the communication is mandatory or optional

## Provider Abstraction

The application must define an internal email-provider contract.

A conceptual contract may include:

```php
interface EmailProvider
{
    public function send(EmailDeliveryRequest $request): EmailProviderResult;
}
```

The contract must use ShuleOS-owned data structures.

It must not expose provider-specific request or response objects to business domains.

The adapter should support:

- Single-recipient delivery
- Multiple approved recipients
- HTML content
- Plain-text content
- Subject
- Reply-to where permitted
- Approved headers
- Provider idempotency
- Provider tags or metadata where safe
- Attachments or protected links where approved
- Delivery identifiers
- Error normalization

## Laravel Integration

Resend may be integrated through its approved Laravel mail transport or through a dedicated provider adapter using the supported SDK.

The selected implementation must preserve:

- Laravel mail abstractions where useful
- Central Notification Engine policy
- Queue-based delivery
- Provider independence
- Testability
- Secret isolation
- Delivery-status tracking

The application must not scatter calls to a provider facade throughout controllers and services.

## Sending Domain

Production email must use a verified ShuleOS-controlled sending domain or approved tenant-controlled domain.

Examples of platform senders may include:

```text
no-reply@shuleos.com
security@shuleos.com
billing@shuleos.com
support@shuleos.com
```

The final domain names depend on approved production ownership and configuration.

Unverified or development-only sender identities must not be used in production.

## Domain Authentication

Production sending domains must be configured with approved email-authentication controls.

These may include:

- SPF
- DKIM
- DMARC
- Provider-required DNS records
- Approved return-path configuration

Domain verification must be completed before production release.

DNS changes must be reviewed and documented.

## Sender Identities

Sender identities must be controlled centrally.

Approved sender categories may include:

### Security

Used for:

- OTP
- Password reset
- Account lock
- Session revocation
- Suspicious activity

### Billing

Used for:

- Subscription invoices
- Payment confirmation
- Licence activation
- Renewal notices

### School Communication

Used for approved school communication.

### Platform Operations

Used for:

- Maintenance notices
- Operational incidents
- Support communication

A school must not choose arbitrary sender addresses that impersonate ShuleOS or another tenant.

## Tenant Branding

School communication may use approved tenant branding while remaining technically delivered through an authorized domain.

Tenant branding may include:

- School name
- School logo
- School contact information
- Approved reply-to address
- School footer
- School colours within approved templates

Tenant branding must not alter:

- Security warnings
- Privacy notices
- Mandatory legal wording
- ShuleOS platform billing identity
- Authentication instructions

## Reply-To Behaviour

Where replies are appropriate, the Notification Engine may resolve an approved reply-to address.

Reply-to addresses must be:

- Verified or approved
- Tenant-owned where school-specific
- Validated server-side
- Protected from arbitrary client input
- Appropriate to the notification category

Security and no-reply messages may prohibit replies.

## Message Categories

Email messages must be classified.

Approved categories include:

- Authentication
- Security
- Platform billing
- School finance
- Academic
- Administration
- Operational
- Parent communication
- Staff communication
- Marketing or optional communication

Classification controls:

- Template selection
- Sender identity
- Retention
- Mandatory status
- Suppression rules
- Tracking policy
- Audit requirements
- Data-minimization level

## Mandatory Transactional Email

Users may not opt out of essential transactional email such as:

- Email verification
- Login OTP
- Password reset
- Password-change notice
- Security alerts
- Account suspension notice
- Licence activation
- Legally required notices

Mandatory does not mean unlimited.

Mandatory communication remains subject to:

- Rate limits
- Correct recipient verification
- Safe templates
- Idempotency
- Security controls

## Optional Communication

Optional or promotional communication requires appropriate consent and preference handling.

Transactional email must not be used to disguise marketing messages.

Optional email must support approved unsubscribe or preference-management behaviour.

## Recipient Resolution

Recipients must be resolved from trusted ShuleOS records.

Protected workflows must not accept an arbitrary email address as the authoritative recipient.

The server must verify:

- Recipient identity
- Tenant relationship
- Communication role
- Current email address
- Verification status where required
- Account state
- Consent or mandatory status
- Suppression status

## Recipient Privacy

Bulk email must not expose recipients to one another.

Recipient lists must not be placed in visible `To` or `Cc` headers unless the workflow explicitly requires shared visibility and has approval.

Bulk delivery should ordinarily create individual messages or use a safe approved batch mechanism.

## Email Verification State

Where a workflow requires a verified email address, delivery alone does not prove ownership.

Verification requires a separate time-limited verification workflow.

The system must distinguish:

- Email recorded
- Verification pending
- Verified
- Verification expired
- Delivery bounced
- Address suppressed
- Address changed

## Template Ownership

Email templates are owned by the Notification Engine.

Templates must include:

- Unique key
- Category
- Language
- Version
- Subject
- HTML body
- Plain-text body
- Allowed variables
- Required variables
- Sender category
- Branding rules
- Sensitivity classification
- Approval status

Provider-hosted templates must not become the only source of template truth unless a later ADR approves that model.

## Template Rendering

Template rendering should occur using approved ShuleOS template data.

Every variable must be allowlisted.

Unsafe arbitrary HTML injection is prohibited.

User-provided values must be escaped according to their context.

Template rendering failures must prevent delivery rather than send broken or partially rendered messages.

## HTML and Plain Text

Transactional email should provide:

- Safe HTML content
- Plain-text alternative

HTML must avoid:

- Untrusted scripts
- Embedded credentials
- Unsafe remote content
- Excessive tracking
- Unnecessary personal data
- Unsupported active content

## Localization

English and Kiswahili are first-class email languages.

Language resolution may use:

- User preference
- Guardian preference
- School default
- Platform default
- Template availability

Missing localized templates must use an approved fallback.

The system must not send empty or partially translated messages.

## Security Email

Security email requires stronger controls.

Examples include:

- OTP
- Password reset
- New session alert
- Account lock
- Credential change
- Suspicious activity

Security email must:

- Avoid exposing passwords
- Avoid including raw tokens unnecessarily
- Use short-lived protected links
- Identify ShuleOS clearly
- Include safe expiry information
- Avoid revealing internal system details
- Be rate-limited
- Be audited

## OTP Email

Email OTP messages must contain only the information required to complete the authentication challenge.

OTP email must:

- Use the registered user email
- Contain a time-limited code
- Avoid including passwords
- Avoid including full sensitive account details
- State that the code should not be shared
- Avoid allowing replies to authenticate a user
- Be idempotent for one authentication challenge
- Be subject to resend limits

The OTP itself must be stored hashed in ShuleOS.

Resend is only the delivery provider.

## Password Reset Email

Password-reset email must use a random, short-lived, single-use server-generated token or protected link.

The email provider must not determine reset validity.

A reset email must not reveal whether other accounts exist.

## School Bootstrap Email

When a school is activated, the initial administrator email may include:

- Account email
- Temporary login instructions
- Password-change requirement
- Licence-activation instructions where applicable
- Support information

A permanent plain-text password must not be sent.

Temporary credentials must expire or require replacement on first use.

## Subscription Email

Platform subscription email may include:

- Plan
- Billing period
- Invoice reference
- Amount
- Due date
- Payment instructions
- Payment-confirmation status
- Renewal status
- Licence-activation result

Platform billing messages must be clearly identified as ShuleOS communications.

## Academic Email

Academic email must respect publication state.

Unpublished:

- Marks
- Reports
- Exam papers
- Assessment outcomes

must not be disclosed.

Where possible, sensitive academic information should be accessed through an authenticated portal rather than embedded directly in email.

## Learner Data Protection

Emails involving learners must minimize learner personal data.

Avoid unnecessary inclusion of:

- Full legal identifiers
- Birth certificate numbers
- Assessment numbers
- Health data
- Discipline details
- Full financial history
- Confidential marks

Guardian relationships must be verified before learner-specific email is sent.

## Attachments

Sensitive files should ordinarily be provided through protected download links rather than direct attachments.

Approved links must:

- Be short-lived
- Require authorization where possible
- Refer to private Cloudflare R2 objects
- Be auditable
- Avoid exposing internal object keys

Direct attachments require explicit workflow approval.

## Queue-Based Delivery

Email delivery must be asynchronous by default.

```text
Business Transaction
    |
    v
Notification Intent Persisted
    |
    v
Transaction Committed
    |
    v
Email Job Queued
    |
    v
Resend Adapter Called
    |
    v
Delivery Result Recorded
```

Provider failure must not roll back an already completed authoritative business transaction.

## Transactional Outbox

High-value email intents should use the Notification Engine outbox.

Examples include:

- OTP
- Subscription activation
- Payment confirmation
- Password reset
- Security notification

The outbox prevents business events from being lost between database commit and queue dispatch.

## Idempotency

Each email delivery must have a ShuleOS idempotency identity.

It should account for:

- Event
- Resource
- Recipient
- Template version
- Channel
- Intended occurrence
- Tenant or platform scope

Where supported, the provider request should also use the provider idempotency mechanism.

ShuleOS must maintain its own durable idempotency record because provider retention policies must not become the sole duplicate-prevention control.

## Duplicate Prevention

Duplicate email can result from:

- Queue retry
- HTTP retry
- Replayed event
- Duplicate provider request
- Worker crash
- Callback replay
- Repeated user action

Duplicate prevention must operate before the provider call.

A provider response received after an uncertain timeout must be reconciled before a blind resend where practical.

## Delivery States

Internal normalized states may include:

- Pending
- Queued
- Processing
- Provider Accepted
- Sent
- Delivered
- Delayed
- Bounced
- Complained
- Failed
- Suppressed
- Cancelled
- Expired
- Unknown
- Dead Letter

The exact provider event names must be mapped into these internal states.

## Provider Message Identifier

The provider response identifier must be stored.

It supports:

- Webhook correlation
- Delivery tracking
- Reconciliation
- Support investigation
- Duplicate analysis

The identifier must be indexed where appropriate.

## Webhooks

Resend webhook events will be accepted through a dedicated provider endpoint.

The webhook route must:

- Use HTTPS
- Verify the provider signature
- Read the raw request body when signature verification requires it
- Reject invalid signatures
- Reject expired or replayed events where supported
- Normalize the event
- Resolve the original delivery record
- Process idempotently
- Return a safe response
- Record audit evidence

Webhook requests are not user JWT-authenticated requests.

## Webhook Secret

The webhook signing secret must be:

- Stored outside source control
- Environment-specific
- Rotatable
- Redacted
- Excluded from API responses
- Limited to the intended endpoint
- Treated as compromised if exposed

## Webhook Event Processing

A conceptual flow is:

```text
Webhook received
    |
    v
Signature verified
    |
    v
Event idempotency checked
    |
    v
Provider message resolved
    |
    v
Internal status normalized
    |
    v
Delivery record updated
    |
    v
Suppression or alert rules evaluated
    |
    v
Audit event recorded
```

## Webhook Idempotency

Every webhook event must have a stored replay identifier or equivalent normalized uniqueness key.

A repeated webhook must return the existing result without applying duplicate side effects.

## Bounce Handling

Bounce events must be categorized where possible.

Possible categories include:

- Temporary bounce
- Permanent bounce
- Invalid address
- Mailbox full
- Domain failure
- Policy rejection

Permanent failure may cause:

- Address suppression
- Verification-state review
- User alert through another approved channel
- Administrative review

A single temporary failure must not permanently invalidate an address automatically.

## Complaint Handling

Complaint events must be handled promptly.

Possible consequences include:

- Suppression of optional email
- Security review
- Tenant communication review
- Template review
- Sender-reputation investigation

Mandatory security communication requires a separately approved fallback strategy.

## Suppression

The Notification Engine must maintain suppression state where necessary.

Suppression reasons may include:

- Permanent bounce
- Complaint
- Invalid address
- User opt-out
- Administrative block
- Provider block
- Legal restriction

Suppression must be tenant-aware where appropriate while still preventing unsafe repeated sends.

## Retry Policy

Retryable conditions may include:

- Network timeout
- Temporary provider outage
- Provider rate limit
- Temporary server error

Non-retryable conditions may include:

- Invalid recipient
- Invalid verified-domain configuration
- Rejected request data
- Suppressed address
- Invalid template

Retries require:

- Bounded exponential backoff
- Jitter
- Maximum attempts
- Idempotency
- Dead-letter handling
- Operational visibility

## Timeout Handling

Provider requests require explicit connection and request timeouts.

A timeout creates an uncertain result.

The application must avoid assuming that timeout always means the provider did not accept the email.

Reconciliation should use stored idempotency and provider identifiers where available.

## Dead-Letter Handling

Email jobs that fail permanently must enter a reviewable dead-letter state.

Dead-letter records should contain:

- Tenant or platform scope
- Recipient reference
- Template key
- Failure category
- Attempts
- Last provider response code where safe
- Correlation identifier
- Created time
- Last attempt

Sensitive content and secrets must not be duplicated unnecessarily.

## Rate Limiting

Rate limits must protect:

- OTP requests
- OTP resend
- Password reset
- Account invitations
- Bulk school communication
- Tenant email volume
- Recipient email volume
- Provider API volume

Rate limiting must not silently drop critical email.

## Bulk Email

Resend may support batch-style provider operations, but ShuleOS must retain per-recipient ownership, status, idempotency, privacy, and auditability.

Bulk sends require:

- Approved permission
- Audience preview
- Recipient count
- Template preview
- Confirmation
- Bounded batching
- Queue processing
- Cancellation where practical
- Delivery reporting

## Provider Tags and Metadata

Provider tags or metadata may be used for safe operational correlation.

Allowed values may include:

- Notification category
- Environment
- Tenant-safe opaque identifier
- Template key
- Correlation identifier

Do not include:

- Learner names
- Passwords
- OTP values
- National identification numbers
- Birth certificate numbers
- Full message content
- Raw internal database data

## Environment Separation

Development, testing, staging, and production must use separate provider configuration.

Production API keys must never be used in automated tests or local development.

Non-production email should use:

- Test recipients
- Safe provider modes
- Development domains
- Fake mail drivers
- Test doubles

## Test Strategy

Automated tests must not depend on live Resend delivery.

Tests should use:

- Laravel mail fakes
- Notification Engine fakes
- Provider adapter mocks
- Webhook fixtures
- Signature-verification tests
- Contract tests
- Dedicated integration tests outside the ordinary unit suite

## API-Key Management

The Resend API key must be:

- Stored in an environment secret
- Excluded from Git
- Environment-specific
- Least-privileged where supported
- Rotatable
- Audited
- Restricted to authorized operators
- Redacted from logs

A leaked key must be revoked and replaced immediately.

## Configuration

Expected configuration may include variables such as:

```env
MAIL_MAILER=resend
RESEND_API_KEY=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"
RESEND_WEBHOOK_SECRET=
```

The exact final variable names must match the implemented configuration.

`.env.example` must contain placeholders only.

## Logging

Logs must not contain:

- API keys
- Webhook secrets
- OTP codes
- Password-reset tokens
- Verification tokens
- Raw access links
- Full sensitive email bodies
- Unmasked learner identifiers

Safe logs may contain:

- Internal notification identifier
- Provider message identifier
- Tenant identifier
- Template key
- Delivery state
- Failure category
- Correlation identifier

## Audit Logging

Important events include:

- Email intent created
- Email queued
- Provider request submitted
- Provider accepted
- Delivered
- Bounced
- Complained
- Suppressed
- Retry scheduled
- Dead-lettered
- API key rotated
- Webhook secret rotated
- Sending domain changed
- Sender identity changed
- Template version changed

## Observability

Operational metrics should include:

- Email intents created
- Queue depth
- Send latency
- Provider latency
- Provider error rate
- Delivery rate
- Bounce rate
- Complaint rate
- Suppression rate
- Retry volume
- Dead-letter volume
- Webhook signature failures
- Webhook processing failures
- OTP-delivery latency
- Per-tenant volume
- Per-category volume

Critical anomalies must create alerts.

## Sender Reputation

ShuleOS must protect sending reputation.

Controls include:

- Verified domains
- Proper email authentication
- Suppression handling
- Complaint monitoring
- Bounce monitoring
- No unsolicited bulk sending
- Rate limiting
- Template quality review
- Consent enforcement
- Separation of transactional and optional communication where appropriate

A tenant must not be allowed to damage the shared platform sender reputation through uncontrolled campaigns.

## Noisy-Neighbour Protection

One school's email volume must not degrade delivery for others.

Controls may include:

- Per-tenant limits
- Queue fairness
- Bulk-send approval
- Daily volume limits
- Priority queues
- Reserved security-email capacity
- Abuse monitoring

## Privacy

Email inherently transfers information outside the ShuleOS platform.

Messages must use data minimization.

Sensitive content should ordinarily remain inside the authenticated portal.

Email retention and tracking must have documented purposes.

## Tracking

Open and click tracking should be disabled or minimized for sensitive transactional email unless a documented operational requirement justifies it.

Delivery tracking is distinct from behavioural tracking.

Security, authentication, learner, and financial email require stricter privacy treatment.

## Data Residency and Processing

The use of Resend introduces an external processor.

ShuleOS must document:

- Processing purpose
- Data categories sent
- Applicable contractual terms
- Subprocessors where relevant
- Cross-border processing implications
- Retention
- Deletion
- Security controls
- Children's-data implications

No unnecessary platform data should be transmitted to the provider.

## Retention

Internal retention must be defined for:

- Email content
- Delivery status
- Provider identifiers
- Recipient addresses
- Webhook events
- Bounce records
- Complaint records
- Audit logs

Provider retention behaviour must be reviewed separately.

## Incident Response

Email incidents include:

- API-key exposure
- Webhook-secret exposure
- Unauthorized bulk send
- Cross-tenant email
- OTP delivery to wrong recipient
- Sending-domain compromise
- Template injection
- High complaint volume
- Provider outage
- Provider account suspension

Incident response must support:

- Key revocation
- Queue pause
- Sender disablement
- Template disablement
- Recipient notification where required
- Evidence preservation
- Provider coordination
- Post-incident review

## Provider Outage

During a Resend outage:

- Business transactions should remain authoritative.
- Email intents remain queued.
- Safe retries use backoff.
- Critical operational alerts use an approved fallback where available.
- Users receive safe status information.
- Duplicate sends are prevented when service returns.
- Queue backlog is monitored and processed fairly.

## Provider Replacement

Resend must remain replaceable.

Replacement requires:

- A new provider adapter
- Contract tests
- Configuration migration
- Domain verification
- Webhook migration
- Delivery-state mapping
- Key rotation
- Rollback plan
- An ADR if the strategic provider decision changes

Business domains must not require modification merely because the provider changes.

## Alternatives Considered

### Laravel Log Mailer

Suitable only for development.

It does not deliver production email.

### Traditional SMTP Provider

Not selected as the initial primary integration.

SMTP is broadly compatible but may provide less structured API-level delivery control, idempotency, and provider-event integration than the selected API approach.

SMTP may remain an emergency or future adapter if approved.

### Self-Hosted Mail Server

Rejected.

It would introduce substantial:

- Deliverability work
- Security risk
- Reputation management
- DNS management
- Abuse handling
- Monitoring
- Operational burden

### Resend

Accepted as the initial transactional email provider because it fits the Laravel/PHP ecosystem and the provider-adapter architecture.

## Consequences

### Positive

- Central transactional email provider
- Laravel/PHP integration path
- API-based delivery
- Provider idempotency support
- Delivery webhook support
- Centralized domain verification
- Easier operational visibility
- Supports the Notification Engine abstraction

### Negative

- Introduces third-party dependency
- Email delivery depends on external availability
- Requires DNS and sender-reputation management
- Provider limits and policies must be observed
- Webhook and key security require operations
- External processing creates privacy and compliance obligations

These costs are accepted because reliable email delivery is required and self-hosting creates greater operational risk.

## Risks and Mitigations

### Risk: Duplicate Email

Mitigation:

- ShuleOS idempotency
- Provider idempotency
- Outbox processing
- Retry controls
- Reconciliation

### Risk: Cross-Tenant Recipient

Mitigation:

- Server-side recipient resolution
- Tenant-aware intents
- Cross-tenant tests
- Central templates
- Audit logging

### Risk: API-Key Exposure

Mitigation:

- Secret storage
- Rotation
- Redaction
- Least privilege
- Environment separation

### Risk: Webhook Forgery

Mitigation:

- Signature verification
- Raw-body validation
- Replay protection
- HTTPS
- Idempotent processing

### Risk: High Bounce or Complaint Rate

Mitigation:

- Suppression
- Contact validation
- Consent controls
- Monitoring
- Tenant limits
- Template review

### Risk: Provider Outage

Mitigation:

- Queues
- Backoff
- Dead-letter handling
- Monitoring
- Provider abstraction
- Fallback planning

### Risk: Sensitive Data in Email

Mitigation:

- Data minimization
- Protected portal links
- Template review
- Child-data controls
- No raw secrets

## Security Impact

Email is part of the authentication and recovery boundary.

Compromise of email delivery configuration may affect:

- OTP
- Password reset
- Email verification
- Account activation
- Security notifications

The integration requires focused security review, secret management, webhook verification, rate limiting, and incident response.

## Tenant Impact

Tenant-specific branding, reply-to configuration, templates, recipients, and delivery records must remain tenant scoped.

Platform security and billing email remains platform owned.

Schools cannot access another school's delivery history or configuration.

## Performance Impact

Email delivery is asynchronous.

Performance controls include:

- Queue workers
- Bounded batches
- Provider timeouts
- Retry limits
- Indexed delivery records
- Webhook queueing
- Per-tenant fairness
- Priority queues for security messages

## Operational Impact

Platform Engineering must operate:

- API-key management
- Domain verification
- DNS configuration
- Sender identities
- Queue monitoring
- Webhook monitoring
- Bounce and complaint handling
- Suppression
- Delivery reporting
- Provider outage response
- Key rotation
- Compliance review

## Implementation Notes

Resend will be integrated under the Notification Engine and not directly from domain controllers.

Implementation should use the approved Laravel or PHP integration approach while preserving the internal provider contract.

Provider-specific limitations must be confirmed against current official documentation during implementation.

## Verification

Compliance will be verified through:

- Provider-adapter unit tests
- Notification Engine integration tests
- Tenant-recipient tests
- Cross-tenant email tests
- Template escaping tests
- Localization tests
- Idempotency tests
- Queue retry tests
- Timeout tests
- Dead-letter tests
- API-key redaction tests
- Webhook signature tests
- Webhook replay tests
- Bounce handling tests
- Complaint handling tests
- Suppression tests
- Domain configuration review
- OTP delivery contract tests
- Password-reset tests
- School-bootstrap email tests
- Subscription-email tests
- Child-data privacy tests
- Provider outage tests
- Security review
- CI email-contract gates

## Constitution Compliance

This decision supports:

- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 7 — Human Experience
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 17 — Audit important actions
- Rule 19 — Every security feature is tested
- Rule 28 — TenantContext is mandatory
- Rule 30 — Every query is tenant scoped
- Rule 41 — Humanized interface
- Rule 43 — Professional language
- Rule 57 — Email via Resend
- Rule 60 — Notification Engine selects the delivery channel
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 69 — Performance tests are mandatory
- Rule 74 — No secrets committed
- Rule 88 — Authentication has one source of truth
- Rule 91 — Secrets follow one approved protection standard
- Rule 100 — Idempotency is enforced using stored keys
- Rule 102 — Security-critical invariants are verified automatically
- Rule 103 — Dependencies are regularly scanned and patched
- Rule 104 — Secrets have a managed lifecycle
- Rule 105 — Security incidents follow a documented response process
- Rule 107 — Production systems are observable
- Rule 108 — Third-party providers remain replaceable
- Rule 109 — Every data category has a retention policy
- Rule 110 — Architecture rules are enforced by CI
- Rule 121 — Learner information receives the highest privacy classification
- Rule 126 — Localization is a platform capability
- Rule 127 — English and Kiswahili are first-class languages

## Related ADRs

- ADR-0000 — Architecture Decision Record Process
- ADR-0001 — Modular Monolith Architecture
- ADR-0002 — Multi-Tenant Architecture
- ADR-0003 — JWT Authentication
- ADR-0005 — School Payment Architecture
- ADR-0006 — Notification Engine
- ADR-0007 — Cloudflare R2
- ADR-0009 — Africa's Talking SMS
- ADR-0010 — Role Template System
- ADR-0011 — Multi-Level Tenant Hierarchy

## Implementation Checklist

- [ ] Resend account ownership documented
- [ ] Production sending domain verified
- [ ] SPF configured
- [ ] DKIM configured
- [ ] DMARC policy reviewed
- [ ] Sender identities approved
- [ ] Environment-specific API keys configured
- [ ] API keys excluded from source control
- [ ] API-key rotation documented
- [ ] Internal email-provider contract implemented
- [ ] Resend adapter implemented
- [ ] Notification Engine integration implemented
- [ ] Queue-based delivery implemented
- [ ] Transactional outbox implemented for high-value email
- [ ] ShuleOS idempotency implemented
- [ ] Provider idempotency applied
- [ ] Provider message identifiers stored
- [ ] Delivery-state normalization implemented
- [ ] Webhook endpoint implemented
- [ ] Webhook signatures verified
- [ ] Webhook replay protection implemented
- [ ] Bounce handling implemented
- [ ] Complaint handling implemented
- [ ] Suppression handling implemented
- [ ] Retry policy implemented
- [ ] Dead-letter handling implemented
- [ ] English templates implemented
- [ ] Kiswahili templates implemented
- [ ] HTML and plain-text versions implemented
- [ ] Tenant branding implemented
- [ ] Security templates reviewed
- [ ] OTP email implemented
- [ ] Password-reset email implemented
- [ ] School-bootstrap email implemented
- [ ] Subscription email implemented
- [ ] Protected-link attachments implemented
- [ ] Rate limits implemented
- [ ] Tenant volume controls implemented
- [ ] Logging redaction verified
- [ ] Email observability implemented
- [ ] Provider outage runbook created
- [ ] Privacy and data-processing review completed
- [ ] Incident response updated
- [ ] CI email-contract gates implemented

## Supersession Status

This ADR has not been superseded.

## Final Decision

ShuleOS will use Resend as its initial transactional email provider.

All email will flow through the centralized Notification Engine and an internal provider adapter.

Business domains will not call Resend directly.

Email delivery must remain tenant-safe, idempotent, queue-based, localized, auditable, privacy-aware, observable, and replaceable.

Resend is responsible for provider delivery; ShuleOS remains responsible for identity, authorization, recipient resolution, templates, business rules, security, and compliance.
