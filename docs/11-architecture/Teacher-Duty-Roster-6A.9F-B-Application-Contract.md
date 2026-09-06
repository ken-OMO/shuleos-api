# Teacher Duty Roster 6A.9F-B — Application Contract

## Status

FROZEN for Phase 6A.9F-B application implementation.

This contract builds on the frozen 6A.9F-B database contract and does not
expand the phase into daily operations, reporting, notifications, commercial
entitlement enforcement, or scoped responsibility authorization.

## 1. Domain Boundary

Teacher-on-Duty is a school-wide responsibility.

It is not a Boarding or Hostel responsibility and must not use
HostelStaffAssignment or BoardingStaffResponsibilityService as its domain
model.

A duty assignment identifies a Teacher specialization, not merely a User.

Assignment does not grant authorization.

## 2. Domain Models

The application layer introduces:

- TeacherDutyPeriod
- TeacherDutyAssignment

Both are tenant-owned historical lifecycle records.

Neither record may be generically deleted, soft-deleted, restored, or
reactivated.

An ended episode remains preserved. A later duty period or later assignment
creates a new record.

## 3. Service Boundary

The domain service is TeacherDutyRosterService.

Its application operations are:

- createPeriod
- endPeriod
- assignTeacher
- endAssignment
- period
- currentPeriods
- periodHistory
- assignment
- currentAssignmentsForPeriod
- assignmentHistoryForPeriod

HTTP controllers, requests, resources, routes, and middleware are a later
boundary and must consume this service rather than duplicate its invariants.

## 4. Period Creation

createPeriod receives:

- schoolId
- startDate
- endDate
- optional academicWeekId
- actorUserId

The server owns:

- school_id
- active
- created_by
- ended_by
- ended_at
- end_reason
- timestamps

startDate and endDate must use strict YYYY-MM-DD format.

Dates are interpreted using the school's configured timezone, falling back to
the application timezone.

endDate must be greater than or equal to startDate.

The supplied dates are authoritative.

The application must not require Monday-to-Friday boundaries and must not
derive or overwrite these dates from AcademicWeek.

Overlapping duty periods are permitted.

## 5. Optional AcademicWeek

academicWeekId is optional.

When supplied, the referenced AcademicWeek must exist and belong to the same
school as the duty period.

The AcademicWeek link is metadata/context only.

The duty period dates do not have to equal the AcademicWeek start_date and
end_date.

AcademicWeek active=true is not an eligibility requirement for this phase.
Historical or otherwise valid same-school academic-week references must not
silently rewrite duty-period dates.

Tenant mismatch fails closed.

## 6. Actor Eligibility

Every write receives an authoritative actorUserId from the authenticated
server context.

A write actor must be a User who:

- belongs to the same school,
- is active,
- is not deleted, and
- is not suspended.

Actor records used by lifecycle writes are locked transactionally where
appropriate.

Client input never controls created_by, assigned_by, ended_by, or any other
actor field.

## 7. Teacher Eligibility

assignTeacher receives a Teacher identifier.

The selected Teacher must:

- belong to the same school,
- have active=true,
- have is_deleted=false, and
- have a linked User.

The linked User must:

- belong to the same school as both the Teacher and duty period,
- be active,
- not be deleted, and
- not be suspended.

The Teacher and its linked User eligibility state must be validated under the
assignment transaction.

A User without an eligible Teacher specialization cannot be assigned merely
because that User holds a role whose name contains "Teacher".

Role names are not Teacher eligibility rules.

## 8. Assignment Rules

A Teacher may only be assigned to a current duty period.

A current duty period has active=true and no ending evidence.

One duty period may contain one, two, three, or more different Teachers.

There is no one-Teacher-per-week rule.

The same Teacher cannot hold the same current assignment twice in one duty
period.

The service may perform a friendly pre-check, but the PostgreSQL partial unique
index remains authoritative for concurrency.

A PostgreSQL SQLSTATE 23505 violation of
teacher_duty_assignments_active_identity_unique must be translated into a
stable validation error rather than exposed as an infrastructure error.

## 9. Assignment Ending

endAssignment receives:

- schoolId
- assignmentId
- actorUserId
- optional reason

The assignment is resolved by school_id and id and locked for update.

Only a current assignment may be ended.

Attempting to end an already-ended assignment is a validation failure.

The reason is normalized by trimming whitespace.

A null or blank reason becomes null.

A non-null reason may not exceed 500 characters.

Ending sets server-owned lifecycle evidence:

- active=false
- ended_by=actor
- ended_at=authoritative server time
- end_reason=normalized reason

Ending is terminal.

The record is never reused or reactivated.

## 10. Period Ending

endPeriod receives:

- schoolId
- periodId
- actorUserId
- optional reason

The period is resolved by school_id and id and locked for update.

Only a current period may be ended.

Attempting to end an already-ended period is a validation failure.

The reason follows the same normalization and 500-character boundary used for
assignment ending.

Ending a duty period must atomically close every current assignment belonging
to that period.

The period and all current child assignments are closed in the same database
transaction using:

- the authoritative actor,
- one authoritative ending timestamp, and
- the normalized period-ending reason.

This prevents an inactive duty period from retaining active Teacher
assignments.

Already-historical child assignments remain unchanged.

The period is then closed with:

- active=false
- ended_by=actor
- ended_at=the same authoritative ending timestamp
- end_reason=normalized reason

The operation is terminal and does not reactivate or repurpose any record.

## 11. Transactions and Locking

Creation, assignment, and terminal lifecycle writes occur inside database
transactions.

The implementation follows established project practice of transactional
retries where appropriate.

Eligibility and lifecycle records that participate in a write are locked with
SELECT ... FOR UPDATE where needed to prevent stale-state decisions.

Tenant validation occurs inside the transaction.

Database constraints remain the final authority for concurrency-sensitive
uniqueness.

## 12. Tenant Safety

school_id is server-owned.

All reads and writes are explicitly scoped to the authoritative school.

Cross-school:

- actors,
- Teachers,
- linked Teacher Users,
- AcademicWeeks,
- duty periods, and
- duty assignments

must fail closed.

The application must not trust a relationship merely because an ordinary
single-column foreign key succeeds.

This is especially important for teacher_id and academic_week_id because the
frozen database contract intentionally did not mutate existing core tables
solely to manufacture composite foreign-key candidate keys.

## 13. Read Semantics

period resolves one period by authoritative school_id and id.

assignment resolves one assignment by authoritative school_id and id.

currentPeriods returns current periods for one school.

periodHistory returns current and ended period episodes for one school in a
deterministic order suitable for history presentation.

currentAssignmentsForPeriod first proves that the period belongs to the
authoritative school, then returns only active assignments for that period.

assignmentHistoryForPeriod first proves that the period belongs to the
authoritative school, then returns current and ended assignment episodes in a
deterministic history order.

Historical reads do not require the referenced Teacher or linked User to remain
currently eligible.

Lifecycle history must remain readable after a Teacher/User later becomes
inactive, suspended, or otherwise ineligible for new assignment.

## 14. Model Mutation Boundary

TeacherDutyPeriod and TeacherDutyAssignment expose only narrowly appropriate
client-domain fields for mass assignment.

Tenant, actor, lifecycle, and timestamp fields remain server-owned.

Generic model deletion is forbidden.

There is no generic update method.

Changing period dates, changing AcademicWeek, replacing the assigned Teacher,
restoring an ended record, or directly manipulating lifecycle evidence is not
part of the 6A.9F-B service contract.

## 15. Authorization Boundary

The roster-administration capability is:

manage_teacher_duty_roster

Runtime authorization remains capability-based.

Role names are not authorization rules.

Teacher-on-Duty assignment itself does not grant manage_teacher_duty_roster or
any other permission.

Weekly duty-report review authority is separate and remains deferred to
6A.9G-C.

## 16. School Operational Boundary

At the eventual HTTP boundary:

- creating a duty period requires school.operational,
- assigning a Teacher requires school.operational,
- reads do not require school.operational,
- ending an assignment does not require school.operational, and
- ending a duty period does not require school.operational.

Terminal lifecycle cleanup must remain possible when creation is unavailable.

The domain service must not invent a second, inconsistent operational-state
policy.

## 17. Commercial Policy

Teacher-on-Duty is commercially intended for Standard and Enterprise and is
excluded from Basic unless the commercial policy is changed later.

6A.9F-B does not implement technical subscription entitlement enforcement.

No Teacher-on-Duty-specific tier middleware, hard-coded plan-name check, or
ad-hoc subscription gate may be introduced.

A future canonical entitlement layer must enforce commercial availability
consistently across tier-gated ShuleOS features.

## 18. Explicit Non-Goals

6A.9F-B does not implement:

- Boarding or Hostel staff responsibility,
- daily occurrences,
- occurrence-category configuration,
- daily duty reports,
- daily reporting obligation/status,
- overdue detection,
- weekly report aggregation,
- weekly report submission/review,
- TeacherWorkflowService generalization,
- reminders,
- escalations,
- a second notification system,
- subscription entitlement enforcement,
- scoped responsibility authorization,
- generic Staff identity,
- room-level responsibility,
- hard-coded leadership role authorization, or
- report-review permission selection.

These remain assigned to their frozen future phases.

## 19. Future Integration

6A.9G-A will add daily occurrences and school-configurable occurrence
categories with a seeded canonical set.

6A.9G-B will add authoritative daily reporting obligations/status and overdue
detection.

6A.9G-C will add weekly aggregation, submission, and school-wide review.

Any TeacherWorkflowService generalization decision is deferred until 6A.9G-C
and must not be performed speculatively during this phase.

Notification reminders and escalation will consume authoritative reporting
state through the existing Notification Engine.

Scoped responsibility authorization remains 6A.9H.

## 20. Frozen Application Invariants

The 6A.9F-B implementation must prove at minimum:

1. Period dates use strict YYYY-MM-DD validation.
2. Period end_date cannot precede start_date.
3. Period dates are authoritative and are not forced to AcademicWeek dates.
4. AcademicWeek is optional.
5. Supplied AcademicWeek must belong to the same school.
6. Multiple different Teachers may share one duty period.
7. Assignment requires a current duty period.
8. Teacher must be active, not deleted, and same-school.
9. Teacher's linked User must be active, not deleted, not suspended, and
   same-school.
10. User role names do not substitute for Teacher specialization.
11. Duplicate current Teacher assignment is rejected.
12. Concurrent duplicate insertion is translated from the database constraint
    into a stable validation error.
13. Actor identity and school_id are server-owned.
14. Cross-tenant actors fail closed.
15. Cross-tenant Teachers fail closed.
16. Cross-tenant Teacher-linked Users fail closed.
17. Cross-tenant AcademicWeeks fail closed.
18. Ending an assignment is terminal.
19. Ending an already-ended assignment fails.
20. Ending a period is terminal.
21. Ending an already-ended period fails.
22. Ending a period atomically ends every current child assignment.
23. Historical child assignments are not rewritten when a period ends.
24. End reason is optional, trimmed, blank-to-null, and limited to 500
    characters.
25. Historical episodes remain readable after Teacher/User eligibility changes.
26. Generic delete is forbidden for duty periods.
27. Generic delete is forbidden for duty assignments.
28. No restore/reactivate lifecycle exists.
29. Assignment does not grant authorization.
30. No Boarding/Hostel responsibility coupling is introduced.
31. No daily occurrence/reporting tables or behavior are introduced.
32. No weekly reporting/workflow behavior is introduced.
33. No notification subsystem is introduced.
34. No technical commercial entitlement gate is introduced.
35. No scoped responsibility authorization is introduced.
