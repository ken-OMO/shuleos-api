# ShuleOS Authorization Standard

> School in Clouds

## Document Information

| Field                | Value                                                                                                          |
| -------------------- | -------------------------------------------------------------------------------------------------------------- |
| Document             | Authorization Standard                                                                                         |
| Document ID          | SEC-STD-0003                                                                                                   |
| Version              | 1.0                                                                                                            |
| Status               | Approved                                                                                                       |
| Owner                | Platform Engineering                                                                                           |
| Repository           | `shuleos-api`                                                                                                  |
| Effective Date       | 02 August 2026                                                                                                 |
| Related Constitution | Engineering Constitution v1.1                                                                                  |
| Related ADRs         | ADR-0010 (Role Template System), ADR-0011 (Multi-Level Tenant Hierarchy), ADR-0002 (Multi-Tenant Architecture) |

---

# Purpose

This document defines the mandatory authorization standard for the ShuleOS platform.

It governs:

- Role-Based Access Control (RBAC)
- Permissions
- Role templates
- Custom permissions
- Policy-based authorization
- Resource ownership
- Tenant isolation
- Administrative delegation
- Separation of duties
- Least privilege
- Audit logging
- Authorization testing

Authorization determines what an authenticated identity is permitted to do.

---

# Security Principles

Authorization must be:

- Explicit
- Least privilege
- Tenant-aware
- Deny by default
- Auditable
- Consistent
- Testable

Authentication verifies identity.

Authorization verifies permission.

---

# Authorization Model

ShuleOS uses:

- Role-Based Access Control (RBAC)
- Fine-grained permissions
- Policy-based authorization
- Tenant-scoped permissions

Every protected action requires authorization.

---

# Default Behaviour

The default authorization decision is:

```text
DENY
```

Access is granted only when explicitly authorized.

---

# Authorization Flow

Every protected request follows:

```text
Request
    ↓
Authentication
    ↓
Tenant Resolution
    ↓
Account Status
    ↓
Role Resolution
    ↓
Permission Check
    ↓
Policy Evaluation
    ↓
Business Rules
    ↓
Allow / Deny
```

Skipping any stage is prohibited.

---

# Role Templates

Role templates define common permission sets.

Examples:

- Platform Owner
- Platform Administrator
- School Owner
- Principal
- Deputy Principal
- Head Teacher
- Teacher
- Finance Officer
- Librarian
- Parent
- Learner

Role templates are starting points and may be customized by the tenant where permitted.

---

# Permissions

Permissions represent individual capabilities.

Examples:

```text
manage_users
manage_teachers
manage_learners
manage_finance
manage_reports
manage_exams
manage_attendance
manage_transport
manage_hostels
view_audit_logs
```

Permissions should be granular and reusable.

---

# Custom Permissions

Schools may create custom roles by combining supported permissions.

Custom roles must never exceed the privileges available to the assigning administrator.

---

# Least Privilege

Users receive only the permissions necessary to perform their responsibilities.

Additional permissions require explicit assignment.

---

# Separation of Duties

Critical responsibilities should be separated where practical.

Examples:

- Fee approval ≠ Fee reversal
- User creation ≠ Platform administration
- Examination publication ≠ Result approval

Separation of duties reduces operational risk.

---

# Resource Ownership

Some actions require ownership validation.

Examples:

- A parent views only their learners.
- A teacher updates only assigned records where applicable.
- A learner accesses only their own information.

Ownership checks supplement RBAC.

---

# Tenant Isolation

Authorization is always tenant scoped.

Users must never access resources belonging to another tenant.

Cross-tenant authorization failures are security incidents.

---

# Administrative Delegation

Administrators may delegate only permissions they are authorized to manage.

Privilege escalation through delegation is prohibited.

---

# Policy-Based Authorization

Complex business rules should be implemented using authorization policies.

Examples:

- Publish examination results
- Approve report cards
- Reverse payments
- Promote learners

Policies combine permissions with business rules.

---

# Emergency Access

Emergency administrative access must:

- Be time-limited
- Be audited
- Require explicit approval where applicable

Emergency privileges must not become permanent.

---

# Authorization Failures

Unauthorized requests return:

```http
403 Forbidden
```

Responses must not reveal unnecessary security details.

---

# Audit Logging

Authorization-sensitive actions should generate audit records.

Examples:

- Role assignment
- Permission changes
- Failed authorization attempts
- Administrative overrides
- Privilege escalation attempts

Audit logs must be immutable.

---

# Logging

Authorization logs may include:

- User ID
- Tenant ID
- Permission evaluated
- Resource
- Result
- Timestamp
- Correlation ID

Sensitive information must not be logged.

---

# Monitoring

Security monitoring should detect:

- Repeated authorization failures
- Privilege escalation attempts
- Unusual administrative activity
- Cross-tenant access attempts
- Unexpected permission changes

Critical events require alerts.

---

# Testing

Authorization tests must verify:

- Role permissions
- Policy enforcement
- Ownership checks
- Tenant isolation
- Custom roles
- Administrative delegation
- Least privilege
- Deny-by-default behaviour

Authorization regressions are unacceptable.

---

# Continuous Integration

CI should verify:

- Authorization tests pass
- Policies are covered by tests
- Tenant isolation remains intact
- Documentation updated
- Security review completed

---

# Definition of Done

Authorization is complete only when:

- Roles defined
- Permissions implemented
- Policies enforced
- Tenant isolation verified
- Audit logging enabled
- Tests pass
- Documentation complete

---

# Constitution Compliance

This standard reinforces:

- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 13 — Protect against IDOR
- Rule 17 — Audit important actions
- Rule 19 — Every security feature is tested
- Rule 30 — Every query is tenant scoped
- Rule 46 — Frontend authorization never replaces backend authorization
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 89 — Authorization fails closed
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- Security-Standards.md
- Authentication-Security.md
- Secrets-Management.md
- API Authentication Standard
- API Rate Limiting Standard
- ADR-0010 — Role Template System
- ADR-0011 — Multi-Level Tenant Hierarchy

---

# Final Standard

Authorization protects every operation performed within ShuleOS.

Every request must be evaluated against the authenticated identity, tenant context, assigned permissions, ownership rules, and business policies before access is granted.

Authorization is never assumed, inherited implicitly, or enforced solely by the client. It is always verified by the backend and defaults to denial unless explicitly permitted.
