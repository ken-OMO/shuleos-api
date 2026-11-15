# Elections and Transport audit repairs — 16 November 2026

Status: locally verified against baseline 785d86adffe14ad8d143ae5e2832a597a095dcd8. Final source review completed; this follow-up is not staged, committed, pushed, merged or deployed. CI for this follow-up has not run. Real-school Elections use remains blocked.

## Ordered repair scope

1. Election creation, position creation/attachment, voter generation, lifecycle transitions and legacy candidate review write strict same-transaction lifecycle audit. An unavailable audit actor or failed audit aborts the mutation. Voter-generation audit contains only the insertion total, never learner-to-ballot evidence. HTTP explicitly supplies the authenticated actor for attachment and voter generation; direct callers must supply an actor or authenticated context.
2. Missing/blank position codes are null and do not participate in code duplicate checks. Name uniqueness within school/scope remains. The school row serializes service position creation before commercial locks. No schema or existing evidence is rewritten. Direct SQL position writes still require the normal reviewed administrative process.
3. Voter generation batches 500 learners per position and returns the PostgreSQL insertion count. The existing unique voter constraint protects retries. Existing eligibility and participation are never reset. Repeated generation returns zero when nothing is added. Existing learner eligibility policy is retained.
4. Transport order is school/actor/commercial locks, then trip, learner, roster, then specific evidence. Journey and exception writes now acquire the trip before the learner. The existing exclusive school write lock already serializes ordinary same-school service writes; the original audit overstated the service-to-service deadlock evidence. Direct database writers are why domain ordering still matters. When journey, attendance/completion or exception services own the outer transaction, SQLSTATE 40P01 is translated to 409 after rollback; clients refresh, with no automatic custody retry. A nested Laravel DeadlockException propagates to the outer transaction owner, which must abort the transaction before responding. The tests distinguish this framework wrapper from a top-level QueryException. Synthetic exception tests verify propagation/rollback; the separate PostgreSQL lock probe verifies real contention. Other database errors are not hidden. A three-session probe verifies that a blocked journey has not acquired the learner lock.
5. An actor with no applicable exception action permission is rejected before failure-ID lookup. Concrete responsibility checks remain inside the transaction. Guardian and school-counterpart designation/revocation both require authorize_transport_handover. manage_transport alone is insufficient. Existing staff eligibility, dated assignment, distinct crew, direction and tenant checks remain.
6. Declaration requires an officer distinct from the root tally officer and every linked-round tally officer, in addition to existing permissions and integrity gates. Missing or ambiguous linked tally audit fails closed. Published evidence remains immutable; declaration retries do not create another appointment. Existing tests use a distinct publisher and retain their original integrity assertions.

## Station deployment configuration

STUDENT_ELECTION_SCHOOL_NETWORKS accepts a JSON object mapping lowercase school UUIDs to nonempty IPv4/IPv6 CIDR lists. Example (documentation UUID only):

```dotenv
STUDENT_ELECTION_SCHOOL_NETWORKS='{"11111111-1111-4111-8111-111111111111":["10.20.30.0/24","2001:db8::/64"]}'
```

Missing/malformed JSON, invalid schools/CIDRs, empty lists or /0 entries deny all station networks. There is no wildcard fallback. Configuration is resolved when Laravel configuration is loaded/cached; rebuild the configuration cache through the reviewed deployment process after changes. Trusted proxy settings must reflect the real network boundary. This setting does not enable stations. Stations remain disabled by default; TLS, trusted origin, persistent limiting, logging/privacy review and release approval remain prerequisites. The example is not a real-school grant.

## Delivery status reconciliation

Certificates/PDF reports and voting-window extension are requirements, not delivered endpoints. Ordinary extension is currently unavailable. Implementing it must separately address one-school-day restrictions, current-day attendance, commercial opening receipts, frozen round definitions, station/session expiry, stale versions, reason/audit and races with closure/tally. Do not edit voting dates directly as a workaround.

Transport commercial activation/renewal HTTP and provider payment verification remain explicitly deferred. Manual platform attestation services are not payment automation. No activation endpoint, cancellation or payment verification is introduced by these repairs.

Absence of Transport Eloquent models/global scopes is not itself a vulnerability. Explicit school filters, actor authorization, database constraints and bidirectional tenant tests remain required. These repairs do not claim PostgreSQL row-level security or cryptographic ballot anonymity.

## Verification

Verification completed locally on the isolated PostgreSQL database `shuleos_assessment_fk_test` with classroom rollout and both reporting schedulers disabled. Process environment settings were restored afterward.

| Check | Passed result |
| --- | --- |
| Elections features, including application/round races | 376 tests, 1,707 assertions |
| Elections units, including network configuration | 26 tests, 741 assertions |
| Transport features, including actual contention and deadlock exception coverage | 229 tests, 2,828 assertions |
| Transport subscription enforcement | 41 tests, 362 assertions |
| Transport units | 9 tests, 182 assertions |
| Full backend regression | 2,521 tests, 35,273 assertions |
| Full regression runtime and peak memory | 16 minutes 28.893 seconds; 650 MB |
| Elections and Transport scoped PHPStan level 5 | No errors in either scope |
| Repository formatting | 1,472 files passed |
| Composer validation, locked dependency audit and strict optimized PSR-4 autoload | Passed; no vulnerability advisories reported at verification time |
| Diff checks | Passed |

The targeted lifecycle suite passed 11 tests/43 assertions and the network suite passed 2 tests/15 assertions; these are included in the Elections counts above and are not additional tests. The nested deadlock propagation test passed 1 test/7 assertions; the standalone rollback/conflict test passed 1 test/15 assertions. Both are included in the Transport feature count. The separate-session contention runner verifies blocking with `pg_blocking_pids`; its third-session NOWAIT probe verifies that a journey waiting for its trip does not already hold the learner. Injected PDO/QueryException cases are synthetic exception-handling coverage, not a reproduced PostgreSQL deadlock or a throughput benchmark.

An earlier full regression reported five portal-permission failures. The later migration diagnostic reported that the portal backfill was already recorded and refused to replay it; that diagnostic did not establish a new database upgrade. Subsequent authentication tests passed 7 tests/58 assertions and portal backfill tests passed 9 tests/132 assertions, followed by the successful full regression above. No claim is made about the cause of the earlier failures or a new fresh-schema verification in this repair slice.

The reviewed selection contains 33 changed/new files and introduces no migrations or dependency updates. Final documentation cleanup changes five Markdown files only; verified PHP source and configuration remain byte-for-byte unchanged. Local verification does not establish CI success, production configuration readiness or release approval.

No real-school activation, deployment, payment collection or scheduler activation. Elections remains blocked for real-school use.
