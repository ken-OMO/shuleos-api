# Teacher-on-Duty / Duty Roster Foundation — 6A.9F-B Database Contract

## Status

FROZEN for Phase 6A.9F-B database implementation.

## 1. Domain boundary

Teacher-on-Duty is a school-wide operational responsibility.

It is not a Boarding/Hostel responsibility and MUST NOT reuse
hostel_staff_assignments or BoardingStaffResponsibilityService as its
domain model.

An assignee is a Teacher specialization, not an arbitrary User.

Assignment does not grant authorization.

## 2. Duty period

A teacher_duty_period represents one preserved school-wide duty-period
episode over an inclusive calendar-date range.

Required fields:

- id
- school_id
- start_date
- end_date
- active
- created_by
- created_at
- updated_at

Optional lifecycle/link fields:

- academic_week_id
- ended_by
- ended_at
- end_reason

The duty period's start_date and end_date are authoritative.

academic_week_id is optional linkage only. Absence of an AcademicWeek
must not prevent creation of a valid date-based duty period.

The server must reject end_date earlier than start_date.

No Monday-Friday assumption is part of this contract.

## 3. Multiple-Teacher assignment

teacher_duty_assignments associates Teachers with a duty period.

Required fields:

- id
- school_id
- duty_period_id
- teacher_id
- active
- assigned_by
- created_at
- updated_at

Optional lifecycle fields:

- ended_by
- ended_at
- end_reason

A duty period may contain one, two, three, or more Teachers.

The data model MUST NOT impose one Teacher per period or one Teacher
per academic week.

The same Teacher cannot hold the same current assignment twice within
the same duty period.

Historical ended episodes remain preserved.

## 4. Teacher eligibility

A duty assignee must resolve to a same-school Teacher whose:

- active = true
- is_deleted = false
- linked User belongs to the same school
- linked User is active

Eligibility is checked by the server under the authoritative tenant
context.

A client cannot substitute a User ID for teacher_id.

## 5. Tenant safety

school_id is server-owned.

Foreign identifiers are resolved under the authenticated school
context and tenant mismatch fails closed.

The existing teachers and academic_weeks tables do not expose verified
composite (school_id, id) candidate keys. Phase 6A.9F-B therefore does
not mutate those established core tables solely to manufacture
composite foreign-key targets.

The new tables use ordinary referential constraints plus strict
same-school transactional validation.

Actor identity is server-owned and authoritative.

## 6. Lifecycle

A current duty period has:

- active = true
- ended_by = null
- ended_at = null
- end_reason = null

An ended duty period has:

- active = false
- ended_by present
- ended_at present

end_reason remains optional.

Duty assignments follow the same terminal lifecycle.

Ended periods and assignments are never reactivated. A later
resumption creates a new episode.

There is no generic delete, restore, or reactivate operation.

## 7. Database protection

Database constraints must enforce:

- duty period end_date >= start_date
- valid current/ended lifecycle shape
- assignment lifecycle shape
- no duplicate current Teacher assignment within the same duty period

Database design MUST permit multiple different Teachers in one period.

Overlapping duty periods are not prohibited by Phase 6A.9F-B.

## 8. Authorization

Teacher-on-Duty roster administration is capability-based.

Role names are not authorization rules.

Phase 6A.9F-B introduces:

    manage_teacher_duty_roster

Teacher-on-Duty assignment itself grants no permission.

Weekly duty-report review authority is NOT defined by this permission
and is deferred to Phase 6A.9G-C.

## 9. Operational middleware

Creation of a duty period and assignment of a Teacher require:

    school.operational

Terminal lifecycle operations such as ending an assignment or ending
a duty period do not require school.operational.

Read/history operations do not require school.operational.

## 10. Commercial policy

Teacher-on-Duty is commercially intended for Standard and Enterprise
and excluded from Basic.

Technical subscription-tier enforcement is not implemented in
6A.9F-B. It is deferred to the canonical entitlement layer.

No ad hoc Teacher-on-Duty-only tier gate may be introduced.

## 11. Explicit non-goals

6A.9F-B does not implement:

- daily duty occurrences
- occurrence categories
- daily reporting obligations
- overdue reporting state
- weekly report aggregation
- weekly report submission/review
- reminders or escalations
- Notification Engine changes
- subscription entitlement enforcement
- scoped responsibility authorization
- Boarding/Hostel staff responsibility
- room-level responsibility
- automatic permission grants from assignment

Those remain assigned to later frozen phases.

## 12. Phase relationship

6A.9F-B
Duty-period and Teacher roster foundation

6A.9G-A
Daily occurrences and configurable occurrence categories

6A.9G-B
Daily reporting obligations and overdue state

6A.9G-C
Weekly aggregation, submission and school-wide review

Future canonical entitlement layer
Commercial feature enforcement

6A.9H
Scoped responsibility authorization
