# Teacher Duty Daily Reporting — 6A.9I-B HTTP Contract

## 1. Purpose

Phase 6A.9I-B exposes the already-frozen Teacher Duty daily-report database/domain workflow through a tenant-safe HTTP API.

This phase does not redesign the 6A.9G-B daily-report domain.

It exposes:

- idempotent daily-report opening;
- daily-report state/evidence reading;
- draft summary updating;
- explicit daily-report submission.

The existing 6A.9H Teacher Duty responsibility authorization model is authoritative for every operation in this HTTP slice.

---

## 2. Scope

6A.9I-B owns:

1. HTTP routes for Teacher Duty daily reporting.
2. A thin Teacher Duty daily-report controller.
3. Minimal FormRequests required by the HTTP boundary.
4. Explicit HTTP response serialization.
5. HTTP-layer audit logging for daily-report mutations where applicable.
6. HTTP feature tests.
7. Hardening `TeacherDutyDailyReportService::state(...)` so it uses the frozen 6A.9H reporter authorization boundary.

6A.9I-B does not own:

- weekly report aggregation, submission, or review;
- reviewer authorization;
- leadership daily-report dashboards;
- daily approval or acknowledgement;
- rejection or changes requested;
- reopening, withdrawal, resubmission, amendment, or correction;
- notification or escalation delivery;
- Teacher Duty settings administration;
- occurrence redesign;
- frontend implementation;
- commercial entitlement enforcement.

Weekly reporting and review remain 6A.9I-C work.

---

## 3. Existing domain remains authoritative

The existing `TeacherDutyDailyReportService` remains the authoritative daily-report domain boundary.

The HTTP layer MUST delegate domain behavior to:

- `openReport(...)`;
- `state(...)`;
- `updateDraft(...)`;
- `submitReport(...)`.

Controllers MUST NOT duplicate:

- strict date validation;
- duty-period date-range validation;
- deadline computation;
- persisted lifecycle rules;
- overdue or late-submission derivation;
- report locking or uniqueness;
- lifecycle-history creation.

Controller-level reporter authorization is intentionally required at the
HTTP boundary for all four operations using concrete duty-period context.

This controller check does not replace domain authorization. The domain
service remains authoritative, and `state(...)` MUST also be hardened to
call the frozen reporter authorization boundary so non-HTTP callers fail
closed.

---

## 4. Authorization

### 4.1 Reporter-only surface

Every endpoint in 6A.9I-B is reporter-facing.

There is no reviewer-facing endpoint in this phase.

The frozen 6A.9H reporter rule is authoritative:

`TeacherDutyAuthorizationService::reporter(...)`

A reporter must satisfy the existing effective `submit_teacher_duty_reports` permission requirement and must have Teacher Duty responsibility for the concrete duty period.

Role names MUST NOT be used as runtime authorization.

### 4.2 `state(...)` hardening

Before HTTP exposure, `TeacherDutyDailyReportService::state(...)` MUST be hardened to use:

`TeacherDutyAuthorizationService::reporter($schoolId, $periodId, $actorUserId)`

after resolving the concrete same-school period.

The existing weaker eligible-school-user-only check in `state(...)` MUST be removed.

This is intentional domain-boundary hardening so non-HTTP callers cannot bypass the frozen 6A.9H responsibility model.

### 4.3 Preserved assignment history

Reporter authorization retains the frozen 6A.9H semantics.

A preserved Teacher Duty assignment remains responsibility evidence even after that assignment is ended.

6A.9I-B MUST NOT add an `active = true` assignment filter.

---

## 5. Tenant boundary

All operations are scoped to the authenticated tenant school.

The client MUST NOT select or override `school_id`.

Route identifiers MUST be resolved fail-closed against the authenticated school.

Cross-tenant resources MUST NOT disclose whether the foreign resource exists.

Malformed, unavailable, or foreign resource identifiers shall follow the existing generic unavailable-resource validation pattern used by the Teacher Duty HTTP API.

---

## 6. Operational-school gating

Daily-report mutations require an operational school:

- open report;
- update draft;
- submit report.

These operations MUST use the existing `school.operational` mutation gate.

The state/read endpoint MUST NOT require `school.operational`.

A non-operational school may read preserved daily-report state/evidence when the actor remains authorized under the frozen reporter rule.

Read access MUST NOT become a side door around reporter authorization.

---

## 7. HTTP routes

The 6A.9I-B routes are:

### 7.1 Open report

`POST /teacher-duty/periods/{period}/daily-reports/open`

Purpose:

- explicitly open/materialize a daily report for a supplied report date;
- delegate to `openReport(...)`.

Request body:

- `report_date` — required strict `YYYY-MM-DD`.

### 7.2 Read state

`GET /teacher-duty/periods/{period}/daily-reports/state?report_date=YYYY-MM-DD`

Purpose:

- return derived daily-report state/evidence;
- delegate to `state(...)`;
- MUST NOT materialize a report merely to answer the read.

`report_date` is required and uses the existing strict domain date rule.

### 7.3 Update draft

`PATCH /teacher-duty/daily-reports/{report}`

Purpose:

- update the mutable draft summary only;
- delegate to `updateDraft(...)`.

Request body:

- `summary` — nullable string.

No arbitrary application-level maximum is introduced in 6A.9I-B because
the frozen domain stores `summary` as nullable `TEXT` and defines no
maximum length.

The domain service remains responsible for trimming the summary and
normalizing an empty string to `null`.

### 7.4 Submit report

`POST /teacher-duty/daily-reports/{report}/submit`

Purpose:

- explicitly submit a draft daily report;
- delegate to `submitReport(...)`.

No client-owned lifecycle field is accepted.

---

## 8. Route middleware

The daily-report route group MUST remain inside the existing secure
tenant/JWT boundary.

Because the existing Teacher Duty module permission maps the module to
`manage_teacher_duty_roster`, reporter routes MUST NOT accidentally
inherit that roster-management authorization requirement.

The 6A.9I-B reporter routes shall follow the proven occurrence HTTP
pattern:

- exclude `module.permission` for this reporter-facing subgroup;
- require effective `submit_teacher_duty_reports`;
- retain the outer JWT and tenant middleware;
- additionally enforce concrete period responsibility through
  `TeacherDutyAuthorizationService::reporter(...)`.

Mutation routes additionally require `school.operational`.

The state/read route does not.

---

## 9. FormRequest boundary

FormRequests MUST use:

`authorize(): true`

Authorization remains outside request validation.

Controllers MUST pass only validated client data into the domain
boundary.

### 9.1 Open request

Client-controlled:

- `report_date`.

Prohibited server-owned fields include at minimum:

- `school_id`;
- `duty_period_id`;
- `status`;
- `summary`;
- `deadline_at`;
- `created_by`;
- `submitted_by`;
- `submitted_at`;
- `late`;
- presentation `state`;
- actor/user ownership fields.

### 9.2 State request/query validation

Client-controlled:

- `report_date`.

The HTTP request layer may establish basic presence/string shape.

The domain remains authoritative for strict `YYYY-MM-DD` identity,
school-local interpretation, and period inclusion.

Server-owned tenant and actor fields MUST NOT be accepted.

### 9.3 Update request

Client-controlled:

- `summary`.

Prohibited server-owned fields include at minimum:

- `school_id`;
- `duty_period_id`;
- `report_date`;
- `status`;
- `deadline_at`;
- `created_by`;
- `submitted_by`;
- `submitted_at`;
- `late`;
- presentation `state`;
- actor/user ownership fields.

### 9.4 Submit request

Submission accepts no client-controlled lifecycle data.

Fields such as the following MUST be prohibited if supplied:

- `school_id`;
- `duty_period_id`;
- `report_date`;
- `status`;
- `summary`;
- `deadline_at`;
- `created_by`;
- `submitted_by`;
- `submitted_at`;
- `late`;
- presentation `state`;
- actor/user ownership fields.

---

## 10. Daily-report response

Daily-report entity responses use an explicit allowlist.

The response MAY expose:

- `id`;
- `duty_period_id`;
- `report_date`;
- `status`;
- `summary`;
- `deadline_at`;
- `created_by`;
- `submitted_by`;
- `submitted_at`;
- `created_at`;
- `updated_at`.

The response MUST NOT expose:

- `school_id`;
- arbitrary model attributes;
- unrelated relationships.

Derived `late` and presentation state belong to the state operation and
MUST NOT be fabricated from controller-side lifecycle logic.

---

## 11. State response

The state endpoint returns a `data` envelope containing exactly:

- `state`;
- `deadline_at`;
- `submitted_at`;
- `late`.

`state` is one of:

- `NOT_STARTED`;
- `DRAFT`;
- `SUBMITTED`;
- `OVERDUE`.

The exact values are supplied by the domain service.

The controller MUST NOT independently derive overdue or late truth.

For an unmaterialized obligation, state reading MUST NOT create:

- a daily-report row;
- daily-report lifecycle history;
- audit mutation evidence.

---

## 12. Mutation response envelopes

### 12.1 Open report

A newly materialized report returns:

- HTTP `201 Created`;
- message `Teacher duty daily report opened successfully.`;
- explicit daily-report `data`.

When the same school/period/date already has a report, opening is
idempotent and returns:

- HTTP `200 OK`;
- the existing daily-report `data`;
- no duplicate report;
- no duplicate `created` history;
- no duplicate Create audit entry.

The controller MAY determine whether the report already exists before
delegating to `openReport(...)` solely to choose the HTTP status/audit
envelope, but any such lookup MUST be tenant-scoped and MUST NOT replace
domain authorization or lifecycle enforcement.

If the existing report is already submitted, open returns that submitted
report unchanged. This is not a lifecycle reopening.

### 12.2 Update draft

Successful update returns:

- HTTP `200 OK`;
- message `Teacher duty daily report updated successfully.`;
- explicit daily-report `data`.

### 12.3 Submit report

Successful submission returns:

- HTTP `200 OK`;
- message `Teacher duty daily report submitted successfully.`;
- explicit daily-report `data`.

---

## 13. Lifecycle semantics

Persisted statuses remain only:

- `draft`;
- `submitted`.

Presentation states remain:

- `NOT_STARTED`;
- `DRAFT`;
- `SUBMITTED`;
- `OVERDUE`.

Allowed lifecycle transitions remain:

`NOT_STARTED -> DRAFT`

`DRAFT -> SUBMITTED`

An overdue unmaterialized obligation may still be opened and subsequently
submitted.

An overdue persisted draft remains persisted as `draft` until submission.

Late submission is valid.

A submitted report remains submitted permanently under this contract.

No HTTP route shall introduce:

- submitted -> draft;
- resubmission;
- reopening as a lifecycle transition;
- withdrawal;
- amendment;
- approval;
- rejection;
- changes requested.

Idempotent `openReport(...)` returning an existing submitted report is
not a reopening lifecycle transition.

---

## 14. Occurrence relationship

6A.9I-B MUST NOT require an occurrence before submission.

A daily report may be submitted:

- with zero occurrences;
- with `summary = null`;
- with both zero occurrences and a null summary.

No synthetic "no occurrence" occurrence may be created.

Occurrence recording remains governed by the frozen 6A.9I-A contract.

---

## 15. Deadline and lateness

`deadline_at` is server-owned.

For a newly materialized report, the domain computes and persists the
deadline using:

- report date;
- authoritative school timezone;
- persistent Teacher Duty report deadline time;
- persistent grace minutes.

Once persisted, `deadline_at` remains the historical deadline snapshot
for that report.

Changing school settings later MUST NOT rewrite it.

For an unmaterialized obligation, state may compute the effective
deadline using current persistent settings.

`OVERDUE` is derived truth and MUST NOT depend on a scheduler,
queue worker, cron job, or notification process.

Late submission is derived from:

`submitted_at > deadline_at`

Submission exactly at the deadline is not late.

The client MUST NOT control:

- `deadline_at`;
- `submitted_by`;
- `submitted_at`;
- derived `late`;
- derived presentation state.

---

## 16. Daily-report lifecycle history

Existing domain lifecycle history remains authoritative and append-only.

Opening a new report creates exactly:

- `from_status = null`;
- `to_status = draft`;
- `event = created`.

Submitting creates exactly:

- `from_status = draft`;
- `to_status = submitted`;
- `event = submitted`.

Late submission uses the same `submitted` event.

Idempotently opening an existing report MUST NOT create another history
row.

Draft summary updates do not create a lifecycle-history event.

Daily-report lifecycle history MUST NOT be updated or deleted.

No HTTP route for lifecycle-history mutation shall exist.

---

## 17. HTTP audit logging

HTTP audit evidence is separate from the immutable daily-report lifecycle
history.

The HTTP layer shall follow the existing ShuleOS audit conventions.

### 17.1 Open

When `openReport(...)` actually materializes a new report, the controller
shall create one Create audit entry.

The audit entry shall identify the authenticated actor through the
existing audit actor field.

Relevant business evidence may include:

- `duty_period_id`;
- `report_date`;
- `status`;
- `summary`;
- `deadline_at`.

`school_id` MUST NOT be treated as client-controlled audit evidence.

Idempotently opening an existing report MUST NOT create another Create
audit entry.

### 17.2 Update draft

A successful draft-summary update shall create one Update audit entry
using the existing audit convention.

Audit old/new values MUST be limited to meaningful changed business
evidence.

The HTTP layer MUST NOT fabricate a lifecycle-history event for a summary
edit.

### 17.3 Submit

A successful submission shall create one lifecycle/update audit entry
using the existing audit convention.

The audit evidence shall capture the meaningful server-owned transition
from `draft` to `submitted` and the authoritative submission timestamp
without accepting those values from the client.

### 17.4 Reads

State reads MUST NOT create mutation audit entries.

---

## 18. Controller responsibilities

The controller shall remain thin.

It may:

- obtain authenticated school and actor context;
- resolve tenant-safe route resources where required;
- consume validated input;
- enforce the frozen reporter boundary with concrete period context;
- delegate to the daily-report service;
- serialize explicit allowlisted responses;
- write HTTP audit entries;
- choose the frozen HTTP response/status envelope.

For report-id operations, the controller MUST resolve the report
tenant-safely before using its `duty_period_id` for reporter
authorization.

For period-id operations, the controller MUST resolve the period
tenant-safely before reporter authorization.

The controller MUST NOT:

- calculate deadlines;
- derive overdue;
- derive late submission;
- directly transition lifecycle state;
- manufacture lifecycle history;
- perform role-name authorization;
- accept client tenant or actor ownership;
- implement weekly review behavior.

---

## 19. Forbidden HTTP surface

6A.9I-B MUST NOT expose generic or lifecycle-expanding routes such as:

- `DELETE /teacher-duty/daily-reports/{report}`;
- generic PUT replacement;
- generic status update;
- reopen;
- withdraw;
- resubmit;
- approve;
- reject;
- request changes;
- amend;
- daily reviewer acknowledgement;
- daily leadership review.

No daily-report-history mutation routes shall exist.

No generic daily-report deletion route shall exist.

---

## 20. Required HTTP tests

The 6A.9I-B feature suite MUST prove:

- JWT and tenant security;
- reporter can open a report for a responsible period;
- reporter can read state for a responsible period;
- reporter can update a draft belonging to a responsible period;
- reporter can submit a draft belonging to a responsible period;
- effective `submit_teacher_duty_reports` permission is required;
- concrete period responsibility is required for all four operations;
- `state(...)` enforces the frozen `reporter(...)` authorization boundary;
- preserved ended-assignment responsibility remains valid;
- an otherwise eligible school user without responsibility fails closed;
- cross-tenant period identifiers fail closed;
- cross-tenant report identifiers fail closed;
- malformed identifiers follow the generic unavailable-resource pattern;
- strict report-date validation remains domain-authoritative;
- report dates outside the selected duty period fail;
- state reading does not materialize a daily report;
- state reading does not create lifecycle history;
- state reading does not create mutation audit evidence;
- state reading does not require `school.operational`;
- open requires `school.operational`;
- update requires `school.operational`;
- submit requires `school.operational`;
- opening is idempotent;
- repeated open does not create a duplicate daily report;
- repeated open does not create duplicate `created` lifecycle history;
- repeated open does not create a duplicate Create audit entry;
- opening an existing submitted report returns it unchanged;
- draft update accepts only mutable summary data;
- summary trimming remains domain-owned;
- empty summary normalization to `null` remains domain-owned;
- an overdue draft remains editable;
- a submitted report cannot be updated;
- a submitted report cannot be submitted again;
- late submission remains allowed;
- submission exactly at the deadline is not late;
- zero-occurrence submission remains allowed;
- null-summary submission remains allowed;
- zero-occurrence plus null-summary submission remains allowed;
- client cannot control `school_id`;
- client cannot control `duty_period_id`;
- client cannot control persisted lifecycle status;
- client cannot control `deadline_at`;
- client cannot control `created_by`;
- client cannot control `submitted_by`;
- client cannot control `submitted_at`;
- client cannot control derived `late`;
- client cannot control derived presentation state;
- daily-report response allowlists exclude `school_id`;
- state response contains exactly the frozen state evidence;
- mutation audit records use the authoritative authenticated actor;
- forbidden daily lifecycle routes do not exist;
- generic daily-report deletion does not exist;
- daily-report-history mutation routes do not exist;
- reporter routes are not accidentally blocked by the
  `manage_teacher_duty_roster` module mapping.

The existing daily-report service/domain suite MUST remain green.

The complete Teacher Duty regression suite MUST remain green.

---

## 21. Explicit non-goals and later work

This contract does not introduce a school-wide leadership daily-status
dashboard.

If a leadership/oversight daily read surface is required later, it MUST
receive its own explicitly frozen authorization, tenant, route, and
response contract rather than overloading the reporter-facing state
endpoint.

This contract introduces no reviewer authorization.

`TeacherDutyAuthorizationService::reviewer(...)` is not part of the
6A.9I-B daily reporter surface.

Weekly reporting, submission, and review HTTP exposure belong to
6A.9I-C.

Notification and escalation infrastructure remain later work.

---

## 22. Exit criteria

6A.9I-B is complete only when:

1. this HTTP contract is reviewed and frozen;
2. `TeacherDutyDailyReportService::state(...)` is hardened to the frozen
   reporter authorization boundary;
3. daily-report HTTP routes are implemented;
4. required FormRequests are implemented;
5. the thin daily-report controller is implemented;
6. explicit response serialization is implemented;
7. HTTP mutation auditing is implemented;
8. the required daily-report HTTP feature tests pass;
9. the existing daily-report domain/service tests pass;
10. the complete Teacher Duty regression suite passes;
11. the full project regression suite passes;
12. Pint passes;
13. `git diff --check` passes;
14. the implementation is reviewed through the normal PR process;
15. the implementation is merged before 6A.9I-B is considered closed.
