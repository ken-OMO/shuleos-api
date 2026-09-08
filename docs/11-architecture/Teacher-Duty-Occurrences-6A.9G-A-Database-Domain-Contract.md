# Teacher Duty 6A.9G-A - Database and Domain Contract

## Status

FROZEN contract for Phase 6A.9G-A - Daily Occurrences and Configurable Occurrence Categories.

This extends 6A.9F-B and 6A.9F-B.1 without changing their frozen contracts.

## Scope

- Daily Teacher Duty occurrences.
- School-configurable occurrence categories.
- Canonical seeded occurrence categories.
- Tenant-safe persistence and lifecycle invariants.
- Database/domain regression coverage.

Deferred:
- 6A.9G-B: daily report workflow, draft, submitted and overdue state.
- 6A.9G-C: weekly aggregation, submission and review.
- 6A.9H: scoped responsibility authorization.
- Notifications, escalation and commercial entitlement enforcement.

No new permission is created in 6A.9G-A.

## Existing Foundation

`teacher_duty_periods` and `teacher_duty_assignments` remain unchanged.
A duty period is school-wide and may contain multiple Teacher assignments.
Assignment identifies participation; it does not grant authorization.

## Occurrence Ownership

Occurrences belong to duty periods, not individual Teacher assignments.
Occurrences must not contain teacher_id or teacher_duty_assignment_id.
Multiple assigned Teachers must not duplicate one school-wide occurrence.

## Occurrence Categories

Introduce `teacher_duty_occurrence_categories` with:
- id UUID primary key
- school_id
- code
- name
- description nullable
- is_canonical
- display_order
- active
- created_by
- deactivated_by nullable
- deactivated_at nullable timestamptz
- timestamps

Categories are school-owned, never globally shared.
`(school_id, code)` is unique.

## Canonical Categories

- discipline - Discipline
- attendance - Attendance
- health_safety - Health & Safety
- cleanliness - Cleanliness
- property_facilities - Property & Facilities
- academic - Academic
- visitor_security - Visitor & Security
- general - General

Canonical categories are provisioned per school.
Provisioning must be deterministic, concurrency-safe and idempotent.
Both existing and future schools must receive the canonical set.
Canonical codes are stable machine identities.
Schools may add custom categories.

## Category Lifecycle

Active category: active=true, deactivated_by=null, deactivated_at=null.
Deactivated category: active=false, deactivated_by and deactivated_at are non-null.
Deactivation is terminal in 6A.9G-A; reactivation is not introduced.
Deactivated categories cannot receive new occurrences.
Historical occurrences remain linked to deactivated categories.

## Occurrences

Introduce `teacher_duty_occurrences` with:
- id UUID primary key
- school_id
- duty_period_id
- occurrence_category_id
- occurrence_date
- occurrence_time nullable
- description
- recorded_by
- timestamps

Occurrences are preserved factual records.
Generic occurrence deletion is prohibited.
No occurrence soft-delete lifecycle is introduced.

## Date Semantics

occurrence_date is a strict school-local YYYY-MM-DD date.
Occurrence date must fall inside the duty period date range, inclusive.
New occurrences require a current eligible same-school duty period.
Period closure must preserve existing occurrences.
occurrence_time is optional school-local wall-clock time.

## Narrative

description is required and whitespace-only content is invalid.
The exact maximum length is frozen by the later application contract.

## Tenant Integrity

All tenant mismatches fail closed.
Required composite relationships are:
- occurrence (school_id,duty_period_id) -> teacher_duty_periods (school_id,id)
- occurrence (school_id,occurrence_category_id) -> category (school_id,id)
- occurrence (school_id,recorded_by) -> users (school_id,id)
- category (school_id,created_by) -> users (school_id,id)
- category (school_id,deactivated_by) -> users (school_id,id)

All delete relationships use ON DELETE RESTRICT.
No cascade deletion is permitted.
Application tenant validation remains mandatory.

## Server Ownership

school_id and actor fields are server-owned.
recorded_by, created_by and deactivated_by must resolve from same-school server context.
Clients cannot control authoritative tenant or actor fields.

## Historical Preservation

Occurrence history survives period closure, assignment closure and category deactivation.
No automatic deletion, recategorization or rewriting is allowed.
A future correction/amendment workflow requires a separate frozen contract.

## Duplicate Rules

Multiple legitimate occurrences of the same category on the same date are allowed.
No uniqueness constraint may collapse distinct factual occurrences.

## Migration Rules

Use new migration(s). Existing merged Teacher Duty migrations are immutable.
No silent repair, tenant reassignment, recategorization or deletion is allowed.
Rollback must be explicitly verified.

## Permission Boundary

`manage_teacher_duty_roster` remains the existing administrative capability.
No new permission is introduced by this contract.
Assignment remains non-authoritative for permission.
Scoped responsibility authorization remains deferred to 6A.9H.

## HTTP Boundary

6A.9G-A database/domain freeze creates no routes, controllers or FormRequests.
It changes no middleware or existing HTTP response contract.
Future school.operational behavior must be frozen in the HTTP contract.

## Required Regression Coverage

Tests must prove:
1. Same-school category succeeds.
2. Duplicate school category code fails.
3. Same code in different schools succeeds.
4. Category actor tenant mismatches fail.
5. Same-school occurrence relationships succeed.
6. Period tenant mismatch fails.
7. Category tenant mismatch fails.
8. recorded_by tenant mismatch fails.
9. Category lifecycle inconsistency fails.
10. History survives category deactivation.
11. History survives period closure.
12. Multiple same-day category occurrences succeed.
13. Generic occurrence deletion is prohibited.

## First Implementation Slice

Allowed only:
- new 6A.9G-A migration(s)
- occurrence category model
- occurrence model
- minimum required model relationships
- focused database/domain tests

Not allowed: routes, controllers, FormRequests, new permissions, daily report workflow, weekly workflow, scoped authorization, notifications or commercial changes.

## Frozen Invariants

1. Occurrences belong to duty periods.
2. Occurrences do not belong to assignments.
3. Categories are school-owned.
4. Canonical categories are provisioned per school.
5. Canonical codes are stable.
6. Schools may create custom categories.
7. Category code is unique per school.
8. Category deactivation preserves history.
9. Occurrences are preserved factual records.
10. Generic occurrence deletion is prohibited.
11. Occurrence date must fall inside the duty period.
12. New occurrences require a current same-school period.
13. New occurrences require an active same-school category.
14. Actor and tenant fields are server-owned.
15. Tenant mismatches fail closed.
16. PostgreSQL tenant constraints are defense in depth.
17. Delete relationships use RESTRICT.
18. Multiple same-day occurrences are allowed.
19. 6A.9G-B owns daily reporting workflow.
20. 6A.9G-C owns weekly workflow.
21. 6A.9H owns scoped responsibility authorization.
22. Existing 6A.9F-B contracts remain unchanged.
23. Existing merged migrations remain immutable.
24. No new permission is created by this contract.

## Freeze Rule

Implementation must conform to this frozen contract.
Verified conflicts require stop, documentation amendment, re-freeze, then implementation.
