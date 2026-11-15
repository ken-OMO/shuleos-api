# Student Elections: linked rounds and confirmation

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Date: 12 November 2026
Policy: single-seat-resolution-v1
Status: locally verified; real-school release remains blocked.
Release: blocked for real-school elections

## Approved rules

- Only candidates tied at the winning cutoff enter a runoff. An original election
  remains tallied while its separately stored linked rounds resolve outstanding
  offices. Resolved offices and their original tally rows are preserved.
- Every later round uses the original eligible electorate, including eligible
  learners who did not participate in the first poll. Eligibility is frozen at
  first opening. Each later ballot requires current finalized daily attendance,
  fresh short-lived authorization and an active linked learner identity.
- Repeated cutoff ties require another linked runoff. Neither UUID order nor an
  administrator selects the winner. An unresolved seat blocks the whole election.
- An unopposed candidate requires Approve/Reject/Abstain confirmation. Approval
  requires at least one approval and strictly more approvals than rejections.
  There is no quorum. A single approval with no rejection therefore confirms.
  Abstentions do not count for either side. A tied or rejected confirmation does
  not appoint a leader; new nominations require a separate controlled workflow.
- Zero participation never elects anyone. A linked repeat poll can be scheduled
  for a zero-turnout contested outcome, or another confirmation for a zero-turnout
  unopposed outcome. Abstention-only contested voting also leaves the seat unfilled.
- Multi-seat voting remains disabled for the first release. Every active required
  office must be resolved before whole-election declaration.

These rules supersede earlier documentation that described the runoff electorate
or unopposed policy as undecided. They do not enable an unfinished classroom UI.

## Evidence and lifecycle

Original results and tally snapshots are never updated by a resolution round.
Each round freezes its parent, sequence, original tally digest, office, qualifying
candidate identities, original electorate digest, one school-local voting day and
policy version. One child per parent and one sequence per office prevent forks.
A pending round must finish before another can be scheduled. Root election locks
coordinate scheduling, closure, tally and declaration. Root SHARE locks permit
independent voters; a per-learner registry UPDATE lock prevents duplicate ballots.

Scheduled -> open -> closed -> tallied are the only round phase transitions.
Opening uses the current server time. Acceptance is checked after blocking locks
using the half-open interval [open, close). Administrative closure can be early;
accepted transactions finish before closure commits. A closed round cannot reopen.
There is no generic phase parameter that bypasses tally or chooses a winner.

Tally reconciles original membership, participation and anonymous ballot shapes.
An immutable outcome checksum freezes aggregate counts, abstentions and statuses.
A retry verifies the existing outcome and creates no second tally audit. Creation,
phase changes and tally each commit with strict administrative audit. Any audit
failure rolls back that mutation. No per-vote candidate audit record is created.

Declaration re-verifies the original evidence and every linked definition/outcome,
then binds the complete effective resolution chain in the declaration checksum.
Changed membership, candidates, definition, totals or lineage block publication.
The ordinary seat/occupancy/permission gates and exact appointment receipt remain.
Result reads identify original versus resolution-round counts; totals from different
polls must not be summed as one poll. Eliminated nominees retain original history.
A declaration retry never repairs missing terms or silently rewrites evidence.

Checksums are unkeyed consistency checks, not signatures or protection against an
operator who can rewrite every database source. Direct SQL can bypass application
write rules; no privileged-operator-proof or cryptographic anonymity is claimed.

## Attendance and authorization boundary

The internal approval primitive requires current management plus voter-management
permissions. It accepts only a learner in the frozen registry and finalized daily
attendance from the same school, learner, current grade/stream and school-local
date, with PRESENT or LATE status. Lesson-only, draft, deleted and stale evidence
is refused. Casting rechecks the attendance under register/record SHARE locks,
the approving officer's access, learner access, the approval expiry and the poll
window after coordination waits. A replacement approval invalidates its predecessor.

Approval secrets are random, stored only as hashes, expire after two minutes and
are consumed in the participation transaction. Consumed approval identity, expiry
and attendance references are cleared. Ballots contain no learner/user/registry/
approval/attendance reference and no creation/cast timestamp. No ballot ID is
returned. Pending named approval metadata and privileged traces remain within the
previously documented anonymity threat-model limitations.

Approval and casting are INTERNAL primitives: there are no new learner or teacher
HTTP casting/approval routes. This is not the finished class-teacher device flow.
Classroom station binding, delegated class-teacher checks, throttling, complete
ballot submission and privacy-safe operational logging remain release gates.
The ordinary learner voting endpoint remains disabled. Do not conduct real polls.

## Administrative HTTP surface

- GET /api/student-elections/{election}/rounds: management and tally permissions.
- POST the same path: management, voter-management and tally-inspection permissions;
  body election_position_id, opens_at and closes_at only, explicit ISO-8601 offsets.
- POST /api/student-elections/{election}/rounds/{round}/phase/{open|closed}:
  management, voter-management and tally-inspection permissions.
- POST /api/student-elections/{election}/rounds/{round}/tally: management and tally.

JWT, tenant and module middleware apply; services recheck current access after
lock waits. Responses are allowlisted and never serialize the frozen electorate,
approval secrets or attendance evidence. Per-candidate round outcomes are returned
only after tally to authorized officers. Existing learner results remain limited
to published elections and authorized office scopes.

## Adoption and verification

The forward migration adds original-electorate, linked-round, round-registry and
anonymous-round-ballot tables plus a declaration-chain checksum. It does not erase
votes or backfill an electorate from a poll already opened. Earlier local test
polls with no pre-opening freeze require reviewed adoption or a new election;
the service deliberately refuses retrospective certification. Rollback requires a
reviewed forward migration. No real school or subscription is activated.

New tests cover confirmation arithmetic, repeated ties, no turnout, original
membership, preserved offices, frozen evidence, current attendance, approval use,
permission/tenant HTTP isolation, retries and audit rollback. Independent PostgreSQL
workers cover competing scheduling, parallel voters, duplicate approval use,
vote/closure in both orders, competing tallies, publication waiting for tally and
approval expiry after a verified registry wait. Results are pending until the
installer is run on the isolated database. Full backend regression is also pending.
