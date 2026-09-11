# Teacher Duty Daily Reporting â€” Phase 6A.9G-B Database & Domain Contract

Status: FROZEN once committed
Phase: 6A.9G-B
Parent foundation:

- Phase 6A.9F-B â€” Teacher Duty roster and duty-period foundation
- Phase 6A.9G-A â€” Daily occurrences and configurable occurrence categories

## 1. Purpose

Phase 6A.9G-B introduces the Teacher Duty daily-report lifecycle.

The phase provides:

- one explicit daily-report aggregate for a Teacher Duty period and duty date;
- draft daily reporting;
- explicit submission;
- server-owned submission evidence;
- deterministic daily reporting deadlines;
- derived overdue and late semantics;
- immutable lifecycle history;
- tenant-safe persistence;
- database and domain invariants.

The daily report is distinct from Teacher Duty occurrences.

Occurrences are factual records captured during duty.

The daily report is the explicit reporting obligation and submission envelope for a duty date.

Recording an occurrence MUST NOT, by itself, mean that the daily report has been submitted or completed.

---

## 2. Scope

### 2.1 Included

6A.9G-B includes:

1. Teacher Duty daily-report persistence.
2. Teacher Duty daily-report history.
3. School-level Teacher Duty daily-report deadline configuration.
4. Explicit draft creation/opening.
5. Explicit daily-report submission.
6. Server-owned submission evidence.
7. School-timezone deadline computation.
8. Derived NOT_STARTED, DRAFT, SUBMITTED, and OVERDUE presentation states.
9. Derived late-submission semantics.
10. Tenant-safe database constraints and foreign keys.
11. Domain-service validation and locking.
12. Database-integrity and domain-service regression tests.

### 2.2 Explicitly excluded

The following remain outside 6A.9G-B:

- weekly report aggregation;
- weekly submission;
- weekly review or approval;
- HOD / Head / Deputy review workflow;
- 6A.9H scoped responsibility authorization;
- general notification or escalation infrastructure;
- SMS, email, or push delivery;
- Teacher Duty frontend implementation;
- unrelated Teacher Duty roster changes;
- HTTP/controller/request/route implementation unless separately frozen later;
- commercial entitlement enforcement.

Phase 6A.9G-C owns weekly aggregation, submission, and review.

Phase 6A.9H owns scoped responsibility authorization.

Notification/escalation remains a later layer.

---

## 3. Existing authoritative boundaries

### 3.1 School timezone

`schools.timezone` is the authoritative school timezone.

Teacher Duty daily-report date and deadline calculations MUST use:

`$school->timezone ?: config('app.timezone')`

The daily-report subsystem MUST NOT introduce a second Teacher Duty timezone field.

### 3.2 Occurrences

`teacher_duty_occurrences` remains authoritative for daily occurrences.

6A.9G-B MUST NOT duplicate occurrence rows inside the daily-report table.

A daily report may query occurrences using:

- `school_id`;
- `duty_period_id`;
- `occurrence_date`.

6A.9G-B MUST NOT call an occurrence mutation method merely to determine whether occurrences exist.

### 3.3 Duty periods

`teacher_duty_periods` remains authoritative for the Teacher Duty period and inclusive duty-period date range.

A daily report MUST belong to exactly one Teacher Duty period.

Its `report_date` MUST fall inclusively between that period's `start_date` and `end_date`.

---

## 4. School-level reporting configuration

The existing `school_settings` aggregate shall own Teacher Duty daily-report deadline configuration.

The following fields are added:

### `teacher_duty_report_deadline_time`

- type: `TIME WITHOUT TIME ZONE`;
- not nullable;
- default: `17:00:00`;
- interpreted in the authoritative school timezone.

### `teacher_duty_report_grace_minutes`

- integer;
- not nullable;
- default: `120`;
- MUST be non-negative;
- PostgreSQL CHECK MUST enforce:

`teacher_duty_report_grace_minutes >= 0`

The effective reporting deadline for a duty date is:

`report_date at teacher_duty_report_deadline_time in school timezone + teacher_duty_report_grace_minutes`

Example:

- report date: `2026-09-14`
- configured deadline time: `17:00`
- grace: `120` minutes
- effective deadline: `2026-09-14 19:00` in the school timezone.

The defaults are product defaults only.

Schools may later configure these values through an appropriate settings surface.

6A.9G-B does not require that settings HTTP surface.

---

## 5. Daily-report aggregate

Create:

`teacher_duty_daily_reports`

### Required columns

- `id` UUID primary key;
- `school_id` UUID not null;
- `duty_period_id` UUID not null;
- `report_date` DATE not null;
- `status` VARCHAR not null;
- `summary` TEXT nullable;
- `deadline_at` TIMESTAMPTZ not null;
- `created_by` UUID not null;
- `submitted_by` UUID nullable;
- `submitted_at` TIMESTAMPTZ nullable;
- `created_at` TIMESTAMPTZ not null;
- `updated_at` TIMESTAMPTZ not null.

### 5.1 Identity

There MUST be at most one daily report for:

`(school_id, duty_period_id, report_date)`

PostgreSQL MUST enforce this with a UNIQUE constraint.

### 5.2 Persisted status

The only persisted statuses in 6A.9G-B are:

- `draft`
- `submitted`

PostgreSQL MUST enforce the allowed set.

`overdue` MUST NOT be persisted as the report's lifecycle status.

`not_started` MUST NOT be persisted as a report lifecycle status.

### 5.3 Summary

`summary` is optional in 6A.9G-B.

A report MAY be submitted with no occurrences and with a null summary.

Explicit submission with zero occurrences is a valid declaration that the day's reporting obligation has been completed with nothing requiring occurrence recording.

6A.9G-B MUST NOT fabricate a synthetic "no occurrence" occurrence row.

---

## 6. Deadline snapshot

`deadline_at` is server-owned.

The client MUST NOT supply or override it.

When a daily report is first created, the domain service computes `deadline_at` using:

1. the report date;
2. the school's authoritative timezone;
3. the current school-level Teacher Duty reporting deadline time;
4. the current configured grace minutes.

The resulting instant is persisted as `TIMESTAMPTZ`.

Once created, `deadline_at` MUST NOT change merely because the school later changes its reporting settings.

This prevents later configuration changes from rewriting the historical deadline of an existing daily report.

For a duty date for which no daily-report row exists, NOT_STARTED / OVERDUE presentation may be computed using the current school configuration until the report is materialized.

Once materialized, the persisted `deadline_at` is authoritative for that report.

---

## 7. Presentation-state semantics

The domain exposes the following conceptual states:

- `NOT_STARTED`
- `DRAFT`
- `SUBMITTED`
- `OVERDUE`

These are presentation/domain-result states.

They are not all persisted lifecycle values.

### 7.1 NOT_STARTED

A valid reporting obligation is `NOT_STARTED` when:

- the duty date belongs to the Teacher Duty period;
- no daily-report row exists for that period/date;
- the effective reporting deadline has not yet been reached.

### 7.2 DRAFT

A report is `DRAFT` when:

- a daily-report row exists;
- persisted `status = draft`;
- current time is before `deadline_at`.

### 7.3 SUBMITTED

A report is `SUBMITTED` whenever:

- persisted `status = submitted`;
- `submitted_by` is non-null;
- `submitted_at` is non-null.

A submitted report remains `SUBMITTED` even if submission occurred after the deadline.

### 7.4 OVERDUE

A reporting obligation is `OVERDUE` when:

- it is not submitted; and
- current time is greater than or equal to the applicable effective deadline.

This includes:

1. no daily-report row exists and the computed deadline has been reached; or
2. a report exists with persisted `status = draft` and `deadline_at` has been reached.

`OVERDUE` is derived truth.

It MUST NOT depend on a scheduler, cron job, queue worker, or notification job having executed.

---

## 8. Late submission

Late submission is allowed.

A report may transition from an overdue derived state to persisted `submitted`.

The report remains `SUBMITTED` after late submission.

Lateness is derived as:

`submitted_at > deadline_at`

Submission exactly at `deadline_at` is not late.

The system MUST retain enough evidence to determine permanently whether submission was late.

The client MUST NOT control:

- `deadline_at`;
- `submitted_by`;
- `submitted_at`;
- derived `late`;
- derived presentation state.

---

## 9. Lifecycle transitions

6A.9G-B allows:

`NOT_STARTED -> DRAFT`

`DRAFT -> SUBMITTED`

`NOT_STARTED/OVERDUE -> DRAFT -> SUBMITTED`

A draft that has become overdue remains persisted as `draft` while its derived presentation state is `OVERDUE`.

No transition from `submitted` back to `draft` exists in 6A.9G-B.

No resubmission, withdrawal, reopening, amendment, approval, rejection, or changes-requested lifecycle is introduced in this phase.

Those require a later explicit contract.

---

## 10. Daily-report history

Create:

`teacher_duty_daily_report_history`

Required columns:

- `id` UUID primary key;
- `school_id` UUID not null;
- `daily_report_id` UUID not null;
- `actor_user_id` UUID not null;
- `from_status` VARCHAR nullable;
- `to_status` VARCHAR not null;
- `event` VARCHAR not null;
- `created_at` TIMESTAMPTZ not null.

History is append-only lifecycle evidence.

6A.9G-B requires history entries for:

### Draft creation

- `from_status = null`
- `to_status = draft`
- `event = created`

### Submission

- `from_status = draft`
- `to_status = submitted`
- `event = submitted`

A late submission uses the same lifecycle event.

Lateness is determined from `submitted_at` versus `deadline_at`; it does not require a separate persisted lifecycle status.

Generic deletion of daily-report history MUST NOT be supported.

---

## 11. Submission evidence invariant

Database and domain rules MUST enforce:

### Draft

When `status = draft`:

- `submitted_by IS NULL`
- `submitted_at IS NULL`

### Submitted

When `status = submitted`:

- `submitted_by IS NOT NULL`
- `submitted_at IS NOT NULL`

PostgreSQL MUST enforce the correspondence with a CHECK constraint.

The client MUST NOT directly set any of these server-owned lifecycle fields.

---

## 12. Tenant integrity

Every daily report belongs to exactly one school.

Every daily-report history row belongs to exactly one school.

Cross-school references MUST fail closed.

Tenant-safe composite foreign keys MUST be used where necessary so that the database prevents a report from referencing another school's:

- Teacher Duty period;
- creator;
- submitter;
- history actor.

History MUST NOT be able to reference a report from another school.

All lifecycle actor foreign keys use RESTRICT semantics.

Daily reports and their history are preserved operational records.

Generic delete and soft-delete lifecycle are not part of 6A.9G-B.

---

## 13. Actor eligibility

Until Phase 6A.9H introduces scoped Teacher Duty responsibility authorization, 6A.9G-B uses the existing Teacher Duty actor eligibility boundary.

An actor performing a daily-report mutation MUST:

- exist;
- belong to the same school;
- be active;
- not be deleted;
- not be suspended.

6A.9G-B MUST NOT claim that this eligibility rule is the final responsibility-authorization model.

Scoped responsibility authorization is explicitly deferred to 6A.9H.

No new broad permission is introduced merely to implement the database/domain slice.

---

## 14. Domain service

Introduce a dedicated Teacher Duty daily-report domain service.

Recommended boundary:

`TeacherDutyDailyReportService`

The first slice shall expose explicit mutation/read operations equivalent to:

### `openReport(...)`

Responsibilities:

- validate school;
- validate eligible actor;
- lock and validate same-school Teacher Duty period;
- validate strict report date;
- validate report date lies within period;
- return existing same-school report if already present where idempotent opening is permitted;
- otherwise compute server-owned `deadline_at`;
- create `draft`;
- set server-owned `school_id`;
- set server-owned `created_by`;
- append creation history.

It MUST NOT automatically submit the report.

### `updateDraft(...)`

Responsibilities:

- validate tenant and eligible actor;
- lock report;
- allow modification only while persisted status is `draft`;
- update mutable draft fields only;
- MUST NOT allow lifecycle or deadline evidence to be client-controlled.

A draft MAY still be edited after becoming overdue until it is submitted.

### `submitReport(...)`

Responsibilities:

- validate tenant and eligible actor;
- lock report;
- allow transition only from persisted `draft`;
- set persisted status to `submitted`;
- set server-owned `submitted_by`;
- set server-owned `submitted_at = now()`;
- append immutable submission history;
- return refreshed report.

Late submission MUST remain valid.

### State/read operation

The domain/read layer may expose a status computation operation that returns:

- `NOT_STARTED`;
- `DRAFT`;
- `SUBMITTED`;
- `OVERDUE`;

plus:

- effective deadline;
- submitted timestamp where applicable;
- derived `late` boolean for submitted reports.

The read operation MUST NOT mutate merely to calculate overdue truth.

---

## 15. Date handling

All duty-date input MUST use strict:

`YYYY-MM-DD`

The report date is interpreted as a school-local calendar date.

The service MUST reject malformed or normalized-but-not-identical dates.

The same strict-date convention already used by the Teacher Duty domain shall be followed.

The report date MUST lie within the selected Teacher Duty period inclusively.

---

## 16. Occurrence relationship

A daily report does not own occurrence lifecycle.

Occurrences continue to belong to the Teacher Duty period.

For presentation, the daily report may expose occurrences matching:

- the same `school_id`;
- the same `duty_period_id`;
- `occurrence_date = report_date`.

A report may contain:

- zero occurrences;
- one occurrence;
- many occurrences.

Occurrence count MUST NOT determine whether the report is submitted.

Occurrence creation MUST NOT automatically submit or complete a daily report.

6A.9G-B MUST NOT introduce a hidden model observer, created event, or implicit occurrence hook to mutate daily reports.

---

## 17. Overdue computation and scheduler independence

The authoritative overdue predicate is time-based:

`not submitted AND now >= effective deadline`

For an existing report, `deadline_at` is authoritative.

For a not-yet-materialized reporting obligation, the effective deadline is calculated from school configuration.

A future scheduled command may discover overdue obligations for notification or escalation.

Such a scheduler:

- MAY query overdue obligations;
- MAY request idempotent notifications;
- MUST NOT be required to make an obligation overdue;
- MUST NOT rewrite the lifecycle merely to establish overdue truth.

Notification and escalation delivery are outside 6A.9G-B.

---

## 18. Database indexing

The migration MUST provide indexes supporting at least:

1. unique daily-report identity:
   `(school_id, duty_period_id, report_date)`;

2. tenant/date lookup:
   `(school_id, report_date)`;

3. period/date lookup:
   `(school_id, duty_period_id, report_date)`;

4. submission/deadline discovery appropriate for pending reporting;

5. history lookup:
   `(school_id, daily_report_id, created_at)`.

Exact index names are implementation details, but tenant-leading indexes are required.

---

## 19. Model rules

### `TeacherDutyDailyReport`

Must:

- extend `TenantModel`;
- use UUID identity;
- use narrow fillable fields;
- keep tenant/lifecycle/deadline/server actor fields server-owned;
- cast `report_date` as date;
- cast `deadline_at`, `submitted_at`, `created_at`, and `updated_at` as datetimes;
- expose relationships to School, TeacherDutyPeriod, creator, submitter, and history;
- prohibit generic model deletion.

### `TeacherDutyDailyReportHistory`

Must:

- extend `TenantModel`;
- use UUID identity;
- expose report and actor relationships;
- behave as append-only history;
- prohibit generic model deletion.

---

## 20. Concurrency

Opening and submitting daily reports MUST be transaction-safe.

The unique identity constraint is the final guard against duplicate reports.

Submission MUST lock the target report before validating and mutating lifecycle state.

Concurrent submissions MUST NOT create conflicting lifecycle evidence.

At most one persisted transition from `draft` to `submitted` may succeed.

---

## 21. Client-control prohibitions

Clients MUST NOT control:

- `id`;
- `school_id`;
- `status`;
- `deadline_at`;
- `created_by`;
- `submitted_by`;
- `submitted_at`;
- history actor identity;
- history from/to status;
- history event;
- derived presentation state;
- derived overdue;
- derived late.

Only explicitly mutable draft content may be client supplied.

---

## 22. Failure behaviour

The domain MUST fail closed for:

- missing school;
- tenant mismatch;
- missing duty period;
- cross-school duty period;
- invalid report date;
- date outside duty period;
- ineligible actor;
- duplicate conflicting report creation;
- mutation of a submitted report;
- invalid lifecycle transition;
- cross-school creator/submitter/history actor;
- attempts to control server-owned lifecycle evidence.

No partial report/history mutation may remain after a failed transaction.

---

## 23. Migration behaviour

The migration MUST NOT use `migrate:fresh`.

The migration shall:

1. extend `school_settings` with the two Teacher Duty daily-report configuration fields;
2. create `teacher_duty_daily_reports`;
3. create `teacher_duty_daily_report_history`;
4. add PostgreSQL CHECK constraints;
5. add tenant-safe foreign keys;
6. add required indexes.

Existing schools receive the database defaults:

- deadline time `17:00:00`;
- grace `120` minutes.

The migration does not need to backfill historical daily reports.

Existing Teacher Duty periods and occurrences remain untouched.

The down migration MUST drop dependent history before reports and remove the added settings columns safely.

---

## 24. Required tests

At minimum, focused tests MUST prove:

### Settings/database

- deadline time defaults correctly;
- grace minutes default correctly;
- negative grace rejected by PostgreSQL.

### Daily-report identity

- one report per school/period/date;
- same date may exist in different schools;
- same date may exist in different periods where otherwise valid.

### Tenant integrity

- cross-school period rejected;
- cross-school creator rejected;
- cross-school submitter rejected;
- cross-school history actor rejected;
- cross-school history/report relation rejected.

### Date rules

- strict `YYYY-MM-DD`;
- date before period rejected;
- date after period rejected;
- inclusive start date accepted;
- inclusive end date accepted.

### Lifecycle

- open creates draft;
- open records creation history;
- client cannot set lifecycle/server fields;
- draft can be updated;
- submit creates server-owned submission evidence;
- submit records history;
- submitted report cannot return to draft;
- submitted report cannot be mutated through draft update;
- duplicate submission fails safely.

### Overdue

- before deadline without report => NOT_STARTED;
- at deadline without report => OVERDUE;
- before deadline draft => DRAFT;
- at deadline draft => OVERDUE;
- scheduler execution is not required for overdue truth.

### Late submission

- overdue draft may still be submitted;
- late submission becomes SUBMITTED;
- late=true when submitted_at > deadline_at;
- submission exactly at deadline is not late.

### Occurrences

- zero occurrences does not prevent explicit submission;
- one or many occurrences do not automatically submit the report;
- report reads only same-school/same-period/same-date occurrences.

### Preservation

- daily report cannot be generically deleted;
- history cannot be generically deleted;
- existing occurrence records remain untouched.

---

## 25. Validation order

Implementation validation shall follow:

1. migration apply;
2. database catalog / constraint verification;
3. migration rollback;
4. migration re-apply;
5. focused settings/database integrity tests;
6. focused daily-report domain tests;
7. full TeacherDuty test directory;
8. related school-settings / onboarding regression where affected;
9. full repository test suite;
10. Pint;
11. rerun affected suites if formatting changes executable files;
12. `git diff --check`.

Testing database reset MUST avoid destructive `migrate:fresh` where the established workflow forbids it.

---

## 26. Frozen phase boundary

Once this contract is committed, implementation MUST conform to it.

Any newly discovered contract gap MUST be addressed by a separate clarification document and separate commit.

The frozen contract commit MUST NOT be amended or rewritten merely to accommodate implementation convenience.

6A.9G-B ends when the database/domain slice described here is implemented, tested, and merged.

HTTP exposure, weekly reporting/review, scoped responsibility authorization, notification/escalation, and frontend workflow remain subsequent work.
