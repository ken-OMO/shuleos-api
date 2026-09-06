# ADR-0006 — Notification Engine

> School in Clouds

## Document Information

| Field                | Value                                     |
| -------------------- | ----------------------------------------- |
| ADR                  | ADR-0006                                  |
| Decision             | Unified Multi-Channel Notification Engine |
| Status               | Accepted                                  |
| Version              | 1.0                                       |
| Owner                | Platform Engineering                      |
| Repository           | `shuleos-api`                             |
| Effective Date       | 02 August 2026                            |
| Related Constitution | Engineering Constitution v1.1             |
| Supersedes           | None                                      |
| Superseded By        | None                                      |

## Context

ShuleOS must communicate reliably with:

- School administrators
- Principals and school leaders
- Teachers
- Finance officers
- Parents and guardians
- Learners
- Platform administrators
- White-label brand administrators
- Support and operations staff

Notifications may be triggered by many domains, including:

- Authentication
- School registration
- Subscription renewal
- Payments
- Attendance
- Assessment
- Examination results
- Lesson-plan approval
- Records of work
- Discipline
- Transport
- Boarding
- Leadership and elections
- File processing
- Security events
- Offline synchronization
- Operational incidents

The platform intends to support multiple delivery channels:

- In-app notifications
- Email through Resend
- SMS through Africa's Talking
- WhatsApp where appropriate
- Push notifications in future mobile applications

Without a centralized notification architecture, each domain could implement delivery independently, resulting in:

- Duplicate provider integrations
- Inconsistent templates
- Incorrect recipients
- Cross-tenant leakage
- Missing audit records
- Duplicate sends
- Poor retry handling
- Hard-coded provider logic
- Uncontrolled costs
- Inconsistent localization
- Difficult provider replacement

Notifications must therefore be treated as a shared platform capability with tenant-aware, secure, asynchronous, and auditable delivery.

## Decision

ShuleOS will implement a unified, event-driven, multi-channel Notification Engine.

Business domains will publish approved notification intents or domain events.

The Notification Engine will determine:

- Whether notification is required
- Who should receive it
- Which channels are allowed
- Which template and language apply
- Which tenant branding applies
- Whether the message is immediate, scheduled, or digest-based
- Whether consent or preferences permit delivery
- Which provider should deliver the message
- Whether tenant SMS credits or channel quotas are sufficient
- How retries and failures are handled
- What audit and delivery records are created

Business domains must not call email, SMS, WhatsApp, or push providers directly.

## Core Architecture

```text
Business Event
    |
    v
Notification Intent
    |
    v
Recipient Resolution
    |
    v
Policy and Preference Evaluation
    |
    v
Template and Localization Resolution
    |
    v
Channel Selection
    |
    v
Queue
    |
    v
Provider Adapter
    |
    v
Delivery Attempt
    |
    v
Status, Audit, Retry, or Dead Letter
```

The engine separates business intent from provider implementation.

## Notification Intent

A notification intent describes why communication is needed.

An intent should include only trusted server-side information such as:

- Event type
- Tenant or platform scope
- Resource reference
- Intended audience type
- Priority
- Sensitivity classification
- Requested delivery time
- Correlation identifier
- Idempotency key
- Template key
- Safe template data

The intent must not contain provider credentials.

The intent must not trust client-supplied recipients without server-side validation.

## Event Sources

Approved notification events may originate from:

- Domain services
- Application services
- Jobs
- Scheduled tasks
- Security monitoring
- Provider callbacks
- Platform operations

Controllers should not contain complex notification-delivery logic.

The originating domain owns the business event.

The Notification Engine owns delivery orchestration.

## Recipient Resolution

Recipients must be resolved server-side.

The engine must verify:

- Tenant ownership
- User or contact relationship
- Role or audience membership
- Current account state
- Current communication permission
- Current contact details
- Channel eligibility
- Consent where required
- Exclusion and suppression rules

Caller-supplied email addresses or phone numbers must not be accepted as authoritative recipients for protected workflows unless the workflow explicitly validates them.

## Supported Recipient Types

Recipient types may include:

- Specific user
- Specific guardian
- Specific learner where permitted
- School role
- School staff group
- Class or stream audience
- Transport-route audience
- Boarding-unit audience
- Platform role
- Brand role
- External verified contact where approved

Broad audience sends must be bounded, permission-controlled, and auditable.

## Channel Strategy

The Notification Engine selects the delivery channel according to:

- Message criticality
- User preferences
- Tenant configuration
- Legal and consent requirements
- Provider availability
- Channel cost
- Contact availability
- Message sensitivity
- Fallback rules
- Delivery urgency

The engine may use one or more channels.

## Channel Priority

Default strategic guidance is:

### In-App

Preferred for routine platform activity and notification history.

### Email

Used for:

- Account activation
- OTP delivery
- Password reset
- Subscription information
- Detailed notices
- Reports and formal communication
- Security notifications

### WhatsApp

Preferred where appropriate for conversational or parent-facing communication after legal, consent, provider, and template requirements are satisfied.

### SMS

Reserved primarily for critical, urgent, or high-value communication because it has direct per-message cost.

Examples include:

- Security alerts
- Critical payment confirmation
- Emergency school communication
- Time-sensitive OTP fallback where approved
- Urgent attendance or transport alerts

### Push Notifications

Planned for future mobile applications.

Push notifications must not expose sensitive information on locked screens by default.

## Criticality Levels

Notifications should use an approved priority model.

### Informational

Examples:

- General announcements
- Non-urgent reminders
- Routine workflow updates

### Normal

Examples:

- Assignment notification
- Lesson-plan approval
- Upcoming event reminder

### Important

Examples:

- Payment received
- Attendance concern
- Subscription expiry warning
- Mark-entry deadline

### Critical

Examples:

- Security incident
- Account compromise warning
- Emergency school alert
- Suspicious payment activity
- System outage affecting operations

Critical classification must not be overused.

## Template Engine

Every notification must use an approved template unless an explicit workflow permits controlled free text.

Templates must define:

- Template key
- Channel
- Language
- Subject where applicable
- Body
- Allowed variables
- Required variables
- Sensitivity level
- Tenant-branding behaviour
- Version
- Approval status

Arbitrary template-variable injection is prohibited.

## Template Versioning

Templates must be versioned.

A delivery record should identify the template version used.

Changing a template must not alter historical delivery records.

Templates for security, payments, academic results, and legal communication require stronger review.

## Localization

English and Kiswahili are first-class notification languages.

The engine should resolve language using approved preferences such as:

- User preference
- School default language
- Template availability
- Platform fallback

Business logic must not contain hard-coded translated messages.

Missing translations should use an approved fallback rather than sending broken content.

## Tenant Branding

Tenant-owned notifications may include approved school branding such as:

- School name
- School logo
- Contact details
- Approved sender name
- Reply instructions

Branding must not alter security-critical wording.

Platform billing and ShuleOS security notifications must remain clearly identified as ShuleOS communications.

A school must not impersonate ShuleOS or another school.

## Provider Abstraction

The Notification Engine must use provider adapters.

Examples include:

- Resend email adapter
- Africa's Talking SMS adapter
- Future WhatsApp adapter
- Future push adapter

Business domains must depend on Notification Engine contracts rather than provider SDKs.

This allows:

- Provider replacement
- Failover
- Testing
- Cost comparison
- Environment isolation
- Centralized credential management

## Queue-Based Delivery

External delivery must be asynchronous by default.

The originating business transaction should:

1. Complete its authoritative write.
2. Persist the notification intent or outbox event.
3. Commit successfully.
4. Dispatch delivery after commit.

This avoids sending notifications for rolled-back transactions.

## Transactional Outbox

ShuleOS should use an outbox-style pattern for high-value notifications.

The business write and notification intent should be persisted in the same transaction where appropriate.

A worker later processes the outbox.

This reduces the risk of:

- Business action succeeds but message is lost
- Message is sent but business action rolls back
- Duplicate side effects after retry

## Idempotency

Notification creation and delivery must be idempotent.

An idempotency key should account for:

- Tenant or platform scope
- Event
- Resource
- Recipient
- Channel
- Template version
- Intended occurrence

A repeated event must not create duplicate messages unless repeated delivery is explicitly intended.

Provider retry must not create duplicate user-visible communication.

## Delivery Status

Approved delivery states may include:

- Pending
- Queued
- Processing
- Sent
- Delivered
- Failed
- Deferred
- Suppressed
- Cancelled
- Expired
- Dead Letter
- Unknown

Provider acceptance does not always mean recipient delivery.

The engine must distinguish these states where provider capabilities permit.

## Delivery Attempts

Each delivery attempt should record:

- Notification
- Channel
- Provider
- Attempt number
- Request time
- Response time
- Safe provider reference
- Result
- Failure category
- Retry eligibility
- Correlation identifier

Raw provider secrets and unnecessary payloads must not be stored.

## Retry Strategy

Retryable failures may include:

- Temporary network failure
- Provider timeout
- Provider rate limiting
- Temporary provider outage

Permanent failures may include:

- Invalid recipient
- Rejected template
- Suppressed recipient
- Unsupported destination
- Invalid tenant configuration

Retries must use:

- Bounded exponential backoff
- Maximum attempts
- Jitter
- Provider-specific retry guidance
- Idempotency
- Dead-letter handling

Permanent errors must not be retried indefinitely.

## Dead-Letter Handling

Messages that cannot be delivered after approved retries must enter a dead-letter or review state.

Dead-letter records should support:

- Tenant
- Notification type
- Recipient
- Channel
- Failure category
- Last attempt
- Retry history
- Manual action where permitted

Dead-letter processing must not expose sensitive message content unnecessarily.

## Fallback Channels

Fallback may be used where policy permits.

Example:

```text
Email delivery fails
    |
    v
Message classified Important
    |
    v
SMS fallback allowed
    |
    v
SMS credit and consent verified
    |
    v
SMS sent
```

Fallback must not:

- Bypass user preferences
- Bypass consent
- Leak sensitive data
- Create uncontrolled cost
- Duplicate successful delivery

Security-critical fallbacks require explicit design.

## Preferences

Users may configure preferences for non-mandatory communication.

Preferences may include:

- Channel
- Language
- Digest frequency
- Quiet hours
- Notification categories

Users may not disable mandatory communication such as:

- Security alerts
- Account activation
- Password reset
- OTP
- Critical platform notices
- Legally required communication

## Consent and Suppression

Marketing or optional communication must respect consent.

Suppression may apply because of:

- User opt-out
- Invalid contact
- Complaint
- Provider block
- Legal restriction
- School policy
- Child-data protection requirement

Transactional messages must not be disguised as marketing.

## Quiet Hours

Non-critical notifications may respect tenant or user quiet hours.

Critical messages may bypass quiet hours only under an approved policy.

Time calculations must use the recipient or school timezone where available.

## Scheduling

Notifications may be:

- Immediate
- Scheduled
- Delayed
- Recurring
- Digest-based

Scheduled messages must revalidate where appropriate:

- Tenant state
- Recipient eligibility
- Resource state
- Cancellation state
- Subscription state
- Template status

A scheduled message must not send stale or invalid information blindly.

## Digests

Digest delivery may combine non-critical events.

Digest rules must define:

- Eligible categories
- Frequency
- Maximum entries
- Data sensitivity
- Language
- Tenant branding

Critical events must not wait for a digest.

## In-App Notifications

In-app notifications should provide:

- Read and unread state
- Created time
- Safe summary
- Deep link where authorized
- Expiry where appropriate
- Tenant ownership
- Category
- Priority

A deep link must still enforce current authorization when opened.

The notification record must not become a bypass to protected resources.

## Email Delivery

Email delivery is implemented through the provider abstraction.

Resend is the approved initial provider and is documented separately in ADR-0008.

Email content must support:

- Safe HTML
- Plain-text alternative
- Tenant branding
- Unsubscribe behaviour where applicable
- Provider tracking rules
- Sensitive-data minimization

Email must not include raw secrets, passwords, or excessive learner data.

## SMS Delivery

SMS delivery is implemented through the provider abstraction.

Africa's Talking is the approved planned provider and is documented separately in ADR-0009.

SMS must be concise.

Sensitive information must be minimized because SMS may appear on unlocked devices.

Each school may purchase or consume SMS credits according to the approved wallet model.

## WhatsApp Delivery

WhatsApp integration requires a future provider-specific ADR or extension.

The architecture must account for:

- Approved message templates
- Opt-in
- Conversation windows
- Provider policy
- Cost
- Tenant branding
- Consent
- Delivery status
- Data minimization

WhatsApp must not bypass the Notification Engine.

## Push Notifications

Push support requires a future mobile-notification ADR or extension.

Push payloads must contain minimal data.

Sensitive detail should be loaded securely after the user opens the app and authenticates.

## SMS Wallet and Cost Control

Where schools purchase SMS credits:

- Wallet ownership belongs to the school tenant.
- Credit purchases belong to ShuleOS platform billing.
- Message cost is calculated server-side.
- Credits are reserved or deducted idempotently.
- Failed delivery treatment is documented.
- Adjustments are append-only and auditable.
- Negative balances are prohibited unless explicitly approved.

One school's SMS use must not consume another school's credits.

## Recipient Validation

Contact details must be normalized and validated.

Email addresses should be validated according to approved application rules.

Phone numbers should use an approved normalized format.

Validation does not prove ownership of the contact channel.

Verified contact status should be tracked where required.

## Tenant Isolation

Every tenant-owned notification must include tenant ownership.

Tenant isolation applies to:

- Notification intents
- Templates
- Preferences
- Branding
- Provider configuration
- SMS wallets
- Delivery records
- Attachments
- Digests
- Audit records

Cross-tenant recipient resolution is prohibited unless an approved higher governance scope explicitly authorizes it.

## Platform Notifications

Platform notifications are separate from school-owned notifications.

Examples include:

- ShuleOS subscription invoices
- Platform security alerts
- Platform maintenance notices
- Licence activation
- Platform support communication

Platform notifications must use platform-owned templates, credentials, branding, and permissions.

## Brand-Level Notifications

Brand-level communication may target explicitly governed schools within the brand.

Brand sends require:

- Approved brand scope
- Explicit permissions
- Bounded school selection
- Audit logging
- School and recipient validation
- No implicit access to unrelated schools

## Security Notifications

Security messages may include:

- Login alerts
- OTP
- Password reset
- Account lock
- Session revocation
- Suspicious activity
- Credential changes

Security notifications must be resistant to spoofing and must not reveal sensitive internal details.

## Payment Notifications

Payment messages must be generated only from authoritative payment state.

Examples include:

- STK request submitted
- Payment successful
- Payment failed
- Receipt issued
- Subscription activated
- Reconciliation required

Provider callbacks must not directly determine arbitrary recipients or message content.

## Academic Notifications

Academic communication must respect publication state.

Unpublished marks, confidential assessments, and unreleased reports must not be disclosed through notifications.

A message may state that results are available without embedding sensitive results directly.

## Child Data Protection

Notifications involving learners must minimize personal data.

Messages should avoid unnecessary disclosure of:

- Full learner identifiers
- Health information
- Discipline details
- Confidential assessment information
- Guardian personal information

Recipient relationships must be verified before learner-specific communication is sent.

## Attachments

Attachments must use approved protected storage.

Email or notification attachments should normally be represented by:

- Authorized download links
- Signed URLs
- Time-limited access
- Current authorization checks

Sensitive files must not be attached directly where safer controlled access is available.

## Offline Behaviour

Offline clients may queue notification intents only for approved workflows.

The client must not call providers directly.

When connectivity returns, the server must revalidate:

- Identity
- Tenant
- Permission
- Recipient
- Channel policy
- Wallet
- Template
- Resource state

## Rate Limiting

Rate limits must exist for:

- Notification creation
- Bulk sends
- OTP
- Password reset
- SMS
- Email
- Resend attempts
- Recipient-specific volume
- Tenant volume
- Provider volume

Rate limits must protect the platform without silently dropping critical messages.

## Bulk Notifications

Bulk sends require stronger controls.

The workflow should include:

- Audience preview
- Recipient count
- Permission check
- Template preview
- Cost estimate
- Confirmation
- Queue batching
- Cancellation where possible
- Delivery reporting

Large sends must use bounded batches.

## Noisy-Neighbour Protection

One tenant must not monopolize notification infrastructure.

Controls may include:

- Per-tenant queue limits
- Fair scheduling
- SMS budgets
- Daily quotas
- Concurrency limits
- Batch limits
- Provider throttling
- Priority queues

Critical security and platform messages must retain reserved capacity.

## Provider Credentials

Provider credentials must be:

- Stored outside source control
- Encrypted where recoverable use is required
- Scoped to platform or tenant ownership
- Rotatable
- Redacted
- Audited
- Environment-specific
- Excluded from client responses

A school may not access another school's provider credentials.

## Provider Webhooks

Delivery-status callbacks must:

- Authenticate the provider
- Validate signatures or approved references
- Resolve the original delivery
- Enforce replay protection
- Be idempotent
- Update status safely
- Avoid trusting caller-declared tenant ownership
- Record audit evidence

## Audit Logging

Notification audit events may include:

- Intent created
- Recipient resolved
- Delivery queued
- Delivery attempted
- Delivery succeeded
- Delivery failed
- Delivery suppressed
- Fallback used
- Preference changed
- Template changed
- Provider configuration changed
- Bulk send approved
- SMS wallet adjusted

Audit records must not store secrets or excessive message content.

## Observability

The engine must expose metrics for:

- Intent volume
- Queue depth
- Delivery latency
- Success rate
- Failure rate
- Retry rate
- Dead-letter volume
- Provider latency
- Provider errors
- Suppression rate
- Duplicate prevention
- Per-tenant volume
- SMS credit usage
- Template failures
- Callback failures

Critical failures must generate operational alerts.

## Data Retention

Retention must be documented for:

- Notification content
- Delivery attempts
- Provider references
- Recipient addresses
- Audit logs
- Dead-letter records
- Template versions
- Consent records

Sensitive message content should not be retained longer than necessary.

## Privacy

Notification data must follow data-minimization principles.

The engine should separate:

- Message content
- Recipient identity
- Provider response
- Audit metadata

Access to notification history must require appropriate permissions.

## Backup and Recovery

Backup and restore testing must cover:

- Notification intents
- Templates
- Preferences
- Delivery status
- SMS wallet entries
- Provider mappings
- Audit records

Restoration must not cause previously delivered notifications to be sent again.

## Alternatives Considered

### Direct Provider Calls from Every Domain

Rejected.

This creates duplication, inconsistent security, poor retry handling, and provider lock-in.

### One Provider for All Channels

Rejected.

Email, SMS, WhatsApp, push, and in-app communication have different requirements and provider ecosystems.

### Synchronous Delivery Inside HTTP Requests

Rejected as the default.

This increases latency and couples business success to provider availability.

### Central Event-Driven Notification Engine

Accepted.

This provides consistent policy, retry, audit, tenant isolation, localization, and provider abstraction.

## Consequences

### Positive

- Consistent multi-channel delivery
- Centralized recipient validation
- Centralized tenant isolation
- Provider replacement becomes easier
- Queue-based reliability
- Better auditability
- Better localization
- Controlled SMS spending
- Shared retry and dead-letter handling
- Consistent user preferences
- Reduced domain coupling

### Negative

- Notification infrastructure becomes complex.
- Delivery may be eventually consistent.
- Template governance requires operational work.
- Provider callbacks and status models differ.
- Queue and monitoring infrastructure are required.
- User preferences and legal requirements increase complexity.

These costs are accepted because communication is a cross-cutting platform capability.

## Risks and Mitigations

### Risk: Cross-Tenant Message

Mitigation:

- Server-side recipient resolution
- Tenant-owned intents
- Cross-tenant tests
- Scoped templates and credentials
- Audit logging

### Risk: Duplicate Delivery

Mitigation:

- Idempotency keys
- Outbox records
- Provider references
- Replay-safe callbacks
- Retry controls

### Risk: Provider Outage

Mitigation:

- Queues
- Backoff
- Dead-letter handling
- Provider abstraction
- Fallback policy
- Monitoring

### Risk: SMS Cost Abuse

Mitigation:

- Wallet controls
- Permission checks
- Rate limits
- Cost preview
- Per-tenant quotas
- Audit logging

### Risk: Sensitive Data Leakage

Mitigation:

- Data minimization
- Template review
- Protected links
- Recipient validation
- Message classification
- Child-data controls

### Risk: Stale Scheduled Message

Mitigation:

- Revalidate resource and recipient state before send
- Support cancellation
- Expire invalid intents
- Use current permissions where required

## Security Impact

The Notification Engine handles identity, contact information, learner-related communication, financial events, security messages, and provider credentials.

It requires:

- Strict authorization
- Tenant isolation
- Secret management
- Output safety
- Data minimization
- Audit logging
- Rate limiting
- Provider callback security
- Incident response

## Tenant Impact

Tenant configuration, templates, branding, preferences, wallets, recipients, and delivery records must remain tenant scoped.

Platform and brand communication require explicit higher governance scopes.

## Performance Impact

Notification volume can be high.

Performance controls include:

- Queue workers
- Batching
- Priority queues
- Indexed status fields
- Outbox polling controls
- Bounded retries
- Provider throttling
- Digest aggregation
- Per-tenant fairness

## Operational Impact

Platform Engineering must support:

- Queue monitoring
- Provider monitoring
- Template management
- Dead-letter review
- Credential rotation
- Delivery reporting
- SMS wallet operations
- Suppression handling
- Incident response
- Backup and restore

## Implementation Notes

ShuleOS has selected:

- Resend for transactional email
- Africa's Talking for SMS
- WhatsApp as a future channel
- SMS for critical communication
- Provider abstraction as a long-term requirement

Provider-specific decisions are documented separately.

This ADR defines the shared engine and does not claim that every final channel is already production-ready.

## Verification

Compliance will be verified through:

- Tenant recipient-resolution tests
- Cross-tenant notification tests
- Template validation tests
- Localization tests
- Idempotency tests
- Duplicate-delivery tests
- Queue retry tests
- Dead-letter tests
- Preference tests
- Mandatory-message tests
- SMS wallet tests
- Bulk-send authorization tests
- Provider credential redaction tests
- Webhook replay tests
- Attachment authorization tests
- Academic publication-state tests
- Child-data privacy tests
- Performance tests
- Backup and restore tests
- Security review
- CI notification-contract gates

## Constitution Compliance

This decision supports:

- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 7 — Human Experience
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 13 — Protect against IDOR
- Rule 17 — Audit important actions
- Rule 19 — Every security feature is tested
- Rule 28 — TenantContext is mandatory
- Rule 30 — Every query is tenant scoped
- Rule 32 — Cross-tenant tests are mandatory
- Rule 41 — Humanized interface
- Rule 43 — Professional language
- Rule 57 — Email via Resend
- Rule 58 — WhatsApp preferred where appropriate
- Rule 59 — SMS for critical communication
- Rule 60 — Notification Engine selects the delivery channel
- Rule 63 — Every file belongs to a tenant
- Rule 64 — Signed URLs
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 69 — Performance tests are mandatory
- Rule 87 — Tenant isolation is enforced by application and database
- Rule 89 — Authorization fails closed
- Rule 91 — Secrets follow one approved protection standard
- Rule 93 — Access revocation takes effect on the next request
- Rule 94 — Every module follows the approved architecture
- Rule 100 — Idempotency is enforced using stored keys
- Rule 102 — Security-critical invariants are verified automatically
- Rule 103 — Dependencies are scanned and patched
- Rule 104 — Secrets have a managed lifecycle
- Rule 107 — Production systems are observable
- Rule 108 — Third-party providers remain replaceable
- Rule 109 — Every data category has a retention policy
- Rule 110 — Architecture rules are enforced by CI
- Rule 114 — ShuleOS is continuously hardened
- Rule 121 — Learner information receives the highest privacy classification
- Rule 126 — Localization is a platform capability
- Rule 127 — English and Kiswahili are first-class languages

## Related ADRs

- ADR-0000 — Architecture Decision Record Process
- ADR-0001 — Modular Monolith Architecture
- ADR-0002 — Multi-Tenant Architecture
- ADR-0003 — JWT Authentication
- ADR-0004 — Offline-First Architecture
- ADR-0005 — School Payment Architecture
- ADR-0007 — Cloudflare R2
- ADR-0008 — Resend Email
- ADR-0009 — Africa's Talking SMS
- ADR-0010 — Role Template System
- ADR-0011 — Multi-Level Tenant Hierarchy

## Implementation Checklist

- [ ] Notification intent model implemented
- [ ] Transactional outbox strategy implemented
- [ ] Recipient resolution centralized
- [ ] Tenant ownership enforced
- [ ] Channel-selection policy implemented
- [ ] Criticality model implemented
- [ ] Template engine implemented
- [ ] Template versioning implemented
- [ ] English templates supported
- [ ] Kiswahili templates supported
- [ ] Tenant branding implemented
- [ ] Provider abstraction implemented
- [ ] Queue-based delivery implemented
- [ ] Idempotency implemented
- [ ] Delivery status model implemented
- [ ] Retry strategy implemented
- [ ] Dead-letter handling implemented
- [ ] Fallback policy implemented
- [ ] Preferences implemented
- [ ] Mandatory-message rules implemented
- [ ] Quiet-hours policy implemented
- [ ] Scheduled delivery implemented
- [ ] Digest delivery implemented
- [ ] In-app notifications implemented
- [ ] Email adapter implemented
- [ ] SMS adapter implemented
- [ ] SMS wallet controls implemented
- [ ] Bulk-send workflow implemented
- [ ] Rate limits implemented
- [ ] Provider webhooks secured
- [ ] Attachment access protected
- [ ] Child-data controls tested
- [ ] Observability implemented
- [ ] Backup and restore tests implemented
- [ ] CI notification-contract gates implemented

## Supersession Status

This ADR has not been superseded.

## Final Decision

ShuleOS will use a centralized, event-driven, tenant-aware Notification Engine for all platform and school communication.

Business domains will publish trusted notification intents and will not call communication providers directly.

The Notification Engine will control recipient resolution, templates, localization, branding, channel selection, queues, retries, delivery status, costs, privacy, audit logging, and provider abstraction.

Every notification must remain secure, tenant-safe, idempotent, auditable, humanized, and appropriate for its channel.
