# Student Elections classroom stations

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Date: 12 November 2026
Status: locally verified; real-school release remains blocked.
Release: blocked for real-school use; feature disabled by default

## Classroom contract

A school manager registers labelled stations against a concrete class-teacher
assignment. The assigned teacher issues a one-use pairing ticket, valid for two
minutes. The classroom browser activates it once and receives a Secure, HttpOnly,
SameSite=Strict device cookie. A teacher JWT is never installed in the voting
browser. Device secrets and pairing tickets are stored only as random-token hashes.
The device credential expires at the earlier of six hours and the school-day end.
Rotation invalidates the prior credential and requires clearing active requests.

The learner enters an admission number on that browser. Lookup is restricted to
its school, grade and stream; it is rate limited. The teacher's class queue shows
only pending/approved requests with names and admission numbers needed to identify
learners. A teacher cannot approve merely because a role says Teacher or Admin:
operate_student_election_stations and the current, active class-teacher assignment
are both required. Its teacher profile, school, grade, stream, academic year and
current dated active term must agree. The new permission is not auto-granted.

Before approval, the device receives waiting status only. Approval requires a
current finalized daily register with PRESENT or LATE status for that learner and
current classroom. It freezes the complete ballot and expires after two minutes.
Submission rechecks the approving teacher, assignment, learner identity, presence,
unused registry rows, frozen definition, device expiry and poll window. The last
authority check takes an assignment SHARE lock, preventing its revocation from
overtaking a vote that has passed that check. Access is rechecked after lock waits.
Existing active learner-account and portal eligibility requirements are retained;
there is no learner-password or ordinary learner-JWT voting bypass.

A reset cancels outstanding requests and frees a device, but never undoes accepted
participation. Revocation invalidates pairing and device credentials and cancels
outstanding requests. These administrative changes and approvals have strict
transactional audit. Audit failures roll them back. No per-vote choice audit is made.

## Complete ballot and counting

Each eligible office requires exactly one explicit answer. A contested single-seat
office permits one candidate selection or Abstain. An unopposed office permits
Approve, Reject or Abstain; it confirms only with at least one approval and more
approvals than rejections, without quorum. Missing/repeated offices, a candidate
from another office, hidden selections inside abstentions and arbitrary fields
are rejected. At most 100 offices are supported in one complete ballot.

All anonymous envelopes, participation changes and session consumption commit in
one transaction. Any failure rolls back every position. There is no shared ballot
batch ID, session/device reference, registry ID or cast timestamp on ballots.
Insertion order is randomized; this is metadata minimization, not a cryptographic
anonymity proof. Completion returns only Your vote has been recorded. The session's
learner, approving user, attendance, expiry, definition and checksum are then
cleared. The device returns to free status and cannot recover the submitted ballot.
The teacher dashboard exposes participation counts and pending requests, never
candidate totals or individual completed choices.

New elections created through the shared service use classroom_single_seat_v1.
Its school timezone is frozen on first opening, and original voting must fit one
school-local day. The previous internal position-at-a-time primitive refuses this
method. The ordinary learner HTTP vote endpoint remains disabled.

Existing election rows retain legacy_single_selection, their original evidence,
and the prior internal counting primitive; an opened legacy poll is never silently
converted. A legacy unopposed poll still needs its separate linked confirmation.
New classroom unopposed offices can confirm in their original ballot, avoiding a
redundant second poll. The original ballot table gains explicit choice shapes and
nullable candidate identity for abstention/confirmation. Reconciliation checks
participation = envelopes = valid selections + approvals + rejections + abstentions.
No turnout or abstention-only voting does not invent a winner.

After original tally, the same classroom flow supports the latest open linked
round for each unresolved eligible office. Resolved offices are excluded. Every
unresolved eligible office must have its current round open before a complete
resolution ballot can be issued. The original electorate and linked ancestry
checks remain. Partial earlier participation requires review rather than silently
voting the remaining positions. Tally/declaration repeat the existing integrity,
seat, checksum, audit and appointment gates. Multi-seat voting remains disabled.

## Browser and network boundary

STUDENT_ELECTION_STATIONS_ENABLED defaults to false. No production enablement is
included. student_election_stations.school_networks defaults to an empty map:
reviewed school UUID => trusted IPv4/IPv6 CIDR list must be configured before any
of these HTTP routes can be used. There is no wildcard network default. Remote
access outside that network is denied even with a browser credential. Proxy trust
must be reviewed so untrusted forwarded headers cannot spoof the network check.

The station browser requires HTTPS outside testing, an exact configured trusted
Origin and the X-Election-Station: 1 header, plus its device cookie after pairing.
This rejects simple cross-site forms; it does not replace deployment review of
CORS, TLS and trusted proxies. The API origin may be set explicitly using
STUDENT_ELECTION_STATION_ORIGIN. Cookies are host-only and scoped to
/api/election-station. New teacher routes also require TLS and the trusted network,
JWT, tenant isolation and explicit action permission. No broad election-management
permission is granted to class teachers.

Limits are applied per browser credential rather than aggregating all valid
school devices behind one NAT IP. Admission lookup has an additional limit of five
attempts per station per minute after device authentication. Pairing issuance is
also limited. Nonpersistent array/null limiter stores and enabled Telescope are
refused outside the permitted test configuration. These are controls, not measured
throughput evidence. A deployment must use a suitable persistent/shared cache.

Logical device binding means possession of the registered browser credential.
It is not hardware attestation: a copied cookie, browser compromise, a compromised
teacher account, a school VPN or an unrestricted server/database operator remains
in the threat model. No strong cryptographic ballot anonymity is claimed. Browser
and server deployment must disable request-body, cookie, SQL-binding, tracing and
analytics capture for these routes. The boundary emits generic unexpected errors
without the original exception chain and returns no-store headers on handled
responses, but cannot control an upstream proxy or separately installed recorder.

## HTTP integration

Management:
- POST /api/student-elections/{election}/stations: teacher_assignment_id and label;
  management and voter-management permissions are checked by the service.
- POST /api/student-elections/{election}/stations/{station}/revoke: management.

Assigned class teacher, requiring operate_student_election_stations:
- POST /api/student-elections/{election}/stations/{station}/pair.
- GET /api/student-elections/{election}/stations/classrooms/{assignment}.
- POST /api/student-elections/{election}/stations/{station}/sessions/{session}/decision:
  allow boolean and attendance_id when approving.
- POST /api/student-elections/{election}/stations/{station}/reset.

Voting browser, with trusted Origin and X-Election-Station: 1:
- POST /api/election-station/activate: station_id and pairing_token; credential is
  issued only as the HttpOnly cookie, never in the JSON body.
- POST /api/election-station/request: admission_no.
- GET /api/election-station/state: waiting status, or approved sanitized ballot.
- POST /api/election-station/submit: session_id, definition_digest, ballots array
  with slot_id, choice and nullable candidate_id. Candidate fields are allowlisted.

Ballot candidate data contains only candidate IDs and display names; it does not
show voting-day manifestos, private photo paths or nominee learner IDs. The frontend
must render labels safely, preserve cookie
credentials for same/trusted-origin requests, discard local answers after success
and return to a blank admission screen. No frontend UI is delivered by this patch.

## Adoption and verification

The forward migration retains existing evidence and adds explicit original ballot
choice shape, a frozen-method marker, stations, ephemeral named sessions and the
new explicit permission. Original electorates are still frozen only before first
opening; no retrospective voter certification is attempted. Rollback requires a
reviewed forward migration. No real-school grants, activation or pricing changes
are performed.

The prior local verification passed 150 Elections tests and 1,523 assertions. New
pure policy, service, HTTP and independent-worker cases are prepared. They cover
atomic submission/rollback, scopes, presence, binding, network/origin controls,
confirmation, abstention, expiry, reset, counting and concurrency. The native PHP
syntax and standalone policy checks do not substitute for these Laravel/PostgreSQL
runs. A full backend regression and scoped static analysis remain pending.

Other release gates remain: nomination terms and guardian consent, independent
review and cancellation policy, annual Elections entitlement/billing, certificates,
observability/deployment privacy review, frontend security and operational readiness.
Do not conduct real-school elections or enable this feature from this patch alone.
