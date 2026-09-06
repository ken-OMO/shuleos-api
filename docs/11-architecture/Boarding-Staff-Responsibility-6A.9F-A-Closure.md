# Boarding Staff Responsibility — 6A.9F-A Closure

## Status

COMPLETE AND FROZEN.

Phase 6A.9F-A establishes the Boarding Staff Responsibility foundation.

The implementation preserves the architectural separation between:

- identity;
- authorization;
- operational responsibility;
- historical responsibility evidence.

A Boarding responsibility associates a tenant-owned User with a Hostel,
responsibility role and effective lifecycle.

A Teacher specialization is not required for generic Boarding personnel.

Responsibility assignment does not grant `manage_boarding` or any other
platform permission.

## Frozen implementation chain

- Architecture / ADR: `ed66415`
- Database contract: `0b524bd`
- Database foundation: `20a410d`
- Application contract: `41d4223`
- Domain service: `583f8ba`
- HTTP boundary: `d82b253`

## Security acceptance

The frozen 28-point 6A.9F-A application/security acceptance contract has
been reviewed against the implemented database, domain service, HTTP
boundary and adversarial tests.

All 28 requirements are classified as PROVEN.

The accepted implementation proves:

1. same-tenant eligible User assignment;
2. no Teacher record requirement;
3. inactive User rejection;
4. deleted User rejection;
5. suspended User rejection;
6. cross-tenant User rejection;
7. cross-tenant Hostel rejection;
8. authoritative same-tenant actor ownership;
9. inactive/deleted Hostel rejection;
10. school-local effective-date enforcement;
11. duplicate-current assignment protection;
12. multiple responsible personnel per Hostel;
13. one User may manage multiple Hostels;
14. different responsibility roles may coexist;
15. terminal ending preserves history;
16. an ended assignment cannot be ended again;
17. ended assignments are not reactivated;
18. later responsibility creates a new episode;
19. current reads exclude ended episodes;
20. history preserves current and ended episodes;
21. cross-tenant reads fail closed;
22. client ownership/lifecycle fields are prohibited;
23. `manage_boarding` protects responsibility management;
24. responsibility assignment grants no permission;
25. creation respects `school.operational`;
26. ending remains a dedicated lifecycle operation;
27. database duplicate violations are translated safely;
28. public API representation hides tenant and security-sensitive fields.

## Database guarantees

The database contract includes:

- explicit tenant ownership;
- tenant-safe composite foreign keys;
- lifecycle CHECK constraints;
- effective-date consistency;
- deterministic lookup indexes;
- PostgreSQL partial unique protection for duplicate current assignments;
- restrictive historical relationships.

Migration, rollback, re-migration, fresh-database and adversarial PostgreSQL
validation were completed before application closure.

## Lifecycle

One `hostel_staff_assignments` row represents one responsibility episode.

The lifecycle is:

`CREATED -> CURRENT -> ENDED -> HISTORICAL`

There is no delete, restore or reactivate lifecycle.

A later return to the same responsibility creates a new row.

Historical episodes remain preserved when associated Users or Hostels later
become inactive or retired.

## HTTP boundary

The frozen API surface is:

- `GET /api/boarding/hostels/{hostel}/staff-assignments`
- `POST /api/boarding/hostels/{hostel}/staff-assignments`
- `GET /api/boarding/hostels/{hostel}/staff-assignments/history`
- `PATCH /api/boarding/staff-assignments/{assignment}/end`

There is no generic update, delete, restore or reactivate endpoint.

## Authorization boundary

Responsibility and authorization remain separate.

All responsibility-management routes require `manage_boarding`.

Creation additionally requires the existing operational-school gate.

6A.9F-A does not introduce responsibility-scoped authorization.

Any future combination of permission, responsibility scope and responsibility
role belongs to the explicit scoped-authorization phase.

## Non-goals preserved

6A.9F-A does not implement:

- Teacher-on-Duty scheduling;
- Teacher-on-Duty operational records;
- Boarding daily reports;
- Boarding attendance;
- Boarding incidents;
- reminders or escalation;
- room-level staff responsibility;
- future-dated responsibility scheduling;
- generic Staff identity;
- subscription billing;
- automatic permission grants.

Those remain in their approved later phases.

## Closure principle

6A.9F-A is frozen as the authoritative Boarding human-responsibility
foundation.

Future Boarding operational features must consume this responsibility model
without weakening tenant isolation, historical accountability or the
separation between identity, authority and responsibility.