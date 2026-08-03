# ShuleOS System Architecture

> School in Clouds

## Document Information

| Field                | Value                            |
| -------------------- | -------------------------------- |
| Document             | System Architecture              |
| Document ID          | ARCH-STD-0001                    |
| Version              | 1.0                              |
| Status               | Approved                         |
| Owner                | Platform Engineering             |
| Repository           | `shuleos-api` & `shuleos-web`    |
| Effective Date       | 03 August 2026                   |
| Related Constitution | Engineering Constitution v1.1    |
| Related Standards    | Security, Coding, UI/UX, Testing |

---

# Purpose

This document defines the overall architecture of the ShuleOS platform.

It serves as the primary architectural reference for every engineer working on ShuleOS and explains how all major platform components interact.

---

# Vision

ShuleOS is designed as a modern cloud-native, multi-tenant School ERP that enables multiple schools to securely share a single platform while maintaining complete logical data isolation.

The architecture emphasizes:

- Scalability
- Security
- Maintainability
- Performance
- Reliability
- Extensibility

---

# Architectural Goals

The platform shall:

- Support thousands of schools
- Support millions of learners
- Maintain strict tenant isolation
- Scale horizontally
- Support continuous deployment
- Minimize operational complexity
- Provide high availability

---

# High-Level Architecture

ShuleOS follows a layered architecture.

```text
Users
│
▼
Next.js Frontend
│
▼
REST API
│
▼
Laravel Application Layer
│
├── Authentication
├── Authorization
├── Business Services
├── Domain Logic
├── Events
├── Jobs
├── Notifications
└── Reporting
│
▼
PostgreSQL
```

---

# Core Technologies

Frontend

- Next.js 16
- React 19
- TypeScript
- Tailwind CSS
- shadcn/ui
- TanStack Query
- Zustand

Backend

- Laravel 12
- PHP 8.2+
- JWT Authentication

Database

- PostgreSQL

Infrastructure

- Linux Servers
- Nginx
- PHP-FPM
- Queue Workers
- Scheduler

---

# Layered Architecture

The platform is divided into distinct layers.

Presentation Layer

- User Interface
- Forms
- Reports
- Dashboards

Application Layer

- Controllers
- Form Requests
- Resources

Domain Layer

- Business Services
- Domain Rules
- Policies

Infrastructure Layer

- Database
- Cache
- Queues
- Notifications
- Storage

Each layer has a clearly defined responsibility.

---

# Modular Architecture

Major platform modules include:

- Platform Administration
- School Administration
- User Management
- Admissions
- Learners
- Guardians
- Teachers
- Staff
- Curriculum
- Timetable
- Attendance
- Assessments
- Report Cards
- Finance
- Inventory
- Library
- Transport
- Boarding
- Parent Portal
- Communication
- Analytics
- Audit Logs

Modules remain loosely coupled.

---

# API-First Design

The backend exposes REST APIs consumed by:

- Web application
- Mobile applications
- Future integrations
- Third-party services

Business logic resides exclusively within the backend.

---

# Multi-Tenant Architecture

Every request executes within an active tenant context.

Tenant isolation applies to:

- Data
- Users
- Permissions
- Reports
- Files
- Notifications

Tenant architecture is documented separately.

---

# Authentication

Authentication uses JWT.

Supported roles include:

- Platform Owner
- School Owner
- Principal
- Deputy Principal
- Teacher
- Finance Officer
- Librarian
- Parent
- Learner

Authentication architecture is documented separately.

---

# Authorization

Authorization uses:

- Roles
- Permissions
- Policies

Every protected resource is validated on the backend.

---

# Database

The platform uses PostgreSQL.

Database design emphasizes:

- Normalization
- Integrity
- Performance
- Tenant isolation

Database architecture is documented separately.

---

# Event-Driven Design

Business events trigger:

- Notifications
- Queue jobs
- Audit logging
- Reports
- Future integrations

Events reduce coupling between modules.

---

# Queue Architecture

Background processing includes:

- Emails
- SMS
- Report generation
- Imports
- Exports
- Scheduled jobs

Long-running operations should never block user requests.

---

# Caching

Caching improves:

- Performance
- Scalability
- Response time

Cache architecture is documented separately.

---

# File Storage

Files include:

- Learner photos
- Staff photos
- Documents
- Reports
- Imports
- Exports

Storage remains tenant-aware.

---

# Reporting Engine

Reporting supports:

- PDF
- Excel
- CSV

Reports are generated through dedicated services.

---

# Security

Security principles include:

- Least privilege
- Defense in depth
- Tenant isolation
- Secure defaults
- Auditability

Security standards are documented separately.

---

# Observability

The platform supports:

- Structured logging
- Metrics
- Audit trails
- Error reporting
- Health checks

Operational visibility is essential for production support.

---

# Scalability

The architecture supports:

- Horizontal scaling
- Stateless APIs
- Queue workers
- Database optimization
- Caching

Growth should require minimal architectural changes.

---

# Reliability

Reliability is achieved through:

- Automated testing
- Database transactions
- Retry mechanisms
- Health monitoring
- Backup strategies

---

# Maintainability

Maintainability principles include:

- Modular design
- Clear boundaries
- Clean code
- Documentation
- Automated testing

---

# Deployment

The platform supports:

- Development
- Staging
- Production

Deployment architecture is documented separately.

---

# Disaster Recovery

The platform includes:

- Backup strategy
- Recovery procedures
- Infrastructure recovery
- Database restoration

Recovery architecture is documented separately.

---

# Engineering Principles

Every architectural decision should prioritize:

- Simplicity
- Consistency
- Security
- Performance
- Maintainability
- Extensibility

---

# Architecture Evolution

Architecture evolves through documented Architecture Decision Records (ADRs).

Significant changes require:

- Technical justification
- Risk assessment
- Documentation updates
- Team review

---

# Constitution Compliance

This architecture reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 66 — Every feature has tests

---

# Related Documents

- Multi-Tenant-Architecture.md
- Domain-Driven-Design.md
- Module-Architecture.md
- Data-Flow.md
- Authentication-Architecture.md
- Authorization-Architecture.md
- Event-Architecture.md
- Caching-Architecture.md
- Deployment-Architecture.md
- Disaster-Recovery-Architecture.md
- Architecture-Decision-Records.md

---

# Final Standard

The ShuleOS architecture provides the foundation for building a secure, scalable, maintainable, and high-performance multi-tenant School ERP.

Every architectural decision should strengthen the platform's ability to serve schools reliably while supporting future growth, continuous innovation, and the long-term vision of the **School in the Clouds**.
