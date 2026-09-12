# Teacher Duty Weekly Reporting — Phase 6A.9G-C Database & Domain Contract

Status: FROZEN
Phase: 6A.9G-C

Parent foundations:

- Phase 6A.9F-B — Teacher Duty roster and duty-period foundation
- Phase 6A.9G-A — Teacher Duty occurrences
- Phase 6A.9G-B — Teacher Duty daily reporting and overdue state

## 1. Purpose

Phase 6A.9G-C introduces the Teacher Duty weekly reporting and review lifecycle.

The weekly report is the reporting aggregate for one Teacher Duty period.

The phase provides:

- one weekly report per Teacher Duty period;
- editable weekly narrative reporting;
- explicit weekly submission;
- server-owned submission evidence;
- a frozen submission evidence snapshot;
- leadership review decisions;
- changes-requested and resubmission workflow;
- immutable lifecycle history;
- tenant-safe persistence;
- database and domain invariants.

The weekly report MUST NOT replace or duplicate the authoritative Teacher Duty period, daily reports, occurrences, or assignments.

---

## 2. Scope

### 2.1 Included

6A.9G-C includes:

1. Teacher Duty weekly-report persistence.
2. Weekly-report lifecycle history.
3. Draft weekly reporting.
4. Explicit weekly submission.
5. Server-owned submission evidence.
6. Server-generated evidence snapshot at every submission/resubmission.
7. Weekly review decision.
8. Approval.
9. Changes requested.
10. Rejection.
11. Resubmission after changes are requested.
12. Tenant-safe database constraints.
13. Domain-service validation and locking.
14. Database-integrity and domain-service regression tests.

### 2.2 Explicitly excluded

The following remain outside 6A.9G-C:

- final scoped Teacher Duty responsibility authorization;
- notification and escalation delivery;
- SMS, email, or push delivery;
- Teacher Duty frontend;
- commercial entitlement enforcement;
- unrelated roster changes;
- mutation of occurrence lifecycle;
- mutation of daily-report lifecycle merely to construct a weekly report;
- generic cross-module approval integration unless separately frozen;
- HTTP/controller/request/route implementation unless separately frozen later.

Phase 6A.9H owns final scoped responsibility authorization.

---

## 3. Authoritative boundaries

### 3.1 Duty period

`teacher_duty_periods` remains authoritative for:

- the Teacher Duty period;
- its school;
- its academic week;
- its inclusive start and end dates.

The weekly report MUST belong to exactly one Teacher Duty period.

6A.9G-C MUST NOT introduce an independent calendar-week identity that can disagree with the duty period.

### 3.2 Daily reports

`teacher_duty_daily_reports` remains authoritative for individual duty-date reporting.

Weekly reporting MUST NOT copy daily-report rows into the weekly-report aggregate.

Daily-report lifecycle remains independently governed by 6A.9G-B.

### 3.3 Occurrences

`teacher_duty_occurrences` remains authoritative for Teacher Duty occurrences.

Weekly reporting MUST NOT duplicate occurrence records.

### 3.4 Assignments

Existing Teacher Duty assignments remain authoritative for Teachers assigned to the duty period.

Weekly reporting MUST NOT introduce a second assignment mechanism.

---

## 4. Weekly-report identity

Create:

`teacher_duty_weekly_reports`

There MUST be at most one weekly report for:

`(school_id, duty_period_id)`

PostgreSQL MUST enforce this with a UNIQUE constraint.

A duty period MAY exist without a weekly-report row.

Opening a weekly report materializes the draft reporting aggregate.

---

## 5. Required weekly-report columns

The weekly-report table shall contain at least:

- `id` UUID primary key;
- `school_id` UUID not null;
- `duty_period_id` UUID not null;
- `status` VARCHAR not null;
- `summary` TEXT nullable;
- `highlights` TEXT nullable;
- `challenges` TEXT nullable;
- `recommendations` TEXT nullable;
- `evidence_snapshot` JSONB nullable;
- `created_by` UUID not null;
- `submitted_by` UUID nullable;
- `submitted_at` TIMESTAMPTZ nullable;
- `reviewed_by` UUID nullable;
- `reviewed_at` TIMESTAMPTZ nullable;
- `review_comment` TEXT nullable;
- `created_at` TIMESTAMPTZ not null;
- `updated_at` TIMESTAMPTZ not null.

---

## 6. Persisted lifecycle statuses

The allowed persisted statuses are:

- `draft`
- `submitted`
- `changes_requested`
- `approved`
- `rejected`

PostgreSQL MUST enforce the allowed set.

6A.9G-C does not persist a separate `under_review` status.

Reading or viewing a submitted report MUST NOT mutate its lifecycle.

---

## 7. Lifecycle transitions

Allowed transitions are:

`DRAFT -> SUBMITTED`

`SUBMITTED -> APPROVED`

`SUBMITTED -> CHANGES_REQUESTED`

`SUBMITTED -> REJECTED`

`CHANGES_REQUESTED -> SUBMITTED`

No other lifecycle transition is valid in 6A.9G-C.

In particular:

- approved reports cannot be reopened;
- rejected reports cannot be reopened;
- submitted reports cannot be edited;
- changes-requested reports may be edited;
- viewing a report does not change its status;
- no withdrawal transition exists;
- no generic reset-to-draft transition exists.

A changes-requested report remains persisted as `changes_requested` while corrections are being made.

It transitions directly to `submitted` when explicitly resubmitted.

---

## 8. Editable narrative content

The Teacher Duty reporting actor may edit:

- `summary`;
- `highlights`;
- `challenges`;
- `recommendations`;

only while status is:

- `draft`; or
- `changes_requested`.

The client MUST NOT control lifecycle evidence, review evidence, tenant identity, or evidence snapshots.

---

## 9. Weekly submission eligibility

A weekly report may be opened and edited while its Teacher Duty period is current.

Initial submission and resubmission are permitted only after the authoritative
Teacher Duty period has been ended through the existing roster lifecycle.

For weekly submission eligibility, an ended duty period must have:

- `active = false`;
- `ended_by` non-null;
- `ended_at` non-null.

6A.9G-C MUST NOT automatically end a Teacher Duty period merely to permit
weekly submission.

The weekly-report subsystem MUST NOT introduce a second independent
"week complete" lifecycle.

If the duty period is still current, submission or resubmission MUST fail
closed.

Draft editing remains permitted while the period is current.

## 10. Weekly submission completeness

Weekly submission MUST NOT require every daily reporting obligation to have been submitted.

A duty period may therefore be submitted for weekly review while one or more daily reports are:

- missing;
- draft;
- overdue;
- late-submitted.

The weekly evidence snapshot MUST make such incompleteness visible.

Weekly submission MUST NOT silently create, submit, complete, or modify missing/incomplete daily reports.

This preserves the distinction between:

- daily reporting compliance; and
- weekly reporting submission.

---

## 11. Submission evidence snapshot

Every transition to `submitted` MUST generate a new server-owned evidence snapshot.

The client MUST NOT supply or modify the snapshot.

The snapshot records the reporting evidence presented for that particular submission.

At minimum it shall contain:

- snapshot schema version, initially `1`;
- duty-period start date;
- duty-period end date;
- expected daily-report count;
- NOT_STARTED daily-report count;
- DRAFT daily-report count;
- OVERDUE daily-report count;
- SUBMITTED daily-report count;
- late-submitted daily-report count;

The NOT_STARTED, DRAFT, OVERDUE, and SUBMITTED counts are mutually exclusive
presentation-state counts evaluated at `snapshot_generated_at`.

They MUST satisfy:

`expected_daily_report_count =
not_started_daily_report_count +
draft_daily_report_count +
overdue_daily_report_count +
submitted_daily_report_count`

`late_submitted_daily_report_count` is an additional subset of
`submitted_daily_report_count` and is not added again when reconciling the
expected count.

The weekly subsystem MUST use the presentation-state semantics frozen by
6A.9G-B and MUST NOT invent different meanings for NOT_STARTED, DRAFT,
SUBMITTED, OVERDUE, or late.
- total occurrence count;
- occurrence category breakdown where categories are available;
- snapshot generation timestamp.

`snapshot_version` is server-owned.

The initial 6A.9G-C snapshot schema version is `1`.

Future changes to snapshot structure MUST use an explicit versioning rule and
MUST NOT make historical snapshots uninterpretable.

The snapshot MAY contain additional server-derived aggregate evidence where required by the frozen contract.

The snapshot MUST NOT contain copied occurrence rows.

The snapshot MUST NOT become an alternative source of truth for current occurrence or daily-report state.

Its purpose is historical review evidence.

---

## 12. Snapshot immutability and resubmission

Once a submission has occurred, the evidence presented for that submission MUST remain historically recoverable.

If leadership requests changes and the report is subsequently resubmitted:

1. current authoritative daily reports and occurrences are evaluated again;
2. a new evidence snapshot is generated;
3. the resubmission receives new server-owned submission evidence;
4. previous submission evidence remains recoverable through immutable history.

The system MUST NOT rewrite historical submission evidence to make an earlier review appear to have seen later data.

---

## 13. Expected daily-report count

Expected daily-report count is based on the inclusive dates belonging to the Teacher Duty period.

Each valid duty date represents one daily reporting obligation under the 6A.9G-B daily-report model.

The weekly domain MUST calculate completeness using the same school-local date semantics established by the Teacher Duty domain.

The weekly subsystem MUST NOT invent a second definition of a duty date.

---

## 14. Occurrence aggregation

Occurrence aggregates are derived using:

- the same `school_id`;
- the same `duty_period_id`;
- occurrence dates belonging to that duty period.

Occurrence aggregation MUST be read-only.

Weekly report creation, submission, resubmission, review, approval, changes request, or rejection MUST NOT mutate occurrences.

Occurrence category breakdown MUST use the authoritative occurrence-category identity/configuration already present in the Teacher Duty occurrence domain.

---

## 15. Submission evidence

For persisted status `submitted`:

- `submitted_by` MUST be non-null;
- `submitted_at` MUST be non-null;
- `evidence_snapshot` MUST be non-null.

These fields are server-owned.

Submission and resubmission timestamps MUST represent the actual submission instant.

TIMESTAMPTZ persistence MUST preserve the instant correctly across database session timezones.

---

## 16. Review evidence

Review decisions apply only to a currently `submitted` weekly report.

### Approval

`submitted -> approved`

Approval MUST set server-owned:

- `reviewed_by`;
- `reviewed_at`.

### Changes requested

`submitted -> changes_requested`

Changes requested MUST set:

- `reviewed_by`;
- `reviewed_at`;
- `review_comment`.

A meaningful review comment is REQUIRED when changes are requested.

### Rejection

`submitted -> rejected`

Rejection MUST set:

- `reviewed_by`;
- `reviewed_at`;
- `review_comment`.

A meaningful review comment is REQUIRED when a report is rejected.

The client MUST NOT directly control reviewer identity or review timestamp.

---

## 17. Database lifecycle-evidence invariants

PostgreSQL MUST enforce lifecycle/evidence correspondence.

### Draft

When `status = draft`:

- `submitted_by IS NULL`;
- `submitted_at IS NULL`;
- `evidence_snapshot IS NULL`;
- `reviewed_by IS NULL`;
- `reviewed_at IS NULL`;
- `review_comment IS NULL`.

### Submitted

When `status = submitted`:

- `submitted_by IS NOT NULL`;
- `submitted_at IS NOT NULL`;
- `evidence_snapshot IS NOT NULL`;
- `reviewed_by IS NULL`;
- `reviewed_at IS NULL`;
- `review_comment IS NULL`.

### Changes requested

When `status = changes_requested`:

- `submitted_by IS NOT NULL`;
- `submitted_at IS NOT NULL`;
- `evidence_snapshot IS NOT NULL`;
- `reviewed_by IS NOT NULL`;
- `reviewed_at IS NOT NULL`;
- `review_comment IS NOT NULL`.

The review comment MUST contain meaningful non-whitespace content.

### Approved

When `status = approved`:

- `submitted_by IS NOT NULL`;
- `submitted_at IS NOT NULL`;
- `evidence_snapshot IS NOT NULL`;
- `reviewed_by IS NOT NULL`;
- `reviewed_at IS NOT NULL`.

`review_comment` may be null for approval.

### Rejected

When `status = rejected`:

- `submitted_by IS NOT NULL`;
- `submitted_at IS NOT NULL`;
- `evidence_snapshot IS NOT NULL`;
- `reviewed_by IS NOT NULL`;
- `reviewed_at IS NOT NULL`;
- `review_comment IS NOT NULL`.

The rejection comment MUST contain meaningful non-whitespace content.

PostgreSQL CHECK constraints MUST enforce all correspondence that can be
enforced at the row level.

## 18. Resubmission

A report in `changes_requested` may be edited.

Resubmission:

`changes_requested -> submitted`

MUST:

- validate the actor;
- lock the weekly report;
- generate a fresh server-owned evidence snapshot;
- clear `reviewed_by`;
- clear `reviewed_at`;
- clear `review_comment`;
- replace the aggregate `evidence_snapshot` with the newly generated snapshot;
- set new `submitted_by`;
- set new `submitted_at`;
- preserve all previous review and submission evidence in append-only history;
- append immutable resubmission history.

---

## 19. Weekly-report history

Create:

`teacher_duty_weekly_report_history`

Required columns shall include:

- `id` UUID primary key;
- `school_id` UUID not null;
- `weekly_report_id` UUID not null;
- `actor_user_id` UUID not null;
- `from_status` VARCHAR nullable;
- `to_status` VARCHAR not null;
- `event` VARCHAR not null;
- `comment` TEXT nullable;
- `evidence_snapshot` JSONB nullable;
- `created_at` TIMESTAMPTZ not null.

History is append-only.

Required events include:

### Creation

- `from_status = null`
- `to_status = draft`
- `event = created`

### Initial submission

- `from_status = draft`
- `to_status = submitted`
- `event = submitted`
- submission evidence snapshot retained

### Changes requested

- `from_status = submitted`
- `to_status = changes_requested`
- `event = changes_requested`
- review comment retained

### Resubmission

- `from_status = changes_requested`
- `to_status = submitted`
- `event = resubmitted`
- new evidence snapshot retained

### Approval

- `from_status = submitted`
- `to_status = approved`
- `event = approved`

### Rejection

- `from_status = submitted`
- `to_status = rejected`
- `event = rejected`
- review comment retained

Generic update or deletion of history MUST NOT be supported.

---

## 20. Tenant integrity

Every weekly report belongs to exactly one school.

Every weekly-report history row belongs to exactly one school.

Cross-school references MUST fail closed.

Tenant-safe composite foreign keys MUST prevent a weekly report from referencing another school's:

- duty period;
- creator;
- submitter;
- reviewer.

History MUST NOT reference another school's weekly report or actor.

Lifecycle actor foreign keys use RESTRICT semantics.

Weekly reports and their history are preserved operational records.

Generic deletion and soft deletion are outside 6A.9G-C.

---

## 21. Actor eligibility before 6A.9H

6A.9G-C MUST NOT pretend to implement final Teacher Duty responsibility authorization.

Until 6A.9H, a mutation actor must at minimum:

- exist;
- belong to the same school;
- be active;
- not be deleted;
- not be suspended.

Review operations MUST remain distinguishable from reporting operations at the domain boundary so 6A.9H can later impose scoped reviewer authority without redesigning the lifecycle.

No broad permission is introduced merely to implement this database/domain slice.

---

Even before 6A.9H finalizes scoped responsibility authorization, a reviewer
MUST NOT review the same submitted version that they submitted.

For any review decision:

`reviewed_by != submitted_by`

Because both values exist on the weekly-report row, PostgreSQL MUST enforce
this separation wherever review evidence is present.

The database CHECK must reject a reviewed row where:

`reviewed_by = submitted_by`

This is a minimum separation-of-duty invariant, not the final reviewer
authorization model.

6A.9H will define which otherwise eligible leadership actor has authority to
review a particular report.

## 22. Domain service

Introduce a dedicated service equivalent to:

`TeacherDutyWeeklyReportService`

The domain boundary shall expose explicit operations equivalent to:

### `openReport(...)`

Must:

- validate school;
- validate eligible actor;
- lock and validate same-school duty period;
- return an existing same-school weekly report where idempotent opening is valid;
- otherwise create a draft;
- set server-owned tenant/creator evidence;
- append creation history.

It MUST NOT submit automatically.

### `updateReport(...)`

Must:

- validate tenant and actor;
- lock the report;
- permit narrative edits only in `draft` or `changes_requested`;
- reject edits to lifecycle/server-owned fields.

### `submitReport(...)`

Must:

- validate tenant and actor;
- lock report;
- accept only `draft`;
- calculate the server-owned evidence snapshot;
- transition to `submitted`;
- set submission evidence;
- append submission history atomically.

### `resubmitReport(...)`

Must:

- accept only `changes_requested`;
- calculate a fresh evidence snapshot;
- transition directly to `submitted`;
- set new submission evidence;
- preserve prior review/submission evidence through history;
- append resubmission history atomically.

### `reviewReport(...)`

Must perform exactly one explicit decision:

- approve;
- request changes;
- reject.

It must:

- validate tenant;
- validate actor under the temporary 6A.9G-C eligibility boundary;
- lock the report;
- require current status `submitted`;
- validate decision;
- require comments for changes-requested/rejected decisions;
- set server-owned review evidence;
- append immutable history atomically.

### Read/evidence operation

Read operations may return:

- current weekly lifecycle state;
- current narrative;
- current authoritative daily-report completeness;
- current authoritative occurrence aggregates;
- latest submission evidence snapshot;
- submission/review evidence;
- immutable history.

Read operations MUST NOT mutate lifecycle state.

---

## 23. Concurrency

Weekly report opening, submission, resubmission, and review MUST be transaction-safe.

The unique `(school_id, duty_period_id)` constraint is the final duplicate-report guard.

Lifecycle mutations MUST lock the weekly-report row.

Concurrent review attempts MUST NOT create two successful decisions for the same submitted version.

Concurrent submission/resubmission MUST NOT create duplicate successful lifecycle transitions.

Report and corresponding history mutation MUST commit atomically.

On failure, neither partial lifecycle state nor partial history may remain.

---

## 24. Client-controlled fields

The client may control only permitted narrative content and an allowed review comment/decision input where applicable.

The client MUST NOT control:

- `id`;
- `school_id`;
- `duty_period_id` after aggregate creation;
- persisted lifecycle status directly;
- `created_by`;
- `submitted_by`;
- `submitted_at`;
- `reviewed_by`;
- `reviewed_at`;
- evidence snapshots;
- history rows;
- derived daily-report counts;
- occurrence aggregates.

---

## 25. Model rules

`TeacherDutyWeeklyReport` MUST:

- use UUID identity;
- be tenant-aware;
- expose only narrative fields for ordinary mass assignment;
- cast JSONB evidence appropriately;
- cast lifecycle timestamps appropriately;
- prohibit generic deletion.

`TeacherDutyWeeklyReportHistory` MUST:

- use UUID identity;
- be tenant-aware;
- be effectively append-only;
- prohibit generic update;
- prohibit generic deletion;
- cast JSONB evidence appropriately.

---

## 26. Database indexing

Indexes MUST support at least:

1. unique weekly identity:
   `(school_id, duty_period_id)`;

2. tenant/status discovery:
   `(school_id, status)`;

3. tenant/submission discovery appropriate for review queues;

4. history lookup:
   `(school_id, weekly_report_id, created_at)`.

Tenant-leading indexes are required.

---

## 27. Failure semantics

All tenant mismatch, invalid actor, invalid lifecycle transition, malformed decision, missing required review comment, or invalid authoritative relationship MUST fail closed.

No failed operation may leave:

- a partially transitioned weekly report;
- partial submission evidence;
- partial review evidence;
- partial history.

---

## 28. No hidden lifecycle automation

6A.9G-C MUST NOT use hidden model observers or generic events to:

- automatically create weekly reports;
- automatically submit weekly reports;
- automatically approve reports;
- automatically convert missing daily reports;
- automatically mutate occurrences.

Lifecycle transitions are explicit domain-service operations.

---

## 29. Testing contract

The implementation MUST include regression coverage proving at least:

- one report per school/duty period;
- cross-tenant references fail closed;
- client cannot control server-owned evidence;
- draft editing works;
- submitted editing fails;
- changes-requested editing works;
- rejected/approved editing fails;
- initial submission creates immutable history;
- incomplete daily reporting does not block weekly submission;
- snapshot accurately records daily completeness;
- occurrence totals are derived without occurrence mutation;
- submission snapshot is frozen;
- later underlying changes do not rewrite historical snapshot;
- changes request requires comment;
- rejection requires comment;
- approval works without client-controlled reviewer evidence;
- resubmission creates a fresh snapshot;
- previous snapshot remains historically recoverable;
- approved/rejected reports cannot be resubmitted;
- concurrent lifecycle transitions are safe;
- report/history mutations are atomic;
- model deletion protections hold;
- history update/delete protections hold;
- timezone-sensitive timestamps preserve the correct instant;
- missing tenant context fails closed.

Tests MUST NOT use `migrate:fresh` as part of normal implementation validation.

---

## 30. Explicit non-goals

6A.9G-C does not finalize:

- which assigned Teacher may submit;
- which HOD, Deputy, Head, Principal, Director, or other leader may review;
- department-specific review scope;
- substitute Teacher responsibility;
- commercial package access;
- notifications/escalations;
- frontend UX.

Those boundaries belong to their explicitly assigned later phases.

---

## 31. Frozen implementation rule

Once this contract is reviewed and committed as FROZEN:

- implementation MUST conform to it;
- implementation MUST NOT silently broaden scope;
- a discovered contract gap MUST be resolved by an explicit clarification document/commit;
- the frozen contract itself MUST NOT be rewritten merely to make implementation easier.
