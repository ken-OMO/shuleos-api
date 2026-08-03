# ADR-0009 — Africa's Talking SMS Provider

> School in Clouds

## Document Information

| Field                | Value                                            |
| -------------------- | ------------------------------------------------ |
| ADR                  | ADR-0009                                         |
| Decision             | Use Africa's Talking as the Initial SMS Provider |
| Status               | Accepted                                         |
| Version              | 1.0                                              |
| Owner                | Platform Engineering                             |
| Repository           | `shuleos-api`                                    |
| Effective Date       | 02 August 2026                                   |
| Related Constitution | Engineering Constitution v1.1                    |
| Supersedes           | None                                             |
| Superseded By        | None                                             |

## Context

ShuleOS requires SMS for communication that is urgent, critical, time-sensitive, or appropriate for recipients who may not have reliable internet access.

SMS use cases may include:

- Security alerts
- Critical authentication messages where approved
- Emergency school communication
- Attendance alerts
- Transport alerts
- Boarding alerts
- Payment confirmations
- Subscription warnings
- School closure notices
- Examination reminders
- Urgent parent communication
- Operational incident notices

ShuleOS has selected Africa's Talking as the initial SMS delivery provider.

SMS has direct financial cost and may expose message content on a locked or shared phone. It therefore requires stricter controls than ordinary in-app communication.

The provider must remain an implementation detail beneath the centralized Notification Engine.

## Decision

ShuleOS will use Africa's Talking as the initial SMS provider.

All SMS communication must pass through:

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
Recipient and Policy Resolution
    |
    v
SMS Wallet and Cost Validation
    |
    v
SMS Queue
    |
    v
Africa's Talking Adapter
    |
    v
Africa's Talking API
    |
    v
Mobile Network
    |
    v
Recipient Handset
```

Business domains must not call Africa's Talking directly.

The provider integration must use a ShuleOS-owned adapter and internal contracts so that Africa's Talking can be replaced without rewriting business modules.

## Architectural Boundaries

The Notification Engine owns:

- Notification intent
- Tenant validation
- Recipient resolution
- Recipient authorization
- Message classification
- Template resolution
- Localization
- Sender selection policy
- SMS-wallet validation
- Cost calculation
- Idempotency
- Queueing
- Retry policy
- Delivery-status normalization
- Audit logging
- Suppression
- Rate limiting

The Africa's Talking adapter owns:

- Provider authentication
- Provider request construction
- API submission
- Provider-response parsing
- Provider-reference storage
- Provider-error normalization
- Delivery-report processing
- Callback validation
- Provider-specific sender formatting

Business domains own:

- The event that caused the message
- The business meaning of the message
- Approved template data
- Notification criticality
- Whether the message is mandatory or optional

## Provider Contract

The application must define an internal SMS-provider contract.

Conceptually:

```php
interface SmsProvider
{
    public function send(SmsDeliveryRequest $request): SmsProviderResult;
}
```

The contract must use ShuleOS-owned request and response objects.

It must not expose provider SDK objects to business services.

The provider adapter should support:

- One or more approved recipients
- Message body
- Approved sender identity
- Provider correlation metadata
- Provider request reference
- Cost result where available
- Delivery-status callbacks
- Error normalization

## Provider Account Ownership

The primary Africa's Talking account used for the ShuleOS SMS platform will be controlled by ShuleOS Platform Engineering.

Provider administration must be restricted to authorized platform personnel.

Schools must not receive access to:

- Platform API keys
- Provider dashboard credentials
- Callback secrets
- Platform sender configuration
- Other schools' SMS activity

Future tenant-owned provider accounts require a separate architectural review.

## SMS Platform Model

ShuleOS will provide an internal SMS platform through which schools can purchase and consume SMS credits.

The model separates:

### Platform Billing

The school purchases SMS credits from ShuleOS.

Payment belongs to ShuleOS platform billing.

### Tenant SMS Wallet

Verified credit purchases increase the school's SMS wallet.

The wallet belongs to the school tenant.

### Provider Delivery

ShuleOS uses the platform provider account to deliver authorized tenant messages.

Provider cost and internal tenant credit accounting must remain reconcilable.

## SMS Wallet

Every school using paid SMS must have a tenant-owned SMS wallet or equivalent controlled balance.

Wallet records must include:

- School
- Currency or internal credit unit
- Available balance
- Reserved balance where used
- Total purchased
- Total consumed
- Total adjusted
- Status
- Updated time

Wallet balances must not use floating-point arithmetic.

## Wallet Ledger

SMS-wallet movements must be append-only.

Approved movement types may include:

- Credit purchase
- Promotional credit
- Reservation
- Consumption
- Reservation release
- Refund
- Administrative adjustment
- Expiry where policy permits
- Reversal

Every movement must include:

- Tenant
- Amount or units
- Direction
- Reason
- Related notification
- Related purchase or payment where applicable
- Actor or system authority
- Idempotency key
- Timestamp

Existing ledger entries must not be edited destructively.

## Purchasing SMS Credits

SMS-credit purchases belong to ShuleOS platform billing.

The approved flow is:

```text
School selects SMS-credit package
    |
    v
Platform invoice created
    |
    v
Payment initiated
    |
    v
Payment verified
    |
    v
Wallet-credit transaction created
    |
    v
School balance increased
    |
    v
Receipt and audit record generated
```

Credits must not be issued before payment verification unless an explicitly authorized promotional or administrative adjustment applies.

## Credit Reservation

The engine may reserve estimated credits before queueing a message.

A conceptual flow is:

```text
Notification approved
    |
    v
Recipient count calculated
    |
    v
Estimated SMS segments calculated
    |
    v
Estimated cost calculated
    |
    v
Credits reserved
    |
    v
Delivery attempted
    |
    v
Actual cost reconciled
    |
    v
Reservation consumed or released
```

Reservation prevents concurrent sends from overspending the same balance.

## Insufficient Balance

When a school lacks sufficient credits:

- The message must not be submitted blindly.
- The delivery should enter a controlled insufficient-balance state.
- The authorized user may be informed.
- The school may purchase more credits.
- Critical platform-owned messages may use a separate platform budget where policy permits.
- One school's shortage must not affect another school.

Security-critical platform messages must not depend on a tenant wallet unless explicitly designed that way.

## Cost Calculation

SMS cost must be calculated server-side.

Cost depends on factors such as:

- Destination
- Network or country
- Message encoding
- Number of SMS segments
- Provider pricing
- Internal pricing policy
- Taxes or fees where applicable

Provider prices must not be permanently hard-coded into business logic.

Pricing should be stored as versioned operational configuration.

Historical wallet and billing entries must preserve the pricing applied at the time.

## SMS Segmentation

The platform must estimate message segments before sending.

Message length may be affected by:

- Character set
- Unicode characters
- Kiswahili text
- Special symbols
- Concatenated SMS rules
- Provider or network restrictions

Templates should be reviewed for segment efficiency.

Users should receive a cost or segment preview for bulk sends where appropriate.

## Sender Identity

ShuleOS will use approved sender identities registered for the intended countries and mobile networks.

Sender configuration must be:

- Centrally controlled
- Approved before production use
- Environment-specific
- Consistent with platform or school branding
- Valid for the intended destination
- Audited when changed

Sender-registration requirements, costs, network support, and timelines are operational information and must be confirmed during implementation.

They must not be assumed permanently by application code.

## Sender Categories

Approved sender categories may include:

### ShuleOS Platform Sender

Used for:

- Platform security
- Subscription billing
- Licence activation
- Platform operations

### School Communication Sender

Used for tenant-owned school messages where the provider and regulatory model permit approved school branding.

### Shared Transactional Sender

May be used where separate school sender identities are unavailable or operationally impractical.

Recipients must still be able to understand which school or platform originated the message.

## Sender Impersonation

A school must not:

- Use another school's name
- Impersonate ShuleOS
- Select an arbitrary sender value
- Bypass sender approval
- Modify provider sender parameters directly

Sender identity must be derived from server-controlled configuration.

## Transactional and Optional Messages

SMS messages must be classified.

### Transactional

Examples:

- Security alerts
- Payment confirmations
- Attendance alerts
- Transport notices
- Subscription warnings

### Optional or Promotional

Examples:

- Campaigns
- Marketing notices
- Non-essential promotions

Optional communication requires applicable consent and preference controls.

Transactional traffic must not be used to disguise promotional messaging.

## Critical-Message Policy

SMS is primarily reserved for critical or high-value communication because of its cost and intrusive nature.

Critical SMS may include:

- Emergency school alerts
- Security warnings
- Urgent transport changes
- Critical payment confirmation
- Time-sensitive attendance concerns
- Approved OTP fallback

Routine workflow updates should prefer:

- In-app notifications
- Email
- Digests
- WhatsApp where approved

Critical classification must not be used simply to bypass preferences or budgets.

## OTP by SMS

Email remains the approved initial channel for session OTP.

SMS OTP may be introduced as:

- An approved fallback
- An account-recovery option
- A future configured second-factor channel

SMS OTP requires additional review covering:

- Phone-number verification
- SIM-swap risk
- OTP interception
- Rate limits
- Cost
- Delivery latency
- Account recovery abuse

The Africa's Talking provider adapter does not by itself approve SMS as the primary authentication factor.

## Recipient Resolution

Phone numbers must be resolved from trusted ShuleOS records.

The server must validate:

- Recipient identity
- Tenant relationship
- Guardian or staff relationship
- Current phone number
- Verification status where required
- Message eligibility
- Consent where applicable
- Suppression state

Caller-supplied phone numbers must not be accepted as authoritative recipients for protected school workflows.

## Phone Number Normalization

Phone numbers must be normalized to one approved international format before delivery.

For Kenya, records should use a canonical form equivalent to:

```text
+254XXXXXXXXX
```

Normalization must be handled centrally.

The system must reject:

- Invalid lengths
- Unsupported characters
- Ambiguous formats
- Unsupported destinations
- Unsafe bulk input

A valid format does not prove that the number belongs to the intended recipient.

## Phone Number Privacy

Phone numbers are personal data.

Ordinary API responses and logs should mask phone numbers where full disclosure is unnecessary.

Examples:

```text
+2547*****123
```

Full numbers should be available only to authorized workflows.

## Template Engine

SMS must use approved templates for sensitive and automated communication.

Templates should define:

- Template key
- Category
- Language
- Message body
- Allowed variables
- Required variables
- Version
- Criticality
- Mandatory or optional status
- Sender category
- Approval status

Arbitrary template-variable injection is prohibited.

## Message Content

SMS must be concise and privacy-aware.

Messages should avoid unnecessary inclusion of:

- Full learner names
- Birth certificate numbers
- National ID numbers
- Assessment numbers
- Full financial balances
- Health details
- Discipline details
- Passwords
- Access tokens
- Long private links

Sensitive detail should remain in the authenticated ShuleOS portal.

## Localization

English and Kiswahili are first-class SMS languages.

Templates must account for:

- Message length
- Character encoding
- Segment count
- Clear language
- Professional tone
- Local comprehension

A missing translation must use an approved fallback.

## Links in SMS

Links should be used sparingly.

Where included, links must:

- Use an approved ShuleOS domain
- Use HTTPS
- Avoid exposing secrets
- Be short enough for SMS efficiency
- Require authentication for protected data
- Expire where appropriate
- Avoid unsafe public file access

Third-party public URL shorteners should not be used for sensitive workflows without approval.

## Queue-Based Delivery

SMS delivery must be asynchronous by default.

```text
Business transaction committed
    |
    v
Notification intent persisted
    |
    v
SMS policy evaluated
    |
    v
Credits reserved
    |
    v
SMS job queued
    |
    v
Africa's Talking adapter called
    |
    v
Submission result recorded
    |
    v
Delivery report processed
    |
    v
Wallet reconciled
```

Provider availability must not determine whether the authoritative business transaction commits.

## Transactional Outbox

High-value SMS intents should be persisted through the Notification Engine outbox.

Examples include:

- Payment confirmation
- Emergency alert
- Security notice
- Subscription lock warning

The outbox prevents loss between database commit and queue dispatch.

## Idempotency

SMS creation and provider submission must be idempotent.

The idempotency identity should include:

- Tenant or platform scope
- Business event
- Resource
- Recipient
- Template version
- Intended occurrence
- Channel

A repeated request or job retry must not:

- Send a duplicate message
- Deduct credits twice
- Reserve credits twice
- Create duplicate delivery records

## Provider Submission Result

Provider submission success does not automatically mean handset delivery.

Internal states should distinguish:

- Pending
- Queued
- Submitted
- Provider Accepted
- Buffered
- Delivered
- Failed
- Rejected
- Expired
- Unknown
- Suppressed
- Dead Letter

Provider-specific statuses must be normalized into ShuleOS states.

## Provider Message Identifier

The provider request or message identifier must be stored.

It supports:

- Delivery-report correlation
- Reconciliation
- Support investigation
- Duplicate detection
- Cost analysis

Provider identifiers should be indexed where appropriate.

## Delivery Reports

Africa's Talking delivery reports must be accepted through a dedicated callback endpoint.

The callback must:

- Use HTTPS
- Validate the expected request structure
- Resolve the original message safely
- Avoid trusting caller-supplied tenant ownership
- Apply replay and idempotency checks
- Normalize provider status
- Update the delivery record
- Reconcile wallet cost where appropriate
- Record audit evidence
- Return a safe acknowledgement

Callback processing should be queued where additional work is required.

## Callback Authentication

Provider callback capabilities must be reviewed during implementation.

Where cryptographic signatures or shared verification mechanisms are available, they must be used.

Additional controls may include:

- Secret callback paths
- Network allowlisting where reliable
- Provider-reference validation
- Request-rate limits
- Replay detection
- Correlation with known provider identifiers
- Strict payload validation

A callback is untrusted until verified.

## Callback Idempotency

A duplicate delivery report must not repeat side effects.

The callback-processing uniqueness key may use:

- Provider event identifier
- Provider message identifier plus status
- Stored payload hash
- Approved normalized event key

Repeated events must return the existing result.

## Delivery-State Ordering

Provider events may arrive late or out of order.

The state machine must prevent unsafe regression.

For example, an old `Submitted` event must not overwrite an already recorded `Delivered` state.

Approved state-transition rules must be explicit.

## Retry Strategy

Retryable failures may include:

- Network timeout
- Temporary provider outage
- Provider throttling
- Temporary server error

Non-retryable failures may include:

- Invalid phone number
- Unsupported destination
- Suppressed recipient
- Prohibited content
- Insufficient school wallet
- Invalid sender configuration

Retries require:

- Bounded exponential backoff
- Jitter
- Maximum attempts
- Idempotency
- Wallet reservation safety
- Dead-letter handling

## Timeout Handling

A provider timeout creates an uncertain submission state.

The application must not automatically assume the message was not accepted.

Where possible, the system should reconcile using:

- Provider request identifier
- Idempotency record
- Delivery report
- Provider query capability
- Safe delayed retry

## Dead-Letter Handling

Permanently failed messages must enter a reviewable dead-letter state.

Records should include:

- Tenant or platform scope
- Recipient reference
- Template
- Failure category
- Attempt count
- Last safe provider response
- Credit reservation status
- Correlation identifier
- Created and updated time

Sensitive content must not be duplicated unnecessarily.

## Wallet Reconciliation

Wallet accounting must distinguish:

- Estimated cost
- Reserved cost
- Provider-submitted cost
- Final charged cost where available
- Released reservation
- Refund or adjustment

Wallet reconciliation must be idempotent.

A delivery failure does not automatically imply that the provider charged nothing. Final credit policy must reflect the actual commercial/provider model.

## Failed Delivery Credit Policy

ShuleOS must document whether tenant credits are:

- Consumed on provider submission
- Consumed on provider acceptance
- Consumed on final delivery
- Refunded for defined failure categories

The policy must be transparent and versioned.

Historical wallet entries must preserve the policy used.

## Rate Limiting

Rate limits must apply to:

- OTP requests
- Security alerts
- Parent alerts
- Bulk sends
- Per-recipient messages
- Per-school volume
- Platform volume
- Provider API requests
- Callback traffic

Rate limiting must not silently discard critical messages.

## Bulk SMS

Bulk sending requires:

- Approved permission
- Audience preview
- Recipient count
- Template preview
- Segment estimate
- Cost estimate
- Wallet-balance check
- Confirmation
- Queue batching
- Delivery reporting
- Cancellation where practical

Recipients must not be loaded through one unbounded operation.

## Noisy-Neighbour Protection

One school must not consume the entire SMS system.

Controls may include:

- Per-tenant queue limits
- Daily limits
- Wallet limits
- Fair scheduling
- Provider throttling
- Maximum recipients per batch
- Priority queues
- Reserved platform-security capacity
- Abuse monitoring

## Tenant Isolation

Tenant isolation applies to:

- SMS wallets
- Wallet movements
- Sender configuration
- Templates
- Recipients
- Notification intents
- Provider requests
- Delivery records
- Delivery reports
- Cost reports
- Audit logs

A school must never see or consume another school's credits or delivery information.

## Platform Messages

ShuleOS platform messages are paid from a platform-owned budget unless an explicit policy assigns the cost elsewhere.

Examples include:

- Platform security alerts
- Subscription activation
- Platform incident notices
- Licence warnings

Platform messages must not consume tenant credits accidentally.

## Brand-Level Messages

Brand-level SMS may target schools within an approved brand scope.

Brand sending requires:

- Explicit brand authority
- Bounded school selection
- Recipient validation
- Cost-ownership rules
- Audit logging
- No access to unrelated schools

## Consent and Suppression

Optional SMS must respect:

- Consent
- User preference
- School policy
- Regulatory requirements
- Complaint or block status

Suppression reasons may include:

- User opt-out
- Invalid number
- Complaint
- Legal restriction
- Administrative block
- Provider block

Mandatory emergency and security messages require separately documented rules.

## Unsubscribe Handling

Optional SMS should support an approved opt-out process where required.

Two-way SMS or inbound keyword handling may require a future ADR.

Transactional SMS must not be used to bypass opt-out requirements for promotional content.

## Child Data Protection

SMS involving learners must minimize personal data.

The engine must verify the guardian or authorized recipient relationship before learner-specific SMS is sent.

Avoid including:

- Detailed marks
- Health information
- Discipline narratives
- Full financial history
- Government identifiers

## Payment Messages

Payment SMS must originate only from authoritative server-side financial state.

A message may include:

- Safe receipt reference
- Amount
- Date
- School name
- Portal-access instruction

It must not expose provider credentials or full sensitive account details.

## Authentication and Security Messages

Security SMS must:

- Avoid passwords
- Avoid full account details
- Use time-limited codes or links
- Be rate-limited
- Be audited
- Warn recipients not to share codes
- Avoid confirming unnecessary account details

## Emergency Communication

Emergency SMS may receive higher queue priority.

Emergency use must require:

- Elevated permission
- Clear audience definition
- Approved template or controlled message
- Confirmation
- Audit logging
- Post-send reporting

Emergency priority must not become a way to bypass wallet, privacy, or authorization rules without an approved platform policy.

## Provider Credentials

Africa's Talking credentials must be:

- Stored outside source control
- Environment-specific
- Rotatable
- Least-privileged where supported
- Redacted
- Excluded from frontend code
- Excluded from ordinary API responses
- Restricted to approved operators

Sandbox and production credentials must remain separate.

## Configuration

Expected configuration may include placeholders such as:

```env
AFRICASTALKING_USERNAME=
AFRICASTALKING_API_KEY=
AFRICASTALKING_SENDER_ID=
AFRICASTALKING_SMS_CALLBACK_URL=
```

The final variable names must match implementation.

`.env.example` must contain placeholders only.

## Environment Separation

Development and automated testing must not send production SMS.

Non-production environments should use:

- Africa's Talking sandbox where appropriate
- Provider test doubles
- Fake SMS adapters
- Allowlisted test numbers
- Explicit environment indicators

Production credentials must not be available to automated unit tests.

## Testing Strategy

Ordinary automated tests must not depend on live SMS delivery.

Tests should use:

- Notification Engine fakes
- SMS-provider mocks
- Provider-response fixtures
- Delivery-report fixtures
- Callback validation tests
- Contract tests
- Dedicated controlled integration tests

## Logging

Logs must not contain:

- API keys
- Provider credentials
- Raw OTP codes
- Full sensitive message bodies
- Unmasked phone numbers where unnecessary
- Full learner identifiers

Safe logs may include:

- Internal notification ID
- Provider message ID
- Tenant ID
- Template key
- Delivery state
- Segment count
- Safe cost value
- Correlation ID
- Failure category

## Audit Logging

Important audit events include:

- SMS intent created
- Credits reserved
- Message queued
- Provider request submitted
- Provider accepted
- Delivery report received
- Delivered
- Failed
- Rejected
- Credits consumed
- Reservation released
- Wallet adjusted
- Bulk send approved
- Sender changed
- API key rotated
- Callback configuration changed
- Message suppressed

## Observability

The SMS integration must expose metrics for:

- Messages requested
- Messages submitted
- Messages delivered
- Messages failed
- Messages rejected
- Messages buffered
- Provider latency
- Delivery latency
- Retry volume
- Dead-letter volume
- Callback failures
- Callback replays
- Segment count
- Estimated cost
- Final cost
- Wallet reservations
- Wallet balance
- Per-tenant volume
- Bulk-send volume
- Invalid-number rate

Critical anomalies must create alerts.

## Cost Monitoring

Platform Engineering must monitor:

- Provider account balance
- Tenant wallet liabilities
- Provider-versus-wallet reconciliation
- Unexpected segment growth
- Unusual tenant volume
- Duplicate consumption
- Negative-balance attempts
- Pricing changes
- Delivery-cost variance

## Data Retention

Retention must be documented for:

- Message content
- Phone numbers
- Provider identifiers
- Delivery reports
- Wallet movements
- Cost records
- Callback payloads
- Audit logs

Full message bodies should not be retained longer than necessary.

Financial wallet records may require longer retention than message bodies.

## Privacy and External Processing

Africa's Talking acts as an external service provider processing phone numbers and message content.

ShuleOS must document:

- Processing purpose
- Data categories transmitted
- Retention
- Provider terms
- Applicable subprocessors
- Cross-border processing
- Security controls
- Children's-data implications

Only required information should be sent.

## Backup and Recovery

Backup and restore procedures must preserve:

- SMS wallets
- Wallet ledger
- Delivery records
- Provider references
- Sender configuration
- Templates
- Audit records

Restore must not cause old messages to be resent.

## Incident Response

SMS incidents include:

- API-key compromise
- Unauthorized bulk send
- Cross-tenant message
- Incorrect recipient
- Wallet corruption
- Duplicate deductions
- Sender impersonation
- Callback abuse
- Provider outage
- Provider account suspension
- Unexpected cost surge

Response must support:

- API-key revocation
- Queue pause
- Sender disablement
- Tenant suspension from SMS
- Wallet freeze
- Evidence preservation
- Provider coordination
- Recipient notification where required
- Post-incident review

## Provider Outage

During an Africa's Talking outage:

- Business transactions remain authoritative.
- SMS intents stay queued where safe.
- Retries use bounded backoff.
- Critical messages may use approved fallback channels.
- Duplicate delivery is prevented after recovery.
- Queue backlog is processed fairly.
- Operations receives alerts.

## Provider Replacement

Africa's Talking must remain replaceable.

Replacement requires:

- New provider adapter
- Contract tests
- Credential migration
- Sender registration
- Callback migration
- Status mapping
- Cost-policy review
- Rollback plan
- New ADR where the strategic provider changes

Business domains must not change solely because the provider changes.

## Alternatives Considered

### Direct School Integration with Provider

Not selected as the initial ShuleOS SMS-platform model.

This would require every school to maintain provider credentials and balances independently and would reduce centralized governance.

Tenant-owned provider integration may be reconsidered for enterprise schools through a future ADR.

### Multiple Providers from Initial Release

Not selected initially.

Multiple providers increase routing, cost, callback, and operational complexity.

The provider abstraction preserves a future multi-provider path.

### SMS from Africa's Talking

Accepted as the initial provider beneath the Notification Engine.

### SMS for All Notifications

Rejected.

SMS is costly and privacy-sensitive. It should be reserved for suitable communication.

## Consequences

### Positive

- Strong regional provider fit
- Centralized SMS architecture
- Supports delivery reporting
- Enables ShuleOS SMS-credit sales
- Consistent tenant controls
- Queue-based reliability
- Central cost management
- Provider abstraction preserves replaceability
- Supports critical communication where internet is unavailable

### Negative

- Introduces provider dependency
- SMS has direct cost
- Sender registration requires operational work
- Delivery status may depend on mobile networks
- Message segmentation can increase cost
- Wallet accounting adds financial complexity
- Phone-number and message processing creates privacy obligations
- Callback security requires careful design

These costs are accepted because SMS is an important critical-communication channel for Kenyan schools.

## Risks and Mitigations

### Risk: Cross-Tenant SMS

Mitigation:

- Server-side recipient resolution
- Tenant-owned notification intents
- Tenant wallet enforcement
- Cross-tenant tests
- Central provider adapter

### Risk: Duplicate SMS and Double Charge

Mitigation:

- Stored idempotency
- Wallet reservations
- Provider-reference tracking
- Replay-safe callbacks
- Retry controls

### Risk: SMS Wallet Fraud or Corruption

Mitigation:

- Append-only ledger
- Transactions
- Maker-checker adjustment
- Reconciliation
- Audit logging
- Automated invariants

### Risk: Credential Exposure

Mitigation:

- Secret storage
- Rotation
- Redaction
- Environment separation
- Least privilege

### Risk: Unexpected Message Cost

Mitigation:

- Segment estimation
- Cost preview
- Wallet reservation
- Tenant limits
- Pricing versioning
- Cost monitoring

### Risk: Wrong Recipient

Mitigation:

- Trusted contact resolution
- Relationship validation
- Number normalization
- Data minimization
- Audit logging

### Risk: Provider Outage

Mitigation:

- Queues
- Backoff
- Monitoring
- Dead-letter handling
- Fallback policy
- Provider abstraction

## Security Impact

SMS may carry authentication, payment, learner-related, and emergency communication.

The integration requires:

- Tenant isolation
- Strict authorization
- Recipient validation
- Secret management
- Rate limiting
- Idempotency
- Callback protection
- Wallet integrity
- Privacy controls
- Incident response

## Tenant Impact

Every school has independent:

- SMS-wallet ownership
- Wallet balance
- Delivery history
- Recipient records
- Template usage
- Cost reporting
- Sender configuration where approved
- Usage limits

No school may consume or inspect another school's SMS resources.

## Performance Impact

SMS sending may create high-volume burst workloads.

Performance controls include:

- Queues
- Batching
- Provider throttling
- Indexed delivery references
- Bounded callback processing
- Per-tenant fairness
- Priority queues
- Wallet transaction locking
- Noisy-neighbour limits

## Operational Impact

Platform Engineering must operate:

- Provider account
- API keys
- Sender registration
- Callback endpoints
- Provider balance
- Tenant wallets
- Pricing configuration
- Queue monitoring
- Delivery reporting
- Reconciliation
- Incident response
- Provider outage procedures

## Implementation Notes

Africa's Talking will be integrated only through the Notification Engine.

The provider's current API, sender-registration requirements, delivery-report fields, callback controls, pricing, and sandbox behaviour must be verified from official documentation during implementation.

Pricing and registration details must remain operational configuration rather than fixed architectural assumptions.

## Verification

Compliance will be verified through:

- SMS-adapter unit tests
- Notification Engine integration tests
- Phone-normalization tests
- Invalid-number tests
- Tenant-recipient tests
- Cross-tenant SMS tests
- SMS-wallet isolation tests
- Wallet-ledger tests
- Credit-reservation tests
- Insufficient-balance tests
- Segment-calculation tests
- Cost-calculation tests
- Idempotency tests
- Duplicate-job tests
- Provider-timeout tests
- Retry tests
- Dead-letter tests
- Delivery-report tests
- Callback-replay tests
- Delivery-state ordering tests
- Credential-redaction tests
- Bulk-send tests
- Emergency-message tests
- Child-data privacy tests
- Provider outage tests
- Backup and restore tests
- Performance tests
- Security review
- CI SMS-contract gates

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
- Rule 58 — WhatsApp preferred where appropriate
- Rule 59 — SMS for critical communication
- Rule 60 — Notification Engine selects the delivery channel
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 69 — Performance tests are mandatory
- Rule 74 — No secrets committed
- Rule 87 — Tenant isolation is enforced by application and database
- Rule 91 — Secrets follow one approved protection standard
- Rule 94 — Every module follows the approved architecture
- Rule 100 — Idempotency is enforced using stored keys
- Rule 102 — Security-critical invariants are verified automatically
- Rule 103 — Dependencies are scanned and patched
- Rule 104 — Secrets have a managed lifecycle
- Rule 105 — Incidents follow a documented response process
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
- ADR-0005 — School Payment Architecture
- ADR-0006 — Notification Engine
- ADR-0008 — Resend Email
- ADR-0010 — Role Template System
- ADR-0011 — Multi-Level Tenant Hierarchy

## Implementation Checklist

- [ ] Africa's Talking account ownership documented
- [ ] Production application created
- [ ] Sandbox configuration established
- [ ] Production and sandbox credentials separated
- [ ] API keys stored securely
- [ ] API-key rotation documented
- [ ] Sender registration requirements confirmed
- [ ] Approved platform sender configured
- [ ] Internal SMS-provider contract implemented
- [ ] Africa's Talking adapter implemented
- [ ] Notification Engine integration implemented
- [ ] Phone-number normalization implemented
- [ ] SMS template engine implemented
- [ ] English SMS templates implemented
- [ ] Kiswahili SMS templates implemented
- [ ] SMS segment estimation implemented
- [ ] Pricing configuration versioned
- [ ] Tenant SMS-wallet model implemented
- [ ] Wallet ledger implemented
- [ ] Credit-purchase workflow implemented
- [ ] Credit reservation implemented
- [ ] Actual-cost reconciliation implemented
- [ ] Insufficient-balance handling implemented
- [ ] Queue-based delivery implemented
- [ ] Transactional outbox implemented for critical SMS
- [ ] Idempotency implemented
- [ ] Provider references stored
- [ ] Delivery-state normalization implemented
- [ ] Delivery-report callback implemented
- [ ] Callback validation implemented
- [ ] Callback replay protection implemented
- [ ] Delivery-state ordering enforced
- [ ] Retry policy implemented
- [ ] Dead-letter handling implemented
- [ ] Bulk-send workflow implemented
- [ ] Cost preview implemented
- [ ] Rate limits implemented
- [ ] Noisy-neighbour controls implemented
- [ ] Consent and suppression implemented
- [ ] Child-data protections tested
- [ ] Logging redaction verified
- [ ] SMS observability implemented
- [ ] Provider balance monitoring implemented
- [ ] Provider outage runbook created
- [ ] Privacy and data-processing review completed
- [ ] Incident response updated
- [ ] Backup and restore tests implemented
- [ ] CI SMS-contract gates implemented

## Supersession Status

This ADR has not been superseded.

## Final Decision

ShuleOS will use Africa's Talking as its initial SMS provider beneath the centralized Notification Engine.

SMS will primarily serve critical, urgent, and high-value communication.

Schools will purchase SMS credits through ShuleOS platform billing and consume those credits through isolated tenant wallets backed by an append-only ledger.

Business domains will not call Africa's Talking directly.

SMS delivery must remain tenant-safe, idempotent, queue-based, cost-controlled, privacy-aware, auditable, observable, and replaceable.
