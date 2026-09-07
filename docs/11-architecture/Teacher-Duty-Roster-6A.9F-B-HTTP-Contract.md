# Teacher Duty Roster 6A.9F-B HTTP Contract

Status: FROZEN after review

Phase: 6A.9F-B — Teacher-on-Duty / Duty Roster Foundation

This document freezes the HTTP boundary for the already-frozen
Teacher Duty Roster database and application contracts.

The HTTP boundary MUST consume TeacherDutyRosterService and MUST NOT
duplicate, weaken, broaden, or reinterpret domain invariants.

---

## 1. Scope

This HTTP slice exposes only the Teacher Duty Roster foundation.

Included:

- duty-period creation,
- duty-period current listing,
- duty-period history,
- duty-period single read,
- terminal duty-period end,
- Teacher assignment creation,
- current assignment listing for a period,
- assignment history for a period,
- assignment single read,
- terminal assignment end,
- capability-based authorization,
- school-operational middleware where frozen,
- tenant context enforcement,
- server-owned field rejection,
- safe JSON serialization,
- audit recording for successful create/end mutations.

Excluded:

- occurrences,
- occurrence categories,
- daily reports,
- overdue state,
- weekly reports,
- weekly report review,
- notifications,
- reminders,
- escalation,
- subscription entitlement enforcement,
- scoped responsibility authorization,
- Boarding coupling,
- timetable coupling,
- TeacherWorkflowService integration.

---

## 2. Route Prefix and Security Boundary

All routes use:

    /api/teacher-duty

All routes MUST live inside the project's existing authenticated
school-tenant security middleware boundary.

Every Teacher Duty Roster route MUST require:

    permission:manage_teacher_duty_roster

Runtime authorization MUST remain capability-based.

Role names MUST NOT be used as runtime authorization rules.

Teacher-on-Duty assignment itself MUST NOT grant
manage_teacher_duty_roster or any other permission.

---

## 3. Frozen Route Surface

### Duty periods

#### Current periods

    GET /api/teacher-duty/periods

Controller operation:

    currentPeriods

Domain service:

    TeacherDutyRosterService::currentPeriods

Middleware:

- authenticated secure school boundary,
- permission:manage_teacher_duty_roster.

MUST NOT require:

- school.operational.

Response status:

    200

---

#### Create period

    POST /api/teacher-duty/periods

Controller operation:

    storePeriod

Domain service:

    TeacherDutyRosterService::createPeriod

Middleware:

- authenticated secure school boundary,
- permission:manage_teacher_duty_roster,
- school.operational.

Success status:

    201

---

#### Period history

    GET /api/teacher-duty/periods/history

Controller operation:

    periodHistory

Domain service:

    TeacherDutyRosterService::periodHistory

Middleware:

- authenticated secure school boundary,
- permission:manage_teacher_duty_roster.

MUST NOT require:

- school.operational.

Response status:

    200

This route MUST be registered before the parameterized
/api/teacher-duty/periods/{period} route.

---

#### Single period

    GET /api/teacher-duty/periods/{period}

Controller operation:

    showPeriod

Domain service:

    TeacherDutyRosterService::period

Middleware:

- authenticated secure school boundary,
- permission:manage_teacher_duty_roster.

MUST NOT require:

- school.operational.

Response status:

    200

Tenant mismatch MUST fail closed.

---

#### End period

    PATCH /api/teacher-duty/periods/{period}/end

Controller operation:

    endPeriod

Domain service:

    TeacherDutyRosterService::endPeriod

Middleware:

- authenticated secure school boundary,
- permission:manage_teacher_duty_roster.

MUST NOT require:

- school.operational.

Response status:

    200

Ending a period MUST retain the frozen domain behavior:

- terminal lifecycle,
- no reactivation,
- all current child Teacher assignments close atomically,
- same authoritative actor,
- same authoritative timestamp,
- same normalized end reason,
- historical child assignments remain unchanged.

The HTTP controller MUST NOT reproduce that transaction logic.

---

## 4. Assignment Routes

### Current assignments for period

    GET /api/teacher-duty/periods/{period}/assignments

Controller operation:

    currentAssignments

Domain service:

    TeacherDutyRosterService::currentAssignmentsForPeriod

Middleware:

- authenticated secure school boundary,
- permission:manage_teacher_duty_roster.

MUST NOT require:

- school.operational.

Response status:

    200

The domain service first proves that the period belongs to the
authoritative school.

---

### Assign Teacher

    POST /api/teacher-duty/periods/{period}/assignments

Controller operation:

    storeAssignment

Domain service:

    TeacherDutyRosterService::assignTeacher

Middleware:

- authenticated secure school boundary,
- permission:manage_teacher_duty_roster,
- school.operational.

Success status:

    201

The Teacher identifier comes from the validated request.

The school, period ownership and assigning actor remain authoritative
server context.

---

### Assignment history for period

    GET /api/teacher-duty/periods/{period}/assignments/history

Controller operation:

    assignmentHistory

Domain service:

    TeacherDutyRosterService::assignmentHistoryForPeriod

Middleware:

- authenticated secure school boundary,
- permission:manage_teacher_duty_roster.

MUST NOT require:

- school.operational.

Response status:

    200

This route MUST be registered before any conflicting parameterized
assignment route within the same prefix.

Historical reads MUST remain available after referenced Teachers or
linked Users cease to be currently eligible.

---

### Single assignment

    GET /api/teacher-duty/assignments/{assignment}

Controller operation:

    showAssignment

Domain service:

    TeacherDutyRosterService::assignment

Middleware:

- authenticated secure school boundary,
- permission:manage_teacher_duty_roster.

MUST NOT require:

- school.operational.

Response status:

    200

Tenant mismatch MUST fail closed.

---

### End assignment

    PATCH /api/teacher-duty/assignments/{assignment}/end

Controller operation:

    endAssignment

Domain service:

    TeacherDutyRosterService::endAssignment

Middleware:

- authenticated secure school boundary,
- permission:manage_teacher_duty_roster.

MUST NOT require:

- school.operational.

Response status:

    200

Ending is terminal.

The HTTP controller MUST NOT expose reactivation, restore, delete, or
generic update operations.

---

## 5. Store Period Request Contract

The create-period request accepts only:

- start_date,
- end_date,
- academic_week_id as optional metadata.

Rules:

    start_date:
        required
        date_format:Y-m-d

    end_date:
        required
        date_format:Y-m-d

    academic_week_id:
        nullable
        uuid

TenantMiddleware supplies school_id.

school_id MUST be:

- required after middleware injection,
- UUID,
- equal to the authenticated user's authoritative school,
- rejected if the client attempted to supply it.

The request layer MAY validate syntactic shape.

The domain service remains authoritative for:

- strict date interpretation,
- end_date >= start_date,
- AcademicWeek existence,
- same-school AcademicWeek ownership,
- actor eligibility,
- tenant safety.

The request MUST NOT duplicate those service invariants.

---

## 6. Store Assignment Request Contract

The assignment request accepts only:

- teacher_id.

Rules:

    teacher_id:
        required
        uuid

TenantMiddleware supplies school_id using the same authoritative
school-context rule as the period request.

The period identifier comes from the route.

The domain service remains authoritative for:

- period tenant ownership,
- current-period requirement,
- Teacher specialization,
- Teacher tenant ownership,
- Teacher active/not-deleted state,
- linked User tenant ownership,
- linked User active/not-deleted/not-suspended state,
- duplicate-current-assignment handling,
- database concurrency backstop.

The request MUST NOT perform role-name substitution for Teacher
specialization.

---

## 7. End Request Contract

Both terminal endpoints accept only:

- optional reason.

Rules:

    reason:
        nullable
        string
        max:500

TenantMiddleware supplies school_id.

The route supplies the period or assignment identifier.

Reason normalization remains owned by TeacherDutyRosterService:

- trim whitespace,
- blank becomes null,
- 500-character maximum.

---

## 8. Prohibited Client Fields

All relevant requests MUST explicitly reject client control of
authoritative fields.

Period creation MUST prohibit at minimum:

- id,
- school_id when client supplied,
- active,
- created_by,
- ended_by,
- ended_at,
- end_reason,
- created_at,
- updated_at.

Period end MUST prohibit at minimum:

- id,
- academic_week_id,
- start_date,
- end_date,
- active,
- created_by,
- ended_by,
- ended_at,
- end_reason,
- created_at,
- updated_at.

Assignment creation MUST prohibit at minimum:

- id,
- school_id when client supplied,
- duty_period_id,
- active,
- assigned_by,
- ended_by,
- ended_at,
- end_reason,
- reason,
- created_at,
- updated_at.

Assignment end MUST prohibit at minimum:

- id,
- duty_period_id,
- teacher_id,
- active,
- assigned_by,
- ended_by,
- ended_at,
- end_reason,
- created_at,
- updated_at.

The accepted `reason` field is the client request to end an episode.

The persisted `end_reason` field is authoritative server state and
MUST NOT be client-controlled.

---

## 9. Authoritative School and Actor Resolution

Controllers MUST obtain school identity from the authenticated tenant
request context.

Controllers MUST NOT accept school authority from route or body input.

Controllers MUST obtain mutation actor identity from the authenticated
request user.

The client MUST NOT control:

- created_by,
- assigned_by,
- ended_by.

TeacherDutyRosterService remains responsible for validating that the
authoritative actor is:

- same-school,
- active,
- not deleted,
- not suspended.

---

## 10. Controller Surface

The HTTP boundary uses one dedicated controller:

    TeacherDutyRosterController

Frozen public actions:

- currentPeriods
- storePeriod
- periodHistory
- showPeriod
- endPeriod
- currentAssignments
- storeAssignment
- assignmentHistory
- showAssignment
- endAssignment

No generic:

- update,
- destroy,
- restore,
- reactivate

action is permitted.

The controller MUST depend on TeacherDutyRosterService.

It MUST NOT query Teacher Duty tables directly for domain decisions.

---

## 11. JSON Period Representation

A period response exposes only:

- id,
- academic_week_id,
- start_date,
- end_date,
- active,
- end_reason,
- ended_at,
- created_at,
- updated_at.

The response MUST NOT expose:

- school_id,
- created_by,
- ended_by.

Dates start_date and end_date MUST serialize as YYYY-MM-DD.

No internal tenant or actor authority fields are public API state.

---

## 12. JSON Assignment Representation

An assignment response exposes only:

- id,
- duty_period_id,
- teacher_id,
- active,
- end_reason,
- ended_at,
- created_at,
- updated_at.

The response MUST NOT expose:

- school_id,
- assigned_by,
- ended_by.

No permission is implied by the presence of teacher_id in this
representation.

---

## 13. Collection Response Shape

Current and history listing endpoints return:

    {
        "data": [...]
    }

Single reads return:

    {
        "data": {...}
    }

Creation endpoints return:

    {
        "message": "...",
        "data": {...}
    }

Terminal end endpoints return:

    {
        "message": "...",
        "data": {...}
    }

No pagination is introduced in 6A.9F-B.

---

## 14. Success Messages

Frozen success messages:

Period creation:

    Teacher duty period created successfully.

Period end:

    Teacher duty period ended successfully.

Teacher assignment:

    Teacher assigned to duty period successfully.

Assignment end:

    Teacher duty assignment ended successfully.

---

## 15. Validation and Failure Semantics

The project-standard API exception boundary remains authoritative.

Expected HTTP behavior includes:

- unauthenticated request -> 401,
- missing manage_teacher_duty_roster -> 403,
- request validation failure -> 422,
- tenant mismatch represented as domain validation failure -> 422,
- invalid lifecycle transition -> 422,
- duplicate current Teacher assignment -> 422.

The HTTP layer MUST NOT leak raw SQL errors.

The HTTP layer MUST NOT expose whether a foreign-tenant identifier
exists.

---

## 16. School Operational Boundary

Only these two routes require school.operational:

    POST /api/teacher-duty/periods

    POST /api/teacher-duty/periods/{period}/assignments

These routes MUST NOT require school.operational:

    GET /api/teacher-duty/periods

    GET /api/teacher-duty/periods/history

    GET /api/teacher-duty/periods/{period}

    PATCH /api/teacher-duty/periods/{period}/end

    GET /api/teacher-duty/periods/{period}/assignments

    GET /api/teacher-duty/periods/{period}/assignments/history

    GET /api/teacher-duty/assignments/{assignment}

    PATCH /api/teacher-duty/assignments/{assignment}/end

Terminal lifecycle cleanup MUST remain possible while a school is
non-operational.

---

## 17. Audit Boundary

Successful HTTP mutations MUST create the project's normal audit
record.

Audited operations:

- Create duty period,
- End duty period,
- Assign Teacher to duty period,
- End Teacher duty assignment.

Audit records MUST use the authenticated authoritative actor.

Audit payloads MUST contain only the business-state values needed for
traceability.

Audit payloads MUST NOT create a second source of lifecycle truth.

The domain transaction remains authoritative for Teacher Duty state.

---

## 18. Assignment Does Not Grant Authorization

Teacher-on-Duty assignment is a responsibility record.

It does not grant:

- manage_teacher_duty_roster,
- manage_boarding,
- manage_timetable,
- manage_academics,
- reporting review authority,
- any future scoped responsibility permission.

Authorization continues to come only from the canonical permission
system.

---

## 19. Commercial Policy Boundary

Commercial policy remains:

- Standard: included,
- Enterprise: included,
- Basic: excluded.

6A.9F-B MUST NOT implement technical subscription entitlement
enforcement.

The future canonical entitlement layer will enforce commercial
packaging centrally.

No ad hoc Teacher Duty subscription middleware may be introduced here.

---

## 20. Deferred Work

The following remain explicitly deferred:

### 6A.9G-A

- daily occurrences,
- configurable occurrence categories,
- canonical seeded occurrence categories.

### 6A.9G-B

- daily Teacher-on-Duty obligations,
- not-started/draft/submitted/overdue state,
- overdue calculation.

### 6A.9G-C

- weekly report aggregation,
- weekly submission,
- weekly review,
- reviewer capability discovery,
- workflow reuse/generalization decision.

### 6A.9H

- scoped responsibility authorization.

Future work MUST extend this foundation without altering its frozen
tenant, lifecycle, assignment, and authorization semantics.

---

## 21. Implementation File Boundary

The expected HTTP implementation slice may add:

- app/Http/Controllers/Api/TeacherDutyRosterController.php
- app/Http/Requests/TeacherDuty/StoreTeacherDutyPeriodRequest.php
- app/Http/Requests/TeacherDuty/StoreTeacherDutyAssignmentRequest.php
- app/Http/Requests/TeacherDuty/EndTeacherDutyPeriodRequest.php
- app/Http/Requests/TeacherDuty/EndTeacherDutyAssignmentRequest.php
- tests/Feature/TeacherDuty/TeacherDutyRosterManagementTest.php

and modify:

- routes/api.php

A dedicated JsonResource class is not required for 6A.9F-B because
the nearest established lifecycle precedent uses explicit
privacy-safe controller serializers.

If implementation later demonstrates a concrete reuse requirement,
resource extraction requires separate review and MUST NOT silently
broaden this frozen slice.

---

## 22. Frozen Invariants Summary

The HTTP implementation MUST preserve all of the following:

1. Every route requires manage_teacher_duty_roster.
2. Runtime authorization is capability-based.
3. Assignment does not grant authorization.
4. TenantMiddleware owns school context.
5. Client school ownership attempts are rejected.
6. Actor fields are server-owned.
7. Lifecycle fields are server-owned.
8. Route identifiers do not establish tenant authority.
9. Domain service performs authoritative tenant validation.
10. Create period requires school.operational.
11. Assign Teacher requires school.operational.
12. Reads do not require school.operational.
13. End period does not require school.operational.
14. End assignment does not require school.operational.
15. Period end closes current child assignments atomically.
16. Assignment end is terminal.
17. Period end is terminal.
18. No delete/restore/reactivation HTTP endpoints exist.
19. Multiple Teachers may serve the same duty period.
20. Overlapping duty periods remain valid.
21. Calendar dates remain authoritative.
22. AcademicWeek remains optional metadata.
23. Historical reads survive Teacher/User retirement.
24. Safe JSON hides tenant and actor authority fields.
25. Successful mutations create normal audit records.
26. No Boarding responsibility coupling is introduced.
27. No timetable permission is reused.
28. No academic-management permission is reused.
29. No subscription entitlement gate is introduced.
30. No occurrence/reporting/notification workflow is introduced.

This contract is frozen for the 6A.9F-B HTTP implementation slice.