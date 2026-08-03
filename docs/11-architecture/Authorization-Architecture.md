# ShuleOS Authorization Architecture

> School in Clouds

## Document Information

| Field                | Value                                                                      |
| -------------------- | -------------------------------------------------------------------------- |
| Document             | Authorization Architecture                                                 |
| Document ID          | ARCH-STD-0007                                                              |
| Version              | 1.0                                                                        |
| Status               | Approved                                                                   |
| Owner                | Platform Engineering                                                       |
| Repository           | `shuleos-api` & `shuleos-web`                                              |
| Effective Date       | 03 August 2026                                                             |
| Related Constitution | Engineering Constitution v1.1                                              |
| Related Standards    | Authentication Architecture, Multi-Tenant Architecture, Security Standards |

---

# Purpose

This document defines the authorization architecture of the ShuleOS platform.

Authorization determines what an authenticated user is permitted to do within the system.

---

# Philosophy

Authorization answers one question:

> **Is this authenticated user allowed to perform this action on this resource?**

Authorization always follows successful authentication.

---

# Architectural Principles

Authorization should be:

- Secure
- Explicit
- Least-privilege
- Tenant-aware
- Consistent
- Auditable

---

# Authorization Flow

Every protected request follows this sequence.

```text
Authenticated User
        │
        ▼
Tenant Resolution
        │
        ▼
Role Verification
        │
        ▼
Permission Verification
        │
        ▼
Resource Ownership Check
        │
        ▼
Policy Evaluation
        │
        ▼
Business Logic
```

---

# Role-Based Access Control

ShuleOS uses Role-Based Access Control (RBAC).

Users receive one or more roles.

Roles determine available permissions.

---

# Standard Roles

Examples include:

- Platform Owner
- School Owner
- Principal
- Deputy Principal
- Head of Department
- Teacher
- Finance Officer
- Librarian
- Boarding Master
- Transport Officer
- Parent
- Learner

Additional roles may be introduced without changing the architecture.

---

# Permissions

Permissions represent individual capabilities.

Examples:

- manage_users
- manage_teachers
- manage_learners
- manage_finance
- manage_assessments
- manage_timetable
- view_reports
- manage_transport
- manage_boarding

Permissions should remain granular.

---

# Policies

Policies evaluate access to specific resources.

Examples:

- Can edit learner?
- Can publish assessment?
- Can approve report?
- Can delete timetable?

Policies should contain resource-specific authorization logic.

---

# Resource Ownership

Authorization should verify ownership before allowing access.

Examples:

- Teacher accesses assigned classes only.
- Parent accesses linked learners only.
- School staff access only their school's records.

Ownership checks are mandatory.

---

# Tenant-Aware Authorization

Authorization always executes within the active tenant.

Users must never gain access to another school's resources.

Cross-tenant access is prohibited except for authorized platform administration.

---

# Platform Owner Authorization

Platform Owners may access platform-wide functionality.

Platform-level privileges should never be granted through ordinary school role assignment.

---

# Backend Enforcement

Authorization is enforced on the backend.

Frontend visibility improves user experience but never replaces backend authorization.

---

# Frontend Responsibilities

The frontend should:

- Hide unauthorized actions
- Display only permitted navigation
- Handle authorization failures gracefully

Frontend checks are informational only.

---

# API Authorization

Every protected API endpoint should verify:

- Authentication
- Tenant context
- Role
- Permission
- Resource ownership

Requests failing authorization should return appropriate HTTP status codes.

---

# Permission Inheritance

Higher-level administrative roles may inherit lower-level permissions where appropriate.

Inheritance rules should remain explicit and documented.

---

# Least Privilege

Users should receive only the permissions required to perform their responsibilities.

Excessive permissions increase security risk.

---

# Audit Logging

Authorization-related events should be logged.

Examples:

- Permission changes
- Role assignments
- Access denials
- Administrative actions

Audit records support security investigations.

---

# Error Handling

Authorization failures should return safe and consistent responses.

The system should never reveal sensitive implementation details.

---

# Security Considerations

Authorization architecture should prevent:

- Privilege escalation
- Unauthorized resource access
- Cross-tenant access
- Permission bypass
- Insecure direct object references

---

# Testing

Authorization requires automated verification through:

- Unit tests
- Feature tests
- Integration tests
- Multi-tenant tests
- Security tests

Critical authorization failures block release.

---

# Engineering Guidelines

Engineers should:

- Authorize every protected action.
- Prefer policies over inline checks.
- Keep permissions granular.
- Protect resource ownership.
- Never trust frontend authorization.
- Review authorization during code reviews.

---

# Architecture Governance

Changes affecting authorization require:

- Security review
- Architecture review
- Documentation update
- Test update

---

# Constitution Compliance

This architecture reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 46 — Backend authorization is mandatory
- Rule 66 — Every feature has tests

---

# Related Documents

- Authentication-Architecture.md
- Multi-Tenant-Architecture.md
- Data-Flow.md
- Security Standards
- Security Testing Standards

---

# Final Standard

Every protected operation within ShuleOS must pass explicit authorization checks before business logic executes.

The authorization architecture ensures that every user can access only the resources and actions permitted by their role, permissions, tenant ownership, and business responsibilities, preserving the security and integrity of the School in the Clouds.
