# Boarding operational integrity

Status: local verification and final diff review complete; not deployed.
Baseline: e3a2cac15bb90528539f38fb6684297f062b1753.
Verification date: 2 November 2026.

## Scope

This patch strengthens existing Boarding facilities, bed-allocation lifecycle
and staff-responsibility HTTP mutations.

It introduces no schema changes, new permissions or daily-reporting workflows.
Existing tenant boundaries, school-mode restrictions and operational gates remain.

## Occupied hostel classification

A hostel with any active bed allocation cannot change between BOYS and GIRLS.

The occupancy check includes allocations held by inactive or terminal learners
and does not exclude an allocation because its bed was later retired.

The school row is locked before the hostel row, serializing this check with
allocation and transfer operations that also acquire the school lock.

Release or transfer active occupants before changing hostel type.
Ordinary permitted edits, such as renaming the hostel, remain available.
Released allocation rows and their history remain preserved.

This is an application-service invariant. Direct SQL hostel-type changes are
not protected by a new database trigger.

## Atomic HTTP mutations and audit records

All 14 existing Boarding HTTP mutation methods execute their service operation
and audit write inside one outer database transaction:

- hostel creation, update and retirement;
- room creation, update and retirement;
- bed creation, update and retirement;
- bed allocation, release and transfer;
- staff-responsibility assignment and ending.

Existing service transactions participate as nested transactions.

The Boarding controller uses strict audit persistence. Audit failures propagate
instead of being suppressed by the shared best-effort audit logger.
Audit ownership derives from the authenticated request, with tenant agreement
checked against the mutated model.

A failed audit therefore rolls back the HTTP mutation and any associated
allocation or responsibility lifecycle changes.

This guarantee applies to these HTTP boundaries. Direct service callers remain
responsible for their own business audit requirements. The shared logger used
by other modules is unchanged.

## Verification

Database: shuleos_assessment_fk_test only.

- Boarding suites: 134 tests passed, 1,124 assertions.
- Full regression: 1,712 tests passed, 28,427 assertions.
- Pint: 1,259 files passed.
- Assessment coverage and Assessment PHPStan passed.
- Composer strict validation and dependency audit passed.
- git diff --check passed.

Assessment PHPStan does not establish static-analysis coverage for Boarding.

Added failure tests cover allocation, release, transfer, structure mutations,
responsibility assignment and ending. Snapshots compare facilities, allocations,
allocation history, responsibility episodes and audit rows before and after
simulated audit failure.

Both Boys-to-Girls and Girls-to-Boys occupied-hostel changes are rejected.
Tests also prove that renaming remains possible and reclassification succeeds
after release.

Real concurrent transaction contention has not been demonstrated.
The tests do not certify every possible direct SQL or service-only mutation.

## Next operational work

The existing staff-responsibility foundation remains authoritative.
A responsibility assignment does not grant permission.

Daily Boarding operations require a separate implementation contract covering:

- facility/date reporting obligations without duplicate reports per manager;
- structured occurrences and follow-up actions;
- boarding attendance/status and authorized learner movements;
- submission and leadership review;
- preserved reporting evidence and corrections;
- school-local deadlines and late/overdue state;
- reminders through the approved notification architecture;
- permission and responsibility scope.

Daily operations must not be represented by adding operational fields to
hostel_staff_assignments or by reusing Teacher-on-Duty assignment tables.

No production migration or deployment is part of this patch.
