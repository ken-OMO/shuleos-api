# Elections photo locking, commercial evaluation and legacy settings

Locally verified on 16 November 2026; final source review completed against the 23-file verification snapshot. Full backend regression passed 2,537 tests/35,422 assertions. Both scoped static analyses and remaining checks passed. This follow-up has no commit or CI result yet and is not deployed. Real-school Elections use remains blocked. See [current verification and delivery limits](Elections-Transport-Audit-Repairs.md).

## Photo ownership

Profile metadata and image reads take a shared learner lock. Uploads retain the exclusive learner lock that serializes expected-version checks and profile-head updates. Actor authentication, learner ownership, tenant filters, integrity checks and private response headers remain mandatory. File preparation remains inside the authorized transaction; this change does not promise nonblocking storage I/O or instant revocation of bytes already authorized for delivery.

The PostgreSQL tests require separate worker sessions: profile readers overlap, an exclusive ownership lock blocks a reader, learner portal revocation waits for an existing read, and a waiting reader rechecks access after revocation commits. The exclusive-lock probe uses the same learner lock as upload; existing photo tests separately exercise real scanned upload/replacement and immutable application-photo binding. It is not a concurrent file-upload benchmark.

## Shared commercial facts

StudentElectionCommercialEvaluator owns the school/main-subscription locks, post-lock UTC clock, timezone-sensitive main-subscription eligibility and add-on period selection. It requires a caller transaction. It does not authorize actors or grant domain permissions.

StudentElectionEntitlementService retains actor validation and its nullable decision, mode and ends_at fields. StudentElectionCommercialAccess retains HTTP denial behavior and its existing opening-receipt, live-window continuation and historical-completion policies. A current main subscription remains required for live continuation. Parity tests cover the inclusive start/exclusive end and main-subscription grace/read-only decisions; existing contention tests continue to verify time after lock waits and paid-window continuation.

## Unsupported settings

New election creation accepts omitted results_visibility or hidden only. Controlled declaration governs result publication. after_close, after_approval and live_turnout_only are rejected rather than stored as promises of unsupported behavior.

allow_self_nomination, require_candidate_approval and show_live_turnout are legacy storage columns, not configuration for the hardened workflow. Supplying any of them, including null or a default-looking value, returns a validation error at HTTP and service boundaries. Existing rows and schema are retained. Versioned applications, guardian consent, independent reviews and existing teacher progress views keep their own mandatory policies. This does not enable live candidate tallies or make nomination review optional.

No migration, subscription issuance, real-school activation or deployment is included.
