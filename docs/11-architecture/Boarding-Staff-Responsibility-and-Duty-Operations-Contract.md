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