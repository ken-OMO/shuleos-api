# ADR-0002 — Multi-Tenant Architecture

> School in Clouds

## Document Information

| Field                | Value                         |
| -------------------- | ----------------------------- |
| ADR                  | ADR-0002                      |
| Decision             | Multi-Tenant Architecture     |
| Status               | Accepted                      |
| Version              | 1.0                           |
| Owner                | Platform Engineering          |
| Repository           | `shuleos-api`                 |
| Effective Date       | 02 August 2026                |
| Related Constitution | Engineering Constitution v1.1 |
| Supersedes           | None                          |
| Superseded By        | None                          |

## Context

ShuleOS is a cloud platform intended to serve many independent schools from shared infrastructure.

Each school manages sensitive information, including:

- Learner records
- Guardian information
- Staff records
- Assessment results
- Financial transactions
- Attendance records
- Behaviour records
- Boarding information
- Transport information
- Communication records
- Uploaded documents
- Subscription and operational settings

Schools must share the ShuleOS platform without sharing ownership, visibility, authority, or access to one another's data.

ShuleOS also supports higher-level governance scopes, including:

- Platform administration
- White-label brands
- Groups of schools
- Individual schools
- Departments and operational units

The architecture must therefore support both strict school isolation and explicitly authorized higher-level access.

Tenant isolation must survive:

- Application bugs
- Incorrect client input
- Background jobs
- Queue processing
- Offline synchronization
- Imports
- Exports
- File access
- Caching
- Notifications
- Webhooks
- Reporting
- Payment callbacks
- Administrative support workflows

A tenant boundary is a security boundary.

## Decision

ShuleOS will use a shared-application, shared-database multi-tenant architecture with explicit hierarchical tenant scopes and defense-in-depth isolation.

The primary tenant is the school.

Most school-owned records will include a non-null `school_id` that identifies the owning school.

Tenant isolation will be enforced through multiple independent layers:

1. Authenticated identity
2. Server-resolved tenant context
3. Account and subscription state
4. Role and permission authorization
5. Object ownership validation
6. Tenant-scoped application queries
7. Tenant-aware foreign-key validation
8. Database constraints
9. Database-level isolation policies where adopted
10. Automated cross-tenant tests
11. Audit logging
12. Operational monitoring

No single layer is considered sufficient on its own.

## Tenant Hierarchy

ShuleOS recognizes the following governance hierarchy:

```text
Platform
   |
   +-- White-Label Brand
   |      |
   |      +-- School
   |      |      |
   |      |      +-- Department
   |      |      +-- Grade
   |      |      +-- Stream
   |      |      +-- Operational Unit
   |      |
   |      +-- School
   |
   +-- Independent School
```

The hierarchy does not grant automatic access.

Access must always be based on an explicit authorized scope.

## Tenant Scope Types

### Platform Scope

Platform-level access is reserved for approved ShuleOS platform roles.

Platform scope may manage:

- School registration
- Platform subscriptions
- Platform configuration
- Support operations
- White-label brands
- Platform security
- Infrastructure operations

Platform scope must not be assigned through school-level role management.

### Brand Scope

Brand scope permits approved users to manage explicitly associated schools within a white-label brand.

Brand-level access must:

- Be explicitly assigned
- Be limited to schools owned by the brand
- Use dedicated permissions
- Be audited
- Never imply unrestricted platform access

### School Scope

School scope is the default tenant boundary.

School users may access only resources owned by their active school and permitted by their roles and permissions.

### Sub-School Scope

Departments, grades, streams, boarding units, transport units, and similar structures may create narrower authorization boundaries within a school.

These are authorization scopes inside the school tenant, not independent tenants unless a future ADR states otherwise.

## Tenant Resolution

Tenant context must be resolved by the server.

The application must not trust a client-supplied `school_id` as evidence of authority.

Tenant resolution may use:

- The authenticated user's school relationship
- An approved platform scope
- An approved brand scope
- A server-issued tenant context
- An explicitly authorized administrative support workflow

The active tenant must be established before tenant-owned business logic executes.

## Authoritative Tenant Context

ShuleOS must maintain one authoritative tenant context for each request.

The resolved tenant context must be available consistently to:

- Middleware
- Policies
- Services
- Queries
- Jobs
- Events
- Audit logging
- File operations
- Notifications

Multiple competing tenant-resolution mechanisms are prohibited.

If tenant context is missing, inconsistent, or ambiguous, the request must fail closed.

## Client Input

Client-supplied ownership fields must not determine tenant ownership.

Fields such as the following must be treated as untrusted:

```text
school_id
brand_id
learner_id
guardian_id
teacher_id
invoice_id
payment_id
stream_id
grade_id
```

The server must derive ownership through authenticated context and validated relationships.

Client input may identify a requested resource, but it may never prove authorization.

## Data Ownership

Every tenant-owned resource must have a clear owner.

The ownership relationship must be traceable to a school through one of the following:

- Direct `school_id`
- A parent record with a verified `school_id`
- A documented platform-level exemption
- A documented brand-level exemption

Hidden or ambiguous ownership is prohibited.

## Tenant-Owned Tables

A new tenant-owned table is not complete until it includes:

- A `school_id` column or documented exemption
- A foreign key to the owning school
- An index beginning with or appropriately including `school_id`
- Tenant-aware uniqueness constraints
- Tenant-safe foreign-key relationships
- Application-level tenant scoping
- Database-level isolation strategy
- Cross-tenant denial tests
- Documentation

These requirements should be checked automatically wherever possible.

## Tenant-Scoped Uniqueness

Uniqueness is tenant-scoped by default.

For example:

```text
school_id + admission_number
school_id + employee_number
school_id + stream_name
school_id + role_name
school_id + invoice_number
```

A globally unique constraint requires a written architectural justification.

Global uniqueness may be appropriate for:

- Platform-generated licence keys
- System-wide immutable identifiers
- External provider references where required
- Platform-level integration identifiers

## Tenant-Aware Foreign Keys

A resource must not reference another tenant's resource.

For example:

- A learner cannot belong to a stream in another school.
- A payment cannot settle an invoice belonging to another school.
- A teacher assignment cannot connect a teacher to another school's learning area.
- A file cannot be attached to another school's record.
- A guardian link cannot connect records across schools.

Where PostgreSQL supports a stronger composite relationship, the schema should use tenant-aware composite constraints where practical.

Application validation alone is insufficient.

## Application-Level Enforcement

Application-level tenant enforcement includes:

- Tenant middleware
- Tenant context services
- Global or explicit query scopes
- Policies
- Service-level ownership checks
- Tenant-aware validation
- Safe API resources
- Fail-closed authorization

Controllers must not implement tenant security independently.

Tenant logic should be centralized and reusable.

## Database-Level Enforcement

The database must independently support tenant isolation.

Database protections may include:

- Non-null tenant ownership columns
- Foreign keys
- Composite foreign keys
- Check constraints
- Tenant-scoped unique constraints
- Restricted database roles
- PostgreSQL Row-Level Security where appropriate
- Append-only audit structures
- Controlled database functions

Row-Level Security must not be described as active until it has been explicitly designed, implemented, tested, and deployed.

Where RLS is introduced, it requires a dedicated ADR or an amendment to this ADR.

## Query Rules

Every tenant-owned query must be tenant scoped.

Unsafe example:

```php
Learner::findOrFail($learnerId);
```

Safer conceptual pattern:

```php
Learner::query()
    ->where('school_id', $tenantContext->schoolId())
    ->findOrFail($learnerId);
```

Actual implementation must follow the approved ShuleOS tenant abstraction rather than duplicating ad hoc scope logic.

Queries must not rely solely on globally unique identifiers to prevent cross-tenant access.

## IDOR Protection

Possession of a resource identifier does not grant access.

Every resource operation must verify:

- Authenticated identity
- Active tenant
- Role and permission
- Object ownership
- Object state
- Business rules

Cross-tenant access attempts must return a safe response without revealing whether the target resource exists.

## Authentication Relationship

Authentication establishes the authoritative user identity.

Tenant resolution must derive from that identity or an explicitly approved higher governance scope.

Tenant middleware must not independently authenticate a different user or interpret identity through a separate mechanism.

If identity resolution fails, tenant resolution must fail.

## Authorization Relationship

Tenant membership alone does not grant permission.

A user may belong to a school but still lack permission to:

- View finance
- Enter marks
- Manage teachers
- Approve lesson plans
- Configure integrations
- Access confidential assessment content

Authorization must combine tenant scope with role, permission, policy, ownership, and resource state.

## Account and School State

Tenant access must respect:

- User activation state
- User suspension
- User lockout
- School operational state
- Subscription state
- Trial state
- Grace period
- Read-only state
- Locked state

State changes must take effect on the next request.

## Background Jobs

Every tenant-aware job must carry a safe, explicit tenant reference.

Jobs must:

- Resolve tenant context before execution
- Verify the tenant still exists
- Verify the tenant remains eligible for the operation
- Re-check permission or authority where required
- Avoid relying on stale serialized user models
- Record tenant-aware audit information
- Fail safely if tenant context cannot be restored

Queue workers must clear tenant context between jobs to prevent leakage.

## Scheduled Tasks

Scheduled operations spanning many schools must process tenants deliberately.

A scheduled command must not execute one unbounded query over all tenant data unless the operation is explicitly platform-wide and approved.

Preferred pattern:

```text
Resolve eligible tenants
    |
    v
Process one bounded tenant batch
    |
    v
Clear context
    |
    v
Continue
```

This reduces noisy-neighbour risk and accidental cross-tenant processing.

## Offline Synchronization

Offline synchronization is a tenant-aware security boundary.

Every sync batch must:

- Authenticate the submitting user
- Resolve the current tenant
- Validate device ownership where applicable
- Validate each operation
- Enforce permissions
- Enforce object ownership
- Enforce version and conflict rules
- Reject cross-tenant identifiers
- Record idempotency receipts
- Audit accepted and rejected operations

A device's historical tenant context must not override the user's current authorization.

## Imports

Bulk imports must not trust tenant identifiers inside uploaded files.

The import process must:

- Derive the tenant from server context
- Validate every referenced entity
- Reject cross-tenant relationships
- Preview changes before commit where appropriate
- Use transactions or controlled batches
- Record errors safely
- Quarantine uploaded files
- Audit the final result

## Exports and Reports

Exports must be tenant scoped at query time.

A report must not fetch broad data and then filter it only after retrieval.

Generated files must:

- Belong to the tenant
- Use protected storage
- Use authorized download mechanisms
- Expire where appropriate
- Avoid exposing internal storage paths
- Be audited when sensitive

## Caching

Every cache entry containing tenant-owned data must be tenant namespaced.

Example conceptual key:

```text
tenant:{school_id}:permissions:{user_id}
```

Unsafe cache keys include:

```text
permissions:{user_id}
dashboard
settings
```

unless the data is genuinely platform-global and documented.

Cache invalidation must preserve tenant boundaries.

## Sessions and Tokens

Sessions and tokens must not allow the caller to change tenant by modifying client-controlled values.

Where a user may operate across multiple approved scopes, tenant switching must:

- Require explicit authorization
- Produce a server-issued context
- Be auditable
- Re-evaluate permissions
- Prevent access outside the approved scope

## File Storage

Tenant-owned files must include tenant ownership metadata.

Storage paths should use non-guessable identifiers and tenant-aware prefixes where appropriate.

Access must use:

- Authorization checks
- Signed URLs or controlled downloads
- Safe expiration
- Private storage
- Audit logging for sensitive documents

A storage path alone must never grant access.

## Notifications

Notification recipients must be resolved server-side.

Tenant-aware notifications must verify:

- The school owns the underlying event
- The recipient belongs to the approved audience
- Contact details are resolved from trusted records
- Channel configuration belongs to the tenant
- Provider credentials belong to the correct tenant or platform scope
- Delivery logs do not expose secrets

## Payments

Payment ownership must be derived from trusted relationships.

Before a financial write, the server must verify:

```text
tenant
  -> invoice
  -> learner/account
  -> payment
```

All relationships must belong to the same approved scope.

Caller-supplied `school_id`, `learner_id`, or `invoice_id` must never be accepted without server-side ownership validation.

## Webhooks

External callbacks may not contain an authenticated user.

Webhook tenant resolution must use trusted, validated provider references such as:

- Registered merchant identifiers
- Server-generated transaction references
- Hashed callback mappings
- Provider configuration ownership

Webhook payloads must not be trusted to declare the owning tenant directly.

Callbacks must be authenticated, replay-safe, idempotent, rate-limited, and audited.

## Audit Logging

Every sensitive event must record the relevant scope.

Audit data should include where applicable:

- Actor
- Tenant
- Governance scope
- Action
- Resource type
- Resource identifier
- Outcome
- Reason
- Request or correlation identifier
- Timestamp

Audit logs must not expose secrets or unnecessary personal data.

## Observability

Operational monitoring must detect possible tenant-isolation failures.

Signals may include:

- Repeated cross-tenant denial attempts
- Tenant context missing from protected operations
- Jobs executing without tenant context
- Unexpected platform-scope access
- Unusual export volume
- Cross-tenant relationship validation failures
- Cache namespace violations
- Database policy violations

Critical signals must generate alerts.

## Noisy-Neighbour Protection

One school must not degrade the platform for others.

Tenant-aware controls may include:

- Rate limits
- Queue partitioning
- Bounded batch sizes
- Import limits
- Export limits
- Storage quotas
- Concurrency limits
- Notification budgets
- SMS wallet enforcement
- Query timeouts
- Resource usage monitoring

Noisy-neighbour protection must not weaken tenant isolation.

## Support Access

Platform support access to school data must be exceptional.

Support access must require:

- Explicit platform permission
- A valid reason
- Time-bounded access where practical
- Audit logging
- Least privilege
- Data minimization
- Safe impersonation controls if impersonation is ever introduced

Silent or undocumented support access is prohibited.

## Deletion and Archiving

Archive operations must preserve tenant ownership.

Archived records must remain inaccessible to unauthorized tenants.

Restoration must revalidate:

- Tenant ownership
- Actor permission
- Resource relationships
- Current business rules

Permanent deletion, where legally and operationally justified, must never cross tenant boundaries.

## Data Residency

Tenant data storage and processing locations must be documented.

Any cross-border processing must comply with approved legal, contractual, privacy, and security requirements.

Tenant isolation must remain consistent across:

- Database
- Object storage
- Backups
- Logs
- Analytics
- External providers

## Alternatives Considered

### Separate Database per School

Not selected as the default architecture.

Advantages:

- Strong physical isolation
- Easier tenant-specific backup and restore
- Reduced risk of cross-tenant SQL queries

Disadvantages:

- High operational complexity
- Expensive migrations across many databases
- Connection management complexity
- Difficult platform-wide analytics
- Higher infrastructure overhead
- Harder onboarding for many small schools

This model may be reconsidered for exceptional enterprise or regulatory requirements through a future ADR.

### Separate Schema per School

Not selected as the default architecture.

Advantages:

- Stronger logical separation
- Shared PostgreSQL cluster
- Easier tenant-specific organization

Disadvantages:

- Large schema count
- Complex migration management
- Difficult ORM support
- More operational overhead
- Harder shared reporting

### Shared Tables with Application Scoping Only

Rejected.

Application-only isolation is insufficient because an application bug could expose another tenant's data.

### Shared Tables with Defense-in-Depth Controls

Accepted.

This provides operational efficiency while supporting application, schema, test, audit, and database-level protections.

## Consequences

### Positive

- Efficient use of shared infrastructure
- Consistent deployment
- Centralized security controls
- Easier platform-wide maintenance
- Scalable school onboarding
- Clear school ownership model
- Support for white-label and platform governance
- Strong automated testing opportunities

### Negative

- Every tenant-owned query must be correct.
- Schema design requires tenant-aware constraints.
- Shared infrastructure increases the impact of isolation defects.
- Database-level controls require careful implementation.
- Platform and brand access models are more complex than flat tenancy.
- Cache, queues, files, and background processing must all be tenant aware.

These costs are accepted because multi-tenancy is fundamental to the ShuleOS business and architecture.

## Risks and Mitigations

### Risk: Cross-Tenant Data Exposure

Mitigation:

- Central tenant context
- Tenant-scoped queries
- Policies
- Database constraints
- Cross-tenant tests
- CI compliance checks
- Audit logging
- Database-level isolation

### Risk: Incorrect Higher-Scope Access

Mitigation:

- Separate platform, brand, and school permissions
- Explicit governance scopes
- No implicit inheritance
- Time-bounded support access
- Audit requirements

### Risk: Tenant Context Leakage Between Queue Jobs

Mitigation:

- Explicit tenant job payloads
- Tenant re-resolution
- Context clearing
- Queue tests
- Worker lifecycle controls

### Risk: Cache Leakage

Mitigation:

- Tenant-prefixed cache keys
- Central key generation
- Cache review
- Automated tests

### Risk: Noisy Neighbours

Mitigation:

- Rate limits
- Bounded work
- Quotas
- Queue isolation
- Monitoring
- Per-tenant resource controls

## Security Impact

This decision defines tenant isolation as a core security control.

A tenant-isolation defect is treated as a critical security issue.

Security reviews must examine all paths through which tenant data enters, moves, is cached, is stored, is exported, or is transmitted.

## Performance Impact

Shared tables may grow significantly.

Performance depends on:

- Correct composite indexes
- Tenant-first query patterns
- Pagination
- Bounded searches
- Partitioning where justified
- Query-plan review
- Archival
- Caching
- Noisy-neighbour controls

Indexes should commonly begin with `school_id` when tenant-scoped query patterns support that order.

Index design must still be verified using real query plans.

## Operational Impact

Platform operations must remain tenant aware.

Operational tooling must support:

- Tenant-specific diagnostics
- Tenant-specific exports
- Tenant-specific restore workflows
- Tenant-specific usage metrics
- Tenant-specific support access
- Platform-wide monitoring without exposing tenant data unnecessarily

## Implementation Notes

This ADR defines the required architecture.

It does not claim that every current ShuleOS table, query, job, cache entry, and integration already satisfies the final standard.

Existing violations must be identified through Stage 1 and later hardening stages and corrected using this ADR and the Engineering Constitution as acceptance criteria.

## Verification

Compliance will be verified through:

- Cross-tenant feature tests
- Policy tests
- Route middleware tests
- Schema inspection
- Migration review
- Tenant-index checks
- Composite relationship checks
- Queue-context tests
- Cache namespace tests
- File-access tests
- Import and export tests
- Offline-sync tests
- Payment ownership tests
- Webhook replay and ownership tests
- CI architecture gates
- Security review

## Constitution Compliance

This decision supports:

- Rule 4 — Tenant First
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 13 — Protect against IDOR
- Rule 14 — No endpoint bypasses the security pipeline
- Rule 17 — Audit important actions
- Rule 19 — Every security feature is tested
- Rule 28 — TenantContext is mandatory
- Rule 29 — Requests never choose their own tenant
- Rule 30 — Every query is tenant scoped
- Rule 31 — Foreign keys respect tenant ownership
- Rule 32 — Cross-tenant tests are mandatory
- Rule 46 — Frontend authorization never replaces backend authorization
- Rule 51 — Schools cannot assign platform permissions
- Rule 63 — Every file belongs to a tenant
- Rule 68 — Cross-tenant tests are mandatory
- Rule 87 — Tenant isolation is enforced by application and database
- Rule 89 — Authorization fails closed
- Rule 90 — Uniqueness is tenant scoped
- Rule 93 — Access revocation takes effect on the next request
- Rule 99 — Tenant-owned tables are complete only when tenant safe
- Rule 101 — Financial ownership is established server-side
- Rule 102 — Security-critical invariants are verified automatically
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI
- Rule 111 — Tenant schema requirements are automatically validated
- Rule 116 — Offline synchronization is tenant aware
- Rule 119 — Tenant hierarchy is explicit and governed
- Rule 120 — Cross-school access requires approved higher scope
- Rule 121 — Learner information receives the highest privacy classification
- Rule 125 — Data residency decisions are documented

## Related ADRs

- ADR-0000 — Architecture Decision Record Process
- ADR-0001 — Modular Monolith Architecture
- ADR-0003 — JWT Authentication
- ADR-0004 — Offline-First Architecture
- ADR-0005 — School Payment Architecture
- ADR-0006 — Notification Engine
- ADR-0007 — Cloudflare R2
- ADR-0010 — Role Template System
- ADR-0011 — Multi-Level Tenant Hierarchy

## Implementation Checklist

- [ ] Authoritative tenant context documented
- [ ] Tenant middleware audited
- [ ] Tenant-owned tables inventoried
- [ ] Tenant ownership exemptions documented
- [ ] Tenant indexes reviewed
- [ ] Tenant-aware uniqueness reviewed
- [ ] Foreign-key ownership reviewed
- [ ] Cross-tenant tests added for every domain
- [ ] Queue tenant context audited
- [ ] Cache namespace strategy verified
- [ ] File ownership enforcement verified
- [ ] Import and export tenancy verified
- [ ] Offline synchronization tenancy verified
- [ ] Payment ownership enforcement verified
- [ ] Webhook tenant resolution verified
- [ ] Support-access process documented
- [ ] Database-level isolation implementation reviewed
- [ ] CI tenant-compliance gates implemented
- [ ] Observability alerts implemented
- [ ] Noisy-neighbour controls implemented

## Supersession Status

This ADR has not been superseded.

## Final Decision

ShuleOS will operate as a hierarchical, shared-infrastructure multi-tenant platform in which the school is the primary tenant.

Tenant identity will be resolved by the server and enforced through application logic, authorization, schema constraints, database controls, automated tests, audit logging, and operational monitoring.

No caller, background process, integration, cache, file operation, payment workflow, or offline synchronization path may bypass the tenant boundary.
