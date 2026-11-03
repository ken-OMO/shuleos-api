# Boarding daily operations contract

Status: local implementation verified; documentation review in progress; not committed, merged or deployed.
Foundation: 51601f59e1a61b8660e6a5c210b64903e377814a.
Date: 2 November 2026.

## 1. Domain boundary

Boarding operations consume existing Hostels, Users, staff responsibility
episodes and bed-allocation history.

They do not reuse Teacher-on-Duty assignment tables or add reporting fields
to hostel_staff_assignments. A non-teaching responsible User does not require
a Teacher record.

Existing Finance eligibility remains independent. Attendance, an occurrence
or a report must not create, cancel or reprice an invoice.

## 2. Delivery order

A. Reporting settings, facility/date obligations, shared daily reports,
structured occurrences, submission, preserved revisions and leadership review.

B. Boarding attendance sessions and authorized learner movements, with their
own evidence and correction rules.

C. Configurable reminders and escalation through the approved notification
architecture.

Slice A must not claim to implement B or C. Reports may describe an observed
absence but do not thereby establish an attendance or movement record.

## 3. Reporting obligation

There is one reporting obligation per school, Hostel and local reporting date.
Multiple responsible staff do not create duplicate obligations or reports.

Reporting requires explicit school configuration:
- enabled state and effective start date;
- required weekdays;
- deadline time and day offset, supporting next-day deadlines;
- grace minutes;
- school timezone.

No historical obligations are invented before the effective start date.
Configuration changes are audited and apply prospectively. Existing obligation
deadlines, timezone and policy evidence remain unchanged.

Obligations are materialized idempotently by a scheduled service, not by GET.
State reads do not create reports, settings or history.

A required facility without eligible responsible staff is shown as unassigned;
it does not silently disappear from overdue accountability.

Facility retirement stops future obligations. Existing obligations and reports
remain historical records.

## 4. Authority and responsibility

Implemented distinct permissions:
- record_boarding_operations;
- view_boarding_operations;
- review_boarding_reports;
- configure_boarding_reporting.

Existing manage_boarding continues to manage facilities and responsibility.
It does not implicitly grant reporting, review or unrestricted welfare access.

Assignment creation grants no permission. Reporter access requires both the
explicit recording permission and a same-school responsibility episode covering
the Hostel and reporting date.

A writer must also be an active, nondeleted, nonsuspended school User.
The episode must already have started; future responsibility is unsupported.
An ended episode may establish historical scope within its recorded date range.

Each contribution records the responsibility episode used to authorize it.
Historical responsibility evidence remains after an assignment ends.

Leadership reads and review require their explicit permissions and same-school
scope. There is no role-name or responsibility-label authorization shortcut.
Permission provisioning and any default grants must be explicit and tested.

No unscoped administrative reporter override is introduced in the first slice.

## 5. Shared report

One logical daily report belongs to one obligation.
Opening it is idempotent and does not create duplicate history or audit events.

Different eligible responsible staff may contribute to the same draft.
The server controls ownership, actor, status, timestamps, deadline and revision.

A report supports:
- operational summary;
- explicit no-occurrence declaration when applicable;
- structured occurrence evidence;
- actions and follow-up;
- submitting actor and responsibility context;
- revision and review history.

A report must not be reduced to one unstructured text field.

## 6. Structured occurrences

School-owned, extensible categories can cover welfare, health, behaviour,
meals, facilities, maintenance, security, safety and general observations.

Each occurrence records:
- tenant, Hostel and logical report;
- occurrence time;
- category;
- description and actions taken;
- follow-up requirement;
- author and responsibility episode;
- optional same-school learner link.

Client-selected foreign tenant, learner, Hostel, category or assignment fails
closed. A learner link requires evidence of occupancy in that Hostel at the
occurrence time; membership is not inferred merely from current classification.

Missing or ambiguous historical occupancy fails validation rather than guessing.

Occurrence times cannot be future or outside the report's local reporting date.
Creating or editing evidence locks the report and is allowed only in an
editable draft revision. Corrections preserve previous content and actor history.
Removing an erroneous occurrence uses audited voiding, not physical deletion.

## 7. Submission and review

Workflow:
draft -> submitted -> reviewed
submitted -> changes_requested -> new draft revision -> submitted

Submitting freezes an immutable snapshot of report content, occurrences and
contributors. Subsequent category renaming or learner/profile edits do not
rewrite submitted evidence.

Review records reviewer, decision, comment and timestamp against the exact
submitted revision. Changes requested require a meaningful explanation.

A revision's submitter or contributor cannot review that revision.
Concurrent review/submission requests must not create contradictory transitions.

Reviewed evidence is immutable. The first slice provides no generic delete,
restore or silent reopening endpoint.

Corrections retain the preceding submitted snapshot and review history.
A later revision never replaces the identity or bytes of an earlier submission.

## 8. Time and accountability

Store timestamps as timezone-aware instants, with local reporting date and
timezone retained as evidence.

The effective deadline includes configured grace.
A submission strictly after that instant is late; equality is on time.
An outstanding obligation becomes overdue strictly after that instant.

Due and overdue are derived accountability states, not editable report statuses.
Submission and review do not erase the first-submission timestamp or lateness.
Corrected revisions retain their own submission timestamps.

School calendar term activity must not be assumed to represent boarding opening
days. Reporting policy defines required dates independently.

## 9. Attendance and movement direction

Attendance must use explicit Hostel/session rosters captured with a timestamp.
Transfers and releases must not rewrite an already captured roster.

Do not pretend that present occupancy reconstructs an exact historical roster.
Day-level allocation dates alone cannot determine every intraday movement.

Movement authorization must record destination, authorizing actor, departure,
expected return, actual return and preserved correction history. Its permission
and guardian/leadership approval rules require the separate slice-B contract.

Missing pupils must not be automatically marked as authorized leave.

## 10. Database and transaction contract

Use UUIDs, explicit school ownership and composite tenant-safe foreign keys.
Protect unique obligation scope, one logical report per obligation and unique
revision numbers per report.

Statuses, dates, required lifecycle actors and timestamp consistency require
database checks. Preserve restrictive historical relationships.

Mutations, history, submission snapshots and strict audit must commit atomically.
Use a common documented lock order and optimistic revision checks to reject stale
draft edits. Database uniqueness is the final duplicate-creation backstop.

Submitted snapshots and lifecycle history require deliberate immutability
protection, tested with PostgreSQL migrations and rollback.

Rollback must refuse to discard populated reporting evidence. Use reviewed
forward corrections after data exists.

## 11. HTTP and privacy

Use dedicated /api/boarding operational endpoints, FormRequest validation,
authenticated tenant scope and explicit permissions.

Operational writes require boarding-compatible school mode and applicable
school lifecycle/setup gates. Ending responsibility remains a separate existing
lifecycle operation.

Historical reads must remain available to appropriately authorized same-school
leadership after facility retirement or school-mode changes. The existing blanket
boarding mode gate must be adjusted deliberately for these read-only endpoints;
write restrictions must remain intact.

Responses expose bounded operational resources, not unrestricted User models,
authentication data or client-authoritative tenant fields.
Leadership review does not imply parent-portal or learner-portal visibility.
Health and welfare narratives are not copied into notification payloads.

## 12. Reminders

Notification delivery consumes authoritative obligation/report state.
A sent notification does not mean the report is complete.

Use an outbox or equivalent approved after-commit integration, with idempotency
and recipient authorization rechecked before delivery. Report transactions must
not send external messages directly.

School policy controls recipients, channels and escalation timing.
The first reporting slice introduces no automatic external messages.

## 13. Acceptance gates

All development and local testing use shuleos_assessment_fk_test.
Never target the main shuleos database.

Required tests include:
- valid reporting without a Teacher specialization;
- permission alone and assignment alone are insufficient;
- inactive, deleted, suspended and foreign actors fail closed;
- tenant isolation in both directions for all reads and writes;
- shared reporting by multiple eligible staff without duplicate obligations;
- unassigned and missing reports remain visible as obligations;
- school-local dates, next-day deadlines, grace and equality boundaries;
- historical responsibility dates and retired-facility evidence;
- stale edit rejection and idempotent opening;
- valid and invalid learner/occupancy links;
- submission snapshot preservation and terminal review rules;
- self-review rejection for contributors and submitters;
- audit, history and snapshot failure rollback;
- no Finance or Teacher-on-Duty side effects;
- database constraints, migration, safe rollback refusal and re-migration;
- actual concurrent creation, editing, submission and review contention;
- full regression and appropriate quality checks.

Each delivery slice must report what is implemented, what is tested and what
remains. Design documentation alone is not implementation evidence.

## 14. Scheduler implementation

The boarding:materialize-reporting-obligations command is registered every five
minutes with overlap prevention. Scheduling is disabled by default.

Deployment configuration must explicitly enable BOARDING_REPORTING_SCHEDULER_ENABLED
and supply BOARDING_REPORTING_SCHEDULER_ACTORS as a JSON object mapping each school
UUID to an approved same-school User UUID. No account or permission is created
automatically. An empty mapping processes no schools.

The configured actor is the explicitly delegated identity recorded on generated
obligations and audit records. Every run rechecks current actor eligibility,
configure_boarding_reporting permission, school operating mode, lifecycle and
setup. Missing, foreign or unauthorized actors fail closed and make the command
return a failure exit code. Unexpected failures propagate rather than reporting
success.

Each school commits independently. A failed school's transaction rolls back;
earlier schools may already have committed. Repeated execution is idempotent.

Only the current school-local date is materialized. Historical facility
availability is not reconstructed from current active flags. Retired facilities
receive no new obligations. An active facility with no eligible reporter still
receives an obligation.

The operating system scheduler must invoke Laravel schedule:run for automatic
execution. This patch does not enable scheduling in any deployed environment.
It does not register the command with the separate administrator manual-task
allowlist or claim administrator heartbeat monitoring.

No reminders, escalation messages or external deliveries are implemented here.
Learner occurrence links remain unavailable pending occupancy-at-time evidence.
Actual concurrent contention and full regression have passed locally as recorded below.

## 15. Implemented scope and local verification

Verified on 3 November 2026 using shuleos_assessment_fk_test:

- Boarding suite: 217 tests, 1,855 assertions.
- Full regression after static-analysis fixes: 1,795 tests, 29,158 assertions,
  with exit code 0.
- PHPStan level 5: no errors across the 34 application files selected by the
  Boarding analysis command.
- Seven concurrent lifecycle tests: 78 assertions, separate PostgreSQL sessions
  and actual lock contention verified using pg_blocking_pids.
- Formatting, Composer validation, dependency audit and diff checks passed
  during local verification.

The PHPStan result covers the selected application files; it does not claim
static-analysis coverage of every migration, route or test-support file.

The learner-link and occupancy requirements in sections 6 and 13 describe
deferred acceptance criteria. This delivery rejects learner_id and learner_ids;
it does not implement learner occurrence links or historical occupancy inference.
Attendance, movements, reminders and escalation remain separate later work.

configure_boarding_reporting controls reporting-policy configuration,
explicit obligation materialization and occurrence-category mutations.
Recording, leadership viewing and review retain their separate permissions.
Existing manage_boarding does not implicitly grant these reporting permissions.

## 16. Occurrence-category version contract

Category responses expose an opaque 64-character lowercase hexadecimal version.
Updates require the current expected_version; a stale token returns HTTP 409
without changing category or audit evidence.

The version includes category identity, its database-managed lock_version,
category name and active state. Each actual name or active-state change advances
the counter. Restoring earlier values does not restore an earlier version.
A true no-op retains the current token and creates no mutation audit entry.

The database trigger controls counter changes, including direct SQL updates.
Category mutation and strict audit share one transaction, so an audit failure
also rolls back the revision increment.

The original occurrence migration supports fresh schema creation. The forward
category-revision migration upgrades existing local schemas. Its down method
deliberately refuses rollback; use a reviewed forward migration.

These local results do not establish CI success, deployment or closure of
deferred delivery slices.
