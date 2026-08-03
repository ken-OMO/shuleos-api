# ShuleOS Module Architecture

> School in Clouds

## Document Information

| Field                | Value                                                                |
| -------------------- | -------------------------------------------------------------------- |
| Document             | Module Architecture                                                  |
| Document ID          | ARCH-STD-0004                                                        |
| Version              | 1.0                                                                  |
| Status               | Approved                                                             |
| Owner                | Platform Engineering                                                 |
| Repository           | `shuleos-api` & `shuleos-web`                                        |
| Effective Date       | 03 August 2026                                                       |
| Related Constitution | Engineering Constitution v1.1                                        |
| Related Standards    | System Architecture, Domain-Driven Design, Multi-Tenant Architecture |

---

# Purpose

This document defines the modular architecture of ShuleOS.

The platform is organized into independent business modules that collaborate through well-defined interfaces while preserving clear ownership and minimizing coupling.

---

# Philosophy

Each module represents a distinct business capability.

Modules should remain cohesive, loosely coupled, independently testable, and independently maintainable.

---

# Architectural Goals

The module architecture should:

- Encourage separation of concerns
- Reduce coupling
- Improve maintainability
- Support parallel development
- Enable future scalability
- Preserve business boundaries

---

# Module Principles

Every module should:

- Own its business rules
- Own its services
- Own its events
- Own its policies
- Expose only necessary interfaces

Internal implementation details should remain private.

---

# Core Platform Modules

ShuleOS consists of the following major business modules:

- Platform
- Schools
- Users
- Admissions
- Learners
- Guardians
- Academics
- Curriculum
- Teaching
- Assessments
- Attendance
- Finance
- Timetable
- Boarding
- Transport
- Library
- Inventory
- Communication
- Parent Portal
- Reporting
- Analytics
- Audit

---

# Platform Module

Responsibilities:

- Tenant management
- Licensing
- Subscription management
- Platform configuration
- Global administration

---

# School Module

Responsibilities:

- School profile
- Academic structure
- School settings
- Branding
- Calendar

---

# User Module

Responsibilities:

- Authentication
- User management
- Roles
- Permissions
- Profiles

---

# Admissions Module

Responsibilities:

- Learner admission
- Admission numbers
- Guardian linking
- Initial placement

---

# Learner Module

Responsibilities:

- Learner profile
- Grade
- Stream
- Status
- Promotion history

---

# Guardian Module

Responsibilities:

- Parent information
- Relationships
- Contact details
- Communication preferences

---

# Academics Module

Responsibilities:

- Academic years
- Terms
- Weeks
- Learning areas

---

# Curriculum Module

Responsibilities:

- CBC curriculum
- Schemes of work
- Lesson plans
- Lesson notes
- Curriculum coverage

---

# Teaching Module

Responsibilities:

- Teacher assignments
- Records of work
- Classroom activities

---

# Assessment Module

Responsibilities:

- Assessments
- Marks
- Grades
- Reports
- Rankings

---

# Attendance Module

Responsibilities:

- Learner attendance
- Staff attendance
- Reports

---

# Finance Module

Responsibilities:

- Fee structures
- Invoices
- Payments
- Statements
- Receipts

---

# Timetable Module

Responsibilities:

- Timetable generation
- Conflict detection
- Teacher schedules
- Learner schedules

---

# Boarding Module

Responsibilities:

- Hostels
- Rooms
- Beds
- Boarding allocation

---

# Transport Module

Responsibilities:

- Routes
- Stops
- Vehicles
- Transport allocation

---

# Library Module

Responsibilities:

- Books
- Borrowing
- Returns
- Fines

---

# Inventory Module

Responsibilities:

- Assets
- Stock
- Procurement
- Suppliers

---

# Communication Module

Responsibilities:

- SMS
- Email
- Notifications
- Announcements

---

# Parent Portal

Responsibilities:

- Learner progress
- Attendance
- Fee statements
- Communication

---

# Reporting Module

Responsibilities:

- PDFs
- Excel exports
- Dashboards
- Analytics reports

---

# Analytics Module

Responsibilities:

- KPIs
- Performance metrics
- Trends
- Insights

---

# Audit Module

Responsibilities:

- Audit logs
- Activity history
- Security events

---

# Module Communication

Modules should communicate using:

- Application services
- Domain events
- Public interfaces

Avoid direct database access between modules.

---

# Dependencies

Dependencies should remain minimal.

Lower-level modules should never depend upon higher-level business modules unnecessarily.

---

# Shared Services

Shared services may include:

- Authentication
- Notifications
- File storage
- Search
- Reporting
- Logging

Shared services should remain generic.

---

# Versioning

Module changes should preserve backward compatibility whenever practical.

Breaking changes require architectural review.

---

# Testing

Each module should include:

- Unit tests
- Feature tests
- Integration tests

Critical workflows should also include End-to-End tests.

---

# Documentation

Every module should maintain:

- Architecture documentation
- API documentation
- Business rules
- Testing documentation

---

# Governance

Module ownership should be clearly defined.

Major module changes require:

- Architecture review
- Documentation updates
- Test updates
- Peer review

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
- Domain-Driven-Design.md
- Multi-Tenant-Architecture.md
- Data-Flow.md
- Event-Architecture.md

---

# Final Standard

Every ShuleOS capability shall belong to a clearly defined module with explicit responsibilities, well-defined interfaces, and strong business ownership.

A modular architecture enables the School in the Clouds to evolve safely, allowing teams to build, test, deploy, and maintain features independently while preserving the integrity of the overall platform.
