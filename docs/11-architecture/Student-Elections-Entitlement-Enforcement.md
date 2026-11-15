# Elections operational entitlement enforcement

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Status: locally verified; real-school release remains blocked.

## Commercial contract

The optional add-on lasts twelve calendar months from its attested effective activation, in the recorded commercial timezone. Its immutable period and renewal rules are unchanged. No package name grants it. Normal access also requires the existing eligible main subscription and an active school. No main package, prices or wider portal settings are changed.

The commercial gate runs inside the domain transaction, using school SHARE and latest main-subscription SHARE locks. These do not serialize independent voters. Time is sampled after commercial lock waits and rechecked after blocking participation locks before a ballot write. Existing identity, tenant, role, permission, attendance, device, guardian and integrity checks remain mandatory.

## Operation boundaries

| Operation | Commercial boundary |
| --- | --- |
| New election or replacement draft | Current annual add-on and eligible main subscription |
| Positions, attachments, voter generation, nominations, policy, ordinary consent/review, guardian designation, photo upload | Current annual add-on and eligible main subscription |
| New device registration or pairing | Current annual add-on and eligible main subscription |
| First root opening or new runoff/confirmation opening | Current annual add-on; immutable opening receipt recorded atomically |
| Original or round vote after annual expiry | Existing paid opening receipt, unchanged recorded window, eligible main subscription and all existing domain checks |
| Closure, tally and declaration after annual expiry | Recorded root opening, existing domain integrity/declaration gates; no new voting round is opened for free |
| Withdrawal, recorded integrity incident and independent cancellation | Retained safeguards; annual expiry does not erase evidence or prevent reviewed cancellation |
| Historical application, consent and published council reads | Existing identity, tenant, ownership and action permissions; no new commercial period required |

Opening receipts identify the school, election/round, annual period, main subscription, officer and accepted voting window. They do not identify individual voters or ballot choices. They are immutable. Opening, electorate freeze and strict audit share one transaction. A failed opening audit rolls them back.

After expiry, changing a window cannot extend the earlier receipt. Unopened runoffs and fresh replacements require renewed current access. Old elections without paid-opening evidence are not silently grandfathered into free continued voting. Existing published evidence can still be inspected and an existing declaration verified idempotently. No permission to create extra winners or publish unresolved outcomes is introduced.

## Scoped learner and guardian access

Elections uses narrow identity adapters exposing only the learner and guardian-link operations needed for this module. Global school switches for the wider portals do not activate or disable the standalone Elections surface. Personal learner portal revocation, active identities, Parent/Learner roles, tenant ownership, current guardian designation and per-link portal authorization remain enforced.

Only the six existing learner Elections routes bypass the generic learner module middleware; their explicit Elections action/module permissions and narrow domain checks remain. Every other learner route keeps the generic policy. Application and photo endpoints retain their own authenticated tenant-scoped services. General portal classes and settings are unchanged.

Historical access still obeys suspended/locked/archived school restrictions. An expired main subscription does not permit a new live vote. Read-only access is limited to historical applications and controlled result completion/cancellation, rather than configuration or normal voting.

## Verification and adoption

The installer adds service, HTTP, device, calendar-boundary, receipt-integrity, opening-audit-rollback and actual separate-session contention tests. Existing concurrency tests must still prove that independent voters can commit concurrently. New contention tests observe pg_blocking_pids and advance the application clock only after the main-subscription wait completes.

Existing tests explicitly create synthetic annual periods and main subscriptions. Test-only receipt cleanup requires the exact isolated writer database, owned fixture identity, enabled named triggers and ACCESS EXCLUSIVE locks. Only named user triggers are disabled; foreign keys remain enabled. DDL and cleanup are transactional. No production deletion or period-editing API exists.

The installer runs all Elections suites, baseline-free PHPStan level 5 over the listed application/configuration paths, Composer validation and dependency audit, then the full backend regression with a 1 GB PHP memory limit. No static-analysis claim is made for migrations or test support. CI wiring and final source/documentation review remain separate work after local results.

No real school is activated, no role receives an automatic commercial grant, no payment is collected and classroom rollout remains disabled. Legacy production evidence, production scanner health, deployment/network controls and remaining operational release gates still need explicit review. Nothing here establishes production readiness by itself.
