# Elections and Transport audit repairs — 16 November 2026

Status: merged into main through PR #89 at 7a718e3dde1317af5250b2c8566399188daa3b27 on 16 November 2026. All ten PR checks passed. Not deployed; real-school Elections use remains blocked. The separate HTTP, photo-lock and commercial/settings follow-up below is locally verified and final source review is complete; it is not yet committed or CI-verified.

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

## PR #89 historical verification

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

## Subsequent HTTP, photo-lock and commercial/settings follow-up — 16 November 2026

Branch: hardening/elections-http-boundaries. Baseline: 7a718e3dde1317af5250b2c8566399188daa3b27. Final source review found no blocking findings in the reviewed selection. Local checks passed; no CI result exists for this uncommitted follow-up. Nothing staged, committed, pushed, merged or deployed by this documentation update. Real-school Elections use remains blocked.

Admin election and legacy candidate-review path IDs now have UUID constraints and malformed-ID/no-domain-query HTTP tests. Body UUID validation remains separate. Teacher station requests establish JWT identity and tenant context before the network boundary; its election lookup is restricted to that tenant. Device routes retain cookie-based authentication and existing trusted origin/network/session checks, independently of teacher JWTs. No learner voting endpoint is enabled.

Profile metadata/image reads now take shared learner ownership locks; uploads retain exclusive locks. Separate-session PostgreSQL tests prove overlapping metadata reads, reader blocking behind the exclusive ownership lock, revocation waiting for an existing read, and post-wait access revalidation. Real image/metadata tests confirm shared query locks. The writer probe acquires upload's ownership lock; it is not a concurrent scanned-file upload benchmark. File preparation remains inside the authorized transaction; an already-authorized response is not instantly revocable.

StudentElectionCommercialEvaluator now supplies shared school/main-subscription facts and samples commercial time after the subscription lock wait. StudentElectionEntitlementService retains actor validation and nullable/mode/ends_at output. StudentElectionCommercialAccess retains normal denials, opening receipts and bounded continuation/completion behavior. Parity tests cover inclusive start/exclusive end, eligible grace and denied main-expiry/read-only cases; the existing entitlement contention and device suites also passed.

Creation accepts results_visibility omitted or hidden only; declaration still controls publication. Legacy allow_self_nomination, require_candidate_approval and show_live_turnout inputs are rejected explicitly, including null/default-looking values, at HTTP and service boundaries. Existing stored rows and schema are retained. This does not make guardian consent/review optional or enable live candidate tallies. See [photo locking, commercial evaluation and settings](Student-Elections-Photo-Locks-and-Settings.md).

### Current local verification

All checks ran against shuleos_assessment_fk_test with classroom rollout and reporting schedulers disabled; the process environment was restored afterward.

| Check | Passed result |
| --- | --- |
| Photo-read PostgreSQL contention | 4 tests, 11 assertions |
| Real photo-version/privacy suite | 33 tests, 73 assertions |
| Add-on suite including commercial parity | 24 tests, 168 assertions |
| Unsupported-settings HTTP/service tests | 2 tests, 24 assertions |
| Settings policy unit tests | 2 tests, 15 assertions |
| Elections features, including existing contention/device enforcement | 390 tests, 1,841 assertions |
| Elections units | 28 tests, 756 assertions |
| Full backend regression | 2,537 tests, 35,422 assertions |
| Full regression elapsed time / peak memory | 20 minutes 42.378 seconds / 654 MB |
| Elections and Transport scoped PHPStan level 5 | No errors in either scope |
| Repository formatting | 1,478 files passed |
| Composer strict validation, locked security audit and optimized strict PSR-4 autoload | Passed; no advisories at verification time; 8,353 classes |
| Diff checks | Passed |

Targeted tests are included in the Elections/full counts; do not add them together. The earlier HTTP slice separately passed 2 route-boundary tests/55 assertions and 19 station HTTP tests/56 assertions; those tests remain included in the current full regression. Local passing results are not remote CI or production-release evidence.

The final reviewed selection contains 23 changed/new files and no migrations or dependency changes. This final documentation correction changes six Markdown files only. The verified PHP, routes and middleware remain byte-for-byte unchanged; a second full regression is not required for these prose-only corrections. Staging must use the new final reviewed-state hashes.

Certificates/PDFs, voting extension and Transport commercial HTTP activation/payment-provider verification remain deferred. Classroom rollout, real-school activation, payment collection, scheduler activation and deployment are not included.
