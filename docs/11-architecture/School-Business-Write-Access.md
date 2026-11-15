# School business write access — first enforcement slice

Status: implementation under verification; not merged or deployed. Business forms remain on hold. Real-school Elections remains blocked.

## Scope

Unsafe HTTP methods under the following tenant/module-permission groups require a current school lifecycle and a valid recorded main subscription:

- learners (admission, update, deletion, placement, lifecycle, portal accounts and guardian-link mutations)
- guardians, teachers and teacher-assignments
- learning-area-allocations
- academic-years, terms, academic-weeks, grades and streams
- admin/school (school profile)

This is an explicit first slice, not whole-ERP commercial enforcement. Auth/logout/refresh, platform administration, assessment, teacher workflows, finance, parent/learner self-service, Boarding, Teacher Duty and Transport/Elections remain under their existing policies. In particular, Transport/Elections historical-evidence and safe-completion exceptions must not be shadowed by a generic write veto. No package-to-feature matrix, purchase/activation endpoint, payment integration or expiry scheduler is introduced here.

## Decisions

The authoritative identity supplies the school ID. The latest main subscription is selected by created_at DESC, id DESC, without falling back to a more permissive older row. No legacy school_settings value, request school_id, package name or role grants a subscription.

| School state | Business writes in scope | Authorized GET/HEAD reads |
|---|---|---|
| onboarding/trial/active/grace | Require valid subscription below, plus existing permissions/setup/domain checks | Existing read policy |
| read_only | Denied, even with a valid subscription | Preserved |
| locked/suspended/archived/inactive/deleted | Denied | Existing auth boundary denies unavailable schools |

| Latest subscription | Write eligibility |
|---|---|
| ACTIVE | package_id, start_date and expiry_date required; start <= school-local today <= expiry |
| GRACE_PERIOD | Same recorded fields; grace_end_date required and >= expiry; today <= grace_end_date |
| TRIAL | is_trial=true and recorded start/expiry required; start <= today <= expiry; no paid package is inferred |
| Missing, pending, locked, suspended, expired, future or malformed | Denied |

Dates are inclusive in the school timezone. The clock is sampled after lock waits. No grace date or trial duration is invented. Expiry denies in-scope writes even if an administrator has not yet updated school lifecycle. This does not automatically change lifecycle or decide how long read-only lasts before locking. No existing school is assigned a subscription and no paid state, dates, roles or permissions are backfilled.

## Atomicity and concurrency

SchoolBusinessWriteAccess runs the protected controller and its audit writes in one transaction. It re-reads the school under FOR UPDATE, then reads the latest subscription and evaluates current time. Main-subscription INSERT/UPDATE/DELETE has a PostgreSQL trigger acquiring the same school lock; moves acquire old/new school locks in ID order. This serializes a competing subscription change, including a newer row, against the authorized business write. Subscription reads deliberately do not acquire a second row lock: a direct SQL updater may already own that row while waiting on the school lock.

Existing lifecycle administration also locks the school row. In-scope writes serialize per school; no throughput claim is made. Bulk billing updates across multiple schools should lock all affected schools in stable order and handle deadlock retries. Background/direct service writes are not covered merely because HTTP is guarded; future writers must explicitly use the same boundary. Domain authorization and tenant validation remain necessary inside it.

A rejected request performs no business mutation/audit. A valid subscription supplies no missing role permission and does not bypass setup readiness or foreign-resource validation.

## Migration safety

The migration only creates/replaces a trigger function and trigger on school_subscriptions. PostgreSQL migration transactions protect DDL atomicity. It does not rewrite existing records. The existing-database probe snapshots school, subscription, package, role, grant and user rows before applying the migration; after upgrade and rerun all rows must be identical. Explicitly paid test fixtures never enter production provisioning.

DDL requires a table lock; review table size, concurrent billing writers and maintenance timing before deployment. Down removes the serialization trigger/function and preserves data, but removes this concurrency guarantee; do not roll back schema independently while relying on the new application boundary.

## Verification

The dedicated workflow uses PostgreSQL 16 and PHP 8.2, only APP_ENV=testing and shuleos_assessment_fk_test. Fresh and pre-upgrade schemas run the same HTTP and concurrency tests. Existing migration behavior is probed before tests; no production DB or real-school data is used. Existing positive workflow fixtures now explicitly create their own test-only subscription; SchoolBuilder and the shared TestCase do not silently grant access.

Coverage includes actual login then admission, active/grace/read-only/locked states; missing/invalid subscriptions; inclusive local dates; newest-row precedence; missing permission; foreign subscription/placement rejection; read preservation; another academic module; update/delete denial; and PostgreSQL session contention against subscription update/insertion and school lifecycle changes.

Initial verification: fresh and existing PostgreSQL jobs each passed 28 new boundary/concurrency tests (131 assertions) and 243 existing workflow/authentication tests (1,446 assertions), run https://github.com/ken-OMO/shuleos-api/actions/runs/43744330637. Final expanded date-boundary tests and full CI must be recorded in the draft PR. The normal full regression, Pint, security and existing static checks must also pass. No frontend forms may be enabled from this implementation alone, and other domains still need explicit commercial enforcement review.
