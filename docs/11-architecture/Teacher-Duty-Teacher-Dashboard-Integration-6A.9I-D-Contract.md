# Teacher Duty Dashboard Integration Contract

## Phase

6A.9I-D — Teacher Duty Teacher Dashboard Integration

## Purpose

Expose a lightweight Teacher Duty summary on the existing active Teacher Portal
dashboard without rebuilding Teacher Duty domain, lifecycle, tenancy, or
authorization logic.

## Existing Dashboard Surface

The integration targets only:

GET /api/teacher/dashboard

TeacherPortalMobileController::dashboard()
→ TeacherPortalMobileService::dashboard()
→ TeacherDashboardResource

The legacy TeacherPortalService::dashboard() is not part of this phase because
it is not the active /api/teacher/dashboard route.

No new Teacher Portal route is introduced.

## Dashboard Contract

TeacherPortalMobileService::dashboard() adds exactly one top-level key:

teacher_duty

The existing dashboard keys remain unchanged.

The teacher_duty value is either:

1. null when there is no Teacher Duty period for which the authenticated teacher
   has reporter responsibility relevant to the dashboard; or

2. a lightweight dashboard projection containing only dashboard-safe Teacher Duty
   status.

The projection may expose:

- duty_period_id
- academic_week_id
- week_number when available from the authoritative period/week relationship
- today
  - state
  - deadline_at
  - submitted_at
  - late
- occurrences_recorded
- weekly_report
  - id
  - state

No weekly history, narrative, evidence snapshot, reviewer evidence, full occurrence
collection, or unrelated Teacher Duty model attributes are exposed by the dashboard.

## Composition Boundary

This phase is integration/composition only.

It MUST NOT:

- introduce a new Teacher Duty lifecycle rule;
- introduce a new Teacher Duty permission;
- introduce role-name authorization;
- duplicate reporter responsibility rules;
- weaken school/tenant isolation;
- change completed daily or weekly reporting semantics;
- change the 6A.9I-C weekly reporting contract;
- change Teacher Duty roster semantics;
- create a dashboard-specific Teacher Duty source of truth.

Existing Teacher Duty services remain authoritative.

## Reporter Authorization

Dashboard Teacher Duty data is teacher-facing reporter data.

The dashboard MUST NOT infer authorization merely because an assignment row exists.

Existing TeacherDutyAuthorizationService::reporter() semantics remain authoritative
for reporter responsibility.

A same-school teacher without reporter authority for a duty period MUST NOT receive
that period's Teacher Duty dashboard data.

Historical/end-dated responsibility semantics remain exactly as already defined by
Teacher Duty authorization.

## Period Discovery

TeacherDutyRosterService::currentPeriods() may be used to discover candidate current
Teacher Duty periods.

Candidate discovery does not itself grant access.

Each candidate used for teacher-facing dashboard data must still satisfy existing
reporter authorization.

The integration must fail closed and must not expose another teacher's duty period.

## Daily Status

For an authorized current duty period, today's status is projected from:

TeacherDutyDailyReportService::state(
    schoolId,
    periodId,
    today,
    actorUserId
)

The dashboard MUST NOT reimplement daily deadline, overdue, submitted, or late logic.

The dashboard uses the existing state values unchanged.

## Weekly Status

TeacherDutyWeeklyReportService::state() requires an existing weekly report ID.

The integration may discover the same-school weekly report belonging to the
authorized duty period solely to obtain the identifier required to call the existing
weekly state service.

If no weekly report exists:

weekly_report = null

The dashboard MUST NOT create/open a weekly report as a side effect of reading the
dashboard.

If a weekly report exists, its dashboard state is projected from
TeacherDutyWeeklyReportService::state().

Dashboard reads remain read-only.

## Occurrence Count

occurrences_recorded is a lightweight count for the authorized duty period.

The count must be school-scoped and duty-period-scoped.

No occurrence collection or occurrence details are returned.

The integration must not mutate occurrences or invent occurrence lifecycle rules.

## Resource Boundary

The active dashboard continues through TeacherDashboardResource.

Teacher Duty dashboard output must remain within that safe resource boundary.

No school_id, internal authorization metadata, evidence history, or sensitive
server-side fields are added to the dashboard response.

## Permissions

No new permission string is introduced.

The existing dashboard route remains protected by:

permission:access_teacher_portal

Teacher Duty reporter authorization remains an additional domain boundary inside
the composed Teacher Duty projection.

Possession of access_teacher_portal alone does not grant Teacher Duty reporter data.

## No-Duty Behaviour

A legitimate teacher with access to the Teacher Portal but no authorized current
Teacher Duty period receives:

teacher_duty: null

This is a normal dashboard state, not an authorization failure for the entire
Teacher Portal dashboard.

## Multiple Candidate Periods

The existing Teacher Duty roster explicitly permits overlapping duty periods and
TeacherDutyRosterService::currentPeriods() may therefore return more than one active
candidate period.

The dashboard must evaluate reporter authorization against every current candidate
period using the existing TeacherDutyAuthorizationService::reporter() boundary.

The result is:

- zero authorized current periods → teacher_duty = null;
- exactly one authorized current period → project that period;
- more than one authorized current period → fail closed because the existing Teacher
  Duty domain defines no authoritative rule for selecting one period for the dashboard.

The dashboard MUST NOT use collection order, first(), latest(), earliest(), academic
week presence, assignment creation time, or any other integration-layer heuristic to
select between multiple authorized current periods.

No new "primary duty period" or period-priority rule is introduced by this phase.

## Error / Isolation Boundary

Cross-school Teacher Duty data must never be exposed.

Malformed or foreign identifiers must not be introduced into the dashboard
projection.

Expected absence of Teacher Duty responsibility produces teacher_duty: null.

Unexpected domain/integrity failures must not be silently converted into misleading
Teacher Duty status.

## Explicitly Out Of Scope

- task inbox integration
- leadership/HOD dashboard integration
- Teacher Duty settings API
- Teacher Duty notifications or escalation
- PDF/printing
- new Teacher Duty routes
- new permissions
- new database migrations
- Teacher Duty lifecycle changes
- legacy TeacherPortalService dashboard modernization
- dashboard preference redesign

## Required Tests

At minimum prove:

1. /api/teacher/dashboard retains its existing permission protection.
2. existing dashboard keys remain available.
3. teacher_duty is present on the active dashboard.
4. teacher with no authorized current duty receives teacher_duty = null.
5. authorized reporter receives only their authorized current duty projection.
6. today's state comes from existing daily-report semantics.
7. submitted daily state is projected correctly.
8. occurrence count is school- and period-scoped.
9. no weekly report produces weekly_report = null and does not create one.
10. existing weekly report projects existing weekly state.
11. changes_requested weekly state is projected correctly.
12. another teacher's responsibility is not exposed.
13. cross-school Teacher Duty data is not exposed.
14. dashboard read creates no daily/weekly report or lifecycle history.
15. dashboard response does not expose school_id, evidence snapshots, weekly history,
    or review evidence.
16. multiple applicable authorized current periods do not produce arbitrary
    first-period selection.
17. existing Teacher Portal dashboard tests remain green.
18. Teacher Duty regression remains green.

## Verification Gate

Before implementation commit:

- focused Teacher Duty dashboard integration tests PASS;
- existing TeacherPortalMobileTest PASS;
- relevant Teacher Portal regression PASS;
- Teacher Duty regression PASS;
- targeted Pint PASS;
- global Pint PASS;
- git diff --check PASS;
- working diff contains only contract-approved integration paths.

The frozen contract commit must remain separate from the implementation commit.
