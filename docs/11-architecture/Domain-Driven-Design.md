# ShuleOS Domain-Driven Design

> School in Clouds

## Document Information

| Field                | Value                                                               |
| -------------------- | ------------------------------------------------------------------- |
| Document             | Domain-Driven Design                                                |
| Document ID          | ARCH-STD-0003                                                       |
| Version              | 1.0                                                                 |
| Status               | Approved                                                            |
| Owner                | Platform Engineering                                                |
| Repository           | `shuleos-api` & `shuleos-web`                                       |
| Effective Date       | 03 August 2026                                                      |
| Related Constitution | Engineering Constitution v1.1                                       |
| Related Standards    | System Architecture, Module Architecture, Multi-Tenant Architecture |

---

# Purpose

This document defines the Domain-Driven Design (DDD) approach adopted by ShuleOS.

DDD organizes software around real educational business domains rather than technical concerns, ensuring that the platform grows in a maintainable and understandable manner.

---

# Philosophy

Business rules are the heart of ShuleOS.

Technology exists to support the educational domain—not the other way around.

The domain model should reflect how schools actually operate.

---

# Core Principles

The platform emphasizes:

- Business-first design
- Clear domain boundaries
- Rich domain models
- High cohesion
- Low coupling
- Explicit business rules

---

# Ubiquitous Language

Developers, testers, product owners, and educators should use the same terminology.

Examples:

- Learner
- Guardian
- Grade
- Stream
- Academic Year
- Assessment
- Scheme of Work
- Lesson Plan
- Attendance
- Fee Invoice
- Fee Payment
- Report Card

Avoid ambiguous or inconsistent naming.

---

# Bounded Contexts

Major business domains are implemented as independent bounded contexts.

Examples include:

- Platform
- Schools
- Users
- Admissions
- Academics
- Curriculum
- Teaching
- Assessments
- Finance
- Attendance
- Timetable
- Boarding
- Transport
- Library
- Inventory
- Parent Portal
- Communication
- Reporting

Each context owns its own business rules.

---

# Entities

Entities possess identity throughout their lifecycle.

Examples:

- School
- Learner
- Teacher
- Guardian
- User
- Assessment
- Fee Invoice

Identity remains stable even when attributes change.

---

# Value Objects

Value Objects describe concepts without identity.

Examples:

- Address
- Phone Number
- Email Address
- Academic Grade
- Money
- Date Range

Value Objects should be immutable whenever practical.

---

# Aggregates

Aggregates protect business consistency.

Examples:

- Learner Aggregate
- Assessment Aggregate
- Fee Aggregate
- Timetable Aggregate

External components should interact through aggregate roots.

---

# Aggregate Roots

Aggregate roots enforce business invariants.

Examples:

- Learner
- Assessment
- Fee Invoice

Business rules should not bypass aggregate roots.

---

# Domain Services

Domain Services contain business logic that does not naturally belong to a single entity.

Examples:

- Admission Service
- Promotion Service
- Fee Calculation Service
- Timetable Generation Service
- Report Card Generation Service

Services should remain focused on domain behaviour.

---

# Application Services

Application Services coordinate workflows between:

- Controllers
- Domain Services
- Repositories
- Events
- Notifications

They should orchestrate rather than implement core business rules.

---

# Repositories

Repositories provide access to aggregates.

Responsibilities include:

- Retrieval
- Persistence
- Query abstraction

Repositories should not contain business logic.

---

# Domain Events

Business events communicate meaningful domain changes.

Examples:

- LearnerAdmitted
- AssessmentPublished
- FeePaid
- AttendanceRecorded
- ReportGenerated

Events reduce coupling between modules.

---

# Business Invariants

Critical business rules must always hold true.

Examples:

- A learner belongs to one school.
- Marks cannot exceed maximum scores.
- Fee balances cannot be inconsistent.
- Teachers cannot access another school's learners.

These rules should be enforced within the domain.

---

# Cross-Domain Communication

Domains should communicate through:

- Events
- Application Services
- Well-defined interfaces

Avoid direct dependencies whenever possible.

---

# Module Ownership

Each bounded context owns:

- Business rules
- Database entities
- Services
- Policies
- Events

Ownership should remain clear.

---

# Domain Independence

Changes within one domain should minimize impact on others.

Loose coupling improves maintainability.

---

# Testing

Every domain should include:

- Unit tests
- Feature tests
- Integration tests

Business rules should be protected by automated tests.

---

# Evolution

Business domains evolve over time.

Architectural changes should preserve:

- Existing behaviour
- Data integrity
- Tenant isolation
- Backward compatibility where appropriate

---

# Anti-Corruption Layer

When integrating with external systems, adapters should translate external concepts into ShuleOS domain concepts.

External models should not leak into the domain.

---

# Engineering Guidelines

Engineers should:

- Model the business first.
- Keep domain logic independent.
- Avoid anemic domain models.
- Protect aggregate boundaries.
- Prefer explicit business language.
- Keep services cohesive.

---

# Architecture Governance

Significant domain model changes require:

- Architecture review
- Documentation update
- Test updates
- Team approval

---

# Constitution Compliance

This architecture reinforces:

- Rule 1 — Quality over speed
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 66 — Every feature has tests

---

# Related Documents

- System-Architecture.md
- Module-Architecture.md
- Data-Flow.md
- Event-Architecture.md
- Multi-Tenant-Architecture.md

---

# Final Standard

ShuleOS organizes software around educational business domains rather than technical layers.

Every engineering decision should strengthen the domain model, preserve business rules, maintain tenant isolation, and support the long-term evolution of the School in the Clouds.
