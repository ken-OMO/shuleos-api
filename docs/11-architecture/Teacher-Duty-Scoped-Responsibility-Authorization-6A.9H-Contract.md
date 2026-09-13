# Teacher Duty Scoped Responsibility Authorization — Phase 6A.9H Contract

## 1. Purpose

Phase 6A.9H defines the final scoped responsibility-authorization model for the existing Teacher Duty domain.

This phase combines:

- capability-based authority;
- preserved Teacher Duty responsibility;
- same-school tenant boundaries;
- separation of reporting and review authority.

It does not redesign the Teacher Duty roster, occurrence, daily-report, or weekly-report lifecycle already frozen and implemented by earlier phases.

The architectural rule is:

> Authority answers what an actor may do. Responsibility answers which Teacher Duty resource the actor may do it to. Neither substitutes for the other.

---

## 2. Existing authoritative foundations

6A.9H preserves these existing contracts:

- Teacher Duty is a school-wide operational responsibility.
- Teacher Duty assignment is a Teacher specialization, not an arbitrary User assignment.
- assignment does not grant authorization;
- role names are not runtime authorization rules;
- `manage_teacher_duty_roster` governs roster administration only;
- `manage_teacher_duty_roster` does not grant reporting or review authority;
- Teacher Duty assignments are preserved lifecycle records;
- generic assignment deletion is forbidden;
- ending an assignment preserves the historical assignment;
- ending a duty period closes its current assignments but preserves them;
- weekly-report self-review remains forbidden;
- tenant context must fail closed;
- Platform Owner does not implicitly acquire school operational Teacher Duty scope.

---

## 3. New Teacher Duty capabilities

6A.9H introduces the following narrowly scoped capabilities:

    submit_teacher_duty_reports
    review_teacher_duty_reports

### 3.1 `submit_teacher_duty_reports`

This capability authorizes an otherwise responsible Teacher to perform Teacher Duty reporting mutations permitted by the underlying report lifecycle.

It does not by itself establish responsibility for a duty period.

An actor with this capability but without qualifying Teacher Duty responsibility for the target period MUST be denied.

### 3.2 `review_teacher_duty_reports`

This capability authorizes an otherwise eligible same-school reviewer to perform weekly Teacher Duty review decisions permitted by the weekly-report lifecycle.

It does not grant Teacher Duty roster-management authority.

It does not grant reporting responsibility.

### 3.3 Existing roster capability

    manage_teacher_duty_roster

remains exclusively the roster-administration capability.

It controls operations such as creating duty periods, assigning Teachers, ending assignments, and ending duty periods according to the already-frozen roster contracts.

A school may determine which authorized leadership user or role receives this capability through the existing permission system.

Runtime authorization MUST NOT hard-code Head Teacher, Deputy Head Teacher, Principal, School Admin, or any other role name as the Teacher Duty roster administrator.

---

## 4. Role names versus capabilities

Runtime Teacher Duty authorization MUST remain capability-based.

Examples such as:

- Head Teacher;
- Deputy Head Teacher;
- Principal;
- Deputy Principal;
- Director;
- Senior Teacher;
- HOD;
- School Admin;

are administrative role labels and MUST NOT themselves constitute Teacher Duty authorization rules.

System-role permission provisioning may provide sensible defaults, but runtime access MUST be resolved from effective permissions.

Schools remain free to adjust effective role/permission assignments according to their operational structure.

---

## 5. Teacher identity requirement

Reporting responsibility belongs to a Teacher specialization.

For a User to act as the responsible Teacher for a duty period:

1. the User MUST belong to the target school;
2. the User MUST be active;
3. the User MUST not be deleted;
4. the User MUST not be suspended;
5. an eligible same-school Teacher record MUST resolve to that User;
6. that Teacher MUST have qualifying assignment responsibility for the target Teacher Duty period;
7. the User MUST possess the required reporting capability.

A generic school User without the Teacher specialization MUST NOT acquire reporting responsibility merely by possessing `submit_teacher_duty_reports`.

---

## 6. Reporter authorization rule

Teacher Duty reporting authorization requires BOTH:

    submit_teacher_duty_reports

AND

qualifying Teacher Duty assignment responsibility for the target duty period.

Neither condition alone is sufficient.

### 6.1 Historical responsibility is authoritative

Reporter scope MUST NOT depend only on:

    teacher_duty_assignments.active = true

because normal duty-period closure intentionally marks current assignments inactive.

A preserved assignment record belonging to the target school and target duty period is authoritative evidence that the Teacher held Teacher Duty responsibility during that period.

Therefore legitimate responsibility survives normal assignment closure and period closure.

### 6.2 Replacement / substitute Teachers

6A.9H does not introduce a second substitute-assignment mechanism.

Teacher replacement is represented by the existing lifecycle:

1. end the original Teacher Duty assignment;
2. preserve that assignment as history;
3. create a new Teacher Duty assignment for the replacement Teacher.

Both assignments remain preserved responsibility records for the same duty period.

Where multiple Teachers legitimately served during one duty period, each Teacher who:

- has preserved assignment responsibility for that period; and
- possesses `submit_teacher_duty_reports`;

is within the reporting-responsibility scope for that period.

The weekly report remains one duty-period-level report rather than one report per Teacher.

---

## 7. Daily-report authorization

Existing daily-report lifecycle rules remain unchanged.

6A.9H adds responsibility authorization around daily-report mutations.

An actor may mutate a Teacher Duty daily report only when:

- the actor passes the Teacher eligibility boundary;
- the actor possesses `submit_teacher_duty_reports`;
- the actor has qualifying Teacher Duty responsibility for the report's duty period;
- the target belongs to the actor's school;
- the requested mutation is valid under the existing daily-report lifecycle.

Assignment history MUST be considered when determining responsibility.

A Teacher MUST NOT lose legitimate report access merely because the assignment or period was properly closed before reporting was completed.

---

## 8. Weekly-report reporter authorization

Existing weekly-report lifecycle rules remain unchanged.

An actor may open, update, submit, or resubmit a weekly Teacher Duty report only when:

- the actor passes the Teacher eligibility boundary;
- the actor possesses `submit_teacher_duty_reports`;
- the actor has qualifying Teacher Duty responsibility for the report's duty period;
- the target belongs to the actor's school;
- the requested operation is valid under the frozen weekly-report lifecycle.

The authorization layer MUST NOT allow a user to bypass weekly-report lifecycle state rules.

---

## 9. Reviewer authorization

Weekly review authority is distinct from reporting responsibility.

An actor may review a weekly Teacher Duty report only when:

- the actor belongs to the same school;
- the actor is active;
- the actor is not deleted;
- the actor is not suspended;
- the actor possesses `review_teacher_duty_reports`;
- the report belongs to the same school;
- the report is in a lifecycle state that permits review;
- the actor did not submit the submitted version being reviewed.

Reviewer authorization does not require Teacher Duty assignment responsibility.

This reflects the school-wide nature of the Teacher Duty domain.

---

## 10. Department and HOD scope

Teacher Duty is a school-wide responsibility.

6A.9H MUST NOT manufacture departmental scope from:

- `teacher_assignments.learning_area_id`;
- HOD learning-area assignments;
- Teacher Portal departmental workflow scope;
- Boarding/Hostel responsibility.

There is no authoritative Teacher Duty department key on the existing Teacher Duty assignment model.

Therefore an HOD does not gain Teacher Duty review authority merely because the User is an HOD.

An HOD may review a Teacher Duty weekly report only when the User possesses `review_teacher_duty_reports` and satisfies the normal reviewer eligibility rules.

The same capability rule applies to every other leadership role.

---

## 11. Separation of duties

The existing weekly-report separation-of-duty invariant remains mandatory.

A reviewer MUST NOT review the same submitted version that the reviewer submitted.

The database invariant:

    reviewed_by != submitted_by

remains authoritative where applicable.

Application authorization MUST also reject self-review before persistence.

Possession of both:

    submit_teacher_duty_reports
    review_teacher_duty_reports

does not waive the self-review prohibition.

---

## 12. Tenant isolation

Every 6A.9H authorization decision MUST fail closed across tenant boundaries.

A route identifier, report identifier, assignment identifier, Teacher identifier, or duty-period identifier MUST NOT establish tenant authority.

The target School MUST be resolved independently and all responsibility evidence MUST belong to that same School.

Cross-school Teacher assignments, Users, reports, periods, or review attempts MUST be rejected.

Tenant global scopes are not a substitute for explicit domain validation where the existing Teacher Duty services intentionally use `withoutGlobalScopes()`.

---

## 13. Platform Owner boundary

Platform Owner remains outside ordinary school Teacher Duty operational responsibility.

Platform-level authority MUST NOT silently become:

- roster administration;
- Teacher reporting authority;
- weekly-report review authority.

Any future platform-support override would require a separately frozen explicit contract.

6A.9H introduces no such override.

---

## 14. No automatic permission grant from assignment

Creating a Teacher Duty assignment MUST NOT:

- create a permission;
- grant a permission;
- attach a role;
- modify role permissions;
- elevate the Teacher User account.

Assignment records responsibility only.

The assigned Teacher must independently possess `submit_teacher_duty_reports`.

Likewise, removing or ending an assignment MUST NOT mutate the User's permissions.

---

## 15. Roster administrator flexibility

The person responsible for assigning Teachers on Duty varies between schools.

Examples may include:

- Head Teacher;
- Deputy Head Teacher;
- Principal;
- Deputy Principal;
- Senior Teacher;
- Administrator;
- School Admin.

6A.9H MUST NOT encode those role names as roster-management rules.

The established `manage_teacher_duty_roster` capability is the authorization mechanism.

A school's effective permission configuration determines which user or role performs Teacher Duty roster administration.

---

## 16. Teacher Portal integration requirement

An assigned Teacher's Teacher Portal MUST eventually expose the Teacher's relevant Teacher Duty responsibility.

The Teacher Portal experience must be driven by authoritative Teacher Duty assignments and must not maintain a second assignment store.

Expected presentation includes:

- upcoming Teacher Duty;
- current Teacher Duty;
- historical Teacher Duty;
- duty-period start date;
- duty-period end date;
- academic week where available;
- daily reporting status;
- weekly reporting status;
- occurrence access appropriate to the Teacher Duty workflow;
- review outcome/history appropriate to the Teacher.

Example presentation:

    Teacher Duty
    Week 4
    14/09/2026 - 18/09/2026
    Status: Upcoming

The exact Teacher Portal HTTP resources, controllers, API representation, dashboard cards, frontend components, and UX are NOT implemented by 6A.9H.

They are downstream integration requirements.

---

## 17. Printed / PDF Teacher Duty reports

Submitted Teacher Duty reports MUST eventually support a printable/PDF representation.

The printable representation must derive from authoritative server-owned report evidence.

It MUST NOT rely on mutable browser-only state.

The design must support historical accuracy across:

- original submission;
- changes requested;
- resubmission;
- approval;
- rejection;
- later viewing of preserved submission evidence.

Where a historical submitted version is printed, the output must correspond to that preserved submission version rather than silently substituting later mutable report content.

Expected printable information may include:

- School;
- academic year;
- term;
- academic week;
- duty-period dates;
- Teacher(s) who served;
- report narrative;
- occurrence aggregates;
- submission provenance;
- submission timestamp;
- review status;
- reviewer provenance;
- review timestamp;
- review comment where applicable.

PDF generation, print endpoints, presentation templates, and frontend Print/Download actions are outside the implementation scope of 6A.9H and remain downstream Teacher Duty API/reporting work.

---

## 18. Assignment notification integration

An assigned Teacher must eventually be able to receive assignment information such as:

    You have been assigned Teacher Duty for Week 4,
    from 14/09/2026 to 18/09/2026.

Later notification/escalation work may include:

- new Teacher Duty assignment;
- upcoming-duty reminder;
- current-duty reminder;
- pending daily report;
- pending weekly report;
- late/overdue report;
- changes requested;
- approval/rejection outcome.

6A.9H does not implement SMS, email, push, or notification delivery.

---

## 19. Authorization service boundary

6A.9H SHOULD introduce a dedicated Teacher Duty authorization/scope service rather than duplicating responsibility logic across daily and weekly report services.

The service must be capable of asserting, at minimum:

- reporter responsibility for a duty period;
- reporter responsibility for a daily report;
- reporter responsibility for a weekly report;
- reviewer authority for a weekly report.

Exact class and method names are implementation details unless separately frozen by implementation tests.

The authorization service must compose:

- existing authentication context / effective permission resolution;
- Teacher identity resolution;
- Teacher Duty assignment history;
- school tenant validation;
- report/period ownership.

It MUST NOT create a parallel Teacher Duty responsibility model.

---

## 20. Integration with existing domain services

6A.9H must integrate with the existing Teacher Duty services without redesigning their lifecycle state machines.

At minimum, scoped authorization must protect relevant mutation paths in:

- TeacherDutyDailyReportService;
- TeacherDutyWeeklyReportService.

Existing validation concerning:

- report state;
- submission;
- resubmission;
- review decision;
- evidence snapshots;
- history;
- transaction boundaries;
- row locking;
- self-review;

must remain intact.

Authorization is an additional boundary, not a replacement for domain validation.

---

## 21. Reads and history

Responsibility-scoped reads must not leak another school's Teacher Duty data.

Assigned Teachers may eventually access their own relevant current and historical Teacher Duty workspace.

Leadership users with appropriate capabilities may access the school-wide Teacher Duty resources required by their authorized operation.

Read exposure through HTTP remains subject to the later Teacher Duty API contract.

6A.9H does not require frontend or route implementation.

---

## 22. Commercial entitlement

6A.9H does not introduce or enforce subscription/package entitlement.

Commercial entitlement remains owned by the later canonical subscription-entitlement layer.

Authorization and commercial entitlement remain separate concerns.

---

## 23. Operational middleware

6A.9H MUST NOT casually broaden use of `school.operational`.

The existing Teacher Duty lifecycle contracts remain authoritative regarding which creation/current-operation paths require operational-school middleware and which terminal/historical operations remain available after operational status changes.

Scoped authorization does not itself redefine school lifecycle middleware.

---

## 24. Required regression coverage

Implementation tests for 6A.9H MUST prove at least:

1. assignment alone does not grant reporting capability;
2. reporting capability alone does not grant responsibility;
3. assigned Teacher plus reporting capability may act on the correct duty period;
4. Teacher cannot act on another Teacher Duty period without qualifying responsibility;
5. historical closed assignment still establishes legitimate responsibility;
6. normal duty-period closure does not incorrectly remove legitimate reporting scope;
7. replacement Teacher assignment is recognized;
8. original preserved assignment remains recognizable after replacement;
9. cross-school responsibility fails closed;
10. inactive User fails;
11. deleted User fails;
12. suspended User fails;
13. Teacher identity must resolve to the acting User;
14. generic non-Teacher User cannot gain reporter responsibility from capability alone;
15. reviewer requires `review_teacher_duty_reports`;
16. reviewer does not require Teacher Duty assignment;
17. HOD role name alone grants no Teacher Duty review authority;
18. Principal/Deputy/Head/Director role name alone grants no Teacher Duty review authority;
19. reviewer capability works independent of role name when otherwise eligible;
20. self-review remains forbidden;
21. possessing both reporting and review capabilities does not permit self-review;
22. roster-management capability does not imply reporting capability;
23. roster-management capability does not imply review capability;
24. reporting capability does not imply roster-management authority;
25. assignment creation does not mutate permissions;
26. assignment closure does not mutate permissions;
27. Platform Owner receives no implicit school Teacher Duty operational scope;
28. existing daily-report lifecycle tests continue to pass;
29. existing weekly-report lifecycle tests continue to pass;
30. existing roster tests continue to pass.

---

## 25. Explicit non-goals

6A.9H does NOT implement:

- Teacher Duty HTTP controllers/routes;
- Teacher Portal dashboard UI;
- Leadership dashboard UI;
- PDF generation;
- print templates;
- SMS/email/push notifications;
- reminder/escalation delivery;
- subscription entitlement;
- frontend workflows;
- a second substitute-assignment model;
- departmental Teacher Duty mapping;
- Boarding/Hostel responsibility reuse;
- generic approval-engine migration;
- redesign of existing daily/weekly report lifecycle;
- automatic role or permission grants caused by assignment.

---

## 26. Downstream sequence

After 6A.9H, Teacher Duty work proceeds through later frozen phases for:

1. Teacher Duty HTTP/API completion;
2. Teacher Portal and leadership Teacher Duty exposure;
3. printable/PDF Teacher Duty reports;
4. Teacher Duty Settings API;
5. Teacher Duty notifications and escalation.

Student Elections follows completion of the remaining Teacher Duty sequence, then Finance / Fees, then Subscription work according to the agreed roadmap.

---

## 27. Freeze rule

Once this contract is committed as the 6A.9H frozen contract:

- implementation MUST conform to it;
- implementation convenience MUST NOT silently rewrite the contract;
- any genuine architectural contradiction discovered during implementation must be reviewed explicitly before changing the frozen contract;
- previous Teacher Duty contracts remain authoritative except where 6A.9H explicitly resolves authorization questions that those phases deferred.

Phase 6A.9H ends only after the scoped authorization implementation, permission provisioning, integration, regression verification, formatting verification, and merge are complete.
