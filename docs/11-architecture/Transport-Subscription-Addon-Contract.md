# Transport subscription add-on contract

Status: local implementation and final review checks verified; not staged, committed, pushed, merged or deployed.
Baseline: 99674619350aebef3f6e9456c41c7503ab757704.
Date: 7 November 2026.

## 1. Product boundary

Transport is an optional paid add-on to a school's existing ShuleOS package.
The school retains its existing package and other subscribed modules.
Buying Transport does not require an Enterprise upgrade.
This delivery does not introduce a Transport-only school subscription.

Transport operations remain independent of learner fee charging.
School subscription payments are not learner Transport fee payments.

## 2. Separate activation and entitlement

Represent the Transport add-on separately from the main package subscription.
Record school, product, effective period, activation source and audit evidence.
Preserve previous activations, renewals, cancellations and corrections.

No existing school receives a paid entitlement automatically.
Installing code, school operating mode, a module flag, administrator role or
Transport staff assignment does not establish a subscription.

Package names must not implicitly activate Transport.
Legacy module flags are not payment evidence or entitlement authority.

A school may request an add-on but cannot approve its own paid activation.
Activation requires an explicitly authorized platform workflow and verified
commercial evidence. A request or pending payment does not activate access.

Price, billing duration, grace period, proration, trial eligibility and refund
policy remain configurable commercial decisions. Do not invent prices, grant
free access or collect payments as part of the entitlement foundation.

## 3. Dates and renewal

Store precise effective instants and their commercial date/timezone context.
Access periods use inclusive start and exclusive end boundaries.
Future activation must not grant early access.

Evaluate effective access on every relevant operation; a delayed scheduler
must not extend access beyond the recorded period.

Renewal preserves previous periods and payment evidence.
Retries and duplicate activation/payment references must be idempotent.
Concurrent renewals must not create conflicting periods or duplicate charges.
Renewal must not rewrite historical journey, audit or report evidence.

Cancellation normally stops future renewal or future service access.
It does not delete evidence or silently terminate an active learner's custody.
Exceptional suspension requires separate, explicit authority and a reason.

## 4. Authorization

Normal Transport operations require:
- an eligible authenticated school user;
- valid tenant ownership and school lifecycle/setup;
- an effective Transport add-on entitlement;
- the operation's existing permission;
- concrete dated responsibility where required.

Subscription activation never grants staff permissions.
Staff permissions never establish a paid entitlement.
Platform subscription management does not grant school journey authority.

Apply entitlement checks inside shared service boundaries as well as HTTP.
Commands, scheduler execution and alternate entry points must not bypass them.
Preserve all existing tenant, user, school-mode and responsibility checks.

## 5. Expiry and safe operational continuation

Expired access blocks new schedules, dated runs, enrolments, captures and new
boarding/pickup operations. It must not hide historical security evidence.

Design an explicit, narrow continuation policy for captured pre-expiry work:
- record safe delivery of learners already in Transport custody;
- record failed delivery, continuing custody and follow-up;
- authorize a necessary replacement recipient for that same ongoing custody
  only through a bounded, permission-checked workflow;
- record non-travel outcomes for already captured roster entries;
- complete existing trips when their actual evidence permits completion;
- submit and independently review reports for eligible pre-expiry service.

These exceptions do not authorize new passengers, new trips, general catalogue
changes, unrestricted recipient grants or a new reporting service scope.

Capture alone must not create unlimited post-expiry boarding authority.
Every continuation action must target concrete eligible existing evidence,
recheck current authority and append strict audit.

Do not bypass account suspension, tenant isolation or security locks.
Main-package expiry and school read-only states require explicit interaction
rules and tests. Never make all Transport writes available merely to permit a
custody handover.

The current Transport event window remains a separate restriction.
Add-on continuation must not claim that post-window handovers are supported;
that controlled security workflow requires its own implementation and tests.
Outstanding learners remain visible rather than being marked delivered.

## 6. Historical access and discovery

Authorized historical reads remain available after add-on expiry or removal,
subject to school/account security and privacy rules.

Expose separate facts:
- main package and its status;
- Transport purchased/effective period;
- requested or pending activation;
- currently allowed capabilities;
- expiry and renewal information;
- any narrow continuation capability.

Do not describe enabled, subscribed, configured and operationally ready as
interchangeable states.

Administrator module/subscription views must report Transport consistently.
School callers cannot supply another school's identity to inspect or activate it.
GET requests must not activate, renew, charge or mutate records.

## 7. Integrity and audit

Use same-school foreign keys, explicit actors, bounded values and version checks.
Serialize activation, renewal and expiry-sensitive actions with operational
writes using a documented lock order.

Entitlement changes, commercial reference evidence, history and strict audit
commit atomically. An audit failure rolls back the change.
Reject stale changes and duplicate/conflicting activation requests safely.

Never infer a successful payment from an unverified browser request.
Provider integration, payment reconciliation and external settlement remain
separate from the initial entitlement foundation.

## 8. Delivery slices

A. Entitlement storage, platform authorization and school visibility.
B. Transport service/HTTP/scheduler enforcement and bounded continuation.
C. Subscription activation/renewal workflow and payment integration as agreed.

Each slice must identify its implemented and deferred behaviour.
Do not describe the add-on as complete until operational enforcement is tested.

## 9. Verification

Required checks include:
- default-denied Transport without an entitlement;
- unchanged main package and unrelated module access;
- activation from any supported main package without an Enterprise upgrade;
- bidirectional tenant isolation and platform-only activation;
- future start, exact expiry, renewal and cancellation boundaries;
- permission alone and entitlement alone are insufficient;
- historical access after expiry without unauthorized new operations;
- safe continuation without new boarding or scope expansion;
- school/account lifecycle and existing event-window interactions;
- direct service, HTTP and scheduler enforcement;
- duplicate activation and actual concurrent renewal/expiry contention;
- audit rollback, immutable history and populated rollback refusal;
- unchanged learner fees, invoices and ledger;
- scoped static analysis, formatting and full regression.

Development and database tests target only shuleos_assessment_fk_test.
Never target the main shuleos database.

Writing this contract does not activate subscriptions, set a price, grant
permissions, enable scheduling, commit, push, merge or deploy anything.

## 10. Entitlement foundation slice

This slice introduces immutable Transport add-on periods and platform service
methods for recording activation/renewal against manually checked commercial
references. evidence_source is platform_manual_attestation: this is an authorized
operator assertion, not an independently verified provider payment.

Three permission identities are introduced without automatic grants:
manage_transport_subscriptions, view_transport_subscriptions and
view_own_transport_subscription. Fresh platform identity/permissions are required
for activation/renewal; school visibility derives the actor's own school.

Periods use explicit offset-bearing timestamps, inclusive starts, exclusive ends
and a maximum 366-day duration. Future periods grant no early period-active state.
No automatic trial, grace period, proration, price or Enterprise entitlement is
created. The school row lock serializes changes; non-overlap and immutable
predecessor linkage also have PostgreSQL protection.

request_id identifies an idempotent command. Exact authorized retries return the
same period without another audit, including after the requested start has passed.
Changed evidence under the same request, stale history, overlap and repeated
normalized commercial references are rejected. Renewal appends a new period and
preserves its predecessor. Lapsed renewal may leave a gap; it cannot backdate.

The application does not write main package subscriptions, fee invoices, payment
allocations or role grants. Strict administrator audit shares the transaction.
School status omits commercial references and platform actor details. A recorded
period being active is not a claim of operational readiness or permission.

The following operational enforcement slice gates shared Transport mutations.
Cancellation, entitlement revocation, school purchase requests, subscription HTTP
endpoints, administrator dashboard integration and payment verification remain
pending. Foundation service methods are not a public purchase or charging API.
No existing school is activated or grandfathered by the migration.


## 11. Operational entitlement enforcement

Normal Transport mutations require the latest main subscription to have a package
identity, a started start_date, and status ACTIVE through expiry_date or status
GRACE_PERIOD through its explicitly recorded grace_end_date. Dates are inclusive
in the school's timezone. No grace duration or trial add-on is invented. A missing,
future, expired, pending, trial, locked or suspended main subscription denies new
operations. Package names, including Basic/Premium/Enterprise, are not consulted.
The main subscription and all unrelated module checks are never written here.

The Transport period is evaluated at each service write after acquiring the school
row lock, using inclusive start and exclusive end instants. New configuration,
assignment, enrolment, reservation, capture, reporting-policy configuration and
obligation materialization all require normal access. Scheduler runs use those
same services. Retrying capture or materialization does not bypass expired access;
use historical GET operations to inspect existing records.

Only specific service entry points allow continuation. Every continuation still
rechecks fresh identity, security locks, tenant, day/mixed mode, setup readiness,
operation permission, concrete responsibility and existing domain invariants.
School active/grace/read_only lifecycle states may close eligible work; unavailable
school or account security states remain denied. Main subscription commercial
expiry never grants general writes but does not silently terminate custody.

Handover continuation permits school_arrival or destination_dropoff only while
the latest journey state is on_board. Immutable capture, original successful
boarding occurrence and its recording must each fall within a recorded same-school
Transport period. Adjacent renewals may cover different evidence instants. Merely
capturing a roster never permits a new pickup or school_departure after expiry,
including a late request claiming the earlier occurrence time.

Replacement authorization is limited to an existing captured assignment with an
eligible currently onboard learner and exactly its original service date. Inbound
scope allows school_receiver; outbound scope allows guardian_recipient for the
same captured learner and stop. Existing guardian-link, verification, eligibility,
receipt and permission rules remain mandatory. Revocation in this continuation
scope uses the same concrete custody proof. It is not an unrestricted grant API.

Attendance outcomes and trip reconciliation require an existing capture covered
by a recorded period. They cannot manufacture transport or successful delivery.
An onboard learner or unresolved exception keeps completion incomplete. Custody
follow-up/resolution targets a failed arrival/drop-off on a journey with entitled
boarding evidence, including resolution after later actual delivery.

Report closure requires an already materialized obligation whose materialization
and scheduled service departure are covered by recorded entitlement periods.
It cannot create a new reporting scope after expiry. Submission still requires
concrete service responsibility; review remains independent and snapshots remain
immutable. Existing online and service-date event windows are unchanged: this
slice does not introduce offline or post-window handovers.

Each successful operational mutation audit includes entitlement_access with the
normal or continuation mode, period identity, server evaluation time and concrete
continuation scope where applicable. Evidence mutation and this audit share the
existing transaction; audit failure rolls back both. Historical authorized reads
perform no entitlement activation or commercial mutation.

Operational tests use explicit isolated subscription fixtures, not production
bypasses or automatic school grants. Actual-session tests cover waiting
expiry checks, committed/rolled-back adjacent renewal, duplicate renewal retries
and stale competing renewals using pg_blocking_pids through the existing runner.
Final review also requires time to be evaluated after the main subscription
shared-lock wait, covered by a sixth actual entitlement-contention case.
Local verification is recorded in section 12; CI remains a separate gate.

## 12. Local verification

Verified locally on 2026-11-07 using shuleos_assessment_fk_test after the final review timing correction:

- Add-on foundation and enforcement: 41 tests, 362 assertions.
- Transport feature regression: 223 tests, 2765 assertions.
- Transport units: 9 tests, 182 assertions.
- Full backend regression: 2068 tests, 32469 assertions.
- Full regression time: 14:22.162; peak memory: 554.00 MB.
- Scoped Transport PHPStan level 5, formatting, Composer validation, dependency audit and diff checks passed.
- Six entitlement-contention cases passed with distinct PostgreSQL sessions and pg_blocking_pids through the existing runner.

The new main-subscription lock test advances the controlled application clock only after the blocked shared-lock query returns. An expired add-on must then deny the operation. Commercial time is evaluated after that lock, not reused from before the wait.

These results establish local checks only. CI has not been run for this add-on branch. Static analysis covers the configured Transport application/configuration paths; it does not claim static analysis of every migration or test.

Remaining delivery limits: platform manual attestation is not provider payment verification. Subscription purchase HTTP endpoints, school purchase requests, cancellation/entitlement revocation, administrator module/subscription dashboard integration and external payment reconciliation remain pending. Pricing, add-on trial/grace, proration and refund policies are not introduced here.

Production rollout must reconcile existing Transport usage and active custody before enforcement. The migration neither manufactures historical commercial evidence nor automatically adopts unentitled trips. Existing online/service-date handover windows remain unchanged.

No real school was activated or charged. Main subscription records and unrelated module access are not changed by application code. Both reporting schedulers remain disabled by default. Nothing was staged, committed, pushed, merged or deployed during this verification.
