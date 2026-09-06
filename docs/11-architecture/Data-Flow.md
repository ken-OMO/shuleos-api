# ShuleOS Data Flow Architecture

> School in Clouds

## Document Information

| Field                | Value                                                                                     |
| -------------------- | ----------------------------------------------------------------------------------------- |
| Document             | Data Flow Architecture                                                                    |
| Document ID          | ARCH-STD-0005                                                                             |
| Version              | 1.0                                                                                       |
| Status               | Approved                                                                                  |
| Owner                | Platform Engineering                                                                      |
| Repository           | `shuleos-api` & `shuleos-web`                                                             |
| Effective Date       | 03 August 2026                                                                            |
| Related Constitution | Engineering Constitution v1.1                                                             |
| Related Standards    | System Architecture, Module Architecture, Multi-Tenant Architecture, Domain-Driven Design |

---

# Purpose

This document defines how data flows throughout the ShuleOS platform.

It explains how requests are processed from the frontend through the backend and back to the client while preserving security, tenant isolation, data integrity, and performance.

---

# Philosophy

Every request should follow a predictable, secure, and consistent execution path.

Business logic should never bypass architectural boundaries.

---

# High-Level Flow

```text
User
   │
   ▼
Next.js Frontend
   │
   ▼
REST API
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
Validation
   │
   ▼
Controller
   │
   ▼
Application Service
   │
   ▼
Domain Service
   │
   ▼
Repository
   │
   ▼
PostgreSQL
   │
   ▼
Domain Events
   │
   ▼
Queue / Notifications / Audit Logs
   │
   ▼
API Resource
   │
   ▼
JSON Response
   │
   ▼
Frontend UI
```

---

# Request Lifecycle

Every request should pass through the same architectural pipeline.

No component should bypass mandatory security or validation stages.

---

# Frontend

Responsibilities:

- Collect user input
- Validate basic input
- Send HTTP requests
- Display responses
- Handle loading states
- Display errors

Business rules remain on the backend.

---

# REST API

Responsibilities:

- Receive requests
- Route requests
- Apply middleware
- Return JSON responses

REST endpoints remain stateless.

---

# Authentication

Authentication verifies user identity.

Requests without valid authentication should be rejected before business logic executes.

---

# Tenant Resolution

Tenant context is established immediately after authentication.

Every subsequent operation executes within the resolved tenant.

---

# Authorization

Authorization verifies:

- User role
- Permissions
- Tenant ownership
- Resource ownership

Unauthorized requests terminate immediately.

---

# Validation

Incoming requests should be validated before reaching business services.

Validation includes:

- Required fields
- Formats
- Business constraints
- Data types
- Relationships

---

# Controllers

Controllers coordinate request handling.

Responsibilities:

- Receive validated requests
- Invoke application services
- Return API resources

Controllers should remain thin.

---

# Application Services

Application Services orchestrate business workflows.

Responsibilities:

- Coordinate domain services
- Manage transactions
- Dispatch events
- Return results

They should avoid implementing complex business rules directly.

---

# Domain Services

Domain Services implement business behaviour.

Examples:

- Learner admission
- Fee calculation
- Timetable generation
- Report card creation

Business logic belongs here.

---

# Repositories

Repositories interact with PostgreSQL.

Responsibilities:

- Queries
- Persistence
- Retrieval

Repositories should not contain business rules.

---

# Database

PostgreSQL stores all persistent data.

Responsibilities:

- Transactions
- Constraints
- Relationships
- Tenant isolation
- Data integrity

---

# Domain Events

Business actions may publish events.

Examples:

- LearnerAdmitted
- FeePaid
- AssessmentPublished
- AttendanceRecorded

Events reduce coupling.

---

# Queue Processing

Long-running tasks execute asynchronously.

Examples:

- Emails
- SMS
- Report generation
- Imports
- Exports

Queues improve responsiveness.

---

# Notifications

Notification services deliver:

- Email
- SMS
- In-app notifications

Notifications should remain tenant-aware.

---

# Audit Logging

Critical actions should generate audit records.

Examples:

- Login
- Permission changes
- Admissions
- Finance transactions

Audit logs improve accountability.

---

# API Resources

API Resources transform internal models into consistent JSON responses.

Responsibilities:

- Hide internal fields
- Format responses
- Maintain API contracts

---

# JSON Responses

Responses should remain:

- Consistent
- Predictable
- Documented
- Version compatible

---

# Error Handling

Errors should provide safe and meaningful responses.

Internal implementation details must never be exposed.

---

# Security Checkpoints

Every request should verify:

- Authentication
- Tenant context
- Authorization
- Validation
- Business rules

No checkpoint should be skipped.

---

# Performance

Data flow should minimize:

- Database queries
- Duplicate work
- Network overhead
- Memory consumption

Efficiency should never compromise correctness.

---

# Observability

Every request should support:

- Logging
- Metrics
- Tracing
- Monitoring
- Audit history

Observability simplifies troubleshooting.

---

# Failure Handling

Failures should:

- Roll back transactions where appropriate
- Return predictable errors
- Preserve data integrity
- Log significant events

---

# Engineering Guidelines

Engineers should:

- Respect architectural layers
- Avoid bypassing services
- Keep controllers thin
- Protect business rules
- Preserve tenant isolation

---

# Architecture Governance

Changes affecting data flow require:

- Architecture review
- Security review
- Documentation update
- Testing update

---

# Constitution Compliance

This architecture reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 46 — Backend authorization is mandatory
- Rule 66 — Every feature has tests

---

# Related Documents

- System-Architecture.md
- Multi-Tenant-Architecture.md
- Domain-Driven-Design.md
- Module-Architecture.md
- Authentication-Architecture.md
- Authorization-Architecture.md
- Event-Architecture.md

---

# Final Standard

Every ShuleOS request must follow a consistent, secure, and well-defined data flow from user interaction to database persistence and back to the client.

A disciplined data flow architecture ensures maintainability, tenant isolation, security, performance, and long-term scalability for the School in the Clouds.
