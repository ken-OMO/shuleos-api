# Teacher Duty Occurrences 6A.9G-A â€” Database / Domain Contract Clarification 2

## Status

FROZEN CLARIFICATION.

This clarification extends and, where explicitly stated, supersedes the affected wording in:

- `Teacher-Duty-Occurrences-6A.9G-A-Database-Domain-Contract.md`
- `Teacher-Duty-Occurrences-6A.9G-A-Database-Domain-Contract-Clarification-1.md`

Parent implementation checkpoint:

`10e8492c52b328fa740210a52d5b14e079d14f80`

No implementation SHALL begin until this clarification is reviewed and frozen.

## 1. Verified production school-creation boundary

The current live application route surface has exactly one production school-creation workflow:

`POST /api/admin/platform/schools`

implemented through `AdministratorPortalController@onboardSchool` and `PlatformSchoolOnboardingService`.

`SchoolController::store()` exists in source but is not exposed by the current live route surface.

The historical POST route appearing only in `routes/api_full_backup.txt` is not a production integration boundary.

Therefore Clarification 1 is superseded where it identified `SchoolController` as a current production school-creation path.

The current production provisioning integration point SHALL be `PlatformSchoolOnboardingService`.

Any future production workflow that creates a School SHALL explicitly invoke the canonical occurrence-category provisioner before its transaction is committed.

No global School observer, created-event hook, or hidden lifecycle hook SHALL be introduced for this purpose.

## 2. Canonical category provenance

Canonical occurrence categories are system-provisioned configuration records.

They are not human-authored category creation events.

Accordingly, assigning an arbitrary school User to `created_by` during migration or automated provisioning would create false provenance and is prohibited.

For rows where `is_canonical = true`, `created_by` SHALL be nullable and system provisioning SHALL store `created_by = null`.

For rows where `is_canonical = false`, `created_by` SHALL be required and SHALL identify an eligible same-school User supplied by authenticated server context.

The eight reserved canonical codes are:

`discipline`, `attendance`, `health_safety`, `cleanliness`, `property_facilities`, `academic`, `visitor_security`, and `general`.

PostgreSQL SHALL enforce that `is_canonical = true` if and only if `code` is one of those eight reserved canonical codes.

A non-canonical row SHALL NOT use a reserved canonical code, and a canonical row SHALL NOT use any other code.

PostgreSQL SHALL enforce the provenance invariant:

`CHECK ((is_canonical IS TRUE AND created_by IS NULL) OR (is_canonical IS FALSE AND created_by IS NOT NULL))`

The tenant-safe composite foreign key `(school_id, created_by) -> users (school_id, id)` SHALL remain present and SHALL permit null `created_by` for canonical rows.

This section supersedes prior wording that required non-null `created_by` for every category.

`created_by = null` SHALL mean system-provisioned canonical configuration only.

It SHALL NOT be accepted for a custom category.

## 3. Existing-school migration backfill

The migration SHALL provision the canonical set for every School row that exists when the migration runs.

Migration provisioning SHALL use `created_by = null` for canonical rows.

The migration SHALL NOT search for, choose, infer, or fabricate a school User as the creator.

The migration SHALL remain deterministic and idempotent.

If an existing row uses a reserved canonical code with `is_canonical = false`, migration provisioning SHALL fail closed according to Clarification 1.

No tenant/category/actor data SHALL be silently repaired.

## 4. Future-school provisioning

`PlatformSchoolOnboardingService` SHALL invoke the dedicated canonical category provisioner inside the same database transaction that creates the School.

Canonical category provisioning does not require the initial School Admin as `created_by` because canonical provenance is system-owned.

The provisioner SHALL receive explicit School identity and SHALL never infer tenant context.

Provisioning failure SHALL fail the enclosing school-onboarding transaction.

Repeated provisioning for the same School SHALL remain idempotent.

## 5. Canonical default display order

The initial canonical display orders are frozen as:

- `discipline` â€” `10`
- `attendance` â€” `20`
- `health_safety` â€” `30`
- `cleanliness` â€” `40`
- `property_facilities` â€” `50`
- `academic` â€” `60`
- `visitor_security` â€” `70`
- `general` â€” `80`

These are initial presentation defaults, not part of canonical identity.

Schools may later change `display_order` through the applicable configuration workflow without changing canonical identity.

Repeated provisioning SHALL NOT reset a school-customized `display_order` on an already-existing valid canonical row.

The existing frozen `CHECK (display_order >= 0)` requirement remains unchanged.

## 6. Initial canonical lifecycle state

Newly provisioned canonical categories SHALL be created with:

- `is_canonical = true`
- `active = true`
- `deactivated_by = null`
- `deactivated_at = null`
- `created_by = null`

Canonical descriptions may be `null` unless separately frozen later.

## 7. Freeze rule

Implementation SHALL conform to the original 6A.9G-A contract together with Clarifications 1 and 2.

Where Clarification 2 explicitly supersedes earlier wording, Clarification 2 governs.

If implementation reveals a verified technical impossibility, unsafe invariant, or conflict with a higher frozen contract, implementation SHALL stop and the contract SHALL be clarified and re-frozen before proceeding.

Implementation convenience alone is not sufficient reason to change a frozen invariant.
