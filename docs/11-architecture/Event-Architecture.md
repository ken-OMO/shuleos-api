# ShuleOS Event Architecture

> School in Clouds

## Document Information

| Field                | Value                                                          |
| -------------------- | -------------------------------------------------------------- |
| Document             | Event Architecture                                             |
| Document ID          | ARCH-STD-0008                                                  |
| Version              | 1.0                                                            |
| Status               | Approved                                                       |
| Owner                | Platform Engineering                                           |
| Repository           | `shuleos-api` & `shuleos-web`                                  |
| Effective Date       | 03 August 2026                                                 |
| Related Constitution | Engineering Constitution v1.1                                  |
| Related Standards    | System Architecture, Module Architecture, Domain-Driven Design |

---

# Purpose

This document defines the event-driven architecture used throughout the ShuleOS platform.

Events enable independent modules to communicate without creating unnecessary dependencies while supporting scalability, maintainability, and reliability.

---

# Philosophy

Events represent important business occurrences.

Modules should react to business events instead of directly depending on one another whenever practical.

---

# Architectural Principles

The event architecture should be:

- Decoupled
- Reliable
- Tenant-aware
- Observable
- Scalable
- Maintainable

---

# Event Lifecycle

Every event follows this lifecycle.

```text
Business Action
        │
        ▼
Domain Event Created
        │
        ▼
Event Published
        │
        ▼
Event Dispatcher
        │
        ▼
Event Listeners
        │
        ▼
Business Reactions
        │
        ▼
Notifications / Jobs / Audit Logs
```

---

# Event Categories

ShuleOS uses two primary event categories.

## Domain Events

Represent important business activities.

Examples:

- LearnerAdmitted
- TeacherAssigned
- AssessmentPublished
- FeePaymentReceived

---

## Integration Events

Represent communication with external systems.

Examples:

- SMS Sent
- Email Delivered
- External Payment Confirmed

---

# Event Publishing

Events should be published only after successful completion of the business transaction.

Failed transactions should not publish events.

---

# Event Subscribers

Listeners respond to events independently.

Examples:

- Send notification
- Generate report
- Write audit log
- Trigger workflow

---

# Synchronous Events

Synchronous events execute immediately within the current request.

Use only when immediate consistency is required.

---

# Asynchronous Events

Long-running operations should execute asynchronously through queue workers.

Examples:

- Email
- SMS
- Report generation
- Data export

---

# Queue Integration

Queued listeners improve:

- Performance
- Scalability
- User experience

Business requests should remain responsive.

---

# Event Naming

Events should use clear business language.

Examples:

- LearnerAdmitted
- AttendanceRecorded
- MarksSubmitted
- ReportCardGenerated

Avoid technical or implementation-specific names.

---

# Event Versioning

When event payloads change incompatibly, introduce a new event version.

Avoid breaking existing listeners.

---

# Event Payload

Payloads should include only information required by subscribers.

Sensitive information should not be included unnecessarily.

---

# Idempotency

Listeners should safely process duplicate events without creating inconsistent business data.

Repeated processing should not produce unintended side effects.

---

# Failure Handling

Failed listeners should:

- Retry where appropriate
- Log failures
- Preserve data integrity
- Avoid infinite retry loops

---

# Retry Strategy

Retries should be:

- Configurable
- Limited
- Logged

Permanent failures should be escalated for investigation.

---

# Event Ordering

Where event order is important, processing should preserve business consistency.

Applications should avoid assumptions about unrelated event ordering.

---

# Multi-Tenant Isolation

Events execute within the originating tenant context.

Tenant information should accompany queued processing where required.

Cross-tenant event processing is prohibited.

---

# Audit Logging

Important events should generate audit records.

Examples:

- Admissions
- Payments
- Role assignments
- Report publication

Audit logs improve traceability.

---

# Monitoring

Operational monitoring should include:

- Event throughput
- Failed listeners
- Retry counts
- Queue latency
- Processing duration

---

# Security

Event processing should enforce:

- Authentication context where required
- Authorization
- Tenant isolation
- Data protection

Events must never bypass security controls.

---

# Performance

Events should reduce unnecessary synchronous processing.

Long-running work belongs in background jobs.

---

# Testing

Every important event should include:

- Unit tests
- Feature tests
- Listener tests
- Integration tests

Critical business events require automated verification.

---

# Engineering Guidelines

Engineers should:

- Publish meaningful business events.
- Keep events immutable.
- Keep listeners focused.
- Avoid listener coupling.
- Prefer asynchronous processing for long-running work.
- Document new events.

---

# Architecture Governance

Changes affecting event behaviour require:

- Architecture review
- Documentation update
- Testing update
- Security review where applicable

---

# Constitution Compliance

This architecture reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 66 — Every feature has tests

---

# Related Documents

- System-Architecture.md
- Module-Architecture.md
- Domain-Driven-Design.md
- Data-Flow.md
- Caching-Architecture.md

---

# Final Standard

Every significant business action within ShuleOS should be represented by a well-defined event that enables secure, scalable, and loosely coupled communication between platform modules.

A disciplined event architecture allows the School in the Clouds to evolve without creating unnecessary dependencies while preserving tenant isolation, business consistency, and long-term maintainability.
