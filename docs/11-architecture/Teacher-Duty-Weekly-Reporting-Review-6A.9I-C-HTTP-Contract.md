# Teacher Duty Weekly Reporting & Review — 6A.9I-C HTTP Contract

## Status

FROZEN contract for Phase 6A.9I-C — Teacher Duty Weekly Reporting & Review HTTP API.

This exposes the already-frozen Teacher Duty weekly-report domain through HTTP without redesigning its persistence, evidence, lifecycle, responsibility, or review semantics.

---

## 1. Purpose

Phase 6A.9I-C exposes the existing Teacher Duty weekly reporting and review workflow through a tenant-safe HTTP API.

It exposes:

- idempotent weekly-report opening;
- weekly-report narrative updating;
- initial weekly-report submission;
- changes-requested resubmission;
- weekly-report review;
- weekly-report state/evidence reading.

The existing Teacher Duty weekly-report domain and the frozen 6A.9H authorization model remain authoritative.

---

## 2. Scope

6A.9I-C owns:

1. HTTP routes for Teacher Duty weekly reporting and review.
2. A thin Teacher Duty weekly-report controller.
3. Minimal FormRequests required by the HTTP boundary.
4. Explicit HTTP response serialization.
5. HTTP-layer audit logging for weekly-report mutations.
6. HTTP feature tests.
7. Hardening `TeacherDutyWeeklyReportService::state(...)` so weekly state/evidence cannot be read by an otherwise eligible same-school user with neither reporter nor reviewer authority.

6A.9I-C does not own:

- redesign of weekly-report persistence;
- redesign of weekly evidence aggregation;
- redesign of daily reporting;
- redesign of occurrence recording;
- new review decisions;
- reopening approved or rejected reports;
- withdrawal;
- arbitrary amendment after terminal review;
- deletion of weekly reports;
- mutation of weekly-report history;
- notification or escalation delivery;
- Teacher Duty settings administration;
- frontend implementation;
- commercial entitlement enforcement.

---

## 3. Existing domain remains authoritative

`TeacherDutyWeeklyReportService` remains the authoritative weekly-report domain boundary.

The HTTP layer MUST delegate domain behavior to:

- `openReport(...)`;
- `updateReport(...)`;
- `submitReport(...)`;
- `resubmitReport(...)`;
- `reviewReport(...)`;
- `state(...)`.

Controllers MUST NOT duplicate:

- weekly lifecycle transition rules;
- period-ended submission requirements;
- narrative normalization;
- evidence snapshot construction;
- current evidence aggregation;
- review-decision validation;
- meaningful-comment requirements;
- self-review prevention;
- lifecycle-history creation;
- report or period locking.

Controller-level authorization is required at the HTTP boundary as defense in depth.

It does not replace domain authorization.

---

## 4. Authorization

### 4.1 Reporter operations

The following operations require the frozen 6A.9H reporter boundary:

- open;
- update;
- submit;
- resubmit.

The authoritative rule is:

`TeacherDutyAuthorizationService::reporter(...)`

A reporter must:

1. be an eligible same-school actor under the frozen authorization rules;
2. have effective `submit_teacher_duty_reports`;
3. have preserved Teacher Duty responsibility for the concrete duty period.

Preserved assignment history remains authoritative.

6A.9I-C MUST NOT introduce an `active = true` Teacher Duty assignment requirement.

Role names MUST NOT be used as runtime authorization.

### 4.2 Reviewer operation

Review requires the frozen 6A.9H reviewer boundary:

`TeacherDutyAuthorizationService::reviewer(...)`

A reviewer must:

1. be an eligible same-school actor under the frozen authorization rules;
2. have effective `review_teacher_duty_reports`.

A reviewer does not require:

- a Teacher profile;
- Teacher Duty assignment responsibility.

The existing domain self-review prohibition remains authoritative.

Role names MUST NOT be used as runtime authorization.

### 4.3 Weekly state/read authorization

Weekly state/evidence is required by both:

- the responsible reporter working with the weekly report; and
- an authorized reviewer evaluating the submitted weekly report.

Therefore `TeacherDutyWeeklyReportService::state(...)` MUST succeed only when the actor qualifies as at least one of:

1. reporter for the report's concrete duty period; OR
2. reviewer for the report's school.

An otherwise eligible same-school user with neither authority MUST fail closed.

The current eligible-school-user-only `state(...)` gate is insufficient and MUST be hardened in this phase.

The implementation MUST compose the existing frozen reporter and reviewer semantics.

6A.9I-C MUST NOT introduce role-name authorization or weaken either existing authorization rule merely to implement this OR boundary.

No actor-dependent state response shape is introduced. An authorized reporter and an authorized reviewer receive the same frozen state/evidence serialization.

---

## 5. Tenant boundary

All operations are scoped to the authenticated tenant school.

The client MUST NOT select or override `school_id`.

Route identifiers MUST be resolved fail-closed against the authenticated school.

Cross-tenant resources MUST NOT disclose whether the foreign resource exists.

Malformed, unavailable, or foreign Teacher Duty resource identifiers shall follow the existing generic unavailable-resource validation pattern used by the Teacher Duty HTTP API.

Tenant context mismatch MUST fail closed.

---

## 6. Operational-school gating

Weekly-report mutations require an operational school:

- open;
- update;
- submit;
- resubmit;
- review.

These mutation routes MUST use the existing `school.operational` gate.

Weekly state/evidence reading MUST NOT require `school.operational`.

A non-operational school may read preserved weekly state/evidence when the actor remains authorized as a reporter for the concrete period or as a reviewer.

Read access MUST NOT become a side door around the frozen authorization model.

---

## 7. HTTP routes

### 7.1 Open weekly report

`POST /teacher-duty/periods/{period}/weekly-reports/open`

Purpose:

- explicitly open/materialize the weekly report for the concrete duty period;
- delegate to `openReport(...)`.

No client-owned weekly lifecycle data is required.

Opening remains idempotent under the existing domain identity.

### 7.2 Read weekly state

`GET /teacher-duty/weekly-reports/{report}/state`

Purpose:

- return the frozen weekly state/evidence projection;
- delegate to `state(...)`;
- perform no lifecycle mutation.

The report identifier determines the concrete duty period used for reporter-side authorization.

### 7.3 Update weekly report

`PATCH /teacher-duty/weekly-reports/{report}`

Purpose:

- update the mutable weekly narrative;
- delegate to `updateReport(...)`.

Client-controlled fields:

- `summary`;
- `highlights`;
- `challenges`;
- `recommendations`.

Each field is nullable string data.

No arbitrary application-level maximum is introduced because the existing domain contract defines no such HTTP maximum.

Narrative trimming and empty-string-to-null normalization remain domain-owned.

### 7.4 Submit weekly report

`POST /teacher-duty/weekly-reports/{report}/submit`

Purpose:

- perform the initial `draft -> submitted` transition;
- delegate to `submitReport(...)`.

No client-owned lifecycle field is accepted.

### 7.5 Resubmit weekly report

`POST /teacher-duty/weekly-reports/{report}/resubmit`

Purpose:

- perform the existing `changes_requested -> submitted` transition;
- delegate to `resubmitReport(...)`;
- create a fresh authoritative evidence snapshot through the domain service.

Resubmission is a distinct operation from initial submission.

No client-owned lifecycle or evidence field is accepted.

### 7.6 Review weekly report

`POST /teacher-duty/weekly-reports/{report}/review`

Purpose:

- review a currently submitted weekly report;
- delegate to `reviewReport(...)`.

Client-controlled fields:

- `decision`;
- `comment`.

`decision` MUST be one of:

- `changes_requested`;
- `approved`;
- `rejected`.

`comment` is nullable string input at the HTTP validation boundary.

The domain remains authoritative for the rule that a meaningful comment is required for:

- `changes_requested`;
- `rejected`.

The domain remains authoritative for self-review prohibition and reviewable lifecycle state.

---

## 8. Route middleware

All weekly-report routes remain inside the existing secure JWT and tenant boundary.

Because Teacher Duty reporting/review capabilities differ from the existing module permission mapping, weekly-report routes MUST NOT accidentally inherit `manage_teacher_duty_roster` through `module.permission`.

### 8.1 Reporter mutations

Open, update, submit, and resubmit shall:

- exclude `module.permission`;
- require effective `submit_teacher_duty_reports`;
- retain JWT and tenant middleware;
- require `school.operational`;
- additionally enforce concrete-period responsibility through `TeacherDutyAuthorizationService::reporter(...)`.

### 8.2 Review mutation

Review shall:

- exclude `module.permission`;
- require effective `review_teacher_duty_reports`;
- retain JWT and tenant middleware;
- require `school.operational`;
- additionally enforce `TeacherDutyAuthorizationService::reviewer(...)`.

### 8.3 State/read

State is a dual-authority read surface.

Its route-level capability boundary MUST permit an authenticated tenant actor possessing at least one of:

- `submit_teacher_duty_reports`;
- `review_teacher_duty_reports`.

Possession of either permission alone is not the complete domain authorization decision.

After resolving the same-school report and its concrete duty period, the HTTP/domain boundary MUST establish that the actor qualifies as either:

- reporter for that period; or
- reviewer for that school.

State MUST NOT require `school.operational`.

The implementation MAY use the existing middleware infrastructure or a minimal explicitly tested OR-capability mechanism, but MUST NOT weaken the frozen reporter or reviewer semantics.

---

## 9. FormRequest boundary

All weekly FormRequests MUST use:

`authorize(): true`

Authorization remains outside request validation.

Controllers MUST pass only validated client data into the domain boundary.

Tenant and actor identity remain server-owned.

### 9.1 Open request

No client-controlled business field is required.

Prohibited fields include at minimum:

- `school_id`;
- `duty_period_id`;
- `status`;
- all narrative fields;
- `evidence_snapshot`;
- `created_by`;
- `submitted_by`;
- `submitted_at`;
- `reviewed_by`;
- `reviewed_at`;
- `review_comment`;
- presentation `state`;
- actor/user ownership fields.

### 9.2 State request

State accepts no client-controlled lifecycle, tenant, actor, evidence, narrative, submission, or review fields.

Server-owned tenant and actor fields MUST NOT be accepted from the client.

### 9.3 Update request

Client-controlled:

- `summary`;
- `highlights`;
- `challenges`;
- `recommendations`.

The four narrative fields shall use the existing nullable-string domain shape.

Prohibited fields include at minimum:

- `school_id`;
- `duty_period_id`;
- `status`;
- `evidence_snapshot`;
- `created_by`;
- `submitted_by`;
- `submitted_at`;
- `reviewed_by`;
- `reviewed_at`;
- `review_comment`;
- presentation `state`;
- actor/user ownership fields.

### 9.4 Submit request

Initial submission accepts no client-controlled lifecycle or evidence data.

Server-owned fields listed above MUST be prohibited if supplied.

### 9.5 Resubmit request

Resubmission accepts no client-controlled lifecycle or evidence data.

The client MUST NOT supply:

- evidence snapshot;
- submission actor;
- submission timestamp;
- review actor;
- review timestamp;
- review comment as lifecycle state;
- status.

### 9.6 Review request

Client-controlled:

- `decision`;
- `comment`.

Prohibited fields include at minimum:

- `school_id`;
- `duty_period_id`;
- `status`;
- all narrative fields;
- `evidence_snapshot`;
- `created_by`;
- `submitted_by`;
- `submitted_at`;
- `reviewed_by`;
- `reviewed_at`;
- presentation `state`;
- actor/user ownership fields.

The request layer validates the closed decision vocabulary.

The domain remains authoritative for lifecycle eligibility, self-review prohibition, comment normalization, and meaningful-comment requirements.

---

## 10. Weekly-report entity response

Mutation/entity responses use an explicit allowlist.

The response MAY expose:

- `id`;
- `duty_period_id`;
- `status`;
- `summary`;
- `highlights`;
- `challenges`;
- `recommendations`;
- `evidence_snapshot`;
- `created_by`;
- `submitted_by`;
- `submitted_at`;
- `reviewed_by`;
- `reviewed_at`;
- `review_comment`;
- `created_at`;
- `updated_at`.

The response MUST NOT expose:

- `school_id`;
- arbitrary model attributes;
- unrelated relationships.

The controller MUST NOT manufacture evidence or lifecycle values.

---

## 11. Weekly state response

The state endpoint returns a `data` envelope containing exactly the domain state projection:

- `state`;
- `current_narrative`;
- `current_daily_completeness`;
- `current_occurrence_aggregates`;
- `submission_evidence`;
- `review_evidence`;
- `latest_submission_evidence_snapshot`;
- `history`.

`state` is the uppercase representation supplied by the domain service.

`current_narrative` contains:

- `summary`;
- `highlights`;
- `challenges`;
- `recommendations`.

`current_daily_completeness` contains:

- `expected_daily_report_count`;
- `not_started_daily_report_count`;
- `draft_daily_report_count`;
- `overdue_daily_report_count`;
- `submitted_daily_report_count`;
- `late_submitted_daily_report_count`.

`current_occurrence_aggregates` contains:

- `total_occurrence_count`;
- `occurrence_category_breakdown`.

`submission_evidence` contains:

- `submitted_by`;
- `submitted_at`.

`review_evidence` contains:

- `reviewed_by`;
- `reviewed_at`;
- `review_comment`.

`latest_submission_evidence_snapshot` is the immutable snapshot currently stored on the weekly report for its latest submission/resubmission.

`history` is the existing immutable ordered weekly lifecycle history projection supplied by the domain service.

The controller MUST NOT independently recompute current evidence, submission snapshots, review evidence, or history.

State reading MUST NOT:

- transition lifecycle state;
- rewrite the latest submission evidence snapshot;
- create lifecycle history;
- create mutation audit evidence.

---

## 12. Mutation response envelopes

### 12.1 Open

A newly materialized weekly report returns:

- HTTP `201 Created`;
- message `Teacher duty weekly report opened successfully.`;
- explicit weekly-report `data`.

When the same school/period already has a weekly report, opening is idempotent and returns:

- HTTP `200 OK`;
- the existing weekly-report `data`;
- no duplicate report;
- no duplicate `created` history;
- no duplicate Create audit entry.

A tenant-scoped pre-delegation existence lookup MAY be used solely to select the frozen HTTP status/audit envelope.

It MUST NOT replace domain authorization or lifecycle enforcement.

### 12.2 Update

Successful update returns:

- HTTP `200 OK`;
- message `Teacher duty weekly report updated successfully.`;
- explicit weekly-report `data`.

### 12.3 Submit

Successful initial submission returns:

- HTTP `200 OK`;
- message `Teacher duty weekly report submitted successfully.`;
- explicit weekly-report `data`.

### 12.4 Resubmit

Successful resubmission returns:

- HTTP `200 OK`;
- message `Teacher duty weekly report resubmitted successfully.`;
- explicit weekly-report `data`.

### 12.5 Review

Successful review returns:

- HTTP `200 OK`;
- message `Teacher duty weekly report reviewed successfully.`;
- explicit weekly-report `data`.

---

## 13. Lifecycle semantics

Persisted statuses remain:

- `draft`;
- `submitted`;
- `changes_requested`;
- `approved`;
- `rejected`.

Allowed transitions remain:

`draft -> submitted`

`submitted -> changes_requested`

`changes_requested -> submitted`

`submitted -> approved`

`submitted -> rejected`

Only `draft` and `changes_requested` reports may be edited under the existing domain rules.

Initial submission is valid only from `draft`.

Resubmission is valid only from `changes_requested`.

Review is valid only from `submitted`.

`approved` and `rejected` remain terminal.

6A.9I-C introduces no route for:

- approved -> submitted;
- rejected -> submitted;
- approved -> draft;
- rejected -> draft;
- withdrawal;
- reopening;
- arbitrary status replacement;
- deletion.

---

## 14. Submission evidence

Initial submission and resubmission remain domain-owned.

The domain MUST continue to require the Teacher Duty period to be authoritatively ended before either submission operation succeeds.

At each successful submission/resubmission, the domain creates the authoritative evidence snapshot.

The client MUST NOT control:

- `evidence_snapshot`;
- `submitted_by`;
- `submitted_at`.

Resubmission MUST create fresh submission evidence without rewriting historical evidence preserved in lifecycle history.

The state operation computes current evidence independently of the immutable latest submission snapshot.

Reading state MUST NOT rewrite that snapshot.

---

## 15. Review semantics

Review decisions remain exactly:

- `changes_requested`;
- `approved`;
- `rejected`.

The reviewer MUST NOT be the submitter of the current submitted version.

A meaningful review comment remains mandatory for:

- `changes_requested`;
- `rejected`.

An approval may retain a null review comment.

The domain remains authoritative for comment normalization.

Successful review records authoritative:

- reviewer identity;
- review timestamp;
- normalized review comment;
- resulting lifecycle state.

The client MUST NOT control reviewer identity, review timestamp, or persisted lifecycle status.

---

## 16. Weekly lifecycle history

Existing weekly-report history remains authoritative and immutable.

Opening a new weekly report creates the existing `created` history event.

Initial submission creates the existing `submitted` history event and preserves that submission's evidence snapshot.

Resubmission creates the existing `resubmitted` history event and preserves the fresh submission snapshot for that version.

Review creates the existing decision event corresponding to:

- `changes_requested`;
- `approved`;
- `rejected`.

Narrative-only updates do not manufacture lifecycle-history events.

Weekly lifecycle history MUST NOT be updated or deleted.

No HTTP route for weekly-history mutation shall exist.

---

## 17. HTTP audit logging

HTTP audit evidence is separate from immutable weekly lifecycle history.

The HTTP layer follows existing ShuleOS audit conventions.

### 17.1 Open

When `openReport(...)` actually materializes a weekly report, create one Create audit entry.

Idempotently opening an existing report MUST NOT create another Create audit entry.

### 17.2 Update

A successful narrative update creates one Update audit entry.

Old/new audit values MUST be limited to meaningful mutable narrative evidence.

No lifecycle-history event is fabricated by the HTTP layer.

### 17.3 Submit

Successful initial submission creates one lifecycle/update audit entry capturing meaningful authoritative transition evidence.

### 17.4 Resubmit

Successful resubmission creates one lifecycle/update audit entry capturing meaningful authoritative transition evidence.

### 17.5 Review

Successful review creates one lifecycle/update audit entry capturing meaningful authoritative review transition evidence.

The audit actor is the authenticated reviewer.

### 17.6 Reads

State/evidence reads MUST NOT create mutation audit entries.

---

## 18. Error behavior

Malformed, unavailable, or cross-tenant route resources use the existing generic Teacher Duty unavailable-resource validation pattern.

Existing domain `ValidationException` behavior remains authoritative for business-rule failures.

The controller MUST NOT invent alternative lifecycle semantics or translate individual domain failures into new custom business states.

In particular, domain validation remains authoritative for:

- invalid lifecycle transitions;
- period-not-ended submission/resubmission;
- unauthorized reporter responsibility;
- unauthorized reviewer eligibility;
- self-review;
- invalid review decision at the service boundary;
- required meaningful review comments.

The HTTP request layer may reject invalid request shape before service delegation where this contract explicitly assigns validation to the FormRequest.

---

## 19. Controller responsibilities

The controller remains thin.

It may:

- obtain authenticated school and actor context;
- resolve tenant-safe route resources;
- consume validated client input;
- enforce the appropriate frozen authorization boundary;
- delegate to the weekly-report service;
- serialize explicit allowlisted responses;
- write HTTP mutation audit entries;
- choose the frozen HTTP response/status envelope.

For report-id reporter operations, the controller MUST resolve the report tenant-safely before using its `duty_period_id` for reporter authorization.

For period-id operations, the controller MUST resolve the period tenant-safely before reporter authorization.

For review, the controller MUST resolve the report tenant-safely before reviewer authorization and service delegation.

For state, the controller/domain boundary MUST enforce the frozen reporter-or-reviewer read rule.

The controller MUST NOT:

- directly transition lifecycle state;
- build evidence snapshots;
- compute weekly aggregates independently;
- mutate lifecycle history;
- perform role-name authorization;
- accept client tenant or actor ownership;
- weaken reporter responsibility;
- require assignment responsibility from a reviewer;
- derive an actor-specific state response shape.

---

## 20. Forbidden HTTP surface

6A.9I-C MUST NOT expose generic or lifecycle-expanding routes such as:

- `DELETE /teacher-duty/weekly-reports/{report}`;
- generic PUT replacement;
- generic status update;
- generic lifecycle transition;
- reopen approved reports;
- reopen rejected reports;
- withdraw;
- arbitrary amend;
- delete history;
- update history;
- replace evidence snapshot;
- client-selected submitter;
- client-selected reviewer.

No generic weekly-report deletion route shall exist.

No weekly-report-history mutation route shall exist.

---

## 21. Required HTTP tests

The 6A.9I-C HTTP feature suite MUST prove:

- JWT and tenant security;
- reporter can open a weekly report for a responsible period;
- opening is idempotent;
- repeated open creates no duplicate weekly report;
- repeated open creates no duplicate `created` history;
- repeated open creates no duplicate Create audit entry;
- reporter can update a draft;
- reporter can update a changes-requested report;
- reporter can submit a draft after authoritative period end;
- reporter can resubmit a changes-requested report after authoritative period end;
- initial submission before authoritative period end fails;
- resubmission before authoritative period end fails;
- initial submit works only from draft;
- resubmit works only from changes-requested;
- reviewer can review a submitted report;
- review decision vocabulary is closed to `changes_requested`, `approved`, and `rejected`;
- changes-requested requires a meaningful comment;
- rejected requires a meaningful comment;
- approval may use a null comment;
- submitter cannot review own current submitted version;
- approved is terminal;
- rejected is terminal;
- effective `submit_teacher_duty_reports` is required for reporter mutations;
- concrete preserved period responsibility is required for reporter mutations;
- ended assignment history remains valid responsibility evidence;
- no active-only assignment rule is introduced;
- effective `review_teacher_duty_reports` is required for review;
- reviewer does not require Teacher Duty assignment responsibility;
- reviewer does not require a Teacher profile;
- role names alone do not authorize reporter or reviewer operations;
- authorized reporter can read weekly state/evidence;
- authorized reviewer can read weekly state/evidence;
- same-school eligible actor with neither reporter nor reviewer authority cannot read weekly state/evidence;
- reporter state authorization uses concrete report period responsibility;
- reviewer state authorization does not require period assignment responsibility;
- state service itself fails closed for unauthorized non-HTTP callers;
- state response contains exactly the frozen weekly state/evidence projection;
- state reads do not mutate lifecycle state;
- state reads do not rewrite submission evidence snapshot;
- state reads do not create lifecycle history;
- state reads do not create mutation audit evidence;
- state reads do not require `school.operational`;
- open requires `school.operational`;
- update requires `school.operational`;
- submit requires `school.operational`;
- resubmit requires `school.operational`;
- review requires `school.operational`;
- cross-tenant period identifiers fail closed;
- cross-tenant weekly-report identifiers fail closed;
- malformed identifiers use the generic unavailable-resource pattern;
- tenant context mismatch fails closed;
- client cannot control `school_id`;
- client cannot control `duty_period_id`;
- client cannot control persisted lifecycle status;
- client cannot control evidence snapshot;
- client cannot control `created_by`;
- client cannot control `submitted_by`;
- client cannot control `submitted_at`;
- client cannot control `reviewed_by`;
- client cannot control `reviewed_at`;
- client cannot control presentation state;
- update accepts only the four mutable narrative fields;
- narrative normalization remains domain-owned;
- submission creates authoritative evidence snapshot;
- resubmission creates a fresh authoritative evidence snapshot;
- historical submission snapshot remains preserved after resubmission;
- review creates authoritative review evidence;
- mutation audit records use the authenticated actor;
- entity responses exclude `school_id`;
- reporter routes are not accidentally blocked by `manage_teacher_duty_roster`;
- reviewer routes are not accidentally blocked by `manage_teacher_duty_roster`;
- forbidden weekly lifecycle routes do not exist;
- generic weekly-report deletion does not exist;
- weekly-report-history mutation routes do not exist.

The existing weekly-report database/domain/service suites MUST remain green.

The complete Teacher Duty regression suite MUST remain green.

---

## 22. Explicit non-goals and later work

This contract introduces no notification or escalation delivery.

It introduces no generic leadership dashboard beyond the explicitly frozen weekly state/evidence read.

It introduces no actor-specific redaction or alternate weekly state projection.

If a broader school-wide weekly-report listing, dashboard, search, or analytics surface is required later, it MUST receive its own explicitly frozen authorization, tenant, route, and response contract.

No frontend implementation is included.

---

## 23. Exit criteria

6A.9I-C is complete only when:

1. this HTTP contract is reviewed and frozen;
2. weekly `state(...)` is hardened to the frozen reporter-or-reviewer authorization boundary;
3. weekly-report HTTP routes are implemented;
4. required FormRequests are implemented;
5. the thin weekly-report controller is implemented;
6. explicit entity and state response serialization is implemented;
7. HTTP mutation auditing is implemented;
8. required weekly-report HTTP feature tests pass;
9. existing weekly-report database/domain/service tests pass;
10. the complete Teacher Duty regression suite passes;
11. the full project regression suite passes;
12. Pint passes;
13. `git diff --check` passes;
14. the implementation is reviewed through the normal PR process;
15. the implementation is merged before 6A.9I-C is considered closed.
