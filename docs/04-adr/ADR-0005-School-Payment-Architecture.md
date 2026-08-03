# ADR-0005 — School Payment Architecture

> School in Clouds

## Document Information

| Field                | Value                                                              |
| -------------------- | ------------------------------------------------------------------ |
| ADR                  | ADR-0005                                                           |
| Decision             | Separation of ShuleOS Subscription Billing and School Fee Payments |
| Status               | Accepted                                                           |
| Version              | 1.0                                                                |
| Owner                | Platform Engineering                                               |
| Repository           | `shuleos-api`                                                      |
| Effective Date       | 02 August 2026                                                     |
| Related Constitution | Engineering Constitution v1.1                                      |
| Supersedes           | None                                                               |
| Superseded By        | None                                                               |

## Context

ShuleOS processes two fundamentally different categories of payments.

### Platform Subscription Payments

These are payments made by schools to ShuleOS for access to the platform.

Examples include:

- Initial school subscription
- Termly subscription renewal
- Annual subscription renewal
- Plan upgrade
- Approved add-on services
- SMS-credit purchases
- Other future platform services

These funds belong to ShuleOS.

### School Fee Payments

These are payments made by parents, guardians, sponsors, learners, or other parties to an individual school.

Examples include:

- Tuition fees
- Boarding fees
- Transport fees
- Activity fees
- Examination fees
- School meals
- Other school-defined charges

These funds belong to the individual school, not to ShuleOS.

The two payment categories must not share ownership, settlement, reconciliation, accounting, or access rules.

Each school may use its own payment arrangement, including:

- Till number
- Paybill
- Bank account
- Cash or cheque
- Manual payment confirmation
- Future approved payment providers

ShuleOS must support these differences without becoming the owner of school fee funds.

## Decision

ShuleOS will maintain two separate payment architectures:

1. **Platform Billing**
    - Used only for payments owed to ShuleOS.
    - Uses ShuleOS-controlled payment channels.
    - Activates or renews subscriptions after verified payment.
    - Creates platform invoices, receipts, licences, and audit records.

2. **School Finance Payments**
    - Used for payments owed to individual schools.
    - Uses payment channels configured and owned by the school.
    - Posts verified payments to the school finance domain.
    - Never mixes school funds with ShuleOS subscription revenue.

The two systems may share approved infrastructure abstractions, but they must remain logically, financially, operationally, and audibly separate.

## Core Principle

```text
School pays ShuleOS
        |
        v
Platform Subscription Billing
        |
        v
ShuleOS-owned payment channel
        |
        v
Subscription and licence activation
```

```text
Parent or guardian pays school
        |
        v
School Finance Payment
        |
        v
School-owned payment channel
        |
        v
Invoice allocation and school receipt
```

A payment must never move between these two ownership models accidentally.

## Platform Subscription Billing

ShuleOS platform billing may support:

- STK Push
- Manual payment
- Verified till or paybill transactions
- Bank transfer
- Future approved payment providers

The initial intended payment destination is the ShuleOS till number configured securely by Platform Engineering.

The till number, provider credentials, callback secrets, and merchant identifiers must never be hard-coded in source code.

## School Registration and Subscription

During school registration, the school selects an approved subscription plan.

Examples include:

- Basic
- Premium
- Enterprise

The platform generates a subscription invoice containing:

- School
- Plan
- Billing period
- Amount
- Currency
- Invoice reference
- Due date
- Payment status
- Expiration information

The school may then choose:

### STK Push

The authorized payer enters an approved phone number.

ShuleOS initiates a payment request through the configured provider.

### Manual Payment

The school pays using the displayed ShuleOS payment instructions and submits the required transaction reference or proof for verification.

Manual submission does not automatically prove payment.

## Subscription Activation

Subscription access is activated only after payment has been verified.

The approved flow is:

```text
School registration
    |
    v
Plan selected
    |
    v
Subscription invoice created
    |
    v
Payment initiated or declared
    |
    v
Provider callback or manual verification
    |
    v
Payment reconciled
    |
    v
Subscription activated
    |
    v
Licence or activation record generated
    |
    v
Initial administrator account enabled
```

A client request must not directly mark a subscription payment as successful.

## Licence Generation

After successful subscription activation, ShuleOS may generate a school licence or activation record.

The licence must be:

- Unique
- Randomly generated
- Stored hashed where it functions as a credential
- Bound to one school
- Bound to the subscription
- Time-bounded where applicable
- Revocable
- Auditable
- Never returned repeatedly in plain text after activation where avoidable

The first school administrator may be required to provide the activation key before accessing protected school setup workflows.

A licence is not a replacement for user authentication.

## Subscription Renewal

Renewal must not require creation of a completely unrelated school account.

The renewal flow is:

```text
Existing school subscription
    |
    v
Renewal invoice generated
    |
    v
Payment verified
    |
    v
Existing subscription extended or renewed
    |
    v
School access state recalculated
    |
    v
Renewal receipt issued
```

Renewal must preserve:

- School identity
- Users
- Roles
- Historical data
- Existing licence history
- Audit records
- Previous subscription records

A renewal should create a new billing record rather than overwriting financial history.

## Subscription States

Approved subscription states may include:

- Pending
- Trial
- Active
- Expiring
- Expired
- Grace Period
- Read Only
- Locked
- Suspended
- Cancelled
- Archived

Payment verification may cause a controlled state transition.

Every state transition must be validated and audited.

## School Payment Configuration

Each school may configure its own school-fee payment methods.

Approved configuration may include:

- M-Pesa till number
- M-Pesa paybill
- Account-number format
- Bank details
- Cash or cheque acceptance
- Manual payment workflow
- Provider integration status
- Receipt numbering settings
- Reconciliation settings

School payment configuration belongs to the school tenant.

A school must never view or change another school’s payment configuration.

## School-Controlled Payment Channels

ShuleOS acts as the software platform for school finance operations.

It does not automatically become the merchant of record for school fee payments.

Where a school provides its own till, paybill, bank, or provider account:

- Provider credentials belong to that school.
- Credentials must be encrypted.
- Credentials must be write-only through ordinary APIs.
- Access must require explicit permissions.
- Rotation must preserve history.
- Audit events must be created.
- Callback routing must resolve the correct school safely.

## Manual School Payments

Schools may use manual payment capture when automated integration is unavailable.

Manual payment may record:

- Payer
- Learner or account
- Amount
- Method
- Transaction reference
- Payment date
- Supporting evidence where available
- Receiver
- Verification status
- Approval status
- Reason or notes

Manual payments require controls such as:

- Maker-checker approval where appropriate
- Duplicate-reference detection
- Tenant ownership validation
- Invoice validation
- Audit logging
- Reversal instead of destructive deletion

A manually entered payment must not silently bypass accounting rules.

## Server-Derived Ownership

Financial ownership is established by the server.

The server must validate the complete relationship:

```text
Authenticated user
    |
    v
Authorized school
    |
    v
Invoice or account
    |
    v
Learner or payer relationship
    |
    v
Payment
```

Caller-supplied identifiers such as:

```text
school_id
learner_id
invoice_id
account_id
payment_id
```

must never be trusted independently.

A payment for an invoice belonging to a different school must be rejected.

## Money Representation

All financial calculations must use deterministic units.

Amounts should be stored and processed using integer minor units where practical.

For Kenyan shillings:

```text
KES 1.00 = 100 minor units
```

Floating-point arithmetic must not be used for authoritative financial calculations.

The system must define:

- Currency
- Minor-unit scale
- Rounding rules
- Tax behaviour where applicable
- Discount behaviour
- Allocation behaviour
- Reversal behaviour

## Payment Intent

An initiated payment is not the same as a completed payment.

A payment intent may contain:

- Tenant or platform scope
- Invoice
- Amount
- Currency
- Payer contact
- Provider
- Merchant reference
- Idempotency key
- Status
- Expiry
- Created time

Approved statuses may include:

- Created
- Submitted
- Pending
- Successful
- Failed
- Cancelled
- Expired
- Reconciliation Required

## Idempotency

Every payment-creating request requires a stored idempotency key.

The key must be checked before the financial write.

A repeated request with the same valid key must return the original result instead of creating a second payment.

Idempotency applies to:

- STK Push initiation
- Manual payment creation
- Provider callback processing
- Payment allocation
- Receipt generation
- Subscription activation
- Refund or reversal requests
- SMS-credit purchases

Idempotency scope must include the appropriate platform or tenant ownership context.

## Provider Callback

External payment callbacks are not user-authenticated requests.

Callbacks must use provider-specific authentication such as:

- Cryptographic signatures
- Shared secrets
- Registered merchant identifiers
- Certificate validation
- Allowlisted provider references
- Server-generated transaction identifiers

Callback payloads must not be trusted to declare the owning school directly.

The server must resolve ownership through trusted mappings.

## Callback Processing

The callback flow is:

```text
Provider callback received
    |
    v
Callback authentication verified
    |
    v
Payload normalized
    |
    v
Replay and idempotency checked
    |
    v
Payment intent resolved
    |
    v
Ownership and amount verified
    |
    v
Transaction persisted
    |
    v
Reconciliation performed
    |
    v
Subscription or invoice workflow updated
    |
    v
Receipt or audit event generated
```

Callbacks should be acknowledged quickly and processed through a queue where appropriate.

## Amount Verification

The amount received must be compared with the expected amount.

Possible outcomes include:

- Exact match
- Underpayment
- Overpayment
- Duplicate payment
- Unknown payment
- Currency mismatch
- Reconciliation required

A mismatched amount must not automatically post to the wrong invoice or activate a subscription without approved reconciliation logic.

## Transaction References

Provider transaction references must be unique within the appropriate provider and merchant scope.

References must be indexed.

Duplicate callbacks with the same provider transaction reference must not create duplicate financial records.

## Reconciliation

Reconciliation confirms that the internal record matches the provider or manual evidence.

Reconciliation may compare:

- Provider transaction reference
- Merchant account
- Amount
- Currency
- Phone number where appropriate
- Invoice reference
- Payment date
- Provider status
- Tenant or platform ownership

Reconciliation outcomes must be recorded.

## Allocation

A verified school payment may be allocated to:

- One invoice
- Multiple invoice items
- A learner account
- A family account
- An approved suspense account

Allocations must be transactional.

The total allocated amount must not exceed the available payment amount unless an explicitly approved accounting rule allows it.

## Ledger Integrity

Financial ledger records are append-only.

Posted transactions must not be edited destructively.

Corrections use:

- Reversal
- Adjustment
- Credit note
- Debit note
- Reallocation
- Approved reconciliation entry

Every correction must reference the original transaction and include a reason.

## Receipts

Receipts are generated only for verified payments.

A receipt must include:

- Receipt number
- Payment reference
- Payment date
- Amount
- Currency
- Payer
- School or ShuleOS ownership
- Invoice or account
- Payment method
- Verification status
- Authorized issuer

Receipt numbering must be scoped appropriately.

School receipts must not use the same numbering authority as ShuleOS subscription receipts unless intentionally designed.

## Reversals

A successful financial transaction must not be deleted.

A reversal must:

- Reference the original transaction
- Record the reason
- Record the actor
- Preserve the original amount
- Create the opposite financial effect
- Recalculate balances
- Be audited
- Require elevated permission where appropriate

## Refunds

Refunds require a controlled workflow.

A refund may require:

- Original payment validation
- Refund eligibility
- Approval
- Provider support
- Amount validation
- Tenant ownership validation
- Idempotency
- Audit logging
- Reconciliation

Refunds must not be treated as ordinary negative payments without explicit accounting design.

## Failed Payments

Failed payment attempts must not create successful ledger entries.

Failure records may retain safe operational information such as:

- Payment intent
- Failure category
- Provider response code
- Retry eligibility
- Time
- Correlation identifier

Secrets and unnecessary provider payloads must not be exposed.

## Pending Payments

Pending payments remain non-authoritative until verified.

A pending platform subscription payment must not activate a licence.

A pending school fee payment must not reduce the learner balance.

## Duplicate Payments

Duplicate transactions require reconciliation.

The system must not silently discard or double-allocate duplicate funds.

Possible outcomes include:

- Confirmed duplicate callback
- Separate genuine payment
- Unallocated credit
- Refund review
- Manual reconciliation

## Partial Payments

School finance may support partial payments.

Platform subscription billing may accept partial payment only when an approved plan or policy explicitly allows it.

A partial payment must not activate full subscription access unless the required amount has been satisfied or an authorized override exists.

## Overpayments

Overpayments must be handled through an approved rule such as:

- Unallocated credit
- Advance balance
- Refund review
- Manual reconciliation

Overpayments must not be lost or silently assigned.

## Subscription Plan Pricing

Subscription pricing must be versioned.

A subscription invoice must preserve the plan and price offered at the time of invoicing.

Changing the current plan price must not alter historical invoices or receipts.

## Plan Changes

Plan upgrades or downgrades require explicit rules for:

- Effective date
- Proration
- Existing benefits
- Outstanding balance
- Credits
- Renewal date
- Licence state

Plan changes must not be inferred from a caller-supplied plan identifier without server validation.

## SMS Credit Purchases

Where schools purchase SMS credits from ShuleOS:

- The payment belongs to ShuleOS platform billing.
- The resulting wallet belongs to the school tenant.
- Credits are issued only after verified payment.
- Wallet movements are append-only.
- Reservation and usage are idempotent.
- Refund and adjustment workflows are audited.

SMS-credit billing remains separate from school fee collection.

## Permissions

Platform subscription permissions are separate from school finance permissions.

Examples include:

### Platform Permissions

- View subscriptions
- Create subscription invoices
- Verify manual platform payments
- Activate subscriptions
- Revoke licences
- Manage plans
- Reconcile platform payments

### School Finance Permissions

- View school payments
- Capture manual payment
- Approve payment
- Allocate payment
- Reverse payment
- View receipts
- Configure school payment channels
- Reconcile school transactions

Schools cannot grant themselves platform billing permissions.

## Maker-Checker Controls

Sensitive financial actions should support separation of duties.

Examples include:

- One user captures a manual payment.
- Another user approves it.
- One user requests a reversal.
- Another user authorizes it.
- One platform operator verifies payment.
- Another activates exceptional access where required.

The same user must not create and approve high-risk actions where maker-checker is required.

## Tenant Isolation

Every school finance payment belongs to one school.

Tenant isolation must apply to:

- Payment configuration
- Payment intents
- Provider callbacks
- Invoices
- Learner accounts
- Allocations
- Receipts
- Reversals
- Refunds
- Reconciliation
- Audit logs
- Reports

Platform billing records are platform-owned but linked to the paying school.

## Multi-Level Tenancy

A brand administrator does not automatically receive school finance access.

Cross-school financial visibility requires explicit brand-level finance authority and must be documented, minimized, and audited.

Platform support access must not imply authority to post school finance entries.

## Security

Payment architecture must protect:

- Provider credentials
- Callback secrets
- Merchant identifiers
- Payer information
- Phone numbers
- Transaction references
- Invoice data
- Receipts
- Reconciliation evidence

Sensitive provider information must be redacted from ordinary API responses.

## Credential Storage

Payment-provider credentials must be:

- Encrypted at rest
- Write-only through ordinary APIs
- Scoped to the correct owner
- Rotatable
- Audited
- Excluded from logs
- Excluded from frontend bundles
- Different across environments

Hashing should be used for verification secrets where plaintext recovery is not required.

Encryption should be used where the platform must call the provider using the original credential.

## Phone Number Handling

Where phone numbers are used for payment initiation:

- Normalize to an approved format.
- Validate country and length.
- Mask in ordinary API responses.
- Do not trust the phone number as proof of account ownership.
- Avoid unnecessary retention.
- Audit only safe representations.

## Webhook Replay Protection

Provider callbacks must be replay-safe.

Replay protection may include:

- Provider transaction reference
- Callback identifier
- Idempotency key
- Signature timestamp
- Nonce
- Stored callback hash

A replay must return the previously established result without creating another financial write.

## Queue Processing

Provider calls and callback side effects should occur through approved jobs where appropriate.

Jobs must:

- Preserve platform or tenant ownership
- Remain idempotent
- Revalidate relevant state
- Avoid duplicate notifications
- Record final status
- Use bounded retries
- Send permanent failures for reconciliation

## Notifications

Payment notifications must be generated from authoritative server-side results.

Examples include:

- STK request submitted
- Payment successful
- Payment failed
- Receipt issued
- Subscription activated
- Subscription renewal completed
- Reconciliation required

A provider callback must not directly determine arbitrary notification recipients.

## Audit Logging

Financial audit events should include:

- Actor
- Platform or school ownership
- Payment or invoice
- Action
- Amount
- Currency
- Method
- Provider reference where safe
- Previous state
- New state
- Outcome
- Reason
- Correlation identifier
- Timestamp

Raw secrets and unnecessary callback payloads must not be logged.

## Observability

Payment monitoring should track:

- Payment initiation rate
- Provider success rate
- Callback failure rate
- Callback authentication failures
- Duplicate callbacks
- Reconciliation queue size
- Pending payment age
- Amount mismatches
- Activation failures
- Receipt-generation failures
- Per-school payment volume
- Platform billing totals
- Provider latency

Critical anomalies must generate alerts.

## Noisy-Neighbour Protection

One school's payment traffic must not affect other schools.

Controls may include:

- Per-school rate limits
- Queue fairness
- Bounded reconciliation batches
- Provider request limits
- Callback throttling
- Export limits
- Report pagination

## Data Retention

Payment records must have documented retention periods.

Financial history should ordinarily be preserved according to applicable legal, accounting, tax, contractual, and operational requirements.

Sensitive raw provider payloads should be minimized and retained only as long as necessary.

## Backup and Recovery

Financial records require verified backup and restore capability.

Recovery testing must confirm preservation of:

- Payments
- Allocations
- Ledgers
- Receipts
- Reversals
- Reconciliation
- Subscription state
- Licence history
- Audit records

## Alternatives Considered

### One Shared Payment Account for All School Fees

Rejected.

This would make ShuleOS the central collector of school funds and create significant accounting, legal, reconciliation, trust, and operational risk.

### School-Owned Payment Accounts

Accepted for school fee collection.

This preserves school ownership of funds while allowing ShuleOS to provide workflow and accounting software.

### Manual Payments Only

Not selected as the sole architecture.

Manual entry is necessary as a fallback, but it creates more reconciliation work and fraud risk.

### Automated Provider Integration Only

Not selected as the sole architecture.

Some schools may not have integrated provider accounts, and outages may require manual alternatives.

### Direct Client-to-Provider Calls

Rejected.

Provider initiation, credentials, ownership, and reconciliation must remain server-controlled.

## Consequences

### Positive

- Clear separation between ShuleOS revenue and school revenue
- Schools retain ownership of school fee channels
- Platform subscriptions can be automated
- Manual payment remains available
- Financial history is auditable
- Duplicate writes are prevented
- Provider credentials remain tenant-scoped
- Renewals preserve school identity and data

### Negative

- Two related but separate billing systems must be maintained.
- School-specific provider configuration increases complexity.
- Callback routing must safely identify ownership.
- Manual reconciliation requires operational processes.
- Maker-checker controls increase workflow steps.
- Provider failures require queues, alerts, and reconciliation.

These costs are accepted because financial ownership must remain clear and secure.

## Risks and Mitigations

### Risk: School Payment Posted to Wrong Tenant

Mitigation:

- Server-derived ownership
- Tenant-aware foreign keys
- Cross-tenant tests
- Trusted callback mappings
- Transactional validation

### Risk: Duplicate Payment

Mitigation:

- Stored idempotency keys
- Unique provider references
- Replay-safe callbacks
- Duplicate reconciliation workflow

### Risk: Fraudulent Manual Entry

Mitigation:

- Maker-checker controls
- Evidence capture
- Permission separation
- Audit logging
- Duplicate-reference detection

### Risk: Provider Credential Leakage

Mitigation:

- Encryption
- Redaction
- Write-only APIs
- Secret rotation
- Least privilege
- No frontend exposure

### Risk: Subscription Activated without Payment

Mitigation:

- Verified payment state
- Server-controlled activation
- Reconciliation
- Transactional state transition
- Audit event
- Automated tests

### Risk: Provider Outage

Mitigation:

- Retry queues
- Manual payment option
- Provider abstraction
- Monitoring
- Reconciliation
- Safe user messaging

### Risk: Amount Mismatch

Mitigation:

- Expected-versus-received comparison
- Reconciliation-required state
- No automatic posting when unsafe
- Authorized review

## Security Impact

Payment workflows are high-risk and require:

- Strict authorization
- Tenant isolation
- Idempotency
- Replay protection
- Secret management
- Append-only financial records
- Audit logging
- Monitoring
- Incident response

Financial controllers must not bypass the approved service architecture.

## Tenant Impact

School finance records belong to exactly one school.

Platform subscription records belong to ShuleOS but reference the subscribing school.

The two ownership models must remain distinct in schema, services, permissions, reports, and audit logs.

## Performance Impact

Payment processing should use:

- Indexed references
- Bounded queries
- Queue processing
- Transactional writes
- Efficient reconciliation lookups
- Pagination
- Asynchronous notifications
- Provider timeouts
- Bounded retries

Financial correctness takes precedence over premature optimization.

## Operational Impact

Platform Engineering must support:

- Provider configuration
- Callback monitoring
- Payment reconciliation
- Subscription activation failures
- Licence recovery
- Credential rotation
- Manual verification
- Audit review
- Financial backup and restore
- Incident response

Schools must have clear workflows for:

- Configuring payment channels
- Capturing manual payments
- Approving payments
- Reconciling transactions
- Reversing incorrect entries
- Issuing receipts

## Implementation Notes

ShuleOS already contains finance and payment-related foundations, including:

- Minor-unit arithmetic
- Append-only ledger concepts
- Idempotency controls
- Payment resource redaction
- Maker-checker controls
- Safe M-Pesa callback handling
- Replay-safe parent payment callbacks
- Amount-mismatch reconciliation
- Provider abstraction foundations

These existing elements must be audited against this ADR.

The final platform subscription flow, licence generation, ShuleOS till configuration, school payment-channel setup, and production provider integration remain subject to staged implementation and testing.

## Verification

Compliance will be verified through:

- Platform-versus-school payment separation tests
- Cross-tenant payment tests
- Invoice ownership tests
- Idempotency tests
- Duplicate callback tests
- Callback authentication tests
- Replay tests
- Amount mismatch tests
- Manual payment approval tests
- Maker-checker tests
- Allocation transaction tests
- Ledger immutability tests
- Reversal tests
- Subscription activation tests
- Renewal tests
- Licence generation tests
- Credential encryption tests
- Safe-resource tests
- Receipt-number tests
- Queue retry tests
- Reconciliation tests
- Backup and restore tests
- Performance tests
- Security review
- CI financial-invariant gates

## Constitution Compliance

This decision supports:

- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 13 — Protect against IDOR
- Rule 17 — Audit important actions
- Rule 19 — Every security feature is tested
- Rule 21 — Database first
- Rule 22 — Tables require keys, constraints, and indexes
- Rule 24 — Every query is reviewed
- Rule 25 — Use transactions
- Rule 28 — TenantContext is mandatory
- Rule 30 — Every query is tenant scoped
- Rule 31 — Foreign keys respect tenant ownership
- Rule 32 — Cross-tenant tests are mandatory
- Rule 34 — Business logic belongs in services
- Rule 47 — Platform roles are protected
- Rule 51 — Schools cannot assign platform permissions
- Rule 52 — Platform billing is separate from school finance
- Rule 53 — Subscription payments belong to ShuleOS
- Rule 54 — School fees use school-configured payment channels
- Rule 55 — Financial operations are idempotent
- Rule 56 — Every payment is auditable
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 75 — Merge only after acceptance gates pass
- Rule 87 — Tenant isolation is enforced by application and database
- Rule 89 — Authorization fails closed
- Rule 90 — Uniqueness is tenant scoped
- Rule 91 — Secrets follow one protection standard
- Rule 94 — Every module follows the approved architecture
- Rule 100 — Idempotency is enforced using stored keys
- Rule 101 — Financial ownership is established server-side
- Rule 102 — Security-critical invariants are verified automatically
- Rule 104 — Every secret has a managed lifecycle
- Rule 107 — Production systems are observable
- Rule 108 — Third-party providers remain replaceable
- Rule 110 — Architecture rules are enforced by CI
- Rule 112 — Pull requests follow documented governance
- Rule 114 — ShuleOS is continuously hardened

## Related ADRs

- ADR-0000 — Architecture Decision Record Process
- ADR-0001 — Modular Monolith Architecture
- ADR-0002 — Multi-Tenant Architecture
- ADR-0003 — JWT Authentication
- ADR-0006 — Notification Engine
- ADR-0009 — Africa's Talking SMS
- ADR-0010 — Role Template System
- ADR-0011 — Multi-Level Tenant Hierarchy

## Implementation Checklist

- [ ] Platform billing separated from school finance
- [ ] ShuleOS till configuration stored securely
- [ ] Subscription invoice model implemented
- [ ] Subscription payment intent implemented
- [ ] STK Push initiation implemented
- [ ] Manual subscription payment workflow implemented
- [ ] Callback authentication implemented
- [ ] Callback replay protection implemented
- [ ] Stored idempotency keys implemented
- [ ] Amount verification implemented
- [ ] Subscription reconciliation implemented
- [ ] Licence generation implemented
- [ ] Licence values protected appropriately
- [ ] Subscription renewal implemented
- [ ] Trial, grace, read-only, and locked states enforced
- [ ] School payment configuration implemented
- [ ] School provider credentials encrypted
- [ ] Manual school payment workflow implemented
- [ ] Maker-checker approval implemented
- [ ] Tenant-aware invoice validation implemented
- [ ] Payment allocation is transactional
- [ ] Ledger is append-only
- [ ] Reversal workflow implemented
- [ ] Receipt generation implemented
- [ ] Duplicate payment handling implemented
- [ ] Partial and overpayment rules documented
- [ ] SMS-credit purchase flow separated
- [ ] Financial permissions separated
- [ ] Payment notifications queued
- [ ] Payment observability implemented
- [ ] Backup and restore tests implemented
- [ ] CI financial-invariant gates implemented

## Supersession Status

This ADR has not been superseded.

## Final Decision

ShuleOS will maintain strict separation between platform subscription billing and individual school fee payments.

Payments owed to ShuleOS will use ShuleOS-controlled payment channels and may activate or renew subscriptions only after verification.

Payments owed to schools will use school-configured payment channels and remain owned, reconciled, reported, and audited within the school tenant.

All payment creation, callbacks, allocations, receipts, licences, reversals, and renewals must be tenant-safe, idempotent, server-controlled, auditable, and protected by the approved financial architecture.
