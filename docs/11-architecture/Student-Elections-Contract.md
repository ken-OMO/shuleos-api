# Student Elections Module Contract

> Current Elections follow-up — 16 November 2026: HTTP boundaries, shared photo-read locks, shared commercial evaluation and unsupported-setting validation are locally verified. Full backend: 2,537 tests/35,422 assertions; both scoped analyses and remaining checks passed. Final source review completed. This follow-up is uncommitted and has no CI result; real-school Elections use remains blocked. See [current verification](Elections-Transport-Audit-Repairs.md) and [photo/commercial/settings contract](Student-Elections-Photo-Locks-and-Settings.md).

> Audit follow-up — 16 November 2026: [verified repairs and current delivery limits](Elections-Transport-Audit-Repairs.md). Local full regression passed 2,521 tests/35,273 assertions; both scoped analyses and remaining checks passed. PR #89 merged at 7a718e3dde1317af5250b2c8566399188daa3b27; all ten PR checks passed. Not deployed. Real-school Elections use remains blocked. Voting extension and certificates/PDFs remain undelivered requirements.

## Current verification — 16 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. This uncommitted follow-up has no CI result and does not establish production readiness; PR #89 CI evidence above is historical.

Version: 1.0
Date: 8 November 2026
Status: locally verified; real-school release remains blocked.
Release: blocked for real-school elections
Owner: School Administrator within each school
Target repository: ken-OMO/shuleos-api
Target path: docs/11-architecture/Student-Elections-Contract.md

## Contract interpretation

This is a product and engineering contract, not a legal agreement or a claim of implementation readiness. MUST denotes an agreed invariant. Open decisions remain unresolved and MUST NOT be silently converted into operational defaults. Suggested lifecycle names and proposed multi-seat rules require implementation review. The School Administrator has the highest school-level authority, including delegation and revocation, while remaining subject to ballot integrity, privacy, attendance, lifecycle and audit constraints.

The latest decisions replace earlier alternatives: voting is school-only on authorised classroom devices for one school day; there is no ordinary learner-portal vote endpoint, and the former two-day plan does not apply. The Deputy Head Teacher portal hosts the delegated Elections workspace. The yearly add-on includes regular elections, runoffs and by-elections.

## 1  Purpose and agreed scope

ShuleOS will provide school-managed student elections from applications and guardian consent through classroom voting, automatic tallying, official declaration and publication of the elected council. This document consolidates the agreed product decisions and identifies controls and open decisions before further implementation.

Release status: blocked for real-school elections. This is a planning specification, not evidence that the complete module is implemented, tested or ready for deployment.

### Latest decisions take precedence

- The School Administrator holds the highest administrative authority in the school’s Elections module. The Deputy Head Teacher coordinates operations through their portal under delegated authority.

- Voting takes place at school on authorised devices for one day. Only the School Administrator may extend the active voting window.

- Ordinary learner portals provide applications, candidate information, countdowns and published council information. They do not accept votes.

- Only present learners in the assigned classroom may vote, following teacher verification and approval.

- Each learner applies for one position per election and submits one complete ballot covering their eligible positions.

- A yearly Elections add-on includes regular elections, runoffs and by-elections. Pricing and usage limits remain undecided.

## 2  Authority and school configuration

The module is available to the School Administrator and through the Deputy Head Teacher portal as a delegated Elections workspace. Election Officers use their own accounts. Portal location never substitutes for server-side authorisation.

| Role | Authority and duties |
| --- | --- |
| School Administrator | Full school-level election administration: configure elections, assign or revoke officers, review applications, authorise devices, extend active voting, declare verified results and initiate cancellation or by-elections. |
| Deputy Head Teacher | Coordinate the election and exercise only powers delegated by the School Administrator. |
| Election Officer | Perform assigned nomination, consent, review, device, reconciliation, declaration or reporting duties. |
| Classroom Supervisor | Verify learners and approve sessions only for assigned classes and devices. View participation, never choices. |
| Learner and guardian | Learner applies and votes through permitted channels. An authorised guardian consents to their child’s candidacy and views relevant updates. |

Administrative authority does not include changing accepted ballots, calculated vote totals or selected winners, bypassing attendance or duplicate-vote checks, or revealing individual choices. The School Administrator remains subject to integrity controls and an audit trail. No school role gains another school’s access.

### Positions and education levels

Schools define position names, duties, representation, seats and leadership terms. Each position separately specifies who may apply and who may view candidates and vote. Supported representation includes school, configured education level, grade and stream.

Level separation is optional. A school may run separate Primary and Junior councils, shared positions across specified levels, or a mixture. Visibility and eligibility must be enforced by the API as well as the interface.

### Published configuration

Publish application, consent, review, appeal, campaign and voting deadlines in the school’s timezone. Publish terms, voting method, seat counts, tie rules, cancellation rules and representation before voting. Freeze electoral configuration before opening ballots; later changes require a controlled exceptional process.

## 3  Applications consent and review

### One application per learner

The learner selects one eligible position, enters a slogan and manifesto, reviews their existing profile photo and accepts versioned application terms. Enforce one active application across the whole election, including simultaneous requests. Drafts and submitted applications are private.

Before the application deadline, a controlled withdrawal and replacement process may permit a position change. Preserve the original record and obtain fresh consent for the replacement. The detailed replacement policy must be finalised before implementation.

### Profile photo and campaign content

Fetch the photo from the learner profile rather than requesting a separate election upload. Use only a clean, authorised image belonging to the same school and learner. Record the approved image and manifesto versions so later profile edits cannot silently change election material. Missing photos prompt a profile update; whether a photo is mandatory remains a school-policy decision.

### Guardian consent

An authorised guardian receives the application, position, duties, manifesto, photo version, terms and deadlines in their parent portal. Consent is required before approval to vie. Record acceptance or refusal against the exact application and terms version. Material changes require renewed consent and review.

Staff cannot impersonate guardian consent. Multiple linked guardians require an explicit consenting-authority rule. Lack of portal access needs a documented resolution procedure; it does not justify bypassing consent. Consent relates to candidacy and approved publication, never disclosure of the learner’s vote.

### Board review and appeals

Election Officers or the School Administrator verify published eligibility, consent and content. Decisions record the actor, reason, time and version. Conflicted officials recuse themselves. Appeals go to a different authorised reviewer before candidates are finalised. Approval and rejection updates appear privately in learner and guardian portals.

| State | Meaning |
| --- | --- |
| Draft | Application being prepared |
| Awaiting consent | Submitted and waiting for guardian decision |
| Awaiting review | Consent received and review pending |
| Changes requested | Revision needed with renewed consent when material |
| Approved or rejected | Recorded review decision |
| Withdrawn or expired | Application no longer proceeds |

## 4  Learner portal and classroom preparation

| Election phase | Ordinary learner portal |
| --- | --- |
| Applications | Available positions, terms, application form and private status |
| Campaigning | Approved candidate photos, names, slogans and manifestos; countdown to voting |
| Voting opens | Countdown disappears; notice says Voting is today. Go to your classroom voting station. |
| After submission | Participation confirmation only in the election area; no personal choice history |
| Voting closed | Voting has closed. Awaiting official results. |
| Official declaration | Candidate gallery and manifestos disappear; Our Student Council replaces them |

Candidate information remains readable during voting for learners who have not submitted. The submitted learner’s election area shows only confirmation until publication, consistent with the agreed post-submission restriction. No ordinary learner-portal route may cast votes.

### Authorised classroom devices

Assign supervisors to classes and register labelled school devices such as Device A, Device B and Device C. Devices open a restricted election station rather than a learner’s full account. Admission numbers are interpreted inside the authenticated school and assigned classroom context.

### Marked attendance is mandatory

Require the current voting day’s officially marked attendance register. Present learners may request approval. Absent learners, unmarked registers and wrong-class requests are blocked. Verify attendance at access request and at final submission. Attendance corrections require existing attendance authority and an audit record; election supervision does not grant attendance-editing rights.

### Supervisor dashboard

Show learner approval requests with name, profile photo, class and device label. Display Free, Awaiting approval, In use and Offline states. Separate voted, present not voted, absent and unmarked counts. Example: 40 eligible, 23 voted, 12 present not voted and 5 absent. Teachers see participation, never candidate selections or candidate totals.

Several devices and classes may operate simultaneously. Named participation must remain separate from ballots and must not expose precise ballot identifiers or selection-related records.

## 5  Voting and closing the poll

### Identify and request

The learner enters their admission number on a classroom device and sees Waiting for teacher approval. The server verifies school, class, attendance, eligibility and previous participation.

### Verify and authorise

The assigned teacher physically checks the learner against the name and photo and clicks Allow Voting. Authorisation is short-lived, single-use and tied to that exact device session. Admission number alone is insufficient.

### Complete and review

The ballot opens automatically on the same device. Show only eligible positions. Require a selection or explicit abstention for each position, then let the learner review the complete ballot.

### Submit once

Commit all selections and participation updates atomically. Double-clicks, retries, concurrent devices and repeated authorisation cannot create another vote. A learner may not hold two active voting sessions.

### Confirm and clear

After a successful server commit show Your vote has been recorded. Hide selections and do not issue a receipt identifying choices. Mark participation as Voted on the teacher dashboard. Clear the session before marking the device Free.

### Handle interruption

Do not claim success before server confirmation. After a timeout, safely check participation before retrying. Offline devices show Offline, never Free. Do not introduce offline ballot storage without a separate design.

### One day with controlled extension

The election runs for one published school day. Only the School Administrator may extend an active window, recording the reason and new deadline. Update station and portal notices. Preserve prior votes. If continued to another day, require that day’s attendance. After formal closure or tally start, ordinary extension is unavailable; exceptional reopening needs a separately approved design.

### Close and tally automatically

Use a precise server-side cutoff and a documented concurrency rule. A submission must hold the required election coordination lock and recheck the deadline before acceptance. Closure waits for already accepted transactions; waiting or late submissions that fail the cutoff are rejected. Tally only after accepted writes are resolved. No candidate totals are visible to any school user during voting.

## 6  Results runoff cancellation and council

### Reconcile and declare

Automatically reconcile participation with accepted ballots for each position. Record abstentions separately from candidate votes. Include zero-vote approved candidates. For single-seat plurality, the highest count wins. For multiple seats, the proposed method permits up to the seat count with one selection per candidate; this method must be explicitly confirmed and tested before use.

Authorised officials see read-only results after closure. Enable Declare Results only when reconciliation, seat limits, required review and winning-cutoff tie checks pass. The School Administrator holds declaration authority and may delegate it to an Election Officer. Declaration publishes the verified outcome and records the actor. No manual winner selection or total editing is permitted.

### Runoff

A winning-cutoff tie blocks declaration of the election under the current whole-election publication rule. Create a linked round for tied candidates and unresolved seats, preserving already resolved outcomes. Use the defined electorate, fresh classroom approvals and current-day attendance. The exact electorate-freeze policy must be finalised. Never enlarge seats to accommodate a tie.

### Cancellation and annulment

Correctable problems block progression rather than automatically cancelling. Serious requirement failures or substantiated violations may trigger a cancellation request. Record evidence, reason, authority and notices; stop voting and block declaration while preserving records. A replacement election links to the cancelled election.

The School Administrator has final school authority. Independent second-person approval for cancellation is recommended but remains an explicit policy decision; delegated officers cannot self-approve cancellation. After declaration, use a separate annulment and certificate-revocation process rather than ordinary cancellation.

### By election

A vacancy following departure, resignation, removal or an unfilled seat creates a linked by-election for that position only. Repeat the relevant application, consent, review and voting stages. The winner serves the remaining term by default. Preserve the original ballots and declaration.

### Published Student Council

Replace the candidate gallery and manifestos with Our Student Council after declaration. Display approved name, photo, position, representation and leadership dates according to school visibility settings. Keep manifestos in a restricted archive. A by-election updates only the affected position. Preserve former councils and record resignations, removals and replacements without rewriting election history.

## 7  Certificates reporting and subscription

### Automatic certificates

Generate winner certificates after official declaration. Include school branding, approved learner name, optional approved photo, position, representation, leadership term, election title, declaration date, unique certificate number and authorised signature or stamp configuration. A QR code verifies the certificate with minimal disclosure; public verification is optional.

Winners and authorised guardians download certificates through their portals. Administrators download individual files or a combined print-ready PDF. Jobs must retry without duplicate certificates. Corrections create audited replacements; revoked or superseded certificates remain traceable. Certificates for runoff or by-election winners reference the appropriate round and term.

| PDF output | Audience |
| --- | --- |
| Election notice and timetable | Relevant school community |
| Approved candidate booklet and manifestos | Configured eligible audience before declaration |
| Applications consent and reviews | Restricted election officials |
| Attendance and voter readiness | Restricted authorised officials |
| Official results and declaration | Published audience after declaration |
| Reconciliation and administrative audit | Restricted reviewers |
| Runoff cancellation and by-election reports | Audience appropriate to the record |
| Council directory and certificate register | Council viewers or authorised administrators as applicable |

Reports must use consistent result versions and enforce school and level restrictions. Do not include voter-to-choice links, ballot secrets, private file paths or unnecessary learner identifiers. Preserve archived campaign reports without keeping them visible in the ordinary learner portal after declaration.

### Yearly add on

Provide a yearly Elections entitlement within ShuleOS, excluded from Basic by default. Include the limited learner and guardian portal functions needed for applications, consent, candidate information and certificates even where other portal features are not purchased. Regular elections, runoffs and by-elections belong to the module. Price, election limits and commercial year boundaries remain undecided.

Enforce subscription rules server-side. Expiry blocks starting new elections while permitting safe completion of already-open work and authorised access to historical records. Define the precise continuation boundaries before implementing expiry enforcement.

## 8  Hardening delivery and open decisions

### Integrity privacy and speed

Explicit API Resources must allowlist response fields. Enforce tenant and parent-child ownership across every endpoint and database relationship. Retain duplicate-vote constraints; use transaction-held locks that permit different learners to vote concurrently while coordinating safely with closure. Record administrative actions in the same transaction as the mutation.

Participation records may identify learners but must not contain candidate choices, ballot IDs or shared secrets. The threat model must cover timestamps, logs, traces, request correlation, record ordering, WAL, backups, named classroom updates and privileged database access. Removing timestamps alone does not guarantee anonymity. School administrators cannot access personal choices through the application; unrestricted server access remains a distinct threat.

Optimise indexed queries, bounded transactions, photo loading and station updates. Run PDFs, certificates and notifications outside vote transactions. Test shared-network throttling so a school connection is not unfairly blocked. Measure submission, closure, tally and document performance; no estimated throughput is a benchmark.

### Delivery sequence

- Finish privacy Resources, school isolation and HTTP authorisation tests.

- Implement configuration, officer assignments, yearly entitlement boundaries and lifecycle rules.

- Implement one-position applications, profile-photo versions, guardian consent, review and appeals.

- Implement attendance-gated stations, device sessions, teacher approval and complete-ballot submission.

- Implement closure, automatic reconciliation and tally, runoff, cancellation and declaration.

- Implement council publication, by-elections, certificates, PDFs and notifications.

- Verify PostgreSQL permissions, fairness, rollback, actual lock contention and full regression before readiness.

### Open policy decisions

Confirm multi-seat ballot rules; consenting guardian authority and access recovery; mandatory-photo policy; cancellation second-person approval; appeals timing; runoff electorate; exceptional reopening; commercial year boundaries and usage limits; certificate verification visibility; and retention periods. These must not be silently treated as implemented policy.

### Verified baseline and working rules

Confirmed baseline main: e2135bf6132920e4859dac1842ed0a081ee1fa94, Transport PR 85. Local branch: hardening/student-elections-integrity. Existing Elections baseline passed 3 tests and 8 assertions. Candidate Resource and scoping patch was installed and linted; the expanded six-test suite has not been reported as run. No further implementation readiness is implied.

Test only on shuleos_assessment_fk_test; never shuleos. Keep schedulers disabled and restore process settings. Provide Windows PowerShell scripts without rg; write generated PHP to UTF-8 files and lint before execution. Use a 1 GB PHP limit for full regression. Do not commit, push, merge or deploy without instruction. Finance fk_pa_invoice_item schema verification and FinancePaymentService coverage remain separate unconfirmed items.

## Acceptance gates

The following tests are required evidence. None is marked as passed merely because it appears in this contract.

| ID | Required evidence |
| --- | --- |
| ELC-01 | Cross-school reads, writes, relationships, files and exports are rejected for every role, including school administrators. |
| ELC-02 | Optional level boundaries and separate applicant/voter scopes are enforced through HTTP and service operations. |
| ELC-03 | Simultaneous applications cannot produce more than one active position application per learner per election. |
| ELC-04 | Missing, declined, stale or unauthorised guardian consent blocks candidate approval; material changes require renewed consent. |
| ELC-05 | Only approved public candidate fields appear; private notes, identifiers, file paths and secrets do not leak. |
| ELC-06 | Ordinary learner sessions, unregistered devices, absent learners, unmarked registers and wrong-class requests cannot cast votes. |
| ELC-07 | Teacher approvals are scoped, expiring and device-session-bound; replay and two-device attempts fail safely. |
| ELC-08 | One complete submission commits atomically; failure rolls back ballots and participation; retries cannot duplicate votes. |
| ELC-09 | Independent PostgreSQL sessions prove actual contention and correct outcomes for vote/vote and vote/close races in both orders. |
| ELC-10 | Teacher screens expose participation only, clear devices safely and never mark offline or uncleared stations Free. |
| ELC-11 | Only the School Administrator extends active voting; closure/tally blocks ordinary extension and cross-day attendance is enforced. |
| ELC-12 | Automatic tally includes zero-vote candidates, treats abstentions explicitly and reconciles per position without seat overflow. |
| ELC-13 | Candidate totals remain hidden during voting; unresolved winning-cutoff ties and discrepancies block declaration. |
| ELC-14 | Declaration cannot edit outcomes, creates council appointments once and survives safe retry or rolls back on failure. |
| ELC-15 | Runoff, cancellation and by-election preserve original records and obey their approved authority and electorate rules. |
| ELC-16 | After declaration, learner candidate and manifesto access is replaced by the scoped council, including direct API requests. |
| ELC-17 | Certificates and PDFs use declared result versions, enforce access rules and support audited replacement/revocation without duplicate issuance. |
| ELC-18 | Yearly entitlement enforcement and safe expiry continuation match the agreed commercial policy. |
| ELC-19 | Audit failure rolls back administrative mutations; reconciliation and logs do not create voter-to-ballot links. |
| ELC-20 | Privacy review covers timestamps, traces, logs, backups, WAL, ordering, named status updates and privileged access limits. |
| ELC-21 | Representative concurrent classrooms and shared-network throttling are measured; throughput claims identify workload and environment. |
| ELC-22 | Formatting, static analysis, PostgreSQL checks and full regression pass on the approved test database before readiness review. |

## Reconciliation semantics to resolve before schema changes

A complete submission may cover several positions and may include abstentions. The implementation MUST distinguish the number of participating learners, position-level ballots, candidate selections and abstentions. These are not interchangeable counts. Do not infer a whole-ballot grouping identifier that would link a learner or their ordered choices to stored selections. Specify the per-position equations and privacy properties before implementing the revised schema.

## Open decision register

| Decision | Status |
| --- | --- |
| Multi-seat voting method and enabled release scope | Proposed selection of up to seats; confirmation and fairness tests required. |
| Guardian consenting authority and access recovery | Approved: one school-verified designated guardian; recover their own account without bypassing consent. |
| Application replacement and appeal deadlines | Approved: published school-configured deadlines; replacement only before application closure; different appeal reviewer. |
| Photo requirement and missing-photo handling | Approved: school publishes the requirement before applications open; verified photo versions only. |
| Independent cancellation approval | Recommended; whether required for School Administrator remains unresolved. |
| Runoff electorate and no-turnout/unopposed outcomes | Define before tally and runoff implementation; no invented zero-turnout winners. |
| Exceptional reopening and post-declaration annulment | Disabled until a controlled policy is agreed. |
| Annual billing boundaries, price, limits and expiry continuation | Yearly billing agreed; exact commercial rules unresolved. |
| Certificate verification audience and retention periods | Define before public verification or retention jobs. |

## Change control

Update this contract when an authorised product decision changes. Identify affected tests and any migration or compatibility impact. A document update does not constitute approval to commit, push, merge or deploy. Keep evidence of actual test runs separate from required acceptance gates.

## Privacy and HTTP boundary slice — 9 November 2026

Prepared from the local hardening branch at e2135bf. The installed candidate
Resource and its existing six tests are retained. The privacy/HTTP slice passed locally on 9 November 2026: 36 tests and
283 assertions. Concurrency verification below remains pending execution.

- Learner election, position, candidate, result and council responses use
  explicit field allowlists; no raw learner relationship is serialized.
- Candidate galleries require a matching active election-position relationship,
  school ownership and supported grade/stream scope. Galleries are unavailable
  before campaigning, after declaration, and after learner participation.
- Non-empty custom voter rules, grade ranges and unknown scope types fail
  closed on learner reads until their eligibility policies are implemented.
  This does not implement education-level configuration or the full electorate.
- Published learner results and council entries are scoped independently.
  Administrative running candidate totals remain hidden during voting.
- Existing portal/module permission checks remain. Election action permissions
  are added for learner reads, voter generation, tally and declaration.
- The ordinary learner-portal vote endpoint returns HTTP 403 without mutation,
  consistent with the classroom-only contract. Classroom stations are pending;
  there is intentionally no replacement voting HTTP endpoint in this slice.
- Voting service parent-position ownership is checked without changing voting
  locks, token generation, timestamps or the existing duplicate-vote transaction.
- Administrative responses use allowlists. Full nomination/review lifecycle,
  audit atomicity, tally integrity, anonymity, subscriptions and station security
  remain separate release gates. The legacy direct voting service is not a
  production-ready voting channel.

The new HTTP tests exercise real JWT, tenant and permission middleware; readiness
is stubbed only for school setup. Run them and the existing tests on
shuleos_assessment_fk_test before proceeding to concurrency work. No test count
in this section asserts a successful run.

## Voting coordination slice — 10 November 2026

Status: prepared; PostgreSQL contention tests and regression have not yet been
reported as passed. Elections remains blocked for real-school use.

### Coordination and cutoff contract

The legacy internal position-level vote primitive now takes a transaction-held
election SHARE lock, followed by an UPDATE lock on that learner-position voter
row. Independent learners can commit concurrently. Competing submissions for
the same registry row serialize and only one accepted vote may survive.

Election transitions, voter generation, attaching positions and candidate
reviews take the election UPDATE lock inside a database transaction. Candidate
review is unavailable from voting_open onward or after cancellation. Reviews
remain available in pre-voting phases; complete consent/review policy is pending.
This protocol covers the application service paths, not unrestricted direct SQL.

The window is half-open: voting_opens_at <= acceptance time < voting_closes_at.
The application-server clock is read after the election and registry locks and
candidate checks, immediately before persistence. Synchronized application clocks
are an operational prerequisite. Arrival time at the HTTP endpoint is not the
acceptance time. A vote waiting beyond the cutoff is rejected. An already accepted
transaction may commit after the cutoff; closure waits for it to commit or roll
back before completing. Tally retains the exclusive election lock and requires
voting_closed. Tests target PostgreSQL READ COMMITTED behavior.

Ballot and participation writes remain atomic, including model persistence
vetoes. The unused bcrypt work is removed. Each ballot receives an independent
cryptographically random SHA-256 uniqueness marker, never returned to the caller
or copied into participation. The unused participation-token field is cleared.
Existing timestamps remain: these changes do not establish ballot anonymity.

### Verification required

Eight independent-session cases cover parallel distinct learners, duplicate
submissions, vote-first closure, closure-first voting, vote rollback and retry,
cutoff during a voter-row lock wait, competing closures, and review waiting
behind poll opening. Blocking cases require observed pg_blocking_pids evidence;
the parallel case requires the second commit while the first transaction is held.
Fixtures are committed before workers start and removed only from their own
generated school. No production database, shared fixture cleanup or disabled
database triggers are used. A further nine safety tests cover boundary times,
review freeze, persistence vetoes and the random marker. These are test inventory
counts, not claims that a run has passed.

The ordinary learner-portal vote endpoint stays disabled. The internal primitive
is not a complete-ballot station API: attendance, device authorization, supervisor
approval, one session per learner, all-position atomic submission, privacy-safe
idempotency and throttling require their own implementation and tests. No new
voting HTTP route or per-IP limiter is introduced before that station design.

Tally fairness, cutoff ties, declaration gates, full administrative audit,
guardian consent, yearly entitlements, stronger anonymity and full regression
remain release gates. No throughput benchmark or production readiness is claimed.

## Privacy minimization and reconciliation — 10 November 2026

Voting coordination passed locally: 53 tests, 372 assertions, including eight
PostgreSQL worker scenarios. No real-school readiness is implied.

The engineering contract in Student-Elections-Privacy-and-Reconciliation.md
defines metadata minimization, the privileged-operator threat boundary, current
single-selection reconciliation and future complete-ballot equations. New runtime
verification is pending. Tally/publication now require aggregate reconciliation;
fairness, ties, result-version integrity and station delivery remain incomplete.

## Result integrity and declaration — 12 November 2026

Metadata/reconciliation verification passed locally: 72 tests, 471 assertions.
The result policy and evidence protocol in Student-Elections-Results-and-Declaration.md
define cutoff-tie blocking, seat bounds, zero-turnout and unopposed blocks, frozen
outcomes, strict audit and safe declaration retries. New runtime checks are pending.
Multi-seat voting remains disabled pending agreement. Runoff execution, independent
review and classroom stations remain separate incomplete release gates.

## Approved resolution policy — 12 November 2026

The school owner approved single-seat-resolution-v1: linked runoff restricted to
cutoff-tied candidates, original frozen electorate with fresh daily attendance and
authorization, another runoff for repeated ties, and whole-election declaration
only after every required office resolves. Unopposed confirmation uses Approve,
Reject or Abstain; at least one approval and approvals greater than rejections
confirm, with no quorum. Zero turnout produces no winner. Multi-seat positions
remain disabled for the first release.

Student-Elections-Runoffs-and-Confirmation.md is authoritative for this policy,
evidence chain, adoption limits and administrative API. Earlier references to
these policies being undecided are superseded. Classroom authorization and release
verification remain incomplete. No real-school election is authorized by this patch.

## Prepared classroom stations — 12 November 2026

Linked rounds and expiry correction passed locally: 150 tests, 1,523 assertions.
Student-Elections-Classroom-Stations.md now defines the prepared labelled-browser,
class-teacher authorization, current attendance, complete-ballot/abstention,
trusted-network, privacy and adoption contract. New runtime verification is pending.
This supersedes earlier descriptions of stations as purely planned and of all
original ballots as legacy single selections; existing legacy polls retain their
prior method and evidence. The classroom feature remains disabled by default and
real-school use remains blocked. No deployment or annual entitlement is enabled.

## Approved applications, consent and review policy — 12 November 2026

The school owner approved the rules in Student-Elections-Applications-Consent-Review.md:
one school-verified designated consenting guardian, exact-version guardian action,
renewed consent and review after material edits, and no staff impersonation.
Before voting opens, withdrawal invalidates approval; after opening it records an
incident and pauses voting/declaration without automatically rewriting ballots.
One non-conflicted designated officer reviews; a different officer handles appeals.
Schools publish deadlines and the photo requirement before applications open.
Position replacement is limited to before application closure with fresh consent.
These decisions supersede the earlier open-decision entries. Implementation and
verification are pending; classroom rollout remains disabled and real-school use
remains blocked. Last local Elections verification: 203 tests, 1,744 assertions.
