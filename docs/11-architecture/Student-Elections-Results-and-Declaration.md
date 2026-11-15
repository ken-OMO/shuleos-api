# Elections result integrity and declaration

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Date: 12 November 2026
Status: locally verified; real-school release remains blocked.
Release: blocked for real-school use

## Supported counting policy

Single-seat plurality is the supported live method. Every approved candidate is
represented in the tally, including candidates with zero votes. Equal counts
share a competition rank. UUID ordering makes storage deterministic; it never
chooses a winner. A positive, unique leading count wins when there is more than
one candidate. A tie at the winning cutoff records tied candidates and blocks
the whole election declaration. Ties below the cutoff do not block an otherwise
resolved seat. Winners must never exceed configured seats.

Zero turnout never invents a winner. The approved single-seat-resolution-v1
policy is defined in Student-Elections-Runoffs-and-Confirmation.md. Linked rounds
preserve the original tally, use the original frozen electorate and resolve only
outstanding offices. Unopposed candidates require Approve/Reject/Abstain confirmation
without quorum. Multi-seat voting remains disabled for the first release.

## Frozen tally evidence

Tallying requires formally closed voting, current management/tally permissions
and successful reconciliation. The election UPDATE lock serializes tally with
closure, declaration and another tally. A draft position attachment also acquires
this lock before checking its phase. Existing outcome rows are never overwritten
to make a tally pass. Successful tally commits result rows, a single snapshot,
the phase update and an administrative audit record atomically.

The snapshot freezes candidate identities, configured offices/scopes, supported
rules, school timezone, academic scope, accepted aggregate counts, ranks, statuses
and result-row IDs. Nominee learner IDs identify candidates, never ballot casters.
Snapshot payloads have no voter-registry IDs, individual ballot IDs, vote times,
marker hashes, classroom sessions or per-vote audit links. Result API resources
and declaration-check responses remain allowlisted.

The canonical SHA-256 digest is a consistency checksum, not a digital signature,
cryptographic election proof or protection against an unrestricted database
operator who changes all sources. Candidate/configuration and accepted counts
are independently recomputed when verifying stored evidence. Instance-model
updates/deletes are blocked for tally results, snapshots and declarations.
Privileged SQL and bulk query-builder writes can bypass model events; direct
SQL in tests simulates corruption and is not an authorized repair workflow.

## Declaration gates

Publication requires management, publication and tally-inspection permissions,
rechecked after the election lock wait. Inspection is also checked by the shared
reconciliation service. Every active position must have a fully resolved outcome
within its seat limit. Reconciliation, snapshot membership/checksum, current
candidate/configuration/counts and every stored result row must still agree.
Changed votes, candidate identities, missing zero-vote rows, changed ranks,
extra winner flags, stale configuration or missing evidence block publication.

The whole election is declared together. A resolved office is not appointed while
another office has a cutoff tie. Publication locks referenced leadership offices
in stable order before checking existing active appointments. It does not retire
or overwrite an existing office-holder to free a seat. Other manual leadership
workflows require their own coordination review; no global ERP concurrency claim
is made here.

Appointments, one declaration receipt, the published phase and strict audit share
one transaction. The official start date uses the frozen school timezone.
A partial unique PostgreSQL index prevents duplicate elected appointments for the
same election/office/learner. The receipt binds the snapshot and exact term IDs.
Safe retries validate existing evidence and produce no second tally, appointment,
declaration or audit. Missing/changed appointments are not silently recreated.
Later resignation, replacement, annulment or certificate revocation needs a
separate append-only workflow rather than rewriting this receipt.

GET /api/student-elections/{election}/declaration-checks is authorized and read-only.
It reports the outcome version and per-office gate reasons without voter identity.
It is advisory; publication repeats authoritative checks under its transaction.
There is no client parameter to select winners or replace totals. Generic phase
transitions cannot mark an election tallied or published.

## Adoption and verification

The forward migration adds tally/declaration evidence tables and elected-term
uniqueness. Existing duplicate appointments require reviewed adoption. Legacy
tallied/published elections without snapshots are not automatically certified,
retallied or redeclared. No voting history is deleted and rollback deliberately
requires a reviewed forward migration.

Previous local verification passed 72 tests and 471 assertions. New unit, service,
HTTP and independent-worker tests cover cutoff ties, zero turnout, unopposed and
multi-seat blocks, immutable evidence, corruption, seat occupancy, whole-election
blocking, audit rollback and idempotency. Three new worker cases require actual
pg_blocking_pids evidence for competing tallies, competing declarations and
declaration waiting for tally completion. Their runtime results remain pending.

Open release gates remain: linked-round runtime verification, independent review workflow,
applications and guardian consent, annual entitlement rules, classroom stations,
attendance, one-use approvals, complete-ballot submission/abstentions, throttling,
observability privacy review, certificates and full backend regression. No real
election, scheduler activation, deployment or cryptographic anonymity is claimed.
