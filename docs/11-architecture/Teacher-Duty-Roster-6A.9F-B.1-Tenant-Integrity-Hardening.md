# Teacher Duty Roster 6A.9F-B.1 — Tenant Integrity Hardening Contract

## Status

Frozen addendum for post-merge database defense-in-depth hardening of Teacher Duty Roster Phase 6A.9F-B.

This addendum does not replace, rewrite, or invalidate the frozen 6A.9F-B Database, Application, or HTTP contracts.

It narrows one specific follow-up concern: database-enforced tenant integrity for Teacher Duty relationships that were previously protected by application/service validation but referenced parent rows through single-column foreign keys.

---

## 1. Scope

Phase 6A.9F-B.1 is limited to strengthening database-level tenant integrity for these three relationships:

1. `teacher_duty_periods.academic_week_id`
2. `teacher_duty_assignments.duty_period_id`
3. `teacher_duty_assignments.teacher_id`

No other Teacher Duty behavior is changed.

This phase must not introduce:

- daily occurrences
- occurrence categories
- daily reports
- weekly reporting workflow
- new permissions
- new endpoints
- entitlement middleware
- new authorization semantics
- lifecycle behavior changes
- roster scheduling behavior changes
- commercial-plan enforcement

Those remain deferred to later roadmap phases.

---

## 2. Historical Contract Preservation

The already-merged 6A.9F-B migration remains immutable.

The historical frozen 6A.9F-B contracts remain valid records of the design that was implemented and merged.

6A.9F-B.1 must use a new migration and a new addendum document.

The original migration must not be edited, reordered, squashed, or rewritten.

---

## 3. Current Verified Database State

Inspection on the merged 6A.9F-B baseline established:

### `teachers`

Existing candidate keys:

- `PRIMARY KEY (id)`
- `UNIQUE (tsc_no)`
- `UNIQUE (user_id)`

There is currently no unique candidate key on:

`(school_id, id)`

### `academic_weeks`

Existing candidate keys:

- `PRIMARY KEY (id)`
- `UNIQUE (term_id, week_number)`

There is currently no unique candidate key on:

`(school_id, id)`

### `teacher_duty_periods`

Existing candidate keys:

- `PRIMARY KEY (id)`

There is currently no unique candidate key on:

`(school_id, id)`

### Existing tenant relationship integrity

Database inspection confirmed zero current cross-school mismatches for:

- Teacher Duty Period -> Academic Week
- Teacher Duty Assignment -> Duty Period
- Teacher Duty Assignment -> Teacher

The relevant school and assignment identity columns required for the new composite relationships are non-nullable.

Therefore no tenant-data repair is required before installing the hardening constraints.

---

## 4. Candidate Keys

The hardening migration must make the following parent-column pairs valid PostgreSQL foreign-key targets:

`teachers (school_id, id)`

`academic_weeks (school_id, id)`

`teacher_duty_periods (school_id, id)`

This must be achieved through database-level unique candidate keys.

The existing primary key on `id` remains unchanged.

No identifier format, UUID behavior, primary key, or model identity semantics may change.

---

## 5. Composite Tenant Foreign Keys

The existing single-column Teacher Duty foreign keys covered by this addendum must be replaced by tenant-composite foreign keys.

### Teacher Duty Period -> Academic Week

Required relationship:

`teacher_duty_periods (school_id, academic_week_id)`

references:

`academic_weeks (school_id, id)`

### Teacher Duty Assignment -> Duty Period

Required relationship:

`teacher_duty_assignments (school_id, duty_period_id)`

references:

`teacher_duty_periods (school_id, id)`

### Teacher Duty Assignment -> Teacher

Required relationship:

`teacher_duty_assignments (school_id, teacher_id)`

references:

`teachers (school_id, id)`

A row must therefore be rejected by PostgreSQL if the referenced resource exists but belongs to a different school.

---

## 6. Delete Semantics

The existing delete behavior of all three relationships is frozen.

The replacement composite foreign keys must preserve:

`ON DELETE RESTRICT`

No cascade behavior may be introduced.

No soft-delete behavior is changed.

No Teacher Duty lifecycle behavior is changed.

---

## 7. Existing Application Tenant Validation

The existing service-layer same-school validation remains required.

Database hardening is additive defense in depth.

The service must continue to:

- resolve resources under the authenticated school context
- fail closed on tenant mismatch
- reject cross-school Teacher assignments
- reject cross-school Academic Week references
- reject cross-school Duty Period assignment targets

The application must not rely exclusively on database constraint violations for normal tenant validation.

---

## 8. Migration Requirements

6A.9F-B.1 must use a new migration.

The migration must:

1. verify that existing data is compatible with the tenant-composite relationships before replacing constraints
2. add the required `(school_id, id)` candidate keys
3. replace the three covered single-column foreign keys
4. install the three tenant-composite foreign keys
5. preserve `ON DELETE RESTRICT`
6. be reversible
7. avoid modifying unrelated constraints, indexes, columns, or tables

The migration must fail closed if incompatible existing tenant relationships are detected.

It must not silently repair, reassign, or delete inconsistent data.

---

## 9. Direct Database Regression Requirements

Permanent regression coverage must prove that PostgreSQL itself rejects all three cross-school relationships.

Tests must demonstrate rejection of:

1. a Teacher Duty Period whose `school_id` does not match its referenced Academic Week
2. a Teacher Duty Assignment whose `school_id` does not match its referenced Duty Period
3. a Teacher Duty Assignment whose `school_id` does not match its referenced Teacher

These tests must exercise database constraints directly rather than relying only on `TeacherDutyRosterService`.

The tests must also confirm valid same-school relationships continue to succeed.

---

## 10. Regression Preservation

The following existing behavior must remain unchanged and green:

- Teacher Duty Roster service suite
- Teacher Duty Roster HTTP management suite
- existing lifecycle semantics
- duplicate current-assignment protection
- audit behavior
- permission behavior
- operational middleware behavior
- HTTP response contracts
- tenant fail-closed application behavior

No existing route, request, controller, service method, model API, permission name, or JSON response contract may change solely for this hardening.

---

## 11. Scope Boundary

Expected implementation scope after this contract is frozen is narrowly limited to:

- one new migration
- focused permanent database-level tenant-integrity regression coverage

Changes outside that scope require a verified failure and explicit re-freeze before implementation.

In particular, Phase 6A.9G-A work must not enter this branch.

---

## 12. Completion Gate

6A.9F-B.1 is complete only when:

- migration applies successfully
- migration rollback succeeds
- all three composite foreign keys exist
- all three `(school_id, id)` candidate keys exist
- direct cross-tenant DB writes are rejected
- valid same-tenant DB writes succeed
- Teacher Duty service tests pass
- Teacher Duty HTTP tests pass
- related regression passes
- full regression passes
- `git diff --check` passes
- implementation scope matches the frozen addendum
- worktree is clean after commit

Until those gates are satisfied, 6A.9G-A must not begin.
