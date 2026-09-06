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

## 6A.9E — Bed Allocation Lifecycle

Phase 6A.9E extends learner bed allocation with release, transfer, and immutable occupancy history. One `bed_allocations` row represents one occupancy episode. An episode is never repurposed or reactivated after it becomes terminal.

### Lifecycle states

The authoritative allocation statuses are:

- `active`
- `released`
- `transferred`

The database enforces lifecycle consistency:

- `status = active` requires `active = true` and `release_date = NULL`.
- `status IN (released, transferred)` requires `active = false` and a non-null `release_date`.
- A terminal allocation is never changed back to active.
- Any later occupancy is represented by a new `bed_allocations` row.

Legacy migration rules are conservative. Existing active rows become `active`. Existing inactive rows with a release date become `released`. Historical transfer meaning is not inferred where the legacy data cannot prove it, and ambiguous legacy state causes migration failure rather than invented history.

### Release

Endpoint:

`PATCH /api/boarding/bed-allocations/{allocation}/release`

Authorization:

- authenticated school user
- `manage_boarding`
- no `school.operational` middleware requirement

Release is occupancy cleanup rather than a new boarding admission. The source allocation must currently be active, but release remains possible when the learner has subsequently withdrawn, transferred, graduated, or otherwise ceased to satisfy current boarding admission eligibility. Existing occupancy can also be released after its bed, room, or hostel has been retired.

A successful release:

- changes the source status from `active` to `released`
- sets `active = false`
- sets the server-controlled school-local `release_date`
- preserves the allocation episode permanently
- creates one immutable lifecycle history event
- records the authenticated actor
- accepts an optional reason of at most 500 characters

Clients cannot control lifecycle status, active flags, allocation/release dates, history identifiers, event types, transition states, actor fields, or history timestamps.

### Transfer

Endpoint:

`POST /api/boarding/bed-allocations/{allocation}/transfer`

Authorization:

- authenticated school user
- `manage_boarding`
- `school.operational`

Client-controlled transfer input is limited to the destination bed identifier plus an optional reason of at most 500 characters. Tenant context remains authoritative; client input cannot redirect an allocation to another school.

Transfer is one atomic transaction:

1. validate and lock the tenant-owned source allocation
2. validate that the learner is still eligible for boarding transfer
3. lock source and destination boarding resources in deterministic order
4. validate destination bed, room, hostel, hierarchy, activity and gender compatibility
5. close the source episode as `transferred`
6. create a new `active` destination occupancy episode
7. write one correlated immutable lifecycle-history event

The source is closed before the new destination episode is inserted so the active-learner uniqueness invariant remains valid. If any later transfer step fails, the transaction rolls back completely and the source remains active.

Transfer does not mutate the source episode into the destination episode. The destination is always a new `bed_allocations` row.

### Immutable lifecycle history

Lifecycle history is stored in:

`bed_allocation_history`

Each release or transfer creates one logical event with an independent UUID `event_id`.

A release event records:

- source allocation
- destination allocation as `NULL`
- `from_status = active`
- `to_status = released`

A transfer event records:

- source allocation
- destination allocation
- `from_status = active`
- `to_status = transferred`

One transfer has one history event that correlates both allocation episodes. Reading history for either the source or destination episode resolves that same logical transfer event rather than creating duplicate events.

History records include tenant, learner, event correlation, source/destination allocation, transition state, school-local effective date, optional reason, actor and immutable timestamps.

Lifecycle history is append-only. Application model guards reject mutation, and PostgreSQL independently rejects direct `UPDATE` and `DELETE` operations through the `bed_allocation_history_immutable_trigger`.

Tenant-aware foreign keys bind learner, actor, source allocation and destination allocation to the same school. Cross-tenant lifecycle resources fail closed.

Endpoint:

`GET /api/boarding/bed-allocations/{allocation}/history`

Authorization:

- authenticated school user
- `manage_boarding`
- no `school.operational` middleware requirement

The public history response excludes `school_id` and exposes only the approved lifecycle-history fields.

### Concurrency and database backstops

Release and transfer execute inside database transactions.

Transfer locks source/destination beds and their parent rooms and hostels in deterministic identifier order. Fresh allocation and transfer therefore contend on the destination boarding resources rather than relying only on application pre-checks.

Database partial unique indexes remain the final concurrency backstop:

- one active allocation per bed
- one active bed per learner

A transfer failure cannot leave the source closed without the destination, and lifecycle history is written in the same transaction as the occupancy transition.

### Tenant and server authority

All allocation lifecycle operations are tenant scoped. The authenticated school context is authoritative.

A supplied school context must match the authenticated school and cannot redirect ownership. Server-owned lifecycle/history fields cannot be injected by clients.

Release, transfer and history use the existing `manage_boarding` authorization model. Only transfer requires an operational school because transfer creates a new occupancy episode. Release and history intentionally remain available when a school is non-operational so existing occupancy can still be cleaned up and historical evidence can still be read.

### Audit

Release and transfer produce normal Boarding audit-log actions in addition to immutable occupancy lifecycle history.

Audit logs and `bed_allocation_history` serve different purposes:

- audit logs record application/API actions
- lifecycle history is the permanent authoritative occupancy-transition record

Initial bed allocation does not require a duplicate lifecycle-history event because the allocation row itself is the initial occupancy episode.
