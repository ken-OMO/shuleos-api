# ShuleOS Multi-Tenant Architecture

> School in Clouds

## Document Information

| Field                | Value                                  |
| -------------------- | -------------------------------------- |
| Document             | Multi-Tenant Architecture              |
| Document ID          | ARCH-STD-0002                          |
| Version              | 1.0                                    |
| Status               | Approved                               |
| Owner                | Platform Engineering                   |
| Repository           | `shuleos-api` & `shuleos-web`          |
| Effective Date       | 03 August 2026                         |
| Related Constitution | Engineering Constitution v1.1          |
| Related Standards    | Security, Testing, System Architecture |

---

# Purpose

This document defines the architecture that enables multiple independent schools to operate securely on a single ShuleOS platform while maintaining complete logical isolation of their data.

---

# Vision

ShuleOS is built as a **shared application with isolated tenant data**.

Every school behaves as if it owns a dedicated system while sharing common infrastructure.

---

# Architectural Principles

The multi-tenant architecture is built upon:

- Tenant isolation
- Security
- Scalability
- Simplicity
- Performance
- Reliability
- Maintainability

---

# Tenant Definition

A tenant represents one registered school.

Every business record belongs to exactly one tenant unless explicitly defined as a platform-level resource.

Examples:

- School
- Learner
- Teacher
- Guardian
- Assessment
- Finance
- Attendance
- Timetable

---

# Tenant Identification

Each request must resolve its tenant before business logic executes.

Tenant resolution may use:

- School identifier
- JWT claims
- Platform configuration

Requests without a valid tenant must be rejected.

---

# Request Lifecycle

Every request follows this sequence:

```text
Client
    │
    ▼
Authentication
    │
    ▼
Tenant Resolution
    │
    ▼
Authorization
    │
    ▼
Business Logic
    │
    ▼
Database
    │
    ▼
Response
```

Tenant context is established before any protected resource is accessed.

---

# Shared Infrastructure

All tenants share:

- Application servers
- API
- Queue workers
- Scheduler
- Cache infrastructure
- Monitoring
- Logging

Sharing infrastructure reduces operational complexity.

---

# Data Isolation

Each tenant owns only its own data.

Queries must always execute within the active tenant context.

Cross-tenant data access is prohibited unless explicitly authorized for platform administration.

---

# Database Strategy

ShuleOS uses a shared PostgreSQL database with tenant-aware data isolation.

Every tenant-owned table includes a tenant identifier.

Examples include:

- learners
- teachers
- guardians
- assessments
- attendance
- fee_payments

---

# Query Isolation

All repository and service queries must scope results to the active tenant.

Developers should never rely on frontend filtering for isolation.

---

# Authentication

Authentication verifies user identity.

Tenant resolution determines which school's data the authenticated user may access.

Authentication alone does not grant resource access.

---

# Authorization

Authorization evaluates:

- User role
- Permissions
- Tenant ownership
- Resource ownership

Backend authorization is mandatory.

---

# File Storage

Tenant-owned files include:

- Learner photos
- Staff photos
- Documents
- Reports
- Imports
- Exports

Storage paths should remain tenant-aware.

---

# Cache Isolation

Cache keys should include tenant context.

Cached values must never leak across schools.

---

# Queue Isolation

Background jobs should execute within the originating tenant context.

Examples:

- Email
- SMS
- Report generation
- Imports
- Exports

---

# Notifications

Notifications should:

- Target only tenant users
- Include only tenant data
- Respect authorization rules

---

# Reporting

Reports must contain only records belonging to the active tenant.

Examples:

- Report cards
- Attendance summaries
- Fee statements
- Merit lists

---

# Search

Search functionality must remain tenant-aware.

Search indexes should never expose another school's information.

---

# API Design

Every protected API endpoint should verify:

- Authentication
- Tenant context
- Authorization
- Resource ownership

No endpoint should bypass tenant validation.

---

# Background Processing

Scheduled jobs should execute independently for each tenant.

One tenant's workload should not affect another tenant's data integrity.

---

# Logging

Audit logs should record:

- Tenant identifier
- User
- Action
- Timestamp
- Resource

Logs improve traceability and incident response.

---

# Monitoring

Operational monitoring should track:

- API performance
- Queue health
- Database performance
- Tenant activity
- Error rates

---

# Security

Tenant isolation supports:

- Confidentiality
- Integrity
- Least privilege
- Privacy by Design

Any cross-tenant data exposure is considered a critical security incident.

---

# Performance

The architecture should scale efficiently as:

- Schools increase
- Users increase
- Learners increase
- Assessments increase
- Reports increase

---

# Scalability

The platform should support horizontal scaling without changing tenant behaviour.

Infrastructure should grow independently of business logic.

---

# Disaster Recovery

Backup and recovery processes must preserve tenant ownership and data integrity.

Recovery procedures should ensure tenant data remains isolated after restoration.

---

# Engineering Rules

Engineers must:

- Scope every query to the tenant
- Verify authorization
- Keep business logic tenant-aware
- Protect tenant data in logs
- Test tenant isolation
- Review changes for cross-tenant risks

---

# Architecture Evolution

Changes affecting tenant behaviour require:

- Architecture review
- Security review
- Testing updates
- Documentation updates

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

- System-Architecture.md
- Authentication-Architecture.md
- Authorization-Architecture.md
- Data-Flow.md
- Security Standards
- Multi-Tenant Testing Standards

---

# Final Standard

The ShuleOS multi-tenant architecture ensures that every school operates securely within its own isolated environment while benefiting from a shared, scalable cloud platform.

Tenant isolation is a foundational architectural requirement that must be preserved across every layer of the system.
