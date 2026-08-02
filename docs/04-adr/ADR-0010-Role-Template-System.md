# ADR-0010 — Role Template and Custom Permission System

> School in Clouds

## Document Information

| Field                | Value                                                                    |
| -------------------- | ------------------------------------------------------------------------ |
| ADR                  | ADR-0010                                                                 |
| Decision             | Tenant-Scoped Role Templates, Custom Roles, and Delegated Administration |
| Status               | Accepted                                                                 |
| Version              | 1.0                                                                      |
| Owner                | Platform Engineering                                                     |
| Repository           | `shuleos-api`                                                            |
| Effective Date       | 02 August 2026                                                           |
| Related Constitution | Engineering Constitution v1.1                                            |
| Supersedes           | None                                                                     |
| Superseded By        | None                                                                     |

## Context

ShuleOS serves schools with different leadership structures, staffing models, departments, workflows, and governance practices.

One school may use roles such as:

- Head Teacher
- Deputy Head Teacher
- Director of Studies
- Head of Department
- Class Teacher
- Subject Teacher
- Finance Officer
- Examination Officer
- Boarding Master
- Transport Manager
- Games Teacher
- Librarian
- ICT Administrator

Another school may use different names, combine responsibilities, or create entirely custom operational roles.

A fixed global list of school roles would therefore be too restrictive.

At the same time, unrestricted role creation would create serious security risks, including:

- Schools assigning themselves platform authority
- Users granting permissions they do not possess
- Cross-school role assignment
- Privilege escalation
- Conflicting responsibilities
- Untraceable permission changes
- Inconsistent module access
- Loss of separation of duties
- Insecure delegation by Heads of Department
- Role sprawl and duplicate roles

ShuleOS therefore requires a role and permission architecture that supports school flexibility while preserving platform security, tenant isolation, auditability, and least privilege.

## Decision

ShuleOS will implement a tenant-scoped Role Template and Custom Permission System.

The system will provide:

1. Platform-protected roles and permissions
2. Approved school role templates
3. Tenant-created custom roles
4. Module-based permission templates
5. Delegated administration
6. Assignment boundaries
7. Separation-of-duty controls
8. Versioned role definitions
9. Audit logging
10. Fail-closed authorization

Schools may create, rename, copy, and customize school-level roles only within permissions that the platform explicitly exposes to schools.

Schools may never create, grant, inherit, or delegate protected platform permissions.

## Core Principle

```text
Platform defines permission catalogue
    |
    v
Platform marks school-assignable permissions
    |
    v
ShuleOS provides role templates
    |
    v
School copies or customizes template
    |
    v
Authorized school administrator assigns role
    |
    v
User receives only permitted tenant-scoped authority
```

A role is not trusted merely because it exists.

Every authorization decision must verify:

- Authenticated identity
- Active tenant
- Current role assignment
- Current permission
- Assignment scope
- Resource ownership
- Account state
- Applicable business rule

## Authorization Model

ShuleOS authorization will use a combination of:

- Roles
- Permissions
- Policies
- Assignment scope
- Tenant context
- Object ownership
- Workflow state
- Separation-of-duty rules

Roles group permissions.

Permissions describe approved actions.

Policies decide whether a particular user may perform an action on a particular resource.

Possession of a role name alone is never sufficient authorization.

## Permission Catalogue

The platform owns the authoritative permission catalogue.

Every permission must have:

- Stable permission key
- Human-readable name
- Description
- Module
- Risk classification
- Assignable scope
- Platform-protected status
- School-assignable status
- Delegatable status
- Approval requirement
- Version or lifecycle state

Conceptual permission keys may include:

```text
learners.view
learners.create
learners.update
learners.archive
teachers.manage
attendance.capture
attendance.correct
assessment.enter_marks
assessment.moderate_marks
assessment.publish_results
finance.view
finance.capture_payment
finance.approve_payment
finance.reverse_payment
roles.manage
users.assign_roles
```

Permission keys must not depend on display labels.

## Permission Categories

Permissions should be grouped by module or domain.

Examples include:

- Administration
- Academic
- Learners
- Guardians
- Teachers
- Teaching
- Assessment
- Finance
- Attendance
- Discipline
- Boarding
- Transport
- Sports
- Library
- Communication
- Student Leadership
- Elections
- Reports
- Configuration
- Security
- Platform Operations

This supports module templates and easier governance.

## Platform-Protected Permissions

Platform permissions are never school-assignable.

Examples may include:

- Manage platform subscriptions
- Manage platform plans
- Manage white-label brands
- Access platform security operations
- Manage provider credentials
- Manage platform-wide integrations
- View all tenants
- Suspend schools
- Activate licences
- Manage platform billing
- Access infrastructure diagnostics
- Manage platform administrators
- Override tenant isolation
- Access cross-school support tooling

School users must not obtain these permissions through:

- Custom roles
- Copied templates
- API manipulation
- Database seeding
- Import
- Bulk assignment
- Role inheritance
- Delegated administration

The application and database must enforce this boundary.

## School-Assignable Permissions

School-level permissions are explicitly allowlisted.

Examples may include:

- Manage learners
- Manage teachers
- Capture attendance
- Manage timetable
- Enter marks
- Moderate marks
- Approve lesson plans
- Manage transport
- Manage boarding
- Manage library
- View finance
- Capture school payments
- Generate reports
- Send approved school communication
- Manage school settings

A permission is school-assignable only when the platform permission catalogue says so.

## Role Templates

ShuleOS will provide approved role templates.

Templates are starting points, not mandatory permanent roles.

Examples include:

- School Administrator
- Principal
- Head Teacher
- Deputy Head Teacher
- Director of Studies
- Head of Department
- Class Teacher
- Subject Teacher
- Examination Officer
- Finance Officer
- Accounts Clerk
- Boarding Master
- Transport Manager
- Librarian
- Games Teacher
- ICT Administrator

Templates may be grouped by module.

## Module Templates

ShuleOS may provide permission templates such as:

### Examination Template

May include:

- View examinations
- Create examination sessions
- Manage papers
- Enter marks
- Moderate marks
- Publish results where separately approved
- Generate reports

### Finance Template

May include:

- View invoices
- Capture payment
- Allocate payment
- Issue receipt
- View reports

High-risk actions such as reversal or approval may remain separate.

### Teaching Template

May include:

- View schemes of work
- Create lesson plans
- Create lesson notes
- Submit records of work
- View curriculum coverage

### Sports Template

May include:

- Manage teams
- Manage activities
- Record participation
- Manage fixtures
- View reports

### Dormitory Template

May include:

- Manage hostels
- Manage rooms
- Allocate beds
- Record movements
- View boarding reports

### Transport Template

May include:

- Manage routes
- Manage stops
- Allocate learners
- View transport reports

Templates must not silently include protected permissions.

## Template Application

When a school uses a template, ShuleOS should create a tenant-owned role definition based on the approved template.

The tenant may then customize only permitted fields and school-assignable permissions.

Future changes to the platform template must not silently alter existing school roles.

Template updates should create a reviewable upgrade path.

## Custom Roles

Schools may create custom roles.

Examples include:

- Senior Teacher
- Boarding Coordinator
- Lower Primary Coordinator
- Guidance and Counselling Teacher
- School Bursar
- Curriculum Coordinator

Custom roles must:

- Belong to one school
- Use a tenant-scoped unique name
- Contain only school-assignable permissions
- Respect delegation boundaries
- Be audited
- Be versioned or historically traceable
- Be inactive rather than destructively deleted where already used

## Tenant-Scoped Uniqueness

Role names are tenant-scoped by default.

For example, many schools may each have a role named:

```text
Head of Department
```

The correct uniqueness boundary is conceptually:

```text
school_id + normalized_role_name
```

A school role name must not be globally unique.

Platform role names may use separate global rules.

## Role Assignment

A role assignment must include:

- User
- Tenant
- Role
- Assignment scope
- Effective date
- Expiry date where applicable
- Assigning authority
- Status
- Audit metadata

A role assigned in one school grants no authority in another school.

## Assignment Scope

A role may apply to:

- Entire school
- Department
- Grade
- Stream
- Learning area
- Boarding unit
- Transport unit
- Sports programme
- Specific approved resource set

The scope must be explicit.

Examples:

- A Head of Department may manage teachers and learning areas within one department.
- A Class Teacher may manage one assigned class.
- A Transport Manager may manage transport resources but not finance or examinations.
- A Subject Teacher may enter marks only for assigned learning areas and classes.

A broad role must not be inferred from a narrow assignment.

## Multiple Roles

A user may hold multiple roles in the same school where permitted.

Effective permission is the approved union of active role permissions, subject to:

- Deny rules
- Assignment scope
- Separation of duties
- Resource ownership
- Workflow state
- Current account state
- Current tenant context

Multiple roles must not bypass prohibited permission combinations.

## Explicit Deny

Where required, the architecture may support explicit deny rules.

An explicit deny must take precedence over an allow.

Deny rules may be appropriate for:

- Temporary restriction
- Sensitive finance controls
- Examination confidentiality
- Conflict-of-interest controls
- Disciplinary separation

The deny model must be designed carefully to avoid unpredictable authorization.

## Delegated Administration

Schools may delegate role administration.

For example:

- School Administrator may assign senior leadership roles.
- Principal may assign approved school roles.
- Head of Department may assign limited department roles.
- Transport Manager may assign transport operational roles.
- Boarding Manager may assign dormitory responsibilities.

Delegation does not permit unlimited role creation or assignment.

## Delegation Boundary

A user may delegate only permissions that:

1. The user currently possesses.
2. The permission catalogue marks as delegatable.
3. The user's assignment scope covers.
4. The target role may legally receive.
5. No separation-of-duty rule prohibits.
6. The target user belongs to the same approved tenant.
7. The action is permitted by policy.

A user cannot grant greater authority than the user is allowed to delegate.

## Head of Department Delegation

A Head of Department may assign approved departmental responsibilities to users within the department.

A Head of Department must not be able to grant:

- Platform permissions
- School Administrator authority
- Finance approval authority
- Subscription authority
- Unrelated department authority
- Cross-school access
- Security administration
- Role-management permission beyond the delegated boundary

HOD delegation must be constrained by both permission and department scope.

## Role Management Permissions

Role management must be granular.

Examples include:

```text
roles.view
roles.create_custom
roles.update_custom
roles.archive_custom
roles.assign
roles.revoke
roles.view_audit
roles.manage_templates
roles.delegate
```

Possession of one role-management permission must not imply all others.

## Separation of Duties

Sensitive workflows require separation of duties.

Examples include:

- User creates a payment; another approves it.
- User enters marks; another moderates them.
- User requests a reversal; another authorizes it.
- User creates a role; another approves high-risk permissions.
- User uploads an examination paper; another authorizes publication.
- User requests support access; another approves it.

The role system must support these constraints.

## Prohibited Permission Combinations

Some permission combinations may be prohibited or require special approval.

Examples include:

- Capture payment and approve own payment
- Request reversal and approve same reversal
- Enter marks and publish final results without moderation
- Create users and grant platform authority
- Configure provider credentials and approve own changes
- Access examination paper and approve own publication where separation is required

Combination rules must be centrally defined and tested.

## Least Privilege

Every user should receive the minimum authority required for current responsibilities.

Broad roles should not be assigned merely for convenience.

Temporary responsibilities should use:

- Time-bounded assignment
- Narrow scope
- Explicit approval
- Automatic expiry
- Audit logging

## Time-Bounded Roles

Role assignments may include:

- Start time
- End time
- Temporary status
- Review date
- Revocation reason

Expired assignments must stop granting access automatically.

Time-bounded roles are useful for:

- Acting appointments
- Temporary examination officers
- Event coordinators
- Support access
- Short-term finance duties

## Role Status

Role lifecycle states may include:

- Draft
- Active
- Inactive
- Archived
- Superseded
- Pending Approval

Inactive or archived roles must not grant authority.

## Role Versioning

Role definitions must be historically traceable.

When permissions change, ShuleOS must preserve:

- Previous role definition
- New role definition
- Actor
- Reason
- Approval
- Effective time
- Affected assignments

Historical audits must be able to determine what authority a user had at a specific time.

## Template Versioning

Templates must be versioned.

A template update should record:

- Template version
- Added permissions
- Removed permissions
- Changed risk classification
- Migration guidance
- Security impact
- Effective date

Schools should review changes rather than receiving silent privilege expansion.

## Permission Deprecation

Permissions may be deprecated.

Deprecation requires:

- Replacement permission where applicable
- Migration plan
- Role impact report
- Timeline
- Documentation
- CI or audit checks
- Removal only after safe migration

Deprecated permissions must not remain silently active forever.

## Role Copying

A school may copy an existing tenant-owned role as a starting point.

Copying must:

- Preserve tenant ownership
- Revalidate every permission
- Remove non-delegatable permissions where required
- Create a new role identity
- Avoid copying assignments automatically
- Produce an audit event

## Import and Bulk Assignment

Role imports and bulk assignments must not bypass permission checks.

Bulk operations must:

- Resolve tenant server-side
- Validate each role
- Validate each user
- Validate assignment scope
- Reject platform permissions
- Be transactional or use controlled batches
- Produce clear errors
- Be audited

## Role Deletion

Roles already used in assignments or audit history should not be destructively deleted.

Preferred behaviour is:

- Inactivate
- Archive
- Revoke active assignments where approved
- Preserve historical references

Deletion must never erase evidence of previous authority.

## User Removal

When a user leaves a school or is deactivated:

- Role assignments must stop granting access.
- Active sessions must be revoked.
- Delegated authority must end.
- Pending approvals may require reassignment.
- Audit history remains.

## Account State Enforcement

A role assignment cannot override account state.

Suspended, locked, archived, or deactivated users must fail authorization even when assignments remain in the database.

Account-state changes must take effect on the next request.

## Tenant Context

Role and permission evaluation must use the authoritative tenant context.

The application must not calculate permissions from:

- Client-supplied school identifiers
- Stale frontend state
- Token claims alone
- Cached roles without tenant namespace
- Historical assignments outside the active scope

## Authorization Pipeline

The approved authorization flow is:

```text
Authenticated User
    |
    v
Account State Valid
    |
    v
Tenant Context Resolved
    |
    v
Active Role Assignments Loaded
    |
    v
Permission and Scope Evaluated
    |
    v
Policy and Ownership Evaluated
    |
    v
Separation-of-Duty Rules Evaluated
    |
    v
Allow or Deny
```

Any uncertainty results in denial.

## Policy Layer

Permissions authorize categories of action.

Policies authorize actions against particular resources.

Example:

```text
Permission:
assessment.enter_marks

Policy:
May this teacher enter marks for this class,
learning area, assessment, school, and publication state?
```

The role engine must not replace object-level policies.

## Frontend Behaviour

The frontend may use permissions to:

- Hide unavailable navigation
- Disable unavailable actions
- Improve usability
- Explain access limitations

Frontend checks are not security boundaries.

Every API endpoint must independently enforce authorization.

## Permission Cache

Permission resolution may be cached for performance.

Cache keys must include:

- Tenant
- User
- Role or security version
- Assignment scope where required

Cache invalidation must occur when:

- Role changes
- Permission changes
- Assignment changes
- Account state changes
- Tenant membership changes
- Delegation changes
- Role expiry occurs

Stale permission cache must not preserve revoked access.

## Real-Time Revocation

The following must take effect on the next request:

- Role removed
- Permission removed
- Assignment expired
- User suspended
- School locked
- Delegation revoked
- Scope reduced

JWT expiry must not be the only revocation mechanism.

## Database Model

The schema may include concepts such as:

- Permissions
- Permission modules
- Role templates
- Template versions
- Tenant roles
- Role permissions
- User role assignments
- Assignment scopes
- Delegation grants
- Separation-of-duty rules
- Role audit history

Exact table names are implementation decisions, but schema ownership and relationships must remain explicit.

## Tenant-Aware Foreign Keys

Role assignments must not connect:

- User from School A
- Role from School B
- Scope from another school
- Department from another tenant
- Learning area from another tenant

The database should enforce tenant-aware relationships where practical.

Application validation alone is insufficient.

## Audit Logging

Audit events should include:

- Role created
- Role copied
- Role renamed
- Permission added
- Permission removed
- Role activated
- Role archived
- Assignment granted
- Assignment revoked
- Assignment expired
- Delegation granted
- Delegation revoked
- Template applied
- Template upgraded
- Prohibited assignment attempted
- Platform permission escalation attempted

Audit information should include:

- Actor
- Tenant
- Target user
- Role
- Scope
- Previous state
- New state
- Reason
- Approval where required
- Timestamp
- Correlation identifier

## Approval Workflow

High-risk role changes may require approval.

Examples include:

- Finance approval permission
- Examination publication permission
- Role-management permission
- Provider-configuration permission
- Sensitive learner-data permission
- Bulk communication permission

The role change may remain pending until approved by an authorized second user.

## Notifications

Relevant users may be notified when:

- A role is assigned
- A role is removed
- A high-risk permission is granted
- Delegation is created
- Temporary access is expiring
- A prohibited escalation is attempted
- A sensitive role is changed

Notifications must not disclose unnecessary permission details to unauthorized users.

## Security Alerts

Privilege-escalation attempts should be observable.

Examples include:

- School attempting to assign platform permission
- User granting a permission they cannot delegate
- Cross-tenant role assignment
- Repeated denied role changes
- Assignment outside authorized scope
- Suspended user attempting role administration

Critical events should generate alerts.

## Role Review

Schools should periodically review active roles and assignments.

Review reports may include:

- Users with administrative permissions
- Users with finance permissions
- Users with examination publication authority
- Expired assignments
- Unused roles
- Duplicate roles
- High-risk permission combinations
- Delegated authority
- Users without roles
- Orphaned assignments

## Access Certification

ShuleOS may support periodic access certification.

An authorized reviewer confirms that users still require assigned roles.

Certification may include:

- Review period
- Reviewer
- Assignment
- Decision
- Reason
- Completion status
- Follow-up action

## Emergency Access

Emergency or break-glass access requires a separate controlled mechanism.

It must include:

- Explicit reason
- Strong authentication
- Time limit
- Narrow scope
- Audit logging
- Alerting
- Post-use review
- Automatic revocation

Ordinary custom roles must not be used as undocumented emergency access.

## Support and Impersonation

Platform support access must not rely on assigning ordinary school roles permanently.

Any future impersonation feature requires:

- Dedicated permission
- Consent or approved support process
- Time-bounded scope
- Full audit
- Visible indication
- No hidden privilege inheritance
- Separate ADR or architecture review

## API Design

Role-management endpoints must:

- Require authentication
- Resolve tenant server-side
- Require granular permissions
- Validate assignability
- Validate delegation boundary
- Validate assignment scope
- Validate tenant ownership
- Enforce separation of duties
- Return safe errors
- Audit successful and denied sensitive actions

## Error Handling

Authorization failures must not expose:

- Other tenant role names
- Platform permission catalogue details not intended for schools
- Internal policy structure
- Whether a cross-tenant target exists
- Sensitive administrative configuration

The API should fail safely.

## Testing

Testing must include:

- Platform-permission protection
- School custom-role creation
- Tenant-scoped uniqueness
- Cross-tenant assignment denial
- Delegation-boundary enforcement
- HOD scope enforcement
- Separation-of-duty enforcement
- Role expiry
- Real-time revocation
- Permission-cache invalidation
- Policy enforcement
- Bulk-assignment validation
- Archived-role behaviour
- Account-state enforcement
- Audit creation
- High-risk approval workflows

## Observability

Metrics and alerts should cover:

- Role changes
- Assignment volume
- Permission-denial rate
- Privilege-escalation attempts
- Cross-tenant assignment attempts
- High-risk permission grants
- Expired assignments
- Cache invalidation failures
- Role-review completion
- Emergency-access usage

## Data Retention

Role and permission history must be retained according to security, operational, contractual, and legal requirements.

Historical authorization evidence should not be removed merely because a role is archived.

## Privacy

Role data may reveal sensitive organizational information.

Access to:

- Administrative assignments
- Finance permissions
- Security roles
- Support access
- Staff responsibilities

must be limited to authorized users.

## Alternatives Considered

### Fixed Global Roles Only

Rejected.

Schools have different structures and require customization.

### Fully Unrestricted Custom Roles

Rejected.

This would permit privilege escalation and inconsistent governance.

### Permission Assignment Directly to Every User

Rejected as the default.

Direct assignment creates difficult-to-manage access sprawl and weakens role governance.

Exceptional direct permissions, if ever allowed, require separate design.

### Platform Templates with Tenant Customization

Accepted.

This balances usability, consistency, and school flexibility.

## Consequences

### Positive

- Schools can model their real organizational structures.
- Platform permissions remain protected.
- Role templates simplify setup.
- Custom roles support diverse schools.
- Delegation supports HOD and operational leadership.
- Least privilege becomes practical.
- Permission changes are auditable.
- Role versioning supports historical review.
- Separation of duties can be enforced.

### Negative

- Role and scope evaluation becomes more complex.
- Permission catalogue governance requires discipline.
- Template upgrades require review.
- Cache invalidation becomes security critical.
- Delegation and separation-of-duty rules require extensive tests.
- Schools may create duplicate or poorly named roles without guidance.

These costs are accepted because flexible but secure authorization is a core ShuleOS requirement.

## Risks and Mitigations

### Risk: Privilege Escalation

Mitigation:

- Protected platform permissions
- School-assignable allowlist
- Delegation limits
- Approval workflows
- Automated tests
- Audit logging

### Risk: Cross-Tenant Assignment

Mitigation:

- Tenant context
- Tenant-aware foreign keys
- Scoped queries
- Cross-tenant tests
- Safe errors

### Risk: Stale Permission Cache

Mitigation:

- Security-versioned cache keys
- Immediate invalidation
- Short bounded lifetime
- Revocation tests
- Observability

### Risk: Role Sprawl

Mitigation:

- Templates
- Duplicate detection
- Naming guidance
- Role review reports
- Archive lifecycle
- Usage reporting

### Risk: Separation-of-Duty Bypass

Mitigation:

- Central conflict rules
- Policy enforcement
- Maker-checker workflows
- Automated tests
- High-risk approvals

### Risk: Excessive Delegation

Mitigation:

- Delegatable permission flag
- Scope limits
- User cannot grant more than delegatable authority
- Audit and review

## Security Impact

The role system controls access to nearly every protected ShuleOS operation.

A defect in role resolution, permission assignment, cache invalidation, or delegation may affect the entire platform.

This domain requires:

- Security review
- Tenant-isolation testing
- Fail-closed behaviour
- Database integrity
- Audit logging
- Real-time revocation
- Observability
- Incident response

## Tenant Impact

School roles belong to exactly one school.

Platform roles belong to the platform.

Brand-level roles require explicit brand governance and are further addressed by ADR-0011.

No role may cross tenant boundaries without an approved higher-level scope.

## Performance Impact

Authorization occurs frequently.

Performance controls may include:

- Indexed assignments
- Indexed role permissions
- Permission caching
- Versioned invalidation
- Bounded scope queries
- Precomputed effective permissions where safe
- Avoidance of repeated N+1 permission queries

Optimization must not preserve revoked access.

## Operational Impact

Platform Engineering must maintain:

- Permission catalogue
- Risk classifications
- School-assignable flags
- Delegatable flags
- Role templates
- Template versions
- Separation-of-duty rules
- Migration paths
- Audit review
- Escalation alerts
- Access-certification tooling

Schools must have clear workflows for:

- Creating roles
- Applying templates
- Assigning users
- Delegating authority
- Reviewing access
- Revoking access
- Archiving roles

## Implementation Notes

ShuleOS already intends to support custom school roles and module templates such as:

- Examination
- Finance
- Teaching
- Sports
- Dormitories
- Transport

This ADR defines the security and governance requirements for that capability.

Implementation must not treat role display names as authorization rules.

Stable permission keys and current server-side assignment state remain authoritative.

## Verification

Compliance will be verified through:

- Permission-catalogue tests
- Protected-platform-permission tests
- Custom-role tests
- Role-template tests
- Tenant-scoped uniqueness tests
- Cross-tenant assignment tests
- Delegation tests
- HOD scope tests
- Separation-of-duty tests
- High-risk approval tests
- Role-versioning tests
- Assignment-expiry tests
- Real-time revocation tests
- Permission-cache tests
- Account-state tests
- Archived-role tests
- Bulk-assignment tests
- Import tests
- Audit-log tests
- Access-review tests
- Performance tests
- Security review
- CI authorization-contract gates

## Constitution Compliance

This decision supports:

- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 7 — Human Experience
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
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 75 — Merge only after acceptance gates pass
- Rule 87 — Tenant isolation is enforced by application and database
- Rule 88 — Authentication has one source of truth
- Rule 89 — Authorization depends on authenticated identity and fails closed
- Rule 90 — Uniqueness is tenant scoped
- Rule 92 — Account-state flags ship with enforcement
- Rule 93 — Access revocation takes effect on the next request
- Rule 94 — Every module follows the approved architecture
- Rule 102 — Security-critical invariants are verified automatically
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI
- Rule 112 — Pull requests follow documented governance
- Rule 114 — ShuleOS is continuously hardened
- Rule 119 — Tenant hierarchy is explicit and governed
- Rule 120 — Cross-school access requires approved higher scope

## Related ADRs

- ADR-0000 — Architecture Decision Record Process
- ADR-0001 — Modular Monolith Architecture
- ADR-0002 — Multi-Tenant Architecture
- ADR-0003 — JWT Authentication
- ADR-0005 — School Payment Architecture
- ADR-0006 — Notification Engine
- ADR-0011 — Multi-Level Tenant Hierarchy

## Implementation Checklist

- [ ] Authoritative permission catalogue implemented
- [ ] Permission keys standardized
- [ ] Permission modules implemented
- [ ] Risk classification implemented
- [ ] School-assignable flag implemented
- [ ] Delegatable flag implemented
- [ ] Platform-protected permissions enforced
- [ ] School role templates implemented
- [ ] Module templates implemented
- [ ] Template versioning implemented
- [ ] Tenant custom roles implemented
- [ ] Tenant-scoped role uniqueness enforced
- [ ] Role copying implemented safely
- [ ] Role lifecycle states implemented
- [ ] Role version history implemented
- [ ] User role assignments implemented
- [ ] Assignment scopes implemented
- [ ] Multiple active roles supported safely
- [ ] Delegated administration implemented
- [ ] HOD delegation boundary implemented
- [ ] Separation-of-duty rules implemented
- [ ] Prohibited permission combinations implemented
- [ ] High-risk approval workflow implemented
- [ ] Time-bounded assignments implemented
- [ ] Automatic expiry implemented
- [ ] Real-time revocation implemented
- [ ] Permission-cache invalidation implemented
- [ ] Account-state enforcement verified
- [ ] Tenant-aware foreign keys implemented
- [ ] Bulk assignment secured
- [ ] Import path secured
- [ ] Archived roles blocked from authorization
- [ ] Role audit logging implemented
- [ ] Access-review reports implemented
- [ ] Escalation alerts implemented
- [ ] Performance tests implemented
- [ ] CI authorization-contract gates implemented

## Supersession Status

This ADR has not been superseded.

## Final Decision

ShuleOS will provide platform-controlled permission definitions, approved school role templates, and tenant-owned custom roles.

Schools may create and assign roles only from the school-assignable permission catalogue.

Platform permissions remain protected and cannot be granted by schools.

Delegated administrators, including Heads of Department, may assign only explicitly delegatable permissions within their approved tenant and operational scope.

All authorization must remain tenant-aware, policy-controlled, least-privileged, auditable, versioned, revocable in real time, and fail closed.
