# ADR-0011 — Multi-Level Tenant Hierarchy

> School in Clouds

## Document Information

| Field                | Value                                                                 |
| -------------------- | --------------------------------------------------------------------- |
| ADR                  | ADR-0011                                                              |
| Decision             | Explicit Platform, Brand, School, and Sub-School Governance Hierarchy |
| Status               | Accepted                                                              |
| Version              | 1.0                                                                   |
| Owner                | Platform Engineering                                                  |
| Repository           | `shuleos-api`                                                         |
| Effective Date       | 02 August 2026                                                        |
| Related Constitution | Engineering Constitution v1.1                                         |
| Supersedes           | None                                                                  |
| Superseded By        | None                                                                  |

## Context

ShuleOS is not limited to a flat model in which every user belongs to exactly one isolated school.

The platform architecture must also support higher and lower governance scopes, including:

- ShuleOS platform administration
- White-label brands
- School groups
- Chains of schools
- Dioceses or education organizations
- Individual schools
- Campuses
- Departments
- Grades
- Streams
- Boarding units
- Transport units
- Clubs
- Sports programmes
- Student leadership structures

A flat tenant model would be insufficient because some users may legitimately require access across several schools within an explicitly approved organization.

Examples include:

- A white-label brand administrator managing several branded schools
- A school-group director viewing consolidated reports
- A diocesan education officer overseeing multiple schools
- A platform support operator assisting one school temporarily
- A school leader managing several campuses
- A Head of Department managing one department inside one school

The architecture must support these use cases without weakening tenant isolation.

Higher-level access must never become an informal bypass around school boundaries.

A hierarchy node does not automatically grant access to all descendants.

Every governance scope must be explicit, authorized, auditable, and bounded.

## Decision

ShuleOS will implement an explicit multi-level tenant hierarchy.

The hierarchy will distinguish between:

1. Platform scope
2. Brand or organization scope
3. School scope
4. Campus scope where adopted
5. Sub-school operational scopes

The school remains the primary data-owning tenant for ordinary school records.

Higher levels are governance scopes, not automatic owners of all school data.

```text
ShuleOS Platform
    |
    +-- White-Label Brand or Organization
    |       |
    |       +-- School
    |       |      |
    |       |      +-- Campus
    |       |      |      |
    |       |      |      +-- Department
    |       |      |      +-- Grade
    |       |      |      +-- Stream
    |       |      |      +-- Boarding Unit
    |       |      |      +-- Transport Unit
    |       |      |
    |       |      +-- School-Wide Operational Scopes
    |       |
    |       +-- School
    |
    +-- Independent School
```

Access between levels must be granted through explicit governance assignments and permissions.

## Core Principle

Hierarchy does not equal authority.

```text
Hierarchy Relationship
        ≠
Automatic Data Access
```

A user gains access only when all required conditions are met:

- Authenticated identity
- Active account
- Explicit governance scope
- Active assignment
- Current tenant context
- Current permission
- Resource ownership
- Policy approval
- Business-rule approval

## Scope Definitions

### Platform Scope

Platform scope belongs to ShuleOS.

It may govern:

- School registration
- Platform subscriptions
- Platform billing
- White-label brands
- Platform plans
- Platform security
- Provider integrations
- Global configuration
- Infrastructure operations
- Support tooling
- Platform analytics

Platform scope must remain separate from school-level role administration.

Schools cannot assign platform permissions.

### Brand or Organization Scope

Brand scope represents an approved organization that governs a defined set of schools.

Examples include:

- White-label brand
- School chain
- Diocesan education office
- Education group
- Franchise
- Foundation
- Approved management organization

Brand scope may support:

- Brand configuration
- Shared branding
- Cross-school dashboards
- Consolidated reports
- Shared policy templates
- Shared notification templates
- Approved support workflows
- School onboarding
- Brand-level role assignments

Brand scope must not imply unrestricted access to every school record.

### School Scope

School scope is the primary tenant boundary.

Most operational and personal data belongs to one school.

Examples include:

- Learners
- Guardians
- Teachers
- Fees
- Assessments
- Attendance
- Discipline
- Transport
- Boarding
- Teaching records
- Files
- Notifications
- School roles
- School configuration

### Campus Scope

Campus scope may represent a physical or operational branch of one school.

Campus may be used when:

- One school operates at several locations
- Campuses share one legal school identity
- Campuses share one subscription
- The school needs narrower operational visibility

Campus is not automatically a separate tenant.

A future ADR may elevate campuses to full tenant status where legal, billing, or operational evidence requires it.

### Sub-School Scope

Sub-school scopes include:

- Department
- Grade
- Stream
- Learning area
- Boarding house
- Dormitory
- Transport route
- Sports programme
- Club
- Student leadership body

These are authorization scopes inside a school.

They do not own independent platform subscriptions unless a future ADR explicitly changes that model.

## Ownership Model

The hierarchy must distinguish:

- Governance
- Membership
- Data ownership
- Billing ownership
- Authorization scope

These concepts must not be collapsed into one field.

For example:

```text
Brand governs School A
School A owns Learner X
Campus 1 hosts Learner X
Department Y may manage some staff records
ShuleOS owns the platform subscription invoice
```

Each relationship is different.

## Primary Data Owner

The primary owner of ordinary school operational data is the school.

Records should usually contain:

```text
school_id
```

Additional scope fields may be included where needed, such as:

```text
brand_id
campus_id
department_id
grade_id
stream_id
boarding_unit_id
transport_unit_id
```

A lower-level scope must not replace school ownership.

## Governance Assignments

Higher-scope access must use explicit governance assignments.

A governance assignment should include:

- User
- Governance level
- Scope identifier
- Role
- Permissions
- Effective date
- Expiry date where applicable
- Assigning authority
- Status
- Audit metadata

Examples include:

- Platform operator for all schools
- Brand administrator for Brand A
- Group finance reviewer for Schools A, B, and C
- Campus administrator for Campus 2
- Department HOD for Science Department

## Explicit School Set

A higher-scope user must have access to an explicitly defined school set.

The system must not infer access from:

- Similar school names
- Shared email domain
- Shared brand display name
- Common owner name
- Client-supplied brand identifier
- Frontend navigation state
- Cached stale membership

The authorized school set must be server-resolved and current.

## Brand-School Relationship

A school may belong to:

- No brand
- One primary brand
- Multiple approved governance organizations only if the architecture explicitly supports it

The initial model should prefer one active primary brand relationship unless a documented need justifies many-to-many governance.

Brand-school relationships must be:

- Explicit
- Time-bounded where appropriate
- Audited
- Tenant-safe
- Historically traceable

## Independent Schools

Independent schools operate directly under the ShuleOS platform without brand governance.

They retain:

- School-owned data
- School-level roles
- School-level payment configuration
- School-level notification configuration
- School-level subscription state

Platform support remains governed separately.

## Cross-School Access

Cross-school access is prohibited by default.

It may be permitted only through an approved higher governance scope.

Examples include:

- Brand dashboard
- Group reporting
- Central finance oversight
- Shared staffing administration
- Centralized curriculum oversight
- Consolidated compliance reporting

Every cross-school workflow requires:

- Explicit permission
- Explicit school set
- Current assignment
- Query scoping
- Audit logging
- Safe output minimization
- Cross-school tests
- Separation from platform-wide authority

## Consolidated Reporting

Higher-scope users may need consolidated reports.

Consolidated reporting must:

- Use only authorized schools
- Preserve school boundaries
- Avoid exposing unnecessary row-level personal data
- Support aggregation
- Apply filters server-side
- Avoid broad platform queries followed by client filtering
- Be auditable
- Be performance-bounded

Where possible, aggregate results should be preferred over raw learner-level data.

## Cross-School Personal Data

Access to learner, guardian, teacher, or staff personal data across schools requires stronger justification.

A brand dashboard may show:

- School totals
- Enrollment totals
- Attendance trends
- Financial summaries
- Compliance status

It should not automatically reveal:

- Full learner lists
- Guardian contacts
- Health records
- Discipline records
- Detailed financial records
- Confidential assessment results

Row-level access requires separate permission and purpose.

## Brand-Level Roles

Brand roles must be separate from school roles.

Examples may include:

- Brand Administrator
- Group Director
- Group Academic Officer
- Group Finance Reviewer
- Brand Support Officer
- Group Reporting Analyst

Brand role names do not become school role assignments automatically.

## Platform Roles

Platform roles remain protected.

Examples may include:

- Platform Owner
- Platform Security Administrator
- Platform Billing Administrator
- Platform Support Administrator
- Platform Operations Engineer

Platform roles must not be creatable or assignable by schools or brands.

## School Roles

School roles remain tenant-owned and governed by ADR-0010.

Examples include:

- School Administrator
- Principal
- Head Teacher
- HOD
- Finance Officer
- Teacher
- Boarding Master
- Transport Manager

A brand role does not automatically include any school role.

## Role Translation

Where a brand needs consistent role patterns across schools, the platform may provide:

- Shared templates
- Recommended role mappings
- Brand policy baselines
- Template versioning

The final school role remains school-owned unless the architecture explicitly defines centrally managed roles.

## Centrally Managed Roles

If centrally managed brand roles are introduced, they must:

- Be clearly distinguished from school-created roles
- Be explicitly assigned
- Respect school scope
- Preserve audit history
- Avoid silent privilege expansion
- Require dedicated tests
- Use versioned policies

## Tenant Context

Every protected request must have one authoritative active scope.

Possible active contexts include:

- Platform
- Brand
- School
- Campus
- Sub-school scope

The request must not carry multiple ambiguous active scopes.

Where cross-school reporting is required, the active context remains one brand or governance scope, with an explicit authorized school set.

## Scope Switching

Users with multiple approved scopes may switch context.

Scope switching must:

- Require authentication
- Resolve available scopes server-side
- Re-check current assignments
- Re-check account state
- Re-check role and permissions
- Produce a server-issued context
- Be audited
- Invalidate or namespace relevant caches
- Prevent stale cross-scope access

Client-supplied scope identifiers do not prove authority.

## Session Handling

A session should record or derive:

- User
- Active governance scope
- Active school where applicable
- Active campus where applicable
- Security version
- Scope-switch history where required

Changing scope must not reuse stale permission state.

## JWT Relationship

JWT claims must not become the sole source of current hierarchy access.

Tokens may identify:

- User
- Session
- Active context reference

The server must re-check:

- Governance assignment
- School set
- Role
- Permission
- Account state
- Scope status

## Cache Strategy

Cache keys must include the active governance context.

Examples:

```text
platform:{user_id}:permissions:{security_version}
brand:{brand_id}:user:{user_id}:schools:{version}
school:{school_id}:user:{user_id}:permissions:{version}
```

Unsafe shared cache keys are prohibited.

Cache invalidation must occur when:

- Brand membership changes
- School relationship changes
- Governance role changes
- Permission changes
- Scope expiry occurs
- Account state changes
- School suspension occurs

## Database Model

The schema may include concepts such as:

- Organizations
- Brands
- Brand-school relationships
- Campuses
- Governance roles
- Governance assignments
- Scope memberships
- Authorized school sets
- Scope audit history
- Scope-switch history

Exact names are implementation details, but ownership and authority must remain explicit.

## Tenant-Aware Relationships

The database must prevent invalid relationships such as:

- Campus belonging to one school while referencing another school's records
- Brand assignment referencing unrelated schools
- School role assigned through an unauthorized brand
- Cross-school user assignment without approved governance scope
- Sub-school scope attached to another school

Application validation alone is insufficient.

## Subscription Ownership

The initial subscription model remains school-based unless a future ADR changes it.

A brand may:

- Pay on behalf of schools
- Manage group contracts
- Receive consolidated invoices
- Sponsor school subscriptions

But the architecture must preserve the relationship between:

- Contract owner
- Payer
- School subscription
- School access state
- Platform invoice

These are distinct.

## Group Billing

Future group billing may support:

- One invoice for several schools
- Per-school licence allocation
- Shared plan negotiation
- Centralized renewal
- Consolidated payment

Group billing requires explicit accounting design and may require a separate ADR.

## School Finance

Brand governance does not automatically grant authority over school finance.

Cross-school finance access requires:

- Explicit brand finance permission
- Approved school set
- Purpose limitation
- Audit logging
- Separation of duties
- Data minimization

Platform support access does not grant financial posting authority.

## Payment Configuration

School payment channels remain school-owned.

A brand may provide recommended configuration or operational oversight, but it must not silently replace school ownership.

Any shared merchant model requires a future payment ADR.

## Notification Scope

Notifications may originate from:

- Platform
- Brand
- School
- Campus
- Sub-school unit

Each notification must clearly identify the sender scope.

A brand may send to schools within its approved scope.

A school may not impersonate a brand or platform.

## File Ownership

Files must preserve their true ownership scope.

Examples:

- Platform file
- Brand policy file
- School learner document
- Campus operational file
- Department resource

A brand-shared file may be visible to several schools only through explicit access relationships.

## Offline Access

Higher-level offline access must be more restrictive than ordinary school offline access.

A brand user must not automatically download data for every school.

Offline scope must be:

- Explicit
- Bounded
- Purpose-limited
- Tenant-aware
- Revocable
- Freshness-aware

Cross-school offline aggregation requires dedicated approval.

## Queue Processing

Background jobs operating at higher scope must process schools deliberately.

Preferred pattern:

```text
Resolve authorized school set
    |
    v
Process one school or bounded batch
    |
    v
Record result
    |
    v
Clear school context
    |
    v
Continue
```

A brand-level job must not accidentally retain one school's context while processing another.

## Scheduled Tasks

Platform-wide and brand-wide scheduled work must:

- Declare scope
- Resolve authorized entities
- Use bounded batches
- Clear context
- Preserve auditability
- Apply noisy-neighbour controls

## Search

Cross-school search must be explicitly authorized.

Search results must:

- Be filtered server-side
- Use only authorized schools
- Avoid leaking existence of unauthorized records
- Respect data classification
- Be paginated
- Be auditable for sensitive use

## Exports

Higher-scope exports require:

- Explicit permission
- Approved school set
- Data minimization
- Export purpose
- Bounded size
- Private storage
- Expiry
- Audit logging

Raw cross-school exports should be restricted more strongly than aggregate reports.

## Support Access

Support access is a special higher governance scope.

Support access must be:

- Purpose-specific
- Time-bounded
- Least-privileged
- Approved where required
- Visible where practical
- Audited
- Revocable
- Reviewed afterward

Support access must not be implemented by assigning a permanent school role.

## Impersonation

Any future impersonation feature requires:

- Dedicated platform permission
- Explicit school target
- Explicit user target
- Strong authentication
- Visible banner
- Time limit
- Audit trail
- Restricted high-risk actions
- Separate ADR or amendment

Hidden impersonation is prohibited.

## White-Label Branding

Brand scope may manage:

- Brand logo
- Brand colours
- Brand domain
- Shared email appearance
- Shared communication templates
- Approved school onboarding defaults

Branding must not alter:

- Security controls
- Legal ownership
- Tenant data ownership
- ShuleOS platform billing identity
- Mandatory privacy language

## Custom Domains

Custom domains may resolve to brand or school contexts.

Domain resolution must:

- Be server-controlled
- Use approved domain mappings
- Use TLS
- Avoid trusting arbitrary Host headers
- Resolve current brand or school status
- Preserve authentication and tenant isolation

Custom-domain architecture may require a separate ADR.

## Data Residency

A brand or organization may have contractual residency requirements.

Residency controls must remain compatible with:

- School ownership
- Object storage
- Backups
- Logs
- Analytics
- Providers
- Cross-border transfer rules

A higher scope must not override legal or contractual restrictions silently.

## Child Data Protection

Cross-school access to learner data requires the strongest privacy controls.

Higher governance should prefer:

- Aggregated data
- De-identified data
- Minimum necessary fields
- Explicit purpose
- Strong audit logging
- Short retention

## Academic Integrity

Brand or group academic officers may access:

- Curriculum coverage summaries
- Assessment completion status
- Aggregated performance trends

They must not automatically receive unpublished exam content or unrestricted learner-level marks.

## Audit Logging

Hierarchy audit events should include:

- Brand created
- School linked to brand
- School removed from brand
- Governance role assigned
- Governance role revoked
- Scope switched
- Cross-school report viewed
- Cross-school export generated
- Support access granted
- Support access used
- Brand policy changed
- Campus created
- Campus reassigned
- Unauthorized cross-scope attempt

Audit records should include:

- Actor
- Active scope
- Target scope
- Authorized school set
- Action
- Resource
- Outcome
- Reason
- Timestamp
- Correlation identifier

## Observability

Monitoring should detect:

- Cross-school access denials
- Invalid scope switching
- Unexpected platform-scope use
- Brand user accessing unrelated school
- Support access outside approved window
- Unusual cross-school export volume
- Cache-scope mismatches
- Queue context leakage
- Repeated governance escalation attempts

Critical anomalies must generate alerts.

## Noisy-Neighbour Protection

Higher-scope users may trigger expensive workloads.

Controls include:

- School-count limits
- Bounded report ranges
- Per-brand rate limits
- Query timeouts
- Queue batching
- Export limits
- Aggregation
- Concurrency controls
- Fair scheduling

## Data Retention

Hierarchy records must preserve historical governance relationships.

Retention should cover:

- Brand-school membership
- Governance assignments
- Scope-switch logs
- Support access
- Role history
- Consolidated report audit
- Export audit

Removing a school from a brand must not erase historical evidence.

## Lifecycle States

Brand and hierarchy entities may use states such as:

- Draft
- Active
- Suspended
- Inactive
- Archived
- Terminated

State changes must affect access immediately.

A suspended brand does not necessarily suspend all schools unless policy explicitly says so.

## Brand Suspension

Brand suspension may disable:

- Brand-level dashboards
- Brand-level role assignments
- Brand communication
- Brand-wide exports

School operation may continue according to separate school subscription and security state.

## School Removal from Brand

When a school leaves a brand:

- Brand access ends immediately
- School data remains with the school
- Brand caches are invalidated
- Cross-school assignments are revoked
- Shared configuration is reviewed
- Shared files are reassessed
- Audit history remains
- Subscription ownership is recalculated where required

## Campus Reassignment

A campus must not be moved between schools casually.

Reassignment requires:

- Data ownership review
- Learner and staff impact review
- Financial impact review
- File ownership review
- Audit trail
- Migration plan
- Approval

## Alternatives Considered

### Flat School-Only Tenancy

Rejected.

It does not support legitimate organization-wide governance.

### Platform-Wide Access for All Group Administrators

Rejected.

This would over-grant authority and undermine tenant isolation.

### Separate ShuleOS Deployment per Brand

Not selected as the default.

It would increase operational cost and fragment platform management.

It may remain an option for exceptional enterprise requirements through a future ADR.

### Explicit Hierarchical Governance

Accepted.

This supports brands and groups while preserving school ownership and bounded access.

## Consequences

### Positive

- Supports white-label brands and school groups
- Preserves school ownership
- Enables consolidated reporting
- Enables controlled cross-school administration
- Supports campus and departmental scopes
- Creates a clear path for enterprise growth
- Preserves platform-role protection
- Makes support access governable

### Negative

- Authorization becomes more complex.
- Scope switching requires careful implementation.
- Cache invalidation becomes more important.
- Cross-school reporting requires stronger privacy controls.
- Billing and governance relationships may differ.
- Support and brand access require extensive auditing.
- Queue and offline behaviour must be hierarchy-aware.

These costs are accepted because multi-level governance is a strategic platform requirement.

## Risks and Mitigations

### Risk: Brand User Accesses Unrelated School

Mitigation:

- Explicit school set
- Server-side resolution
- Cross-school tests
- Tenant-aware queries
- Audit logging

### Risk: Platform Role Leaks into School Role System

Mitigation:

- Separate role catalogues
- Protected permissions
- Assignment boundaries
- CI checks
- Database constraints

### Risk: Stale Scope after Membership Change

Mitigation:

- Real-time revocation
- Cache invalidation
- Session revalidation
- Scope-version counters
- Next-request enforcement

### Risk: Cross-School Personal Data Overexposure

Mitigation:

- Aggregate-first reporting
- Purpose limitation
- Row-level permission
- Data minimization
- Audit logging

### Risk: Context Leakage in Jobs

Mitigation:

- Explicit school iteration
- Context clearing
- Job tests
- Bounded batches
- Observability

### Risk: Brand Suspension Disrupts Schools Incorrectly

Mitigation:

- Separate brand and school state machines
- Explicit policy
- Independent subscription enforcement
- Tests

## Security Impact

Hierarchy governs who may cross ordinary school boundaries.

This area requires:

- Strong authentication
- Explicit assignments
- Fail-closed authorization
- Real-time revocation
- Tenant-aware database design
- Audit logging
- Observability
- Privacy review
- Security testing
- Incident response

## Tenant Impact

The school remains the primary tenant for ordinary operational data.

Brand and platform scopes govern access but do not silently become data owners.

Sub-school scopes narrow access inside a school.

## Performance Impact

Cross-school workflows may be expensive.

Performance controls include:

- Indexed relationships
- Authorized-school-set caching
- Aggregate tables where justified
- Pagination
- Bounded report ranges
- Queue processing
- Materialized summaries where approved
- Query-plan review
- Noisy-neighbour controls

## Operational Impact

Platform Engineering must maintain:

- Brand lifecycle
- Brand-school relationships
- Governance assignments
- Scope switching
- Support access
- Cross-school reporting
- Cache invalidation
- Audit review
- Enterprise billing relationships
- Incident response

## Implementation Notes

ShuleOS already contains schema concepts related to:

- White-label brands
- Brand-school relationships
- School subscriptions
- Platform roles
- School roles

These foundations must be audited against this ADR.

This ADR defines the target governance model and does not claim that every current table, route, middleware, or policy already satisfies it.

## Verification

Compliance will be verified through:

- Platform-scope tests
- Brand-scope tests
- School-scope tests
- Campus-scope tests
- Cross-school denial tests
- Authorized school-set tests
- Scope-switch tests
- Real-time revocation tests
- Cache invalidation tests
- Brand-school membership tests
- Brand suspension tests
- School removal tests
- Support-access tests
- Cross-school report tests
- Cross-school export tests
- Queue context tests
- Offline hierarchy tests
- Payment-scope tests
- Notification-scope tests
- File-scope tests
- Performance tests
- Privacy review
- Security review
- CI hierarchy-contract gates

## Constitution Compliance

This decision supports:

- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 9 — Every feature belongs to a Domain
- Rule 10 — Design first. Code second
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
- Rule 47 — Platform roles are protected
- Rule 48 — Schools may create custom roles
- Rule 49 — Schools receive role templates
- Rule 50 — Delegated administration
- Rule 51 — Schools cannot assign platform permissions
- Rule 63 — Every file belongs to a tenant
- Rule 68 — Cross-tenant tests are mandatory
- Rule 87 — Tenant isolation is enforced by application and database
- Rule 88 — Authentication has one source of truth
- Rule 89 — Authorization depends on authenticated identity and fails closed
- Rule 90 — Uniqueness is tenant scoped
- Rule 93 — Access revocation takes effect on the next request
- Rule 94 — Every module follows the approved architecture
- Rule 99 — Tenant-owned tables are complete only when tenant safe
- Rule 101 — Financial ownership is established server-side
- Rule 102 — Security-critical invariants are verified automatically
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI
- Rule 111 — Tenant schema requirements are automatically validated
- Rule 114 — ShuleOS is continuously hardened
- Rule 116 — Offline synchronization is tenant-aware
- Rule 119 — Tenant hierarchy is explicit and governed
- Rule 120 — Cross-school access requires approved higher governance scope
- Rule 121 — Learner information receives the highest privacy classification
- Rule 125 — Data residency decisions are documented

## Related ADRs

- ADR-0000 — Architecture Decision Record Process
- ADR-0001 — Modular Monolith Architecture
- ADR-0002 — Multi-Tenant Architecture
- ADR-0003 — JWT Authentication
- ADR-0004 — Offline-First Architecture
- ADR-0005 — School Payment Architecture
- ADR-0006 — Notification Engine
- ADR-0007 — Cloudflare R2
- ADR-0010 — Role Template System

## Implementation Checklist

- [ ] Governance hierarchy documented
- [ ] Platform scope implemented
- [ ] Brand scope implemented
- [ ] School scope preserved as primary tenant
- [ ] Campus scope defined
- [ ] Sub-school scopes defined
- [ ] Brand-school relationship model implemented
- [ ] Governance assignments implemented
- [ ] Explicit authorized school sets implemented
- [ ] Scope switching implemented
- [ ] Scope-switch audit implemented
- [ ] Platform roles separated from brand and school roles
- [ ] Brand roles implemented
- [ ] School roles remain tenant-owned
- [ ] Cross-school access denied by default
- [ ] Consolidated reporting secured
- [ ] Aggregate-first reporting implemented
- [ ] Cross-school personal-data controls implemented
- [ ] Tenant-aware cache keys implemented
- [ ] Cache invalidation implemented
- [ ] Hierarchy-aware queues implemented
- [ ] Higher-scope offline restrictions implemented
- [ ] Brand notification scope implemented
- [ ] Brand file scope implemented
- [ ] Subscription and payer relationships separated
- [ ] Group billing deferred or separately designed
- [ ] Support access workflow implemented
- [ ] Support access time limits implemented
- [ ] Impersonation prohibited until separately approved
- [ ] Brand suspension implemented
- [ ] School removal from brand implemented
- [ ] Historical governance records retained
- [ ] Data residency review completed
- [ ] Child-data protections implemented
- [ ] Hierarchy observability implemented
- [ ] Cross-school performance tests implemented
- [ ] CI hierarchy-contract gates implemented

## Supersession Status

This ADR has not been superseded.

## Final Decision

ShuleOS will support an explicit multi-level governance hierarchy consisting of platform, brand or organization, school, campus, and sub-school scopes.

The school remains the primary tenant and owner of ordinary operational data.

Higher governance scopes may access several schools only through explicit assignments, explicit authorized school sets, current permissions, strong audit logging, and fail-closed authorization.

Hierarchy must never be treated as automatic authority.
