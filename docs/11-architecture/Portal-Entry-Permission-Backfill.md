# Portal entry permission backfill

## Problem and scope

Live frontend/backend verification against main e5199c420a32d5e371789bc5678fa7f03649a6c4 found missing migrated role grants. July portal migrations skip roles that do not yet exist; core system roles are ensured in August, and that later backfill restores only older/core portal grants.

The forward-only migration `2026_11_15_160000_backfill_portal_entry_permissions.php` adds only this explicit allowlist to existing active, global, system roles:

| System role | Added minimum permissions |
|---|---|
| Teacher | `view_own_teacher_assignments` |
| Principal | `access_leadership_portal_phase_two`, `view_principal_dashboard` |
| Headteacher | `access_leadership_portal_phase_two`, `view_principal_dashboard` |
| Parent | `access_parent_portal_phase_two` |
| Learner | `access_learner_portal_phase_two` |

Headteacher uses the same principal dashboard contract. This is not a replay of historical bulk grants: no new finance, platform administration, Elections, mutation or other operational permissions are added. Existing extra grants are retained, not normalized or revoked. Missing roles are not created; inactive roles are not reactivated. School-specific roles and global non-system roles are not changed or synchronized. Custom role configuration remains explicit.

Portal-entry permissions do not replace the existing identity, tenant, assignment, linked-child, resource ownership and operation-specific permission checks. The system roles are shared, so existing users of the named system roles receive the restored minimum grants after migration; custom roles do not inherit them automatically.

## Idempotency and failure behavior

The migration locks eligible roles in stable ID order in a transaction, resolves the complete permission catalogue before inserting any grants, and checks existing role/permission pairs before insertion. Concurrent executions serialize on the role locks. Existing grant IDs and timestamps remain unchanged on rerun. An absent required permission fails the transaction rather than inventing catalogue entries or leaving a partial backfill.

System roles and the permission catalogue remain owned by the earlier migrations. Future school onboarding reuses the migrated system roles; it does not require fixture grants or a new runtime permission synchronization path.

## Rollback plan

`down()` explicitly refuses rollback. A destructive inverse cannot distinguish pre-existing grants from grants introduced here and could revoke legitimate access. If correction is necessary, deploy a reviewed compensating migration based on the affected environment's grant history. Do not delete role permissions automatically or roll back identity/tenant guards.

## Verification required before merge

`PortalEntryPermissionBackfillTest` covers the freshly migrated baseline; recovery of missing grants on an existing database; preservation of unrelated rows and existing grant identity; no-op reruns; school-specific, non-system and inactive roles; atomic failure for a missing catalogue entry; role-specific authoritative claims in two schools; and rollback refusal.

AuthenticationContractTest updates only the expected authoritative Teacher claim list. Existing endpoint and tenant checks remain unchanged.

Run formatting, these tests and authorization/portal regressions on PostgreSQL, then the full backend regression. Point the frontend live workflow at the exact resulting backend commit and rerun all nine real two-school tests without fixture-only permission grants. PR #1 stays a draft; business workflows remain on hold until the gate passes. Nothing merged or deployed by this change.

## Current validation state

Implementation and tests are prepared locally. PHP/Composer/PostgreSQL are unavailable in the current workspace, so execution requires repository CI. At preparation time the GitHub installation has access only to `shuleos-web`; publishing this backend branch and running backend CI requires adding `shuleos-api` to that existing installation. No test-pass claim is made for this unexecuted repair.
