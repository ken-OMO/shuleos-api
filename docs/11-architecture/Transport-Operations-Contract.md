# Transport operations contract

Status: local implementation verified for the bounded scope in section 15; CI and final commit preparation pending.
Baseline: 801b5518184198e34077a51807fde5d69aa8adbe.
Date: 3 November 2026.

## 1. Domain boundary

Transport is an optional school service independent of boarding.
Day-only and mixed day-and-boarding schools may offer regular day transport.

Learners remain day_scholar. Transport enrolment must not introduce a
day_with_transport classification or alter school operating mode.
The first regular-service slice excludes boarders. Excursions and boarding
opening/closing transport require separate contracts.

School ownership, operational lifecycle and setup eligibility are enforced
server-side. Historical authorized reads survive resource retirement.
Commercial entitlement remains a separate platform concern.

Transport attendance is journey evidence, not classroom attendance.
A missed pickup must not automatically mark a learner absent from school.

## 2. Vehicles, routes, stops and schedules

Vehicles belong to one school and record registration, learner seating capacity,
school-owned or contracted ownership, and active/retired state.
Contracted operation does not remove school accountability.

Routes have school-owned identities and unique codes.
Stops belong to a same-school route and have explicit ordering.
Inbound and outbound arrangements may differ.

Service schedules define route, direction, weekdays, school-local departure
and expected completion times, timezone and effective dates.
Directions are to_school and from_school. Overnight service windows must have
explicit date offsets rather than inferred next-day behaviour.

Vehicle assignments are effective-dated and preserve substitutions.
Conflicting vehicle and driver time windows must be rejected.
Any turnaround allowance must be explicit; route names do not establish
whether two journeys can safely share a vehicle.

Referenced vehicles, routes, stops and schedules are retired rather than
physically deleted. Changes must not rewrite completed trip evidence.

## 3. Staff identity and responsibility

Drivers, attendants and school receiving staff use tenant-owned User identities.
A Teacher specialization is unnecessary.

Responsibility records identify service scope, function and effective dates.
Assignments do not grant permissions. Permission alone does not establish
responsibility for a concrete trip or daily report.

Inactive, deleted, suspended or foreign users cannot receive new responsibility
or record new operational events contrary to the applicable eligibility rules.
Historical actor and responsibility evidence remains readable.

A driver profile may hold transport-specific credentials, but it is not a
replacement identity or proof that legal requirements have been independently
verified. Credential policy requires explicit implementation.

## 4. Learner enrolment and capacity

Enrolments belong to the same school as learner, service schedule and route stop.
Each travel direction has its own enrolment; return transport uses two.
Record effective dates and applicable weekdays.

Reject invalid dates, foreign resources, stops outside the selected route,
duplicate overlapping reservations and incompatible learner classification.
Future starts do not activate early.

Ending or transferring enrolment preserves the previous episode.
Historical journey rosters must not be reconstructed from current enrolment.

Capacity counts overlapping reservations for the concrete service date and
direction against the assigned vehicle's learner capacity.
Reject overbooking, insufficient-capacity substitutions and capacity reductions
below affected reservations. Staff seating requirements must be accounted for
explicitly rather than counted as learner places.

Consistent transactional locking must protect the final available seat.
Capacity is not inferred merely from the number of active enrolment rows.

## 5. Trip rosters and operational state

Each trip belongs to a service schedule, operating date and school.
Trip identity must distinguish multiple runs of the same service.

Capture the dated learner roster, vehicle, route/stop context and responsible
personnel before operational recording starts.
Enrolment alone never proves that a learner travelled.

Later enrolment, route, staff or vehicle changes must not rewrite captured
journey evidence. An authorized substitution records its actor, reason,
effective point and affected trip context.

Trip lifecycle and learner journey lifecycle are separate.
Closing a trip must not conceal an unresolved learner journey.

## 6. Pickup, arrival, departure and drop-off

Every handover must identify:
- learner and concrete trip;
- event type, actual occurrence time and server recording time;
- actual stop or location context;
- person handing over and person receiving;
- authenticated recording actor and responsibility context;
- authorization evidence and any exception.

Record these operational boundaries:
- pickup: approved releasing person to driver/attendant;
- school arrival: transport staff to authorized school receiving staff;
- school departure: authorized school staff to transport staff;
- destination drop-off: transport staff to an approved recipient.

For each transition, preserve the from-whom/to-whom evidence.
Authenticated recording actor and receiving person are distinct facts.
Selecting a person's name must not claim that person confirmed receipt.

Use an explicit confirmation method and confirmation state.
Where confirmation is required, record who confirmed, how and when.
Do not fabricate signatures, recipient accounts or confirmation timestamps.

Known users and guardians use same-school references where applicable.
Approved external recipients require a controlled identity and authorization
record; free text alone must not authorize collection.

## 7. Authorized recipients and security exceptions

Collection arrangements identify learner, direction, location/scope,
validity period and approved releasing/receiving persons.
Guardian linkage alone must not be assumed to grant unrestricted collection
authority. Preserve the authorization used at the event time.

A substitute recipient or independent travel requires explicit, bounded
authorization with actor, reason and preserved evidence.
Independent travel must not be represented as a fictitious person-to-person
handover.

If the recipient is unavailable, unauthorized or cannot be verified, record a
security exception and the learner's continuing custody context.
Do not mark the learner safely delivered merely to complete a trip.

Exceptions require responsible ownership, recorded actions and authorized
resolution. Preserve escalation and resolution history.
The exact approval, verification and emergency-override rules must be defined
and tested before handover endpoints are exposed.

Do not collect identity-document images, biometrics or precise continuous
location data by default. Any such capability requires a separate privacy and
security design.

## 8. Journey attendance and corrections

Journey evidence distinguishes:
- expected but not picked up;
- picked up and on board;
- handed over at school;
- released by school into transport custody;
- handed over at the approved destination;
- cancelled journey;
- unresolved exception.

Expected, absent, cancelled and unresolved are not interchangeable.
Record reasons and responsible actors where required.

Reject impossible ordering, duplicate transitions and invalid trip/learner scope.
A learner must not appear on conflicting active journeys.
An unresolved onboard learner must remain visible to authorized personnel.

Offline or delayed recording is not implicitly supported.
Any later offline design must preserve event time, received time, ordering,
identity and conflict detection.

Corrections append preserved evidence with reason and authorization.
No silent overwriting or physical deletion of security history is permitted.

## 9. End-of-day reporting obligations

Responsible transport staff must submit an end-of-day report for their
explicitly assigned service/reporting scope and school-local operating date.
Multiple staff sharing a scope must not create duplicate obligations.

Schools configure required service days, deadline, timezone and grace.
The deadline must account for the final applicable service, including any
explicit next-day completion. Do not hardcode an afternoon deadline.

Required scopes without eligible responsible staff remain visible as unassigned.
Missing reports remain visible to authorized leadership.
GET requests do not create reports, obligations or mutation history.

Materialization must be idempotent. Scheduler activation, actor configuration
and school write gates require explicit configuration and tests.

A report contains:
- operational summary;
- trips planned, operated, cancelled and incomplete;
- expected learners and actual journey attendance;
- completed pickups and handovers;
- missed pickups, delays and incidents;
- unresolved learners, custody exceptions and follow-up actions.

Counts derive from authoritative trip/journey evidence using a documented
counting rule. Distinguish unique learners from journey legs.
A late return or unresolved learner remains visible even after report submission.

## 10. Submission, review and historical evidence

Submission freezes an immutable snapshot of the report, authoritative totals,
trip context, unresolved exceptions and contributors.
Preserve actor, responsibility, timestamps, timezone and deadline evidence.

A report cannot be submitted with required evidence missing.
A report may document an unresolved exception only through the explicitly
defined exception-reporting workflow. Submission never resolves that exception.

Late and overdue states are derived from the configured deadline.
Deadline equality is on time. Preserve the first-submission timestamp.

Leadership review targets the exact submitted revision.
Contributors and submitters cannot review their own revision.
Changes requested require an explanation and create a new preserved revision;
earlier submissions and reviews remain unchanged.

Security events recorded or resolved after submission do not rewrite the
submitted snapshot. Live unresolved-exception monitoring remains authoritative.

## 11. Permissions and privacy

Implemented distinct permissions (no automatic role grants):
- manage_transport;
- manage_transport_enrolments;
- view_transport_operations;
- record_transport_journeys;
- authorize_transport_handover;
- submit_transport_daily_reports;
- review_transport_daily_reports.

Permission identities and responsibility scopes are provisioned and tested.
Administrators must explicitly grant each permission to eligible roles.
Job titles and custom responsibility labels are not authorization shortcuts.

Configuration, collection authorization, event recording and leadership review
are separate authorities. Emergency overrides must not silently bypass them.

Operational responses expose bounded learner and recipient details.
Do not expose unrestricted User/Guardian models, authentication data or
unnecessary home addresses and contact information.

Parent visibility, external messages and live tracking require separate
authorization and disclosure contracts.

## 12. Finance independence

Finance participation remains charging eligibility, not route enrolment or
proof of travel. Transport mutations do not automatically create or alter
fee participation, invoices, payments, refunds or ledger entries.

Existing invoices retain their recorded financial evidence.
Rate changes, missed journeys, substitutions and enrolment termination do not
automatically reprice them. Proration and any future operational-to-finance
integration require separately approved workflows.

No changes to ShuleOS subscription billing or payment-provider callbacks belong
to this contract.

## 13. Database, concurrency and audit

Use explicit school ownership, UUIDs and composite tenant-safe foreign keys.
Server derives school, actor and lifecycle fields.
Enforce dates, positive capacities, scoped uniqueness and valid transitions.

Document a common lock order for configuration, reservations, trip events,
handover authorization and report submission.
Use optimistic versions to reject stale changes without mutations or audit.

Operational mutations, history and strict audit commit atomically.
Preserve recipient authorization and responsibility evidence used at event time.
Report snapshots and security history are immutable.

Populated evidence must not be discarded by rollback.
Use reviewed forward corrections after operational data exists.

## 14. Delivery slices and acceptance gates

A. Foundation: vehicles, routes, stops, schedules, dated vehicle/staff assignments,
learner enrolments, capacity, permissions and audit.

B. Security operations: captured trip rosters, attendance transitions, recipient
authorization, from/to handovers, exceptions and preserved corrections.

C. Accountability: daily obligations, derived totals, end-of-day submission,
immutable revisions, leadership review and live exception follow-up.

All three slices belong to the agreed Transport delivery.
A alone must not be described as secure pickup/drop-off implementation.

Required verification includes:
- day-only and mixed schools;
- day_scholar classification preserved;
- no Teacher specialization required for transport staff;
- tenant isolation in both directions;
- permission and concrete responsibility checks;
- future, ended, inactive and conflicting assignments/enrolments;
- actual concurrent contention for the last seat;
- capacity-safe vehicle substitutions;
- captured rosters preserved after configuration changes;
- correct handover sequence and receiving-person verification;
- unauthorized recipients and bounded authorized exceptions;
- duplicate, conflicting and stale event rejection;
- learner custody unresolved at trip/report closure;
- unique learner versus journey-leg totals;
- school-local dates, deadlines, grace and late boundaries;
- immutable submitted evidence and correction/review rules;
- audit failure rollback and safe migration rollback refusal;
- no classroom attendance or Finance side effects;
- full regression, formatting, scoped static analysis and Composer checks.

Development migrations and PostgreSQL tests target only
shuleos_assessment_fk_test. Never target the main shuleos database.

GPS, route optimization, external messaging, maintenance management and
excursion workflows are outside these slices.
No deployment, commit, push or scheduler activation follows merely from
writing this contract.

## 15. Implemented scope, limits and local verification

Verified locally on 7 November 2026 against shuleos_assessment_fk_test:

- Transport feature suite: 217 tests and 2,705 assertions.
- Transport unit suite: 9 tests and 182 assertions.
- Full application regression after migration fixes: 2,021 tests and
  32,047 assertions; exit code 0; 12 minutes 4.825 seconds; 544 MB peak memory.
- Formatting passed for 68 PHP files before the final CI/documentation edits.
- Composer validation and dependency audit passed after updating
  symfony/http-foundation, http-kernel, routing and yaml to v7.4.23.
- Concurrent tests use separate PostgreSQL sessions and verify actual blocking
  through pg_blocking_pids for assignments, final-seat reservations, events,
  authorization revocation, exception resolution, attendance, completion,
  submission/review and scheduler materialization.

The persistent phpstan-transport.neon configuration selects Transport services,
seven HTTP controllers and Transport configuration at level 5 without a baseline.
Its final validation result must be recorded separately; it does not claim
PHPStan coverage of migrations, routes or test-support files.

Sections 1-14 define the broader target contract. The following delivery limits
are explicit and must not be described as completed capabilities:

- Dated assignments can be ended and replaced before reservations/capture allow
  it. Controlled vehicle/staff substitutions after capture remain unavailable.
- Recurring enrolments require concrete assigned runs for every selected date,
  cover at most 366 dates and support atomic transfers. A standalone early-end
  operation for an entire recurring episode remains deferred.
- Recipient authorization supports eligible linked guardians and dated school
  User responsibilities. External substitute recipients, independent travel,
  emergency overrides and driver-credential policy remain deferred.
- Journey events support online recording within fifteen minutes of occurrence,
  on the dated service through its completion-local date. Offline/backlog entry,
  arbitrary journey-history corrections and post-window custody handovers are
  not implemented. Outstanding custody remains visible; report submission or
  review cannot resolve it. These cases require a later controlled workflow.
- Daily reports preserve immutable submitted revisions and independent review.
  Report revisions correct the account; they do not edit original handover events.
- Delay/incident narratives may be included in report text. There is no separate
  structured delay/incident workflow, automated escalation or notification.
- Staff seating is not inferred from learner_capacity. The configured capacity
  represents learner places; staff-seat and credential compliance checks are
  separate operational requirements.

Daily reporting scope is one schedule and service-local date, aggregating its
runs. Totals distinguish planned, cancelled, uncaptured, captured, completed and
incomplete runs; expected and transported journey legs; distinct transported
learners; attendance outcomes; current onboard learners and unresolved failures.
Guardian witnessed-verbal receipt is labelled verbally_reported and does not
claim an authenticated guardian confirmation.

Writes lock school before actor and domain records. The school lock serializes
mutations with daily snapshots. Learner/roster and authorization locks additionally
protect custody transitions and revocation races. Mutation and strict audit share
one transaction. Populated rollback refusals remain in force.

Transport function declarations use CREATE OR REPLACE FUNCTION so table rebuilds
can recreate the same routines when PostgreSQL retains them. This does not make
whole migrations rerunnable against populated operational tables.

The scheduler is disabled by default. When explicitly enabled, an explicit
school-to-actor map is required; actors must have current manage_transport
permission and school write access. The command processes current policy-local
service dates only, commits each school independently and reports rejected
schools. A failed school's work rolls back; earlier schools may have committed.
No actor or permission is selected automatically. OS scheduler activation,
historical backfill, reminders, parent visibility, Finance integration and
production deployment are outside this delivery.

The backend CI job now requests a 1 GB PHP limit, and Transport has a separate
baseline-free static-analysis gate. These configuration changes do not establish
that remote CI has passed. Nothing is committed, pushed, merged or deployed by
this documentation update.
