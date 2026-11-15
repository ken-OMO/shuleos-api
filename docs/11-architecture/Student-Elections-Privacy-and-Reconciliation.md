# Elections privacy and reconciliation contract

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Date: 10 November 2026
Status: locally verified; real-school release remains blocked.
Release: blocked for real-school elections

## Privacy claim and limits

The application must not expose an individual learner's choices to a school
administrator, deputy, teacher, guardian, other learner or ordinary reporting
account. Named participation is separate from secret selections. There is no
application ballot browser, personal choice history or choice-identifying receipt.

Ballots retain independent UUID v4 identifiers and independent random uniqueness
markers. They contain no voter, learner, actor, device, authorization, request,
session or whole-submission identifier. The schema omits cast and creation times.
Participation has no voted_at or voting-token field; its model does not update
updated_at when accepting a vote. Registration times are not vote times and
electorate generation is frozen before voting.

The random marker is not an authentication credential, receipt or proof of
anonymity. It is never copied into the named registry. Vote receipts contain only
confirmation and election/position identifiers. Models refuse changes to accepted
ballots, but privileged SQL can bypass ORM controls. Database role separation and
operational access review remain deployment requirements.

This does not protect against an unrestricted server/database operator, SQL
logging with bindings, APM/request tracing, WAL/transaction IDs, backups, physical
row ordering, a compromised classroom station, teacher observation, coerced
voting, or inference from small/unanimous electorates. Atomic writes themselves
can be correlated by a privileged transaction observer. Timestamp removal does
not solve that threat. Stronger protection against privileged operators requires
a separately reviewed cryptographic or mixing protocol; none is claimed here.

Before stations are enabled, verify exclusion/redaction of choice payloads and
credentials across proxies, application logs, exception reports, Telescope,
Nightwatch/Pulse, APM, traces and exports. Administrative audit must not capture
ballot IDs, choices, marker hashes or per-vote correlation tokens. Keep named
classroom progress separate from candidate totals. No candidate totals are shown
during voting; reconciliation is restricted to authorized staff after closure.

## Rollout and existing evidence

The forward migration takes transaction-held PostgreSQL table locks in election,
registry, ballot order. It refuses an open poll, any ballot, any marked
participation, or legacy vote-time/token data. It never deletes voting history to
make migration succeed. Existing installations with evidence need a reviewed
adoption plan, preservation rules and maintenance window. Metadata cannot be
reconstructed by rollback; use a reviewed forward migration. The original schema
migration is retained and the forward migration applies afterward on fresh builds.

## Current internal primitive

The current primitive records one candidate selection for one position. It is
not a complete classroom ballot and has no explicit abstention representation.
Only single-seat reconciliation is supported in this slice. An unsupported
multi-seat configuration is reported as blocked, not assigned an invented rule.

For each attached position p:

- Pp = named position registry rows marked as having voted.
- Bp = every stored ballot row attributed to that position, including invalid rows.
- Sp = structurally valid candidate selections in the same school/election/position.
- Required current equation: Pp = Bp = Sp, with no integrity errors or unassigned rows.

Do not exclude invalid rows merely to make totals match. A ballot without a
participation, participation without a ballot, cross-parent association, changed
candidate identity/status, or malformed registry is an integrity problem.
Inspection reports counts, deltas and issue codes; it never repairs records.

The report's learners_with_any_position_participation counts distinct learners
with any registry participation. It is not the number of completed whole ballots.
Zero equals zero mathematically; reconciliation alone never appoints an unopposed
or zero-turnout winner and never resolves a tie.

All count aggregates use one PostgreSQL statement/MVCC snapshot. The application
holds the election coordination lock during inspection. Both staff HTTP access
and direct service access require the school context, management permission and
tally permission. No named registry rows, ballot identifiers, hashes, choices,
vote timestamps or per-candidate breakdown leave this report.

The normal tally and publication services require reconciliation to pass and hold
the exclusive election lock. Generic lifecycle transitions cannot mark an election
tallied or published. Seat-limit/tie resolution and result-version validation are
still separate incomplete gates: passing arithmetic does not authorize declaration
or establish election fairness. Existing production release remains blocked.

## Future complete-ballot semantics

A station submission must atomically cover all positions for which that learner
is eligible, marking a choice or an explicit abstention for every position. Do not
add a voter-linked ballot batch ID or a common grouping key across secret positions.
Retry/confirmation state may identify the learner, never their selections.

For each position in the future representation:

- Pp = named participations for that position.
- Bp = anonymous position envelopes.
- Vp = envelopes containing selections; Ap = explicit abstention envelopes.
- Pp = Bp = Vp + Ap.
- Single-seat selection count Sp = Vp.
- If an up-to-K multi-seat method is approved, Vp <= Sp <= K*Vp, with no duplicate
  candidate in an envelope and every envelope obeying the approved selection rule.

The multi-seat method, electorate freeze, runoff electorate, no-turnout and
unopposed outcome policies remain open. These equations define required meanings,
not an implemented envelope schema. A whole-ballot completion count must come
from complete named participation coverage, not the sum of selections or the any-
participation count. No station endpoint is introduced by this slice.

## Verification and outstanding work

Previously passed locally: 53 Elections tests, 372 assertions, including eight
independent PostgreSQL worker scenarios. This slice adds metadata, aggregate,
authorization and gate tests and reruns those suites. Its runtime results remain
pending. Native syntax and SQL parsing are not PostgreSQL execution evidence.

Outstanding release gates include fairness/ties, immutable result versions,
nomination/guardian consent, complete station authorization, attendance, one-use
sessions, throttling, observability privacy review, strict administrative audit,
annual entitlement policy and full backend regression. No throughput or stronger
cryptographic anonymity claim is made.

## Prepared classroom envelopes — 12 November 2026

For new classroom_single_seat_v1 elections, an envelope is a contested selection,
unopposed approval/rejection, or explicit abstention. Reconciliation requires
participation = ballot rows = valid selections + approvals + rejections + abstentions.
Legacy polls remain selection-only. Registry, session and device identities are
never ballot fields. Named session evidence is cleared on consumption. See
Student-Elections-Classroom-Stations.md for the prepared atomic submission,
network/authorization controls and remaining privileged-observer limitations.
Runtime verification of this extension remains pending.
