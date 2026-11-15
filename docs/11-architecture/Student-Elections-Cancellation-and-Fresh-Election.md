# Reviewed cancellation and fresh linked election

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Status: locally verified; real-school release remains blocked.

## Authority and evidence

After voting has first opened, a recorded application integrity incident and an active hold are prerequisites. An officer with both manage_student_elections and publish_student_election_results proposes cancellation with a reason. A different officer with those same fresh permissions approves or rejects the exact incident fingerprint. Both explicitly attest that they are independent. Candidate self-review and known candidate-family relationships are refused. Undeclared relationships cannot be inferred by the software; the school remains responsible for truthful attestations.

A pending proposal does not clear the hold. Rejection also leaves the hold in place; there is no automatic resumption. New incident evidence makes an earlier proposal stale and requires a fresh proposal. Decisions, proposals and replacement links are append-only. One approved cancellation and one replacement per original election are enforced by database uniqueness.

The root UPDATE lock serializes proposals, decisions and replacement creation with existing voting, closure and publication locks. Mutations and strict audit share the same transaction. An audit error rolls the transaction back.

## Cancellation

An approved decision changes the original status to cancelled and retains its hold. The original election cannot resume, including through an ordinary SQL status update. PostgreSQL refuses post-opening cancellation without an approval receipt. Database credentials with authority to disable triggers remain outside the application threat model.

Original ballots, applications, consent, review events, electorates, linked voting rounds, tally snapshots and declaration receipts are retained. No voter-to-ballot linkage or ordered ballot export is created for cancellation review. Cancellation history is restricted to authorized officers and uses no-store responses.

Appointments from the original election are retained. Active appointments become annulled so they no longer occupy a seat. The immutable decision stores the complete original appointment rows before that projection changes. Original declaration receipts and their term identifiers remain unchanged. The cancelled parent removes the original council from ordinary published-council views.

## Fresh linked election

Only a confirmed cancellation can receive a replacement link. A future voting window with explicit timezone offsets, title and same-school academic-year/term references must be supplied. The new election has a new identity, draft status, versioned applications and classroom ballots.

Nothing is carried forward: no positions, applications, terms acceptance, guardian designations, consent, candidate approvals, electorate, ballots, tally or declaration. School officers explicitly configure current positions and publish a new policy before fresh applications. The immutable link identifies the original election and cancellation decision. A second concurrent replacement attempt is rejected.

A replacement can later be cancelled and replaced through the same reviewed procedure; the complete chain remains identifiable without rewriting its ancestors.

## HTTP

All routes require JWT authentication and tenant middleware, and are throttled.

- GET /api/student-election-cancellations/{election}: restricted review history.
- POST /api/student-election-cancellations/{election}/proposals: reason and conflict_free.
- POST /api/student-election-cancellations/{election}/proposals/{proposal}/decision: approve, reason and conflict_free.
- POST /api/student-election-cancellations/{election}/fresh-election: title, academic_year_id, optional term_id, voting_opens_at, voting_closes_at and conflict_free.

History omits raw appointment snapshots and application/guardian contents. It includes the officers' recorded cancellation reasons, which remain administrative evidence rather than public election content.

## Verification and remaining gate

The installer adds service, HTTP, immutability, audit-rollback and separate-session PostgreSQL contention tests. A contention test must observe pg_blocking_pids before releasing the first worker. No runtime result is asserted by this document.

This slice does not activate an Elections commercial entitlement. The agreed next gate is a 12-month activation-based add-on, including enforcement on fresh election creation. Static analysis and full backend regression follow that enforcement. Classroom rollout remains disabled; no real-school activation, payment collection or production adoption is authorized by this installer.

Evidence-bearing migrations refuse down rollback. Isolated synthetic fixture cleanup may disable only the named user evidence triggers while holding ACCESS EXCLUSIVE locks, with foreign keys active. Cleanup refuses other databases and unowned fixtures; trigger DDL and cleanup are transactional. This is test support, not a production deletion API.
