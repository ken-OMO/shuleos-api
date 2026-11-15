# Elections annual entitlement

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Status: locally verified; real-school release remains blocked.

An optional Elections add-on does not change the school's main package. Enterprise, Premium and Basic names never grant the add-on. A main subscription remains required for normal use; its existing eligible ACTIVE or GRACE_PERIOD state is checked separately.

## Calendar period

The effective start is an explicitly attested current or future activation instant, with an explicit commercial timezone. The server derives the end by adding twelve calendar months in that timezone, clamping a leap-day anniversary to the last valid day of February. The period includes its start and excludes its end. Clients cannot provide an arbitrary end date.

This is not a fixed academic-year product or a fixed 365-day duration. A contiguous renewal begins at the preceding period's end and lasts another twelve calendar months. Overlap is forbidden. School-to-school data isolation, database exclusion constraints and an immutable renewal chain protect commercial history.

A platform administrator must hold the distinct management permission and satisfy the existing platform-administration requirements. Manual attestation records already checked commercial evidence; it is not provider payment verification. Request identity and evidence fingerprint provide idempotency. An identical retry returns the recorded period without another audit; changed evidence with the same request is rejected. A stale expected_version must be refreshed.

Issuance rechecks the actor and school after the coordination lock. A new activation cannot backdate access. Period mutation and strict administrator audit share a transaction. No role permissions are automatically granted by the migration, and no school is activated by installing it.

## Commercial decisions

StudentElectionEntitlementService requires the caller transaction, a fresh accessible school actor, an active school, an effective annual period and an eligible latest main subscription. It uses SHARE coordination so separate voters need not serialize on a school UPDATE lock. Commercial time is sampled after the main-subscription lock wait. Periods are immutable, and the school SHARE lock excludes simultaneous issuance while that decision is used.

This core does not replace domain permissions, tenant checks, attendance, guardian designation, classroom device credentials or the integrity hold. These remain mandatory.

## Operational integration

The shared domain services, learner/guardian identity adapters and voting devices now use the commercial gate described in Student-Elections-Entitlement-Enforcement.md. Normal new work requires current access. Paid opening receipts support bounded completion of their original windows and controlled closure. New replacement elections and unopened resolution rounds require current access. Historical evidence and independently reviewed cancellation remain available under existing authorization rules.

The wider learner and parent portals are not enabled as a side effect. These are implementation claims subject to the installer acceptance gates; they are not rollout approval.

## Verification

The installer runs annual-period tests before the previously verified Elections suites. Tests cover calendar anniversaries, leap years, timezone offsets, idempotency, overlap, immutable evidence, tenant isolation, missing add-on/main subscription, expiry and audit rollback. Runtime results must be obtained from the isolated PostgreSQL test database; source syntax checks alone do not establish readiness.
