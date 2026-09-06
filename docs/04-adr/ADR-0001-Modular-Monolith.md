# ADR-0001 — Modular Monolith Architecture

> School in Clouds

## Document Information

| Field                | Value                         |
| -------------------- | ----------------------------- |
| ADR                  | ADR-0001                      |
| Decision             | Modular Monolith Architecture |
| Status               | Accepted                      |
| Version              | 1.0                           |
| Owner                | Platform Engineering          |
| Repository           | `shuleos-api`                 |
| Effective Date       | 02 August 2026                |
| Related Constitution | Engineering Constitution v1.1 |
| Supersedes           | None                          |
| Superseded By        | None                          |

## Context

ShuleOS is a multi-tenant school cloud platform with many business domains, including:

- Authentication and identity
- School administration
- Learners and guardians
- Teachers
- Teaching and learning
- Assessments and examinations
- Finance
- Communication
- Attendance
- Behaviour and discipline
- Boarding
- Transport
- Leadership and elections
- Parent, teacher, learner, leadership, and administrator portals
- Offline synchronization
- File security
- Subscription management

The platform must support strong security, strict tenant isolation, reliable transactions, maintainability, high performance, and future scalability.

The architecture must also remain practical for the current development team. Introducing distributed services too early would increase deployment, networking, observability, testing, data consistency, and operational complexity before those costs are justified.

A single unstructured application would also be unacceptable because it would encourage duplicated logic, unclear ownership, tight coupling, and long-term architectural decay.

## Decision

ShuleOS will use a **modular monolith** as its primary backend architecture.

The system will remain one deployable Laravel application while being divided internally into clear business domains with explicit boundaries.

Each domain must own its relevant:

- Controllers
- Form Requests
- Services
- Models
- Policies
- Resources
- Jobs
- Events
- Listeners
- Tests
- Documentation

Shared infrastructure may be used where appropriate, but shared code must not become a location for unrelated business logic.

## Architectural Model

```text
Clients
   |
   v
Laravel API
   |
   +-- Authentication and Identity
   |
   +-- Tenant and Subscription Context
   |
   +-- Academic Domain
   |
   +-- Learner Domain
   |
   +-- Teaching Domain
   |
   +-- Assessment Domain
   |
   +-- Finance Domain
   |
   +-- Communication Domain
   |
   +-- Operations Domains
   |
   +-- Portal Domains
   |
   +-- Shared Infrastructure
   |
   v
PostgreSQL
```

The application is deployed as one unit, but its internal structure must preserve domain separation.

## Rationale

The modular monolith provides the best balance between current needs and long-term growth.

### Transactional Integrity

Many ShuleOS operations span related records and require atomic database transactions.

Examples include:

- Learner admission
- Payment allocation
- Examination result processing
- School registration
- Subscription activation
- Teacher assignment
- Report generation

A modular monolith allows these operations to use local database transactions without introducing distributed transaction complexity.

### Operational Simplicity

A single deployable backend is easier to:

- Build
- Test
- Deploy
- Monitor
- Debug
- Back up
- Restore
- Secure

This is especially important while the platform is still being hardened and its domain boundaries are continuing to mature.

### Lower Latency

Internal domain calls occur within the same process rather than over a network.

This avoids unnecessary:

- Network latency
- Serialization overhead
- Service discovery
- Retry logic
- Partial distributed failures

### Easier Refactoring

Domain boundaries can be improved without coordinating multiple repositories, deployment pipelines, network contracts, and versioned service APIs.

### Future Extraction Path

A well-structured modular monolith can later extract a domain into a service when evidence proves that extraction is necessary.

The architecture therefore preserves future flexibility without paying the cost of microservices prematurely.

## Domain Boundary Rules

Every domain must have a clear business purpose.

A domain must not access another domain's internal implementation casually.

Cross-domain interaction should occur through approved mechanisms such as:

- Application services
- Explicit interfaces
- Domain events
- Jobs
- Read-only query services
- Documented shared contracts

Controllers must not directly coordinate complex workflows across multiple models and domains.

Business rules must not be duplicated across domains.

## Shared Infrastructure

Shared infrastructure may include:

- Authentication
- Tenant context
- Authorization
- Audit logging
- Notifications
- File security
- Queue infrastructure
- Observability
- Common value objects
- Approved utilities

Shared infrastructure must remain generic.

Domain-specific decisions must remain inside the owning domain.

## Database Strategy

The modular monolith will initially use a shared PostgreSQL database.

This does not mean every domain may freely access every table.

The following rules apply:

- Migrations remain the authoritative schema.
- Tenant-owned data must be tenant-scoped.
- Foreign keys must preserve tenant ownership.
- Cross-domain writes must use approved services or workflows.
- Sensitive domains may require additional database policies.
- Transactions must preserve business invariants.
- Database-level tenant protection will complement application controls.

## Deployment Strategy

The backend is deployed as one Laravel application.

Scalability is achieved through:

- Multiple stateless application instances
- Load balancing
- Queue workers
- Redis-backed coordination where adopted
- PostgreSQL optimization
- Read replicas where justified
- Cloud object storage
- Caching
- Background processing
- Rate limiting
- Per-tenant workload controls

The modular monolith does not require a single server.

It is a logical application architecture, not a restriction on infrastructure scaling.

## Alternatives Considered

### Traditional Unstructured Monolith

Rejected.

Although simple initially, an unstructured monolith would encourage:

- Large controllers
- Mixed responsibilities
- Direct model coupling
- Duplicate rules
- Weak domain ownership
- Difficult testing
- Expensive future refactoring

### Microservices from the Beginning

Rejected for the current stage.

Starting with microservices would introduce:

- Distributed transactions
- Network failure handling
- Service authentication
- Contract versioning
- More deployment pipelines
- More monitoring infrastructure
- Greater operational cost
- Harder local development
- Increased debugging complexity

These costs are not currently justified by measured scale or independent deployment requirements.

### Separate Application per Module

Rejected.

This would fragment identity, tenant context, audit logging, authorization, and user experience across multiple systems.

### Serverless Functions as the Primary Architecture

Rejected as the core model.

Serverless functions may be useful for specific isolated workloads, but they are not the primary architecture for ShuleOS because many platform workflows require strong domain coordination and transactional consistency.

## Consequences

### Positive

- Simpler deployment and operations
- Strong local transactions
- Lower internal latency
- Easier debugging
- Consistent authentication and tenant context
- Shared security controls
- Clearer development workflow
- Lower infrastructure cost
- Easier testing
- Controlled future service extraction

### Negative

- Poor discipline could allow domain boundaries to erode.
- A shared database can encourage unauthorized cross-domain access.
- Large deployments may affect all modules at once.
- A defect in shared infrastructure may affect the whole platform.
- Independent module scaling is less direct than with separate services.

These risks are accepted only with strong architectural enforcement.

## Risks and Mitigations

### Risk: Domain Coupling

Mitigation:

- Use clear domain ownership.
- Keep controllers thin.
- Put business logic in services.
- Review cross-domain dependencies.
- Record major changes through ADRs.
- Add architecture compliance tests.

### Risk: Shared Database Misuse

Mitigation:

- Enforce tenant-safe relationships.
- Review migrations and queries.
- Use database constraints.
- Restrict direct cross-domain writes.
- Add database-level tenant isolation.
- Automate Rule 99 validation.

### Risk: Application Growth

Mitigation:

- Maintain domain boundaries.
- Measure performance.
- Use queues and background jobs.
- Scale application instances horizontally.
- Extract services only when evidence supports extraction.

### Risk: Whole-Application Deployment

Mitigation:

- Use automated tests.
- Use controlled releases.
- Use rollback plans.
- Use feature flags where appropriate.
- Maintain reliable backup and recovery procedures.

## Service Extraction Criteria

A domain may be considered for extraction into an independent service only when evidence demonstrates one or more of the following:

- It requires independent scaling.
- It requires an independent deployment cycle.
- It has a clearly stable business boundary.
- It needs isolation for security or regulatory reasons.
- It causes measurable resource contention.
- It requires a substantially different technology stack.
- It can operate through a stable versioned contract.
- Its extraction reduces overall complexity rather than merely relocating it.

A service must not be extracted because microservices appear more modern.

Any extraction requires a new ADR.

## Security Impact

This decision supports centralized enforcement of:

- Authentication
- Tenant context
- Authorization
- Account state
- Subscription state
- Validation
- Audit logging
- Secret handling
- Secure error responses

The architecture does not permit shared deployment to become shared authorization.

Every domain remains responsible for protecting its resources.

## Tenant Impact

Tenant identity is resolved centrally and applied consistently across domains.

Every tenant-owned domain must enforce:

- Tenant-scoped queries
- Tenant-aware validation
- Tenant-safe relationships
- Cross-tenant access denial
- Database-level protection where applicable
- Tenant isolation tests

The modular monolith does not weaken tenant isolation.

## Performance Impact

The architecture reduces internal network overhead and supports efficient transactions.

Performance must be maintained through:

- Indexing
- Query review
- Pagination
- Caching
- Queue processing
- Bounded batch operations
- Horizontal application scaling
- Noisy-neighbour protection
- Performance testing

## Operational Impact

Platform Engineering maintains one backend deployment unit.

Operational controls must include:

- Automated build and test pipelines
- Health checks
- Structured logs
- Metrics
- Error tracking
- Queue monitoring
- Database monitoring
- Backup and restore testing
- Rollback capability

## Implementation Notes

The current implementation remains a Laravel application.

Future restructuring may introduce explicit domain directories or namespaces, but this ADR does not require a disruptive rewrite.

Improvements should be incremental and test-protected.

No domain may be declared complete solely because its endpoints work. It must also satisfy the Engineering Constitution.

## Verification

Compliance with this ADR will be verified through:

- Architecture review
- Pull request review
- Static analysis
- Dependency analysis
- Domain-boundary tests
- Service-layer checks
- Controller-complexity review
- Database review
- Tenant isolation tests
- CI architecture gates

## Constitution Compliance

This decision supports:

- Rule 6 — Consistency over cleverness
- Rule 8 — Clean Code
- Rule 9 — Every feature belongs to a Domain
- Rule 10 — Design first. Code second
- Rule 21 — Database first
- Rule 24 — Every query is reviewed
- Rule 25 — Use transactions
- Rule 33 — Thin controllers
- Rule 34 — Business logic belongs in services
- Rule 36 — No duplicated business rules
- Rule 80 — Every feature fits the long-term architecture
- Rule 81 — No isolated features
- Rule 84 — Every Architecture Decision is recorded as an ADR
- Rule 94 — Every module follows the approved architecture
- Rule 95 — Remove duplicate abstractions
- Rule 102 — Security-critical invariants are verified automatically
- Rule 110 — Architecture rules are enforced by CI whenever possible
- Rule 114 — ShuleOS is continuously hardened throughout its lifetime

## Related ADRs

- ADR-0000 — Architecture Decision Record Process
- ADR-0002 — Multi-Tenant Architecture
- ADR-0003 — JWT Authentication
- ADR-0004 — Offline-First Architecture
- ADR-0006 — Notification Engine
- ADR-0011 — Multi-Level Tenant Hierarchy

## Supersession Status

This ADR has not been superseded.

## Final Decision

ShuleOS will use a modular monolith as its primary backend architecture.

The application will remain one deployable Laravel system while preserving strict internal domain boundaries, shared security controls, tenant safety, transactional integrity, and a documented path for evidence-based service extraction.
