# Boarding Bed Allocation

## Status

Implemented as part of Phase 6A.9D — Learner Bed Allocation.

This document defines the ShuleOS Boarding Bed Allocation contract.

It complements the approved Module Architecture, where the Boarding module owns:

- Hostels
- Rooms
- Beds
- Boarding allocation

This document does not redefine the wider Boarding module architecture.

---

## Purpose

Bed Allocation assigns an eligible learner to an available boarding bed while preserving:

- school tenant isolation
- boarding eligibility
- boys/girls hostel separation
- active occupancy uniqueness
- allocation authority
- auditability
- allocation history
- concurrency safety

A bed allocation is an operational school record and must never be created from client-controlled tenant or authority fields.

---

## Domain Relationship

The authoritative Boarding structure is:

School
→ Hostel
→ Hostel Room
→ Hostel Bed
→ Bed Allocation
→ Learner

Every allocation belongs explicitly to one school tenant.

The selected learner and selected bed must belong to the same authenticated school tenant.

---

## Tenant Boundary

Bed Allocation follows the ShuleOS Tenant First architecture.

The authenticated school context is authoritative.

The client must not choose the allocation tenant.

For every allocation:

- `school_id` is server controlled
- learner lookup is tenant scoped
- bed lookup is tenant scoped
- room lookup is tenant scoped
- hostel lookup is tenant scoped
- allocating user lookup is tenant scoped
- cross-tenant learner identifiers fail closed
- cross-tenant bed identifiers fail closed
- hierarchy relationships must remain inside one school

Application tenant checks are reinforced by PostgreSQL tenant-aware foreign keys.

The Bed Allocation tenant-safe relationships include:

- `bed_allocations_school_bed_foreign`
- `bed_allocations_school_learner_foreign`

---

## Learner Eligibility

A learner may receive a boarding bed only when all of the following are true:

1. The learner belongs to the authenticated school.
2. The learner is not archived or deleted.
3. The learner is active.
4. The learner lifecycle status is `active`.
5. The learner mode of study is `boarder`.
6. The learner has a supported canonical gender for Boarding allocation.

Supported allocation gender values are:

- `Male`
- `Female`

Unsupported or missing gender fails closed.

Bed Allocation does not redefine the global learner gender contract.

---

## Boys and Girls Separation

ShuleOS Boarding hostels are separated by gender.

The only supported hostel types for Bed Allocation are:

- `BOYS`
- `GIRLS`

Allocation mapping is:

- learner gender `Male` → hostel type `BOYS`
- learner gender `Female` → hostel type `GIRLS`

A learner cannot be allocated into a hostel that does not match this rule.

Mixed hostels are not permitted by the Boarding structural contract.

---

## Bed Eligibility

A target bed is eligible only when:

1. The bed belongs to the authenticated school.
2. The bed is active.
3. The bed is not archived.
4. Its room belongs to the same school.
5. Its room is active.
6. Its room is not archived.
7. Its hostel belongs to the same school.
8. Its hostel is active.
9. Its hostel is not archived.
10. The bed/room/hostel hierarchy is internally consistent.

A cross-tenant or invalid hierarchy must fail closed.

---

## Active Occupancy Invariants

ShuleOS enforces two active occupancy invariants:

1. One learner may have at most one active bed allocation.
2. One bed may have at most one active learner allocation.

PostgreSQL is the final concurrency backstop through:

- `bed_allocations_active_learner_unique`
- `bed_allocations_active_bed_unique`

These constraints apply to active allocation rows.

Application checks improve the user-facing error contract but do not replace database enforcement.

---

## Allocation History

Bed allocation history is append-only from the allocation perspective.

A new allocation creates a new row.

Previously released allocations must not be overwritten to represent a new stay.

The active allocation row contains:

- learner
- bed
- allocation date
- active state
- allocating user
- timestamps

Release and transfer behavior belong to the subsequent Boarding lifecycle phase and are outside Phase 6A.9D.

---

## Server-Owned Fields

The following allocation fields are server controlled:

- `id`
- `school_id`
- `allocated_by`
- `allocation_date`
- `release_date`
- `active`
- `created_at`
- `updated_at`

The client may provide only the identifiers required to request the allocation:

- `learner_id`
- `bed_id`

Client attempts to control allocation authority or lifecycle fields are rejected.

---

## Allocation Date

The allocation date is not supplied by the client.

ShuleOS derives it from the current date in the school's configured timezone.

If a school timezone is unavailable, the application timezone is used as the fallback.

This prevents client-side backdating of the initial allocation.

---

## Transaction and Concurrency Contract

Bed allocation executes inside a database transaction.

Mutable domain resources are locked before the allocation is persisted.

The service validates and locks the relevant:

- allocating user
- learner
- bed
- room
- hostel

PostgreSQL active-allocation uniqueness constraints remain the final race-condition backstop.

Known active-allocation unique constraint violations are translated into safe validation responses.

Unexpected database failures are not silently converted into misleading business errors.

---

## Authorization

The Bed Allocation mutation endpoint is:

`POST /api/boarding/bed-allocations`

Required security controls include:

- authenticated request
- resolved school tenant
- `manage_boarding` permission
- operational school gate

A school that is not operational cannot create a new bed allocation.

Backend authorization is authoritative.

Frontend controls must never be treated as a security boundary.

---

## Request Contract

The allocation request requires:

- `learner_id` — UUID
- `bed_id` — UUID

Tenant existence validation is deliberately handled inside tenant-scoped domain resolution rather than generic unscoped existence rules.

This prevents cross-tenant identifier disclosure.

---

## Response Contract

A successful allocation returns HTTP `201 Created`.

The response may expose operational allocation information including:

- allocation ID
- learner ID
- bed ID
- allocation date
- release date
- active state
- allocating user
- created timestamp
- updated timestamp

The response does not expose `school_id` as client authority.

---

## Audit Contract

Successful bed allocation is an auditable Boarding mutation.

The audit record captures the allocation operation and its relevant operational values.

Audit logging does not replace database transaction or tenant controls.

---

## Database Safety

Bed Allocation depends on the hardened Boarding database foundation.

Relevant database protections include:

- explicit `school_id`
- school ownership foreign key
- tenant-aware bed relationship
- tenant-aware learner relationship
- active learner uniqueness
- active bed uniqueness

Application-level tenant checks and PostgreSQL constraints operate as defense in depth.

---

## Failure Behaviour

Expected business failures return controlled validation or not-found responses.

Examples include:

- inactive learner
- terminal lifecycle learner
- day scholar
- unsupported learner gender
- inactive bed
- inactive room
- inactive hostel
- occupied bed
- learner already holding an active bed
- gender-incompatible hostel

Foreign tenant learner and bed identifiers fail closed.

Raw database exception details must not be intentionally exposed as business responses.

---

## Phase 6A.9D Scope

Phase 6A.9D includes:

- Bed Allocation model
- Bed Allocation service
- allocation request validation
- allocation HTTP endpoint
- tenant enforcement
- learner eligibility
- boys/girls hostel compatibility
- server-owned allocation state
- concurrency protection
- audit logging
- adversarial HTTP tests
- domain/database tests

Phase 6A.9D does not include:

- bed release workflow
- bed transfer workflow
- allocation history API
- staff responsibility
- hostel attendance
- hostel incidents
- boarding notifications

Those capabilities require their own lifecycle, authorization, tenancy, audit, and concurrency contracts.

---

## Verification

Phase 6A.9D acceptance requires evidence for:

- authenticated and authorized allocation
- school operational gate
- cross-tenant learner denial
- cross-tenant bed denial
- inactive learner denial
- terminal lifecycle denial
- day-scholar denial
- boys/girls separation
- one active bed per learner
- one active learner per bed
- school-local allocation date
- server-owned actor
- audit creation
- PostgreSQL tenant-safe foreign keys
- PostgreSQL active-allocation unique backstops
- complete backend regression
- dependency security audit
- formatting verification

The implementation is not accepted merely because the happy path succeeds.

Tenant isolation, authorization, database invariants, concurrency safety, auditability, and regression safety are part of the feature contract.