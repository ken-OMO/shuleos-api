# ADR-0004 — Offline-First Architecture

> School in Clouds

## Document Information

| Field                | Value                         |
| -------------------- | ----------------------------- |
| ADR                  | ADR-0004                      |
| Decision             | Offline-First Architecture    |
| Status               | Accepted                      |
| Version              | 1.0                           |
| Owner                | Platform Engineering          |
| Repository           | `shuleos-api`                 |
| Effective Date       | 02 August 2026                |
| Related Constitution | Engineering Constitution v1.1 |
| Supersedes           | None                          |
| Superseded By        | None                          |

## Context

ShuleOS is designed for schools operating in environments where internet connectivity may be unreliable, intermittent, slow, or temporarily unavailable.

Teachers, learners, parents, school leaders, and administrators may need to continue working when the platform cannot reach the server.

The current ShuleOS schema already contains offline-capable concepts across multiple portal domains, including structures for:

- Sync operations
- Sync conflicts
- Offline drafts
- Offline resources
- Device registration
- Idempotent sync receipts
- Versioned operations
- Conflict-safe synchronization

This demonstrates that offline capability is not an incidental feature. It is an architectural commitment.

Offline support must preserve the same security, tenant isolation, data integrity, auditability, and authorization standards as online requests.

An offline-capable client must never become a trusted authority merely because it previously authenticated successfully.

## Decision

ShuleOS will implement offline-first capability as a first-class platform architecture.

Approved client applications may continue selected workflows while disconnected, store bounded local state, and synchronize permitted operations when connectivity returns.

Offline capability will be:

- Explicitly allowlisted
- Domain-specific
- Versioned
- Tenant-aware
- Authorization-aware
- Idempotent
- Conflict-aware
- Auditable
- Bounded
- Secure by default

Offline mode will not permit unrestricted local replication of the entire ShuleOS database.

Each offline-capable workflow must define exactly:

- What data may be cached
- What operations may be created offline
- How long cached data remains usable
- How synchronization occurs
- How conflicts are detected
- How conflicts are resolved
- What audit evidence is produced
- What happens when authorization changes
- What the user sees when data is stale

## Architectural Principle

Offline mode is an extension of the same platform, not a separate security model.

```text
Online Request
    |
    v
Authentication
    |
    v
Tenant Resolution
    |
    v
Authorization
    |
    v
Validation
    |
    v
Business Rules
    |
    v
Database Write

Offline Operation
    |
    v
Local Draft or Queued Action
    |
    v
Connectivity Restored
    |
    v
Authentication Revalidated
    |
    v
Tenant Resolution Revalidated
    |
    v
Authorization Revalidated
    |
    v
Operation Version Checked
    |
    v
Conflict Strategy Applied
    |
    v
Business Rules Revalidated
    |
    v
Database Write
```

The server remains authoritative.

## Scope

Offline capability may apply to selected workflows such as:

- Draft lesson notes
- Draft lesson plans
- Draft records of work
- Attendance capture
- Homework drafts
- Safe learning resources
- Parent messages queued for later delivery
- Learner assignments
- Portal preferences
- Approved read-only reference data
- Selected school announcements
- Task lists
- Locally cached timetables
- Approved low-risk profile updates

Offline capability must not be assumed for every domain.

High-risk operations require explicit architectural approval.

## Prohibited or Restricted Offline Operations

The following operations are prohibited offline by default unless a later ADR explicitly approves them:

- Financial ledger posting
- Payment settlement
- Subscription activation
- School registration
- User-role changes
- Permission changes
- Platform administration
- Security configuration
- Exam publication
- Final mark approval
- Report-card publication
- Assessment release
- Provider credential changes
- API-key creation
- Backup restore
- Account suspension
- Tenant switching
- Permanent deletion
- Irreversible workflow transitions

These operations require fresh server-side state and stronger guarantees.

## Server Authority

The server is the final authority for:

- Identity
- Tenant
- Permissions
- Account state
- Subscription state
- Resource ownership
- Current record version
- Workflow state
- Conflict outcome
- Final persistence
- Audit logging

Client timestamps, identities, tenant fields, and record versions are untrusted until validated.

## Offline Data Classification

Every offline-capable record must be classified.

Recommended classifications include:

### Public Reference Data

Examples:

- General curriculum references
- Public school notices
- Non-sensitive learning resources

May be cached more broadly, subject to versioning.

### Tenant Reference Data

Examples:

- Timetables
- Class lists
- Learning-area allocations
- Approved scheme summaries

Must be tenant-scoped and access-controlled.

### Sensitive Personal Data

Examples:

- Learner profiles
- Guardian contacts
- Attendance details
- Behaviour records

Requires stricter local storage, shorter retention, and least-privilege access.

### Restricted Data

Examples:

- Confidential assessment material
- Unpublished marks
- Financial records
- Security credentials
- Platform administration data

Offline caching is prohibited by default.

## Local Storage

Offline data must be stored using the safest practical mechanism for the client platform.

Requirements include:

- Encryption at rest where supported
- Platform-secure storage for tokens
- Bounded retention
- No plain-text secrets
- No unnecessary personal data
- Tenant-aware local namespaces
- Secure deletion on logout or revocation where practical
- Protection against accidental cross-account reuse
- Protection against backup leakage where possible

Browser clients must avoid storing highly sensitive records in insecure browser storage.

Mobile clients must use approved secure storage and local database controls.

## Local Data Minimization

Offline clients may store only what is required for the approved workflow.

The client must not download an entire tenant dataset merely for convenience.

Offline datasets must be:

- Filtered
- Paginated
- Scoped
- Time-bounded
- Versioned
- Purpose-limited

## Device Identity

Where device registration is used, device identity must be:

- Bound to a user
- Bound to an approved tenant context
- Revocable
- Auditable
- Idempotent
- Protected from disclosure
- Limited in lifetime where appropriate

A device identifier does not replace user authentication.

A device may not grant access after the user loses authorization.

## Offline Session Rules

Offline sessions must have a bounded validity period.

Clients must:

- Respect access-token expiration
- Respect local offline-expiry policy
- Require re-authentication after the approved offline period
- Avoid indefinite access
- Display offline status clearly
- Stop sensitive operations when session confidence expires

A previously valid session cannot override a later server-side suspension once connectivity returns.

## Synchronization Request

Every sync request must:

1. Authenticate the user.
2. Resolve the current tenant.
3. Validate device ownership where applicable.
4. Re-check account state.
5. Re-check school state.
6. Re-check permissions.
7. Validate operation type.
8. Validate operation version.
9. Validate object ownership.
10. Validate current workflow state.
11. Validate business rules.
12. Apply idempotency.
13. Detect conflicts.
14. Apply the documented conflict strategy.
15. Record audit evidence.
16. Return a safe result.

No batch bypasses ordinary security controls.

## Sync Batch Structure

A synchronization batch should contain only bounded operations.

Each operation should include approved fields such as:

- Client operation identifier
- Entity type
- Operation type
- Local version
- Base server version
- Client timestamp
- Payload
- Device identifier where applicable
- Schema version
- Sync protocol version

The server must ignore or reject ownership fields that it can derive itself.

## Allowlisted Operations

Every synchronizable domain must define an operation allowlist.

Example:

```text
lesson_note.create_draft
lesson_note.update_draft
attendance.capture
homework.save_draft
portal_preference.update
```

Unknown operation types must be rejected.

Generic arbitrary model mutation is prohibited.

## Versioning

The synchronization protocol must be versioned.

Versioning applies to:

- Operation format
- Payload schema
- Conflict rules
- Resource representation
- Error responses
- Client compatibility

Unsupported versions must fail safely with a clear upgrade requirement.

## Idempotency

Every sync operation must have a unique client operation identifier.

The server must store an idempotency receipt before or with the resulting write.

A repeated operation with the same identifier must:

- Return the original result
- Not create a duplicate record
- Not repeat side effects
- Not double-send notifications
- Not double-post financial or workflow activity

Idempotency scope must include the authenticated user or approved device and tenant context.

## Conflict Detection

A conflict occurs when the server record changed after the client based its offline edit on an earlier version.

Conflict detection may use:

- Version numbers
- Updated-at values combined with stronger checks
- ETags
- Content hashes
- Domain-specific revision identifiers
- Append-only sequence numbers

Client timestamps alone are insufficient conflict evidence.

## Conflict Resolution

Every synchronizable entity must have a documented conflict-resolution strategy.

Approved strategies include:

### Server Wins

The server state is retained.

Suitable for:

- Permissions
- Account state
- Subscription state
- Security configuration
- Published records

### Client Wins

Allowed only for low-risk fields where overwriting server state is explicitly safe.

Client-wins must not be the default.

### Last Write Wins

Generally discouraged.

It may be used only where:

- Data is low risk
- Clock assumptions are controlled
- Lost updates are acceptable
- The decision is documented

### Field-Level Merge

The server merges independent field changes.

Suitable when fields do not violate shared invariants.

### Append-Only Merge

Both client and server changes are preserved as separate entries.

Suitable for:

- Notes
- History
- Audit-style entries
- Certain communication drafts

### Manual Resolution

A user or authorized reviewer chooses the final outcome.

Suitable for:

- Conflicting lesson notes
- Attendance corrections
- Structured workflow conflicts
- High-value records

## Domain-Specific Conflict Rules

Conflict policy must be defined per entity.

Examples:

### Lesson Note Draft

- Field-level merge where safe
- Manual resolution for overlapping content
- Server version preserved
- Client version preserved as conflict evidence

### Attendance

- Server-approved attendance state remains authoritative
- Offline capture may create a pending reconciliation record
- Duplicate learner-date entries are rejected or reconciled

### Portal Preferences

- Server wins for prohibited settings
- Client changes may apply for allowed personal preferences

### Published Academic Records

- Offline mutation prohibited
- Server wins

## Conflict Records

Conflicts must be recorded with enough information to resolve them safely.

A conflict record may contain:

- Tenant
- User
- Device
- Entity type
- Entity identifier
- Client operation identifier
- Base version
- Server version
- Safe diff metadata
- Conflict reason
- Resolution status
- Resolver
- Resolution time

Conflict records must not expose secrets or unnecessary sensitive data.

## Drafts

Offline drafts are not official records.

Drafts must be clearly marked as:

- Local
- Unsynced
- Pending
- Rejected
- Conflicted
- Synced

A draft becomes an official server record only after successful synchronization and validation.

## Data Freshness

Users must be informed when data may be stale.

The interface must show where appropriate:

- Last successful sync time
- Last attempted sync time
- Pending operation count
- Failed operation count
- Conflict count
- Offline status
- Cached-data age
- Whether a record may have changed on the server

Users must not mistake stale data for current authoritative data.

## Sync Result States

Each operation should return a clear state such as:

- Applied
- Duplicate
- Rejected
- Conflict
- Unauthorized
- Invalid
- Expired
- Unsupported version
- Retry later

Generic success for partially failed batches is prohibited.

## Partial Batch Failure

A sync batch may contain operations with different outcomes.

The server must define whether:

- Operations are processed independently
- Related operation groups are transactional
- Entire batches are transactional

Large all-or-nothing batches are discouraged unless business invariants require them.

## Ordering

Operations with dependencies must declare or preserve safe ordering.

Examples:

```text
create draft
    before
attach draft resource
```

The server must not trust arbitrary client ordering where dependencies can be validated explicitly.

## Retries

Sync retries must be safe.

Retries should use:

- Idempotency receipts
- Bounded exponential backoff
- Retry-after guidance
- Maximum retry counts
- Dead-letter or manual review for permanent failures

Permanent validation errors must not be retried indefinitely.

## Queue Processing

Large synchronization tasks may be queued.

Queued sync processing must:

- Preserve tenant context
- Re-resolve user and authority where required
- Clear context between jobs
- Remain idempotent
- Record final status
- Avoid unbounded batch processing
- Respect noisy-neighbour controls

## Tenant Isolation

Every offline record and operation belongs to one tenant.

Offline data must use tenant-aware local namespaces.

Sync must reject:

- Cross-tenant entity identifiers
- Stale tenant membership
- Client-supplied tenant reassignment
- Operations targeting another school
- Device reuse across unauthorized tenants

Tenant switching must clear or isolate cached data appropriately.

## Multi-Level Tenancy

Where a user has approved brand or platform scope, offline capability must not automatically include all schools.

Offline access must be explicitly scoped to:

- One school
- A bounded approved set
- A specific operational context

Cross-school offline aggregation requires separate approval and stronger controls.

## Authorization Changes

Permissions may change while a device is offline.

When connectivity returns, every operation must use current server-side authorization.

Possible outcomes include:

- Operation accepted
- Operation rejected
- Operation preserved as a local draft
- Operation sent for authorized review
- Cached data invalidated

Historical permission does not guarantee current authority.

## Account Revocation

If a user is suspended, locked, archived, or removed:

- New sync requests must fail
- Local sensitive data should be cleared or locked where practical
- Pending operations must not apply automatically
- Device registrations may be revoked
- Audit events must be recorded

## Subscription and School State

Offline operation cannot bypass:

- Subscription lock
- School suspension
- Read-only mode
- Archived school state
- Platform restrictions

The sync server must re-check current state.

## File Uploads

Offline-created attachments must use the secure upload pipeline when connectivity returns.

The process must include:

- Authentication
- Tenant validation
- File-type validation
- MIME validation
- Size limits
- Quarantine
- Malware scanning
- Hashing
- Ownership metadata
- Private storage
- Safe resource responses

A locally selected file is not trusted.

## Learning Resources

Offline learning resources must be:

- Explicitly approved for offline availability
- Versioned
- Tenant-scoped where required
- Protected from unsafe file types
- Subject to expiry or invalidation
- Clear about publication state

## Notifications

Offline clients may queue notification intents only where explicitly permitted.

The server remains responsible for:

- Recipient resolution
- Contact resolution
- Channel policy
- Wallet checks
- Provider selection
- Rate limiting
- Delivery
- Audit logging

Clients must not directly contact SMS, email, or WhatsApp providers using tenant credentials.

## Payments and Finance

Financial mutations are excluded from offline sync by default.

Offline clients may display cached read-only financial summaries only when:

- The data is appropriately protected
- Staleness is displayed
- No settlement decision depends solely on stale data
- Sensitive details are minimized

Payment posting, allocation, reversal, refund, and ledger writes require online authoritative processing.

## Assessment and Academic Integrity

Offline access to assessment content must respect confidentiality.

Unpublished exam papers, mark schemes, and restricted results must not be cached unless a dedicated security design approves it.

Offline mark entry requires separate review because of:

- Confidentiality
- Conflict risk
- Publication state
- Moderation
- Auditability
- Device security

Until explicitly approved, final academic result mutation remains online-only.

## Audit Logging

Synchronization audit events should record:

- Tenant
- User
- Device
- Batch identifier
- Operation identifier
- Entity type
- Operation type
- Outcome
- Conflict status
- Reason
- Server version
- Timestamp
- Correlation identifier

Raw secrets and unnecessary sensitive payloads must not be logged.

## Observability

Offline synchronization must be observable.

Metrics should include:

- Sync success rate
- Sync failure rate
- Conflict rate
- Duplicate-operation rate
- Average batch size
- Processing latency
- Oldest pending operation
- Retry volume
- Rejected unauthorized operations
- Unsupported client versions
- Per-tenant sync load

Critical anomalies must generate alerts.

## Noisy-Neighbour Protection

One tenant's sync workload must not degrade service for others.

Controls may include:

- Batch-size limits
- Operation-count limits
- Payload-size limits
- Per-tenant rate limits
- Queue partitioning
- Concurrency controls
- Fair scheduling
- Processing timeouts
- Daily sync quotas where justified

## Privacy

Offline capability increases the risk of local data exposure.

Each offline workflow must define:

- Data classification
- Local retention period
- Encryption approach
- Logout cleanup
- Device-loss response
- User-consent or notice requirements
- Child-data minimization
- Support-access restrictions

Learner data receives the highest privacy classification.

## Data Retention

Local offline records must not remain indefinitely.

Retention rules must define:

- Maximum local age
- Cleanup after successful sync
- Cleanup after rejection
- Conflict evidence retention
- Logout behaviour
- Account revocation behaviour
- Device de-registration behaviour

## Backup and Restore

Client-side offline stores are not authoritative backups.

The server remains the system of record.

Clients must not be used as the only recovery source for official school records.

## Client Compatibility

The server must define supported sync protocol versions.

Older clients may be:

- Allowed temporarily
- Restricted to read-only mode
- Required to upgrade
- Rejected when unsafe

Compatibility policy must prioritize security and data integrity.

## Alternatives Considered

### Online-Only Architecture

Rejected.

Advantages:

- Simpler security model
- Simpler client design
- No conflict resolution
- Easier consistency

Disadvantages:

- Poor usability under unreliable connectivity
- Lost work during outages
- Reduced suitability for many schools
- Higher operational frustration

### Full Local Database Replication

Rejected.

Advantages:

- Rich offline access
- Fast local queries
- Broad offline capability

Disadvantages:

- Excessive sensitive data on devices
- Complex conflict resolution
- High storage usage
- Greater tenant-leakage risk
- Difficult revocation
- Difficult schema evolution

### Generic Sync of Arbitrary Models

Rejected.

This would bypass domain rules and create severe security and integrity risks.

### Allowlisted Domain-Specific Sync

Accepted.

This provides offline value while preserving control.

## Consequences

### Positive

- Users can continue approved work during connectivity loss.
- Draft work is preserved.
- ShuleOS becomes more practical in real school environments.
- Sync behaviour is auditable and testable.
- Offline capability remains domain-controlled.
- Idempotency prevents duplicate writes.
- Conflict handling is explicit.
- Data freshness is visible.

### Negative

- Client and server complexity increases.
- Conflict resolution requires domain-specific design.
- Local storage creates additional privacy risks.
- Sync protocol versions must be maintained.
- Testing requirements increase.
- Operational monitoring becomes more complex.
- Some workflows must remain online-only.

These costs are accepted because offline capability is a deliberate product requirement.

## Risks and Mitigations

### Risk: Unauthorized Operations after Permission Change

Mitigation:

- Revalidate authorization during sync
- Fail closed
- Reject stale authority
- Preserve local drafts where safe

### Risk: Cross-Tenant Sync

Mitigation:

- Server-resolved tenant
- Tenant-aware operation receipts
- Ownership checks
- Cross-tenant tests
- Local tenant namespaces

### Risk: Duplicate Writes

Mitigation:

- Client operation identifiers
- Stored idempotency receipts
- Replay-safe processing

### Risk: Lost Updates

Mitigation:

- Version checks
- Conflict records
- Manual resolution where required
- Avoid unsafe last-write-wins defaults

### Risk: Sensitive Data on Lost Device

Mitigation:

- Data minimization
- Encryption
- Secure storage
- Bounded retention
- Device revocation
- Remote cleanup where supported

### Risk: Sync Storm after Outage

Mitigation:

- Backoff
- Per-tenant rate limits
- Queue smoothing
- Fair scheduling
- Batch limits
- Monitoring

### Risk: Unsupported Old Client

Mitigation:

- Protocol version enforcement
- Minimum supported version
- Upgrade requirement
- Safe read-only fallback where appropriate

## Security Impact

Offline-first design expands the attack surface.

Security review must cover:

- Local storage
- Token storage
- Device registration
- Sync authentication
- Tenant isolation
- Conflict payloads
- File upload
- Idempotency
- Replay
- Authorization changes
- Account revocation
- Data retention

No offline workflow is accepted solely because it improves convenience.

## Tenant Impact

Offline data and operations must remain bound to one approved tenant scope.

The client cannot change tenant ownership.

The server re-establishes tenant context during every sync.

Higher-scope access does not automatically permit broad offline replication.

## Performance Impact

Offline sync can create burst traffic after outages.

Performance controls include:

- Bounded batches
- Incremental synchronization
- Cursor-based pull
- Efficient indexes
- Queue processing
- Compression where appropriate
- Delta responses
- Per-tenant fairness
- Conflict-rate monitoring

## Operational Impact

Platform Engineering must operate:

- Sync metrics
- Conflict monitoring
- Version compatibility monitoring
- Retry queues
- Dead-letter review
- Device revocation
- Sync cleanup
- Incident response
- Client upgrade policy

## Implementation Notes

Current portal modules already include sync-related structures and tests.

The current test baseline includes evidence that:

- Learner sync operations are idempotent per user.
- Learner sync is allowlisted and versioned.
- Learner sync uses server-wins behaviour for at least one tested conflict path.
- Parent sync excludes financial mutations.
- Teacher sync is allowlisted, versioned, idempotent, and conflict safe.

These existing behaviours are useful foundations but do not by themselves complete the platform-wide offline architecture.

Every synchronizable entity still requires documented conflict policy, data classification, retention, authorization, and observability.

## Verification

Compliance will be verified through:

- Sync authentication tests
- Cross-tenant sync tests
- Idempotency tests
- Duplicate replay tests
- Conflict detection tests
- Conflict-resolution tests
- Permission-change tests
- Account-revocation tests
- School-state tests
- Unsupported-version tests
- Batch-boundary tests
- Payload-size tests
- File-upload tests
- Local-data retention tests
- Data-freshness UI tests
- Queue tenant-context tests
- Noisy-neighbour performance tests
- Security review
- Privacy review
- CI sync-contract gates

## Constitution Compliance

This decision supports:

- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 7 — Human Experience
- Rule 10 — Design first. Code second
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 13 — Protect against IDOR
- Rule 17 — Audit important actions
- Rule 19 — Every security feature is tested
- Rule 28 — TenantContext is mandatory
- Rule 29 — Requests never choose their own tenant
- Rule 30 — Every query is tenant scoped
- Rule 32 — Cross-tenant tests are mandatory
- Rule 41 — Humanized interface
- Rule 44 — Simple workflows
- Rule 63 — Every file belongs to a tenant
- Rule 64 — Signed URLs
- Rule 65 — Files are scanned before permanent storage
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 69 — Performance tests are mandatory
- Rule 87 — Tenant isolation is enforced by application and database
- Rule 88 — Authentication has one source of truth
- Rule 89 — Authorization depends on authenticated identity and fails closed
- Rule 93 — Access revocation takes effect on the next request
- Rule 100 — Idempotency is enforced using stored keys
- Rule 102 — Security-critical invariants are verified automatically
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI
- Rule 114 — ShuleOS is continuously hardened
- Rule 115 — Offline-first architecture is a first-class capability
- Rule 116 — Offline synchronization is tenant-aware and validated
- Rule 117 — Every synchronizable entity has a conflict strategy
- Rule 118 — Users are informed of sync state and freshness
- Rule 121 — Learner information receives the highest privacy classification

## Related ADRs

- ADR-0000 — Architecture Decision Record Process
- ADR-0001 — Modular Monolith Architecture
- ADR-0002 — Multi-Tenant Architecture
- ADR-0003 — JWT Authentication
- ADR-0006 — Notification Engine
- ADR-0007 — Cloudflare R2
- ADR-0011 — Multi-Level Tenant Hierarchy

## Implementation Checklist

- [ ] Offline-capable domains inventoried
- [ ] Allowed offline operations documented
- [ ] Prohibited offline operations documented
- [ ] Data classification completed
- [ ] Local storage strategy documented
- [ ] Offline session lifetime defined
- [ ] Device registration security verified
- [ ] Sync protocol version defined
- [ ] Operation schema defined
- [ ] Idempotency receipts implemented
- [ ] Conflict detection implemented
- [ ] Conflict strategy documented per entity
- [ ] Conflict records implemented
- [ ] Permission changes revalidated during sync
- [ ] Account revocation enforced
- [ ] Subscription and school state enforced
- [ ] Cross-tenant sync tests implemented
- [ ] File sync uses quarantine pipeline
- [ ] Financial mutations excluded
- [ ] Restricted academic mutations excluded
- [ ] Data freshness shown to users
- [ ] Local retention policy implemented
- [ ] Queue tenant context tested
- [ ] Sync observability implemented
- [ ] Noisy-neighbour controls implemented
- [ ] Client compatibility policy documented
- [ ] CI sync-contract gates implemented

## Supersession Status

This ADR has not been superseded.

## Final Decision

ShuleOS will support offline-first workflows through a controlled, allowlisted, versioned, tenant-aware synchronization architecture.

The server remains the authoritative source of identity, tenant, authorization, record state, workflow state, and final persistence.

Offline capability must preserve security, privacy, auditability, idempotency, data freshness, and domain-specific conflict handling.

No offline workflow may bypass the same engineering standards required for online operation.
