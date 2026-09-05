# ADR-0012 — School Worker Identity and Domain Specialization

## Status

Accepted.

## Context

ShuleOS digitizes school operations across teaching, administration,
boarding, transport, finance, communication and other school domains.

A school worker may participate in one or more operational domains.

Examples include:

- Teacher
- Matron
- Warden
- Boarding Master
- Nurse
- Cook
- Driver
- Librarian
- Storekeeper
- Finance Officer
- other school-defined responsibilities

The platform must not force every school worker into a teaching-specific
record merely because the person works for a school.

Repository discovery confirmed that the User model already acts as the
general authenticated school identity and that Teacher is a specialized
domain record used by teaching-specific workflows.

The platform also already supports school-owned custom roles and
school-controlled permission assignment subject to authorization and
platform-permission safeguards.

## Decision

Every school worker is represented by a tenant-owned User with an
authorization role.

A specialized domain record is created only when that worker participates
in workflows requiring domain-specific data.

A specialized record must never become the default representation of
generic school staff.

Examples:

User
→ Teacher specialization when participating in teaching workflows.

User
→ Boarding responsibility when managing a boarding facility.

User
→ future Transport responsibility when participating in transport
operations.

## Identity, Authority and Responsibility

ShuleOS treats these as separate concepts.

### Identity

User answers:

Who is this person?

The authenticated User is the authoritative platform identity.

### Authority

Role and Permissions answer:

What is this User allowed to do?

Authorization must remain server-enforced.

### Responsibility

Domain assignments answer:

For what operational resource, period or function is this User responsible?

Examples:

- User → Girls Hostel → Matron
- User → Boys Hostel → Warden
- Teacher → Duty Week → Teacher on Duty
- future User → Route → Driver

Responsibility does not automatically grant authority.

Authority does not automatically prove operational responsibility.

A domain assignment must not silently grant or expand permissions.

## Operational Records

Responsibility assignments may become the authoritative context for
domain operational records.

Examples include:

- Teacher-on-Duty daily occurrences
- Teacher-on-Duty weekly reports
- Boarding daily occurrences
- Boarding attendance
- Boarding incidents
- Boarding daily manager reports
- future Transport operational records

Operational records must preserve tenant ownership, actor identity,
responsibility context and historical accountability.

## Reporting Obligations

Where a responsibility creates a mandatory reporting duty, ShuleOS may
create and track reporting obligations.

A reporting obligation must be capable of identifying:

- the responsible scope
- the responsible personnel
- the reporting period
- the due time
- submission state
- submitting actor
- submission time
- late or overdue state
- review or acknowledgement state where applicable

Notifications and escalations must consume authoritative reporting state.
They must not become the source of truth for whether a report is due or
complete.

## Domain Separation

Shared concepts do not require a single shared domain table.

Teacher-on-Duty occurrences and Boarding occurrences may share consistent
platform concepts while remaining separate domain records where their
permissions, confidentiality, lifecycle or reporting requirements differ.

A generic cross-domain occurrence table must not be introduced merely
because multiple modules use the concept of an occurrence.

## Tenant Safety

All tenant-owned domain assignments and operational records must follow
ShuleOS tenant-isolation standards.

Client-supplied school ownership is never authoritative.

Where relationally appropriate, database constraints must prevent
cross-tenant relationships in addition to application-layer validation.

## Worker Lifecycle

A User who becomes inactive, suspended, archived or otherwise ineligible
must not gain new operational responsibility contrary to the relevant
domain rules.

Historical responsibility and operational records must remain preserved.

Ending a responsibility must not rewrite historical records.

## Custom Roles

Schools may create school-owned roles using the approved role-management
infrastructure.

Job titles or responsibility labels such as Matron, Warden or Boarding
Master do not have to become global system roles.

Schools may define appropriate authorization roles and permission subsets
without changing the underlying responsibility model.

Platform permissions remain protected.

## Consequences

ShuleOS gains a consistent worker model that can expand into Boarding,
Transport and future school-operation domains without creating artificial
Teacher records or a generic Staff table.

Domain specialization remains explicit.

Authorization and operational responsibility remain independently
auditable.

Future scoped authorization may deliberately combine permission,
responsibility scope and responsibility type, but such authorization must
be explicitly designed and tested rather than inferred automatically from
an assignment.

## Related Decisions

- ADR-0001 — Modular Monolith
- ADR-0002 — Multi-Tenant Architecture
- ADR-0003 — JWT Authentication
- ADR-0010 — Role Template System
- ADR-0011 — Multi-Level Tenant Hierarchy
- Engineering Constitution v1.1