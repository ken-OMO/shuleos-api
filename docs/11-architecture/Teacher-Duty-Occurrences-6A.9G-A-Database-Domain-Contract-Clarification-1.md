# Teacher Duty Occurrences 6A.9G-A
## Database / Domain Contract Clarification 1

Status: FROZEN CLARIFICATION

Parent contract commit: `41c7a9d95232461e4a4b2ee34f1672f706d6061f`

This clarification supplements the frozen 6A.9G-A database/domain contract.
It does not rewrite or weaken any previously frozen invariant.

## 1. Canonical category provisioning boundary

Canonical Teacher Duty occurrence categories SHALL be provisioned through a dedicated reusable domain/service boundary.

The implementation SHALL provide an idempotent `TeacherDutyOccurrenceCategoryProvisioningService` or equivalently named narrowly scoped service.

The provisioner SHALL own the canonical category definitions required by the frozen contract:

- `discipline` â€” Discipline
- `attendance` â€” Attendance
- `health_safety` â€” Health & Safety
- `cleanliness` â€” Cleanliness
- `property_facilities` â€” Property & Facilities
- `academic` â€” Academic
- `visitor_security` â€” Visitor & Security
- `general` â€” General

The provisioner SHALL accept an explicit school identity and SHALL create categories only for that school.

## 2. Existing-school provisioning

The 6A.9G-A migration SHALL deterministically backfill the canonical category set for every school that exists when the migration runs.

The backfill SHALL be idempotent and SHALL rely on the database `(school_id, code)` uniqueness invariant as the final duplicate/concurrency guard.

The migration SHALL NOT guess, repair, delete, or rewrite unrelated tenant data.

## 3. Future-school provisioning

Every legitimate production school-creation workflow SHALL invoke the reusable canonical-category provisioner.

At the time of this clarification, verified production creation paths are:

- `PlatformSchoolOnboardingService`
- `SchoolController` school creation

Provisioning SHALL execute inside the same transaction boundary as school creation.

If canonical provisioning fails, the surrounding school creation SHALL fail closed and roll back.

A `School` model observer, `created` event, or other hidden model lifecycle hook SHALL NOT be introduced for this purpose.

Any future production path that creates a school SHALL also invoke this provisioner before that workflow is considered complete.

## 4. Idempotency and preservation

Repeated provisioning for the same school SHALL NOT create duplicate category codes.

The eight frozen canonical codes are reserved canonical identities within each school.

If a category already exists with a reserved canonical code and `is_canonical = true`, repeated provisioning SHALL preserve that row and SHALL NOT silently reset school-customizable presentation fields such as `name`, `description`, `display_order`, or lifecycle state.

If a category already exists with a reserved canonical code and `is_canonical = false`, provisioning SHALL fail closed.

Provisioning SHALL NOT silently convert that custom row to canonical, overwrite it, delete it, recategorize it, create a duplicate, or guess the school's intent.

Canonical identity is established by the frozen canonical code together with server-owned canonical semantics; provisioning is not a synchronization mechanism for later presentation changes.

## 5. Test-created schools

Tests that directly construct `School` records are not production school-creation workflows.

The implementation SHALL NOT introduce a global model observer solely to make such tests receive canonical categories automatically.

Focused tests that require canonical categories SHALL explicitly invoke the provisioner or use the relevant production workflow/helper.

## 6. Category storage bounds

`code` SHALL be stored as `varchar(100)`.

`name` SHALL be stored as `varchar(150)`.

`description` SHALL be nullable `text`.

`display_order` SHALL be a non-negative integer.

PostgreSQL SHALL enforce `CHECK (display_order >= 0)`.

Category codes SHALL use normalized lowercase snake_case machine identifiers.

Application/domain validation SHALL accept only codes matching `^[a-z0-9]+(?:_[a-z0-9]+)*$` and SHALL enforce the 100-character maximum.

Canonical codes are server-owned and SHALL NOT be client-reassigned.

## 7. Occurrence description storage

`teacher_duty_occurrences.description` SHALL be stored as `text`.

The database/domain layer SHALL require a non-null description.

Application validation SHALL reject descriptions that are empty or whitespace-only.

No arbitrary database varchar truncation limit is introduced in 6A.9G-A.

A later HTTP/application contract MAY freeze a reasonable request-size maximum without changing the database historical-record semantics.

## 8. Tenant-safe actor relationships

The existing PostgreSQL `users_school_id_id_unique` candidate key has been directly verified.

6A.9G-A SHALL therefore use the frozen composite actor relationships:

- categories `(school_id, created_by)` -> users `(school_id, id)`
- categories `(school_id, deactivated_by)` -> users `(school_id, id)`
- occurrences `(school_id, recorded_by)` -> users `(school_id, id)`

All SHALL use `ON DELETE RESTRICT`.

## 9. Freeze rule

Implementation SHALL conform to the original 6A.9G-A contract plus this clarification.

If implementation inspection exposes a verified technical impossibility or conflict with a higher frozen contract, implementation SHALL stop and the contract SHALL be clarified again before code proceeds.
