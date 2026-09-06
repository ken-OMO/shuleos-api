# Boarding Staff Responsibility and Duty Operations Contract

## Status

Frozen architecture contract for Phase 6A.9F.

Implementation must not weaken this contract without an explicit
architecture review.

---

# 1. Purpose

Phase 6A.9F establishes the human responsibility foundation required for
digital school operations.

It contains two related but distinct workstreams:

1. Boarding Staff Responsibility
2. Teacher-on-Duty / Duty Roster foundation

They must not be represented by the same assignment table.

---

# 2. Boarding Staff Responsibility

A Boarding responsibility associates a tenant-owned User with a Boarding
facility for an effective period and responsibility type.

Conceptually:

User
→ Hostel
→ Responsibility
→ Effective Lifecycle
→ Preserved History

Examples include:

- Matron
- Warden
- Boarding Master
- Deputy Boarding responsibility
- other approved school Boarding responsibilities

Responsibility labels are operational concepts and do not automatically
become authorization roles.

---

# 3. Boarding Responsibility Rules

The implementation must provide:

- server-owned school_id
- tenant-safe User relationship
- tenant-safe Hostel relationship
- responsibility role/type
- effective start
- effective end
- current/active state
- assigning actor
- ending actor
- preserved historical responsibility

A non-teaching Boarding worker does not require a Teacher record.

Eligible assignees are Users belonging to the same school and satisfying
the domain's active-account requirements.

Assignment alone must not grant manage_boarding or any other permission.

Management of Boarding responsibility remains protected by approved
Boarding authorization.

---

# 4. Boarding Operational Direction

Boarding responsibility is not merely staff metadata.

ShuleOS must progressively digitize the actual daily work performed by
responsible Boarding personnel.

The Boarding operational workflow is expected to support:

- daily Boarding attendance/status
- daily occurrences
- learner welfare observations
- health occurrences
- discipline and behaviour incidents
- meals and feeding observations
- facility conditions
- water/electricity/maintenance issues
- security and safety matters
- authorized learner movements where applicable
- actions taken
- follow-up requirements
- general observations
- daily Boarding reports
- leadership review or acknowledgement
- overdue-report tracking
- reminders and escalation

Detailed operational implementation belongs to the appropriate Boarding
operations phase rather than being forced into the staff-assignment table.

---

# 5. Boarding Daily Reports

Responsible Boarding facility managers must provide daily operational
reports for the facilities for which they are responsible.

Individual occurrences should be capable of being recorded as they happen.

The daily report may consolidate those records but must not reduce the
entire operational model to a single unstructured text field.

The system must preserve:

- facility
- reporting date
- responsible assignment/context
- submitting User
- submission timestamp
- due timestamp where configured
- late status
- review/acknowledgement state
- relevant operational evidence

Multiple responsible personnel may exist for one facility.

The reporting obligation belongs to the required facility/reporting
period according to school policy and must not automatically require
duplicate identical reports from every assigned manager.

---

# 6. Boarding Report Reminders

ShuleOS must support reporting accountability.

The reporting workflow must be capable of representing:

DRAFT
DUE
SUBMITTED
REVIEWED
OVERDUE

Schools must eventually be able to configure appropriate reporting
deadlines, reminders and escalation policies.

Reminder delivery must use the approved ShuleOS notification architecture.

Notification state is not the source of truth for report completion.

Late submissions must remain identifiable as late rather than being
silently treated as on-time submissions.

---

# 7. Teacher-on-Duty Engine

Teacher on Duty is a school-wide operational responsibility.

It is not a Boarding-only responsibility.

A duty period is normally a school week.

One or more Teachers may be assigned to the same duty period according to
school policy.

Conceptually:

School
→ Duty Period
→ One or More Teachers
→ Daily Operational Records
→ Weekly Report

The engine must support schools that allocate:

- one Teacher on Duty
- two Teachers on Duty
- three or more Teachers on Duty

The architecture must not impose a one-teacher-per-week assumption.

---

# 8. Teacher-on-Duty Eligibility

Teacher-on-Duty assignment requires the teaching-domain Teacher
specialization.

An assigned Teacher must belong to the same school and satisfy the
applicable active Teacher/User eligibility rules.

Teacher-on-Duty responsibility does not itself grant additional platform
permissions.

---

# 9. Duty Period

The canonical Teacher-on-Duty period is date-based.

AcademicWeek must not be a mandatory dependency.

Existing academic weeks are term-bound and therefore cannot safely
represent every possible operational duty period.

A future optional academic-week association may be introduced for
reporting where appropriate without replacing the authoritative duty
dates.

---

# 10. Teacher-on-Duty Daily Operations

Teachers on Duty are responsible for recording daily school occurrences
during their assigned duty period.

The operational model should support structured occurrences such as:

- assembly
- learner welfare
- discipline
- health
- safety
- teaching and learning observations
- meals
- visitors
- facilities
- sports
- transport observations
- security
- general school occurrences
- actions taken
- follow-up requirements

Categories must be designed for controlled extensibility and must not be
prematurely hard-coded as an inflexible permanent taxonomy.

---

# 11. Teacher-on-Duty Reporting

ShuleOS must track daily operational completion during the duty period.

The system should be capable of showing whether each required duty day is:

- not started
- draft/in progress
- submitted/completed
- overdue where a deadline applies

At the end of the duty period, the assigned Teachers must be able to
produce a preserved weekly Teacher-on-Duty report.

Authorized school leadership must be able to review or acknowledge the
report according to the eventual authorization contract.

Historical submitted reports must not be silently overwritten.

---

# 12. Teacher-on-Duty Reminders

ShuleOS must be capable of reminding assigned Teachers when required daily
records or reports remain incomplete.

It must also support overdue identification and configurable escalation.

The notification engine consumes reporting-obligation state.

The notification engine does not determine the authoritative reporting
state.

---

# 13. Commercial Entitlement

Teacher-on-Duty / Duty Roster is included in:

- Standard
- Enterprise

It is not part of the Basic package unless a later explicit commercial
decision changes the entitlement.

Boarding is a paid add-on capability.

Boarding is not automatically included merely because a school purchases
Basic, Standard or Enterprise.

The Platform Owner controls the configured/suggested Boarding add-on
pricing through the appropriate commercial/subscription architecture.

Prices must not be hard-coded into Boarding domain logic.

Historical agreed subscription/invoice prices must not be rewritten by a
later price change.

---

# 14. Boarding Capability

A school uses Boarding operational functionality only when it has the
required Boarding commercial entitlement and applicable school
configuration.

A school without Boarding facilities must not be exposed to irrelevant
Boarding operations merely because of its base subscription package.

Commercial entitlement, school capability/configuration, user
authorization and operational responsibility are separate concerns.

Conceptually:

Subscription Entitlement
→ School Capability
→ User Permission
→ Operational Responsibility
→ Domain Operation

Passing one layer must not bypass another.

---

# 15. Security

All implementations must follow the Engineering Constitution and
multi-tenant architecture.

At minimum:

- school_id is server-owned
- cross-tenant relationships fail closed
- tenant-aware foreign keys are used where relationally appropriate
- authorization is enforced on the backend
- responsibility does not automatically grant permission
- historical accountability is preserved
- actor identity is authoritative
- cross-tenant adversarial tests are mandatory
- concurrency-sensitive assignment rules require database protection
- client-provided ownership fields are untrusted

---

# 16. Implementation Sequence

Phase 6A.9F-A:
Boarding Staff Responsibility.

Phase 6A.9F-B:
Teacher-on-Duty / Duty Roster foundation.

Phase 6A.9G:
Boarding Daily Operations, including attendance, occurrences, incidents
and daily manager reporting.

Reporting obligations, reminders and escalation must integrate with the
approved notification architecture rather than introducing an isolated
notification system.

---

# 17. Non-Goals of the First Assignment Slice

The first Boarding responsibility slice must not accidentally implement:

- a new global authorization engine
- automatic permission grants from assignments
- subscription billing inside Boarding services
- a generic Staff table
- a generic cross-domain occurrence table
- Teacher-on-Duty records inside Boarding assignment tables
- notification scheduling before authoritative reporting obligations exist

Those concerns must remain in their correct architectural boundaries.

---

# 18. Frozen Principle

ShuleOS does not merely record who holds a school responsibility.

ShuleOS progressively digitizes the operational work, accountability,
reporting and follow-up created by that responsibility.
---

# 19. Frozen 6A.9F-A Database Contract

## 19.1 Table

The permanent Boarding staff responsibility record is stored in:

`hostel_staff_assignments`

One row represents one responsibility episode for one school User,
one Hostel and one responsibility type.

The row is historical operational evidence.

Ending an assignment closes the episode.

A later return to the same responsibility creates a new assignment row.

An ended assignment must never be reactivated.

---

## 19.2 Columns

The frozen table contract is:

| Column | Type | Null | Meaning |
| --- | --- | --- | --- |
| id | UUID | NO | Assignment identifier |
| school_id | UUID | NO | Authoritative tenant |
| hostel_id | UUID | NO | Responsible Boarding facility |
| user_id | UUID | NO | School User receiving responsibility |
| responsibility_role | VARCHAR(100) | NO | Operational responsibility label |
| effective_from | DATE | NO | First effective responsibility date |
| effective_to | DATE | YES | Final effective responsibility date |
| active | BOOLEAN | NO | Whether the responsibility episode is currently open |
| assigned_by | UUID | NO | User who created the assignment |
| ended_by | UUID | YES | User who ended the assignment |
| ended_at | TIMESTAMPTZ | YES | Server timestamp at which the assignment was ended |
| end_reason | VARCHAR(500) | YES | Optional administrative reason for ending |
| created_at | TIMESTAMPTZ | NO | Creation timestamp |
| updated_at | TIMESTAMPTZ | NO | Controlled lifecycle update timestamp |

The table must not contain `is_deleted`, `deleted_at` or `deleted_by`.

Responsibility history is preserved by closing an assignment rather than
deleting or archiving the assignment row.

---

## 19.3 Tenant Ownership

`school_id` is server-owned.

The client must not establish or override assignment ownership.

Every assignment belongs to exactly one school.

Platform-level Users without a school tenant cannot receive a Boarding
responsibility assignment.

---

## 19.4 Tenant-Safe Foreign Keys

The database must enforce tenant-safe relationships using composite
foreign keys.

Required relationships:

`(school_id, hostel_id)`
references
`hostels (school_id, id)`

`(school_id, user_id)`
references
`users (school_id, id)`

`(school_id, assigned_by)`
references
`users (school_id, id)`

`(school_id, ended_by)`
references
`users (school_id, id)`

The assignment table must also reference:

`school_id`
references
`schools (id)`

with restrictive deletion behaviour consistent with the hardened Boarding
domain.

Cross-school assignment relationships must therefore fail at the
database layer as well as the application layer.

---

## 19.5 User Eligibility

A new Boarding responsibility may only be assigned to a User who:

- belongs to the authoritative school
- is active
- is not deleted
- is not suspended

A Teacher specialization is not required.

Authorization role and responsibility assignment remain separate.

A responsibility assignment does not automatically grant
`manage_boarding` or any other permission.

---

## 19.6 Hostel Eligibility

A new Boarding responsibility may only target a Hostel that:

- belongs to the authoritative school
- is active
- is not deleted

The service must lock the relevant Hostel when assignment concurrency
requires authoritative current-state validation.

Historical assignments remain preserved if the Hostel later becomes
inactive or retired.

---

## 19.7 Responsibility Role

`responsibility_role` is stored as a bounded string with maximum length
100 characters.

The database must not permanently hard-code responsibility terminology
such as only:

- Matron
- Warden
- Boarding Master

Schools may use other approved operational terminology.

The application layer must normalize and validate responsibility labels
according to the approved Boarding contract.

A responsibility label is not itself an authorization role.

---

## 19.8 Effective Lifecycle

6A.9F-A does not support future-dated responsibility activation.

For a newly created assignment:

`effective_from` must not be later than the authoritative school-local
current date.

While current:

- `active = true`
- `effective_to IS NULL`
- `ended_by IS NULL`
- `ended_at IS NULL`
- `end_reason IS NULL`

When ended:

- `active = false`
- `effective_to IS NOT NULL`
- `ended_by IS NOT NULL`
- `ended_at IS NOT NULL`

`end_reason` remains optional.

The database must enforce lifecycle consistency with CHECK constraints.

`effective_to` must not precede `effective_from`.

An ended assignment must never transition back to active.

---

## 19.9 Ending an Assignment

Ending responsibility is a dedicated lifecycle operation.

Generic deletion is not permitted.

The lifecycle operation must:

1. resolve the authoritative school tenant
2. lock the current assignment
3. confirm that the assignment is currently active
4. confirm tenant ownership
5. derive the effective end date from authoritative school-local time
6. record the authenticated ending actor
7. record the server ending timestamp
8. optionally record the end reason
9. set `active = false`
10. preserve the row permanently as historical evidence

The operation must execute transactionally.

---

## 19.10 Reassignment

Reassignment does not modify an old historical episode into a new one.

Example:

Assignment A:

Mary
→ Girls Hostel
→ Matron
→ January through April
→ ENDED

If Mary returns later:

Assignment B:

Mary
→ Girls Hostel
→ Matron
→ September onward
→ ACTIVE

Both rows remain independently queryable.

---

## 19.11 Multiple Responsible Personnel

A Hostel may have multiple simultaneous responsible Users.

Examples include:

- multiple Matrons
- multiple Wardens
- Boarding Master plus Matron
- other school-defined combinations

6A.9F-A does not impose a universal one-person-per-role rule.

A User may also hold responsibility for more than one Hostel where school
policy permits.

Role-specific exclusivity may only be introduced later through an
explicit policy/configuration contract.

---

## 19.12 Duplicate Current Assignment Protection

The same User must not receive the same current responsibility role for
the same Hostel more than once.

The database concurrency backstop is a PostgreSQL partial unique index
equivalent to:

`UNIQUE (school_id, hostel_id, user_id, responsibility_role)
 WHERE active = true`

The service must also detect duplicate current assignments and return an
appropriate domain validation error.

Database unique violations caused by concurrent requests must be
translated into the same safe domain error.

---

## 19.13 Date-Range Overlap

6A.9F-A does not introduce a PostgreSQL range or exclusion-constraint
engine.

Future-dated assignment scheduling is outside the first slice.

Because a current assignment has no future activation state, the partial
current-assignment uniqueness rule is sufficient for the frozen first
slice.

Any later scheduling/overlap feature requires a separate architecture and
migration decision.

---

## 19.14 Concurrency

Assignment creation and ending are concurrency-sensitive operations.

Implementation must use database transactions and locking where required.

Application-only pre-checks are insufficient.

Database constraints and partial unique indexes provide the final
concurrency backstop.

Concurrent requests must not create duplicate current responsibility
episodes.

Concurrent attempts to end the same assignment must not produce
contradictory lifecycle states.

---

## 19.15 Indexes

At minimum the migration must provide indexes appropriate for:

- tenant + Hostel current responsibility lookup
- tenant + User responsibility lookup
- tenant + responsibility role lookup
- current assignment lookup
- historical effective-date lookup

The duplicate-current partial unique index is mandatory.

Exact index names must be deterministic and migration-controlled.

---

## 19.16 Actor Integrity

`assigned_by` is mandatory.

`ended_by` is required only for an ended assignment.

Both actor relationships must use tenant-safe composite foreign keys.

Historical actor identity must not be rewritten merely because the actor
later changes role, becomes inactive, is suspended or leaves the school.

---

## 19.17 Immutability

Historical responsibility episodes are operational evidence.

After an assignment is ended, application code must treat the record as
immutable.

6A.9F-A must not provide a generic update endpoint capable of rewriting
historical responsibility.

Any future database-level immutability trigger must be introduced
deliberately and tested against migration rollback and lifecycle
operations.

---

## 19.18 API Lifecycle Direction

The eventual API should expose responsibility lifecycle operations rather
than generic CRUD semantics.

Conceptually:

- create assignment
- list assignments
- view assignment
- end assignment

There is no ordinary delete operation.

There is no reactivate operation.

Changing a responsibility in a way that represents a new operational
episode must end the old episode and create a new row.

---

## 19.19 Authorization Boundary

Assignment-management endpoints remain protected by the approved
Boarding authorization boundary.

6A.9F-A does not introduce hostel-scoped authorization.

6A.9F-A does not make assignment membership equivalent to permission.

Future authorization may deliberately consider:

- permission
- responsibility scope
- responsibility role

but that belongs to the explicit scoped-authorization phase.

---

## 19.20 First-Slice Non-Goals

6A.9F-A does not implement:

- Teacher-on-Duty roster records
- Teacher-on-Duty daily occurrences
- Boarding daily reports
- Boarding attendance
- Boarding incidents
- reporting reminders
- escalation scheduling
- Boarding subscription billing
- automatic permission grants
- generic Staff records
- generic cross-domain occurrence records
- room-level Boarding staff responsibility
- future-dated responsibility scheduling

These remain in their approved later phases.

---

## 19.21 Migration Acceptance Requirements

The first migration must prove:

- table creation succeeds on current PostgreSQL
- fresh migration succeeds
- rollback succeeds
- re-migration succeeds
- required composite foreign keys exist
- cross-tenant User assignment is rejected by PostgreSQL
- cross-tenant Hostel assignment is rejected by PostgreSQL
- cross-tenant actor assignment is rejected by PostgreSQL
- lifecycle CHECK constraints reject contradictory states
- effective end date cannot precede effective start date
- duplicate active assignment is rejected
- multiple different managers for one Hostel are allowed
- one User may manage multiple Hostels
- historical ended assignments remain preserved
- a later new assignment may coexist with an ended historical assignment

The migration is not accepted merely because `php artisan migrate`
returns successfully.

---

## 19.22 Frozen Database Principle

One `hostel_staff_assignments` row represents one preserved Boarding
responsibility episode.

Identity, authority, responsibility and operational history remain
separate.

The database must make cross-tenant or contradictory responsibility state
difficult or impossible to persist.

## 6A.9F-A Application Contract

### Purpose

`HostelStaffAssignment` represents one preserved episode of responsibility between a tenant-owned school User and a Hostel.

Responsibility is not identity and is not authorization.

Creating a Boarding responsibility assignment MUST NOT create a Teacher record, change a User role, or grant `manage_boarding`.

### Identity and eligibility

The responsible person MUST be represented by a same-tenant `users` row.

A User is eligible for a new assignment only when:

- `school_id` matches the authoritative tenant;
- `active = true`;
- `is_deleted = false`;
- `suspended_at IS NULL`.

A Teacher record is not required.

The target Hostel MUST:

- belong to the authoritative tenant;
- be active;
- not be deleted.

Historical assignments remain valid historical records if a User or Hostel is later suspended, deactivated, archived, or retired.

### Model

The application model is:

`App\Models\HostelStaffAssignment`

It is tenant-owned and MUST use the established ShuleOS tenant model infrastructure.

Required relationships:

- `school`
- `hostel`
- `user`
- `assignedBy`
- `endedBy`

The model may provide a `current` scope corresponding to `active = true`.

No application-level delete, restore, or reactivation capability is allowed.

### Service boundary

The domain service is:

`App\Services\Boarding\BoardingStaffResponsibilityService`

Supported operations:

- assign responsibility;
- end responsibility;
- resolve one tenant-owned assignment;
- list current assignments for one Hostel;
- list preserved assignment history for one Hostel.

### Assignment creation

Creation MUST execute inside a database transaction.

The service MUST resolve and validate the authoritative School, Hostel, responsible User, and acting User using the supplied tenant id.

Queries that bypass global scopes MUST immediately reapply `school_id`.

`responsibility_role` is required, trimmed, non-empty, extensible, and limited to 100 characters.

`effective_from` is optional. When omitted, the server uses authoritative school-local today.

A supplied `effective_from` may be today or historical, but MUST NOT be later than authoritative school-local today.

School-local today MUST use the School timezone, falling back to the application timezone only when the School timezone is unavailable.

The server sets:

- `school_id`;
- `assigned_by`;
- `active = true`;
- ending lifecycle fields to null;
- timestamps.

The client MUST NOT control those fields.

A duplicate current `(school, hostel, user, responsibility_role)` assignment is a domain conflict and MUST NOT produce an unhandled PostgreSQL exception.

### Ending responsibility

Ending is a dedicated terminal lifecycle operation.

It MUST execute inside a database transaction.

The service MUST tenant-scope and lock the current assignment before changing it.

Only a current assignment may be ended.

Ending sets:

- `active = false`;
- `effective_to = authoritative school-local today`;
- `ended_by = authenticated acting User`;
- `ended_at = server timestamp`;
- `end_reason = validated optional reason`.

`end_reason` may contain at most 500 characters.

An ended assignment MUST never be reactivated or mutated back into a current assignment.

If the same User later resumes the same Hostel responsibility, the system creates a new assignment episode.

### Read contract

Current responsibility reads include only current assignments for the requested same-tenant Hostel.

History reads include both current and ended episodes for that same-tenant Hostel.

Historical records MUST NOT be filtered away merely because their associated User or Hostel later became inactive or retired.

Cross-tenant identifiers MUST fail closed.

### HTTP routes

The 6A.9F-A HTTP surface is:

- `GET /api/boarding/hostels/{hostel}/staff-assignments`
- `POST /api/boarding/hostels/{hostel}/staff-assignments`
- `GET /api/boarding/hostels/{hostel}/staff-assignments/history`
- `PATCH /api/boarding/staff-assignments/{assignment}/end`

There is deliberately no generic update, delete, restore, or reactivate route.

### Authorization

All assignment-management routes require the existing canonical Boarding permission:

`manage_boarding`

Responsibility assignment does not grant that permission.

Creating a new responsibility assignment is an operational Boarding write and requires the existing `school.operational` protection.

Ending an existing responsibility episode is a lifecycle closure operation and does not depend on assignment creation being available.

No role name or `responsibility_role` value implicitly grants protected Boarding authority in 6A.9F-A.

Scoped responsibility authorization remains future 6A.9H work.

### Request ownership protection

HTTP requests MUST prohibit client control of server-owned fields including:

- `school_id`
- `assigned_by`
- `ended_by`
- `ended_at`
- `effective_to`
- `active`
- `created_at`
- `updated_at`

Assignment creation accepts only:

- `user_id`
- `responsibility_role`
- optional `effective_from`

Ending accepts only:

- optional `reason`

### API representation

API responses MUST NOT expose `school_id` as client-authoritative state.

Internal actor ownership identifiers SHOULD remain hidden from ordinary resource responses unless a later audited administrative use case explicitly requires them.

The safe representation may include:

- assignment id;
- Hostel summary;
- responsible User safe display information;
- responsibility role;
- effective dates;
- active state;
- ending reason where appropriate;
- lifecycle timestamps.

Sensitive authentication, tenant, password, token, or internal security fields from the User model MUST never be serialized through this resource.

### Concurrency and database enforcement

Application checks do not replace database constraints.

Transactions and row locking MUST be used for lifecycle mutations.

The PostgreSQL partial unique index remains the authoritative final guard against duplicate current assignments.

Known PostgreSQL constraint violations MUST be translated to domain-safe validation or conflict responses where applicable.

Raw SQL constraint details MUST NOT be exposed to API clients.

### Required security tests

6A.9F-A is not complete until tests prove at minimum:

1. same-tenant eligible User can be assigned;
2. no Teacher record is required;
3. inactive User is rejected;
4. deleted User is rejected;
5. suspended User is rejected;
6. cross-tenant User is rejected;
7. cross-tenant Hostel is rejected;
8. cross-tenant actor cannot establish ownership;
9. inactive or deleted Hostel is rejected;
10. future `effective_from` is rejected using School-local date;
11. duplicate current assignment is rejected;
12. multiple managers for one Hostel are permitted;
13. one User may manage multiple Hostels;
14. same User and Hostel may hold different responsibility roles;
15. ending preserves the historical row;
16. ended assignments cannot be ended again;
17. ended assignments cannot be reactivated;
18. a later assignment creates a new episode;
19. current listing excludes ended episodes;
20. history includes both current and ended episodes;
21. cross-tenant reads fail closed;
22. client ownership and lifecycle fields are prohibited;
23. `manage_boarding` is required;
24. responsibility assignment alone grants no permission;
25. create respects `school.operational`;
26. terminal ending remains a dedicated lifecycle operation;
27. database duplicate races are translated safely;
28. API resources hide tenant and security-sensitive fields.

### Non-goals

This application slice does not implement:

- Teacher-on-Duty scheduling;
- Boarding daily reports;
- Boarding attendance;
- Boarding incidents;
- reminders or escalation;
- permission grants from responsibility;
- room-level responsibility;
- future-dated responsibility activation;
- generic Staff identity;
- subscription billing;
- role-specific exclusivity policy.
