> **Status:** Active Development (Stage 0 – Baseline Protection)  
> **Version:** README v1.0  
> **Last Updated:** 22 July 2026
> ShuleOS

School in Clouds

Cloud-native Multi-Tenant School ERP
Built for Modern Education

# ShuleOS API

<div align="center">

# School in Clouds

### Cloud-native • Multi-Tenant • Secure • Scalable School ERP

**Engineering modern education through secure, reliable and intelligent technology.**

---

**Current Development Stage:** Stage 0 – Baseline Protection

**Backend Repository:** `shuleos-api`

**Frontend Repository:** `shuleos-web`

</div>

---

# Executive Summary

ShuleOS is a cloud-native, multi-tenant School Enterprise Resource Planning (ERP) platform designed to modernize school management through secure, scalable and integrated digital services.

Unlike traditional school management systems that focus on isolated administrative tasks, ShuleOS unifies every major school operation into a single platform. Academic management, teaching and learning, examinations, finance, transport, boarding, communication, leadership, reporting and analytics operate together under one architecture.

The platform is engineered specifically for modern educational institutions where security, data privacy, performance and usability are non-negotiable.

ShuleOS follows an engineering-first philosophy.

Every feature is evaluated not only by what it accomplishes, but by whether it satisfies the platform's standards for security, maintainability, scalability, tenant isolation, performance and reliability.

The guiding engineering principle is:

> **No code enters ShuleOS because it works. Code enters ShuleOS because it has been proven secure, scalable, performant, tenant-safe, maintainable and reliable.**

---

# Vision

To become Africa's most trusted cloud platform for educational institutions by providing secure, scalable and intelligent digital infrastructure that enables schools to focus on learning rather than administration.

ShuleOS seeks to transform how schools operate by delivering technology that is dependable, intuitive and capable of supporting institutions of every size—from a single primary school to large multi-campus organizations.

---

# Mission

To simplify, secure and modernize school management through an integrated cloud platform that supports teaching, learning, administration, communication and leadership while maintaining the highest standards of engineering excellence, security and data protection.

---

# Motto

> **School in Clouds**

The motto reflects the platform's commitment to making school operations available anywhere, anytime, while preserving security, reliability and simplicity.

---

# Product Philosophy

Technology should never become another administrative burden.

ShuleOS is built around the belief that software should reduce complexity rather than create it.

Every workflow is designed to solve real problems experienced by teachers, administrators, learners, parents and school leaders.

The platform is intentionally engineered around five goals:

- Reduce repetitive administrative work.
- Improve accuracy and accountability.
- Protect sensitive educational data.
- Enable informed decision-making through reliable information.
- Scale with schools as they grow.

---

# Engineering Philosophy

Engineering decisions within ShuleOS are guided by principles rather than convenience.

## Security Before Features

Security is designed into the platform from the beginning.

Authentication, authorization, tenant isolation, validation, auditing and monitoring are treated as core architectural requirements rather than optional enhancements.

Every request is authenticated.

Every operation is authorized.

Every action is auditable.

---

## Multi-Tenant by Design

Every school operates inside an isolated tenant.

A school can never access another school's information.

Tenant isolation is enforced through:

- Application services
- Authorization policies
- Database constraints
- Query scoping
- Security testing

---

## Performance Matters

Educational institutions depend on software throughout the school day.

The platform is optimized for:

- Fast database queries
- Efficient indexing
- Intelligent caching
- Queue-driven processing
- Background jobs
- Horizontal scalability

Performance is measured continuously rather than assumed.

---

## Human-Centered Experience

ShuleOS is designed around how schools actually operate.

Teachers should spend their time teaching.

Administrators should spend their time managing.

Parents should spend their time supporting learners.

Software should quietly support these activities rather than becoming an obstacle.

---

## Privacy by Design

Educational data is among the most sensitive information managed by any organization.

ShuleOS minimizes data collection, restricts access through least-privilege principles and maintains comprehensive audit trails for sensitive operations.

Privacy considerations influence every module, workflow and architectural decision.

---

## Continuous Hardening

ShuleOS is never considered "finished."

Every development cycle improves one or more of the following:

- Security
- Performance
- Reliability
- Maintainability
- Scalability
- Documentation
- User experience

The platform evolves through continuous review, testing and improvement rather than occasional large redesigns.

---

# Core Principles

The platform is guided by the following principles.

1. Security First
2. Privacy by Design
3. Multi-Tenant by Design
4. Performance Matters
5. Human-Centered Design
6. Engineering Excellence
7. Continuous Hardening
8. Documentation as Code
9. Test Before Trust
10. Architecture Before Implementation

These principles influence every technical and product decision within ShuleOS.

---

# What Makes ShuleOS Different?

ShuleOS is engineered as a platform rather than a collection of disconnected modules.

Key differentiators include:

- Cloud-native architecture
- Multi-tenant platform
- Offline-first capabilities
- Comprehensive Engineering Constitution
- Strong database engineering standards
- Security-first development lifecycle
- Architecture Decision Records (ADRs)
- Continuous security hardening
- Modular design
- School-specific payment configuration
- Role templates with custom permissions
- Human-centered workflows
- Future-ready architecture designed for long-term scalability

---

# Guiding Engineering Principle

Every contribution to ShuleOS is measured against a single principle.

> **No code enters ShuleOS because it works. Code enters ShuleOS because it has been proven secure, scalable, performant, tenant-safe, maintainable and reliable.**

This principle governs every pull request, architecture review, security review and production deployment.

---

© ShuleOS

**School in Clouds**

_Building secure, scalable and intelligent technology for modern education._

# Platform Features

ShuleOS is designed as a complete educational platform rather than a collection of independent applications. Every module is integrated through a common identity, authorization, audit, and multi-tenant architecture.

---

## Academic Management

The academic engine provides the foundation for all teaching and learning workflows.

Features include:

- Academic Years
- Academic Terms
- Academic Weeks
- Grades
- Streams
- Class Allocation
- Learning Areas
- Teacher Assignment
- Curriculum Coverage
- Timetable Integration (planned)

---

## Learner Management

The learner lifecycle is managed from admission through graduation.

Current and planned capabilities include:

- Admission Wizard
- Bulk Learner Import
- Learner Profiles
- Parent/Guardian Management
- Birth Certificate Tracking
- Assessment Number Tracking
- Health Information
- Discipline Records
- Attendance
- Academic History
- Transfer Management
- Graduation

The admission workflow is designed to support both guided registration and bulk onboarding.

---

## Teaching & Learning

ShuleOS digitizes the entire teaching workflow.

Modules include:

- Teacher Assignment
- Schemes of Work
- Scheme Lessons
- Lesson Plans
- Lesson Notes
- Records of Work
- Curriculum Coverage
- Teaching Resources
- Lesson Approval Workflow
- Digital Teaching Evidence

The teaching engine is designed to reduce administrative workload while improving accountability and curriculum tracking.

---

## Assessment & Examination

Assessment management supports both formative and summative assessment.

Features include:

- Assessment Registration
- Examination Registration
- Examination Papers
- Mark Entry
- Mark Moderation
- Grade Processing
- CBC Reporting
- Report Cards
- Merit Lists
- Performance Analytics
- Historical Results

Future releases will include comprehensive KNEC examination integration where applicable.

---

## Finance Management

The finance engine is designed to support diverse school fee structures.

Capabilities include:

- Fee Structures
- Student Billing
- Invoice Generation
- Receipts
- Payment Allocation
- Arrears Tracking
- Financial Reporting
- School-specific Payment Configuration
- Subscription Management

Each school maintains independent financial records and payment settings.

---

## Communication Platform

Communication services provide secure interaction between schools and stakeholders.

Supported and planned channels include:

- Email (Resend)
- SMS (Africa's Talking)
- WhatsApp Integration
- Push Notifications
- Parent Notifications
- Staff Notifications
- Learner Notifications
- Emergency Broadcasts

Notification delivery is designed to be asynchronous through background queues.

---

## Boarding Management

Boarding functionality includes:

- Hostels
- Rooms
- Beds
- Bed Allocation
- Movement Tracking
- Dormitory Management
- Boarding Reports

---

## Transport Management

Transport services support day scholars and boarding learners.

Features include:

- Transport Routes
- Pickup Points
- Vehicle Assignment
- Driver Assignment
- Route Allocation
- Learner Transport Allocation

---

## Student Leadership

Leadership development is integrated into the platform.

Modules include:

- Student Elections
- Leadership Positions
- Voting Management
- Election Results
- Leadership Portfolio
- Student Councils

---

## Parent Portal

Parents can securely access:

- Learner Progress
- Attendance
- Fees
- Communication
- Timetable
- Examination Results
- School Notices

---

## Teacher Portal

Teachers have access to:

- Assigned Classes
- Timetable
- Schemes of Work
- Lesson Plans
- Lesson Notes
- Attendance
- Assessment
- Curriculum Coverage
- Reports

---

## Leadership Portal

School leadership gains operational visibility through:

- Dashboard Analytics
- Academic Performance
- Financial Reports
- Attendance Reports
- Teaching Compliance
- Curriculum Coverage
- Communication Monitoring

---

## Administrator Portal

Administrative users manage:

- Users
- Roles
- Permissions
- Schools
- Subscriptions
- Platform Configuration
- Audit Logs
- System Health

---

# Technology Stack

ShuleOS is built using modern, proven technologies selected for security, maintainability and long-term scalability.

| Layer             | Technology                 |
| ----------------- | -------------------------- |
| Backend Framework | Laravel 12                 |
| Language          | PHP 8.2+                   |
| Database          | PostgreSQL                 |
| Authentication    | JWT                        |
| Authorization     | Role & Permission Engine   |
| Frontend          | Next.js 16                 |
| UI                | React 19                   |
| Styling           | Tailwind CSS               |
| Component Library | shadcn/ui                  |
| State Management  | Zustand                    |
| Data Fetching     | TanStack Query             |
| Email             | Resend                     |
| SMS               | Africa's Talking (planned) |
| Object Storage    | Cloudflare R2 (planned)    |
| Queue Processing  | Laravel Queues             |
| Cache             | Redis (Production)         |
| Version Control   | Git                        |
| CI/CD             | GitHub Actions (planned)   |

---

# System Architecture

ShuleOS follows a **Modular Monolith** architecture.

The platform is organized into business domains while maintaining a single deployable backend. This approach provides simplicity during development while allowing future extraction of services if required.

```
                    Users
                      │
                      ▼
              Next.js Frontend
                      │
                      ▼
               REST API (Laravel)
                      │
      ┌───────────────┼────────────────┐
      │               │                │
      ▼               ▼                ▼
 Authentication   Business Domains   Integrations
      │               │                │
      ▼               ▼                ▼
 Authorization   Application       Email / SMS /
 Tenant Scope     Services         Storage / Payments
      │
      ▼
 PostgreSQL Database
```

---

# Architectural Principles

The architecture is governed by several fundamental principles.

## Modular Monolith

Business functionality is separated into well-defined domains while remaining within a single application.

Benefits include:

- Simpler deployments
- Easier debugging
- Shared transactions
- Lower operational complexity
- Clear migration path toward microservices if required

---

## Domain-Oriented Design

Each business area is treated as an independent domain.

Examples include:

- Learners
- Teachers
- Finance
- Assessment
- Teaching
- Communication
- Leadership
- Transport
- Boarding

Each domain owns:

- Models
- Services
- Controllers
- Policies
- Validation
- Tests

---

## Layered Architecture

Every request follows a consistent processing pipeline.

```
Client
   │
   ▼
Route
   │
   ▼
Middleware
   │
   ▼
Authentication
   │
   ▼
Authorization
   │
   ▼
Validation
   │
   ▼
Application Service
   │
   ▼
Domain Logic
   │
   ▼
Database
   │
   ▼
API Resource
   │
   ▼
Response
```

This consistency improves maintainability, testing and security.

---

## Offline-First Foundation

ShuleOS is engineered to support offline-capable workflows for teachers, learners and parents.

Where connectivity is unreliable, changes can be stored locally and synchronized once a connection becomes available.

Offline synchronization will respect:

- Tenant isolation
- Conflict resolution policies
- Audit logging
- Data integrity
- User permissions

---

## Scalability Strategy

The platform is designed to grow with schools and increasing workloads.

Scalability is achieved through:

- Database indexing
- Queue processing
- Background jobs
- Efficient caching
- Horizontal scaling
- CDN-backed file storage
- Optimized database queries
- Multi-tenant resource isolation

---

## Future Architecture

The current modular monolith provides a stable foundation for growth.

Future architectural enhancements include:

- Cloudflare R2 object storage
- Advanced analytics
- AI-assisted reporting
- Event-driven integrations
- External API ecosystem
- School marketplace
- Mobile applications
- Real-time notifications
- Advanced observability

# Security, Multi-Tenancy and Performance

The trust placed in a school management platform extends far beyond software functionality. Schools entrust the platform with learner records, financial information, examination data, staff records and sensitive operational information.

For this reason, ShuleOS treats security, tenant isolation and performance as core architectural requirements rather than optional enhancements.

These principles influence every architectural decision, every database table, every API endpoint and every deployment.

---

# Security Architecture

Security within ShuleOS is implemented using a defense-in-depth strategy.

Rather than relying on a single security mechanism, multiple independent layers work together to protect the platform.

```
                 Client
                    │
                    ▼
            TLS / HTTPS Encryption
                    │
                    ▼
          Authentication (JWT + 2FA)
                    │
                    ▼
          Tenant Resolution & Validation
                    │
                    ▼
      Subscription & Account State Checks
                    │
                    ▼
          Role & Permission Authorization
                    │
                    ▼
         Object Ownership Verification
                    │
                    ▼
            Request Validation
                    │
                    ▼
           Business Rules Enforcement
                    │
                    ▼
          Database Constraints & Policies
                    │
                    ▼
              Audit Logging
                    │
                    ▼
               Safe Response
```

Each layer assumes that the previous layer may fail and therefore performs its own validation.

---

# Authentication

Every request originates from an authenticated identity.

Authentication is based on JSON Web Tokens (JWT) together with email-based Two-Factor Authentication (2FA).

The authentication lifecycle includes:

- Email verification during initial account activation
- Temporary password on first login
- Mandatory password change during first login
- Email OTP verification
- Session-based Two-Factor Authentication
- Secure JWT issuance
- Token refresh
- Session invalidation
- Real-time account suspension enforcement
- Password reset through verified email ownership

Authentication establishes a single authoritative identity for every request.

---

# Authorization

Authentication answers:

> "Who is making this request?"

Authorization answers:

> "What is this authenticated user allowed to do?"

Authorization within ShuleOS combines:

- Role-based permissions
- Policy-based authorization
- Tenant-aware authorization
- Object ownership verification
- Resource-level permissions

Authorization is evaluated before business logic executes.

---

# Role Templates

Every school has different operational structures.

ShuleOS provides configurable role templates while allowing schools to create custom roles.

Examples include:

- School Administrator
- Principal
- Deputy Principal
- Head Teacher
- Deputy Head Teacher
- Academic Master
- Director of Studies
- Head of Department
- Class Teacher
- Subject Teacher
- Finance Officer
- Accounts Clerk
- Librarian
- Boarding Master
- Boarding Mistress
- Transport Manager
- Games Teacher
- Examination Officer
- ICT Administrator

Schools may extend these templates with their own organizational roles.

---

# Permission System

Permissions are granular.

Examples include:

- View Learners
- Edit Learners
- Delete Learners
- Admit Learners
- Manage Teachers
- Manage Finance
- Manage Examinations
- Approve Lesson Plans
- Publish Report Cards
- Configure School Settings

Permissions can be combined into reusable role templates.

---

# Two-Factor Authentication

Every authenticated session requires a second verification factor.

The process is:

```
Login
   │
Password Verified
   │
Generate OTP
   │
Send OTP to Registered Email
   │
User Enters OTP
   │
OTP Valid?
   │
Yes
   │
Issue JWT
```

Future releases may support authenticator applications and additional verification methods.

---

# Account State Enforcement

User accounts may exist in different operational states.

Examples include:

- Active
- Pending Verification
- Password Reset Required
- Suspended
- Locked
- Archived

Account state is enforced on every authenticated request.

Role changes, suspension and lockout take effect immediately without requiring token expiration.

---

# Audit Logging

Sensitive operations are permanently recorded.

Examples include:

- Authentication events
- Permission changes
- Examination publication
- Mark changes
- Financial transactions
- Subscription updates
- User administration
- Configuration changes

Audit records provide accountability and operational traceability.

---

# Secure File Handling

File uploads follow a controlled processing pipeline.

```
Upload
   │
Validation
   │
Virus Scan
   │
Metadata Verification
   │
Quarantine
   │
Approval
   │
Protected Storage
```

Uploads are validated before becoming available to users.

Future releases will integrate Cloudflare R2 protected object storage.

---

# Multi-Tenant Architecture

Every school is an independent tenant.

Schools share infrastructure but never data.

```
Platform
    │
 ┌──┴─────────────┐
 │                │
School A      School B
 │                │
Users         Users
 │                │
Data          Data
```

Every request is resolved to exactly one tenant before business logic executes.

---

# Tenant Resolution

Tenant resolution is performed before authorization.

The server determines the active tenant using authenticated context.

Client-supplied tenant identifiers are never trusted as proof of authorization.

---

# Tenant Isolation

Tenant isolation is enforced through multiple independent mechanisms.

These include:

- Application Services
- Authorization Policies
- Query Scoping
- Database Constraints
- Tenant-aware Validation
- Automated Tests

Every query is expected to respect tenant boundaries.

Cross-tenant access is treated as a security defect.

---

# Database Isolation

Most business tables include:

- school_id
- Appropriate indexes
- Foreign key constraints
- Tenant-aware relationships

Schema design follows the principle:

> Every business record belongs to exactly one tenant unless a documented architectural exception exists.

---

# Data Ownership

Every protected resource has a verified owner.

Before returning or modifying a resource the platform verifies:

- User identity
- Active tenant
- Ownership
- Permission
- Object state

This prevents insecure direct object reference (IDOR) attacks.

---

# Subscription Enforcement

Every school operates under an active subscription.

Before processing protected requests the platform verifies:

- Subscription exists
- Trial status
- Expiration
- Grace period
- Read-only mode
- Locked status

Subscription enforcement is independent of authentication.

---

# Performance Philosophy

Performance is considered a product feature.

Every design decision should improve responsiveness rather than increase unnecessary complexity.

---

# Database Performance

Database performance is achieved through:

- Proper normalization
- Selective denormalization where justified
- Composite indexes
- Efficient foreign keys
- Query optimization
- Pagination
- Server-side filtering
- Efficient eager loading

Every schema change is reviewed for performance implications.

---

# Query Performance

Every database query should be:

- Tenant scoped
- Indexed
- Explainable
- Measurable

Long-running queries are investigated rather than accepted.

---

# Caching Strategy

Caching reduces unnecessary computation.

Planned cache layers include:

- Configuration Cache
- Route Cache
- Permission Cache
- Settings Cache
- Reference Data Cache
- Query Result Cache

Cached information is invalidated when underlying data changes.

---

# Queue Processing

Long-running operations execute outside the request lifecycle.

Examples include:

- Email delivery
- SMS delivery
- WhatsApp notifications
- Report generation
- File processing
- Import jobs
- Export jobs

Queues improve responsiveness and overall throughput.

---

# Offline-First Synchronization

ShuleOS is designed to support environments with unreliable connectivity.

Offline capabilities allow users to continue working while disconnected.

Synchronization must preserve:

- Tenant isolation
- Data integrity
- Conflict resolution
- Audit logging
- User permissions

Offline operations are synchronized once connectivity is restored.

---

# Scalability Strategy

The platform is designed to scale without major architectural redesign.

Scalability is supported through:

- Queue workers
- Horizontal application scaling
- Redis caching
- Cloud object storage
- Efficient indexing
- Background processing
- Modular architecture

Future deployments may distribute workloads across multiple application servers while maintaining consistent tenant isolation.

---

# Engineering Commitment

Every security, tenant isolation and performance decision within ShuleOS is governed by the Engineering Constitution.

Security is never traded for convenience.

Performance is never achieved by sacrificing correctness.

Tenant isolation is never optional.

The platform succeeds only when schools can trust that their data is secure, their workflows remain responsive and their information is completely isolated from every other institution.

# Installation & Development

This section describes how to set up the ShuleOS backend for local development and outlines the engineering workflow used throughout the project.

---

# System Requirements

The following software is required for backend development.

| Component          | Version                                    |
| ------------------ | ------------------------------------------ |
| PHP                | 8.2 or later                               |
| Composer           | Latest Stable                              |
| PostgreSQL         | 15 or later                                |
| Git                | Latest Stable                              |
| Node.js            | Latest LTS (optional for frontend tooling) |
| Visual Studio Code | Recommended                                |

Recommended development environment:

- Windows 11 Professional
- WSL2 (optional)
- Ubuntu 24.04 LTS (optional)
- Docker Desktop (optional)

---

# Clone the Repository

Clone the backend repository.

```bash
git clone https://github.com/ken-OMO/shuleos-api.git
```

Navigate into the project.

```bash
cd shuleos-api
```

---

# Install Dependencies

Install PHP dependencies.

```bash
composer install
```

---

# Environment Configuration

Create the environment file.

```bash
cp .env.example .env
```

Generate the application key.

```bash
php artisan key:generate
```

Generate the JWT secret.

```bash
php artisan jwt:secret
```

Configure PostgreSQL connection settings inside `.env`.

Example:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=shuleos
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

---

# Database Setup

Run database migrations.

```bash
php artisan migrate
```

(Optional) Seed development data.

```bash
php artisan db:seed
```

Never seed production environments unless explicitly required.

---

# Running the API

Start the local development server.

```bash
php artisan serve
```

Default address:

```
http://127.0.0.1:8000
```

---

# Code Quality

Every contribution must satisfy the platform quality standards.

Run formatting checks:

```bash
vendor/bin/pint --test
```

Automatically format code:

```bash
vendor/bin/pint
```

Validate Composer configuration:

```bash
composer validate --strict
```

Run dependency security audit:

```bash
composer audit
```

Run the test suite:

```bash
php artisan test
```

No Pull Request should be opened until all checks pass.

---

# Repository Workflow

Development follows a structured Git workflow.

```
Requirement
      │
      ▼
Design
      │
      ▼
Create Branch
      │
      ▼
Implementation
      │
      ▼
Testing
      │
      ▼
Security Review
      │
      ▼
Documentation Review
      │
      ▼
Pull Request
      │
      ▼
Engineering Review
      │
      ▼
Merge into develop
      │
      ▼
Production Release
```

Every stage is mandatory.

---

# Branch Strategy

ShuleOS uses a structured branching model.

```
main
```

Production-ready releases only.

```
develop
```

Primary integration branch.

```
feature/*
```

New platform functionality.

Examples:

```
feature/learner-admission
feature/finance-engine
feature/report-cards
```

```
hardening/*
```

Security, architecture, performance and scalability improvements.

Examples:

```
hardening/authentication
hardening/database-security
hardening/query-performance
```

```
fix/*
```

Bug fixes.

```
fix/report-card-calculation
```

```
docs/*
```

Documentation changes.

```
docs/readme-v1
docs/architecture
```

```
audit/*
```

Repository investigations and engineering audits.

---

# Commit Convention

Commits should be small, focused and descriptive.

Examples:

```
feat(auth): add email OTP verification

feat(assessment): implement report card generation

fix(finance): prevent duplicate payment allocation

docs(readme): replace Laravel project guide

test(tenant): add cross-school isolation tests

chore(deps): upgrade Guzzle to latest secure version

style: apply Laravel Pint formatting
```

Avoid generic commit messages such as:

```
Update

Fixes

Changes

Work

Done
```

---

# Pull Request Workflow

Every Pull Request should answer four questions:

1. What problem does this solve?
2. How was it implemented?
3. How was it tested?
4. Which Engineering Constitution rules are affected?

A Pull Request should include:

- Summary
- Related issue
- Test evidence
- Screenshots (if UI changes)
- Documentation updates
- Reviewer checklist

---

# Code Review Standards

Every review evaluates:

- Security
- Tenant isolation
- Authorization
- Performance
- Maintainability
- Readability
- Test coverage
- Documentation
- Database impact

Code is reviewed for correctness, not merely functionality.

---

# Definition of Done

A task is complete only when:

- Requirements are implemented.
- Tests pass.
- Documentation is updated.
- Security review is complete.
- Performance impact is acceptable.
- No Engineering Constitution rules are violated.
- Pull Request is approved.
- Changes are merged into `develop`.

Working code alone does not satisfy the Definition of Done.

---

# Continuous Integration

Every Pull Request will eventually trigger automated quality checks including:

- Composer validation
- Dependency audit
- Code formatting
- Static analysis
- PHPUnit tests
- Migration validation
- Tenant isolation checks
- Security scanning

A failing quality gate blocks merging.

---

# Release Strategy

Development progresses through clearly defined stages.

```
Feature Branch
        │
        ▼
Develop
        │
        ▼
Integration Testing
        │
        ▼
Release Candidate
        │
        ▼
Production
```

Production releases occur only after successful validation and approval.

---

# Engineering Workflow

Every engineering activity follows a consistent lifecycle.

```
Plan
   │
   ▼
Design
   │
   ▼
Implement
   │
   ▼
Review
   │
   ▼
Test
   │
   ▼
Document
   │
   ▼
Approve
   │
   ▼
Release
```

This workflow ensures that every change entering ShuleOS is secure, maintainable, and fully traceable.

---

# Engineering Culture

ShuleOS values:

- Clarity over cleverness.
- Simplicity over unnecessary complexity.
- Security over convenience.
- Consistency over personal preference.
- Documentation alongside implementation.
- Testing before deployment.
- Continuous improvement through disciplined engineering.

Every contributor is expected to uphold these values throughout the development lifecycle.

# Documentation

Documentation is treated as a first-class engineering artifact within ShuleOS.

Every architectural decision, engineering standard, operational procedure and development guideline is documented, version-controlled and reviewed alongside the source code.

The complete documentation library is maintained under:

```
docs/
```

---

## Documentation Structure

```
docs/
│
├── README.md
│
├── 01-vision/
├── 02-engineering-constitution/
├── 03-architecture/
├── 04-architecture-decision-records/
├── 05-database/
├── 06-api/
├── 07-security/
├── 08-coding-standards/
├── 09-ui-ux/
├── 10-testing/
├── 11-devops/
├── 12-operations/
├── 13-user-manuals/
├── 14-release-notes/
└── 15-future-ideas/
```

Each document follows a common template including:

- Document Name
- Version
- Status
- Owner
- Last Updated
- Related ADRs
- Related Engineering Constitution Rules

Documentation evolves together with the platform.

---

# Engineering Constitution

The ShuleOS Engineering Constitution is the governing standard for the platform.

It defines the engineering principles that every contribution must satisfy before it can be accepted.

The Constitution covers:

- Architecture
- Database Engineering
- Security
- Authentication
- Authorization
- Multi-Tenancy
- Privacy
- Payments
- Offline Synchronization
- Performance
- Testing
- Documentation
- Operations
- Deployment
- Incident Response
- Coding Standards

The Constitution currently contains **112 Engineering Rules**.

Every Pull Request is reviewed against these rules.

The Constitution represents the platform's engineering law rather than optional guidance.

---

# Architecture Decision Records (ADRs)

Major technical decisions are permanently documented through Architecture Decision Records.

An ADR records:

- Context
- Problem
- Decision
- Alternatives Considered
- Consequences
- Related Constitution Rules

Current ADRs include:

- ADR-0001 — Modular Monolith
- ADR-0002 — PostgreSQL
- ADR-0003 — JWT Authentication
- ADR-0004 — Archive-First Data Lifecycle
- ADR-0005 — Offline-First Synchronization
- ADR-0006 — Tenant Isolation
- ADR-0007 — Cloudflare R2 Object Storage
- ADR-0008 — Resend Email Provider
- ADR-0009 — Africa's Talking SMS
- ADR-0010 — School-Specific Payment Architecture

Future architectural changes require new ADRs before implementation.

---

# Project Roadmap

ShuleOS development follows a structured engineering roadmap.

| Stage    | Description                              | Status      |
| -------- | ---------------------------------------- | ----------- |
| Stage 0  | Baseline Protection                      | In Progress |
| Stage 1  | Complete Database Audit                  | Planned     |
| Stage 2  | Multi-Tenant Security                    | Planned     |
| Stage 3  | Authorization Framework                  | Planned     |
| Stage 4  | Authentication & Email 2FA               | Planned     |
| Stage 5  | School Registration & Subscription       | Planned     |
| Stage 6  | Scalability & Noisy-Neighbour Protection | Planned     |
| Stage 7  | Cloudflare R2 Integration                | Planned     |
| Stage 8  | Learner Admission & Bulk Import          | Planned     |
| Stage 9  | School Experience & Portals              | Planned     |
| Stage 10 | Production Readiness                     | Planned     |

Each stage concludes with:

- Architecture Review
- Security Review
- Performance Review
- Test Validation
- Documentation Review
- Pull Request Approval

---

# Future Vision

The long-term roadmap includes:

## Artificial Intelligence

- AI-assisted report generation
- Performance analytics
- Learning recommendations
- Administrative insights

## Mobile Applications

- Teacher Mobile App
- Parent Mobile App
- Learner Mobile App
- Leadership Mobile App

## Communication Platform

- SMS Marketplace
- WhatsApp Integration
- Push Notifications
- Voice Notifications

## Educational Ecosystem

- Digital Content
- Learning Resources
- Assessment Bank
- School Marketplace
- Third-Party Integrations

---

# Contributing

Contributions are welcome provided they follow the project's engineering standards.

Every contributor is expected to:

- Follow the Engineering Constitution.
- Maintain documentation.
- Write automated tests.
- Preserve tenant isolation.
- Prioritize security.
- Keep changes focused and reviewable.

Before opening a Pull Request:

- Run all quality checks.
- Review your own changes.
- Update affected documentation.
- Verify tests pass.
- Confirm no Constitution rules are violated.

See `CONTRIBUTING.md` for the complete contribution guide.

---

# Security Reporting

Security vulnerabilities should never be reported through public GitHub issues.

Instead:

1. Report the issue privately.
2. Provide sufficient reproduction details.
3. Allow time for investigation and remediation.
4. Coordinate disclosure after a fix is available.

The full security disclosure process is documented in:

```
SECURITY.md
```

Supported areas include:

- Authentication
- Authorization
- Multi-Tenant Isolation
- Payments
- File Uploads
- API Security
- Data Privacy
- Dependency Security

Responsible disclosure helps protect schools and their data.

---

# Support

During active development, support is provided through the project repository and engineering documentation.

Future support channels may include:

- Documentation Portal
- Community Discussions
- Issue Tracker
- Knowledge Base

---

# License

ShuleOS is proprietary software.

Unless a separate license agreement is provided, all rights are reserved.

Unauthorized copying, redistribution or commercial use of the software is prohibited.

---

# Acknowledgements

ShuleOS is built upon the work of the open-source community.

We acknowledge the projects and communities whose technologies make this platform possible, including:

- Laravel
- PHP
- PostgreSQL
- React
- Next.js
- Tailwind CSS
- shadcn/ui
- TanStack Query
- Zustand

Open-source software has provided the foundation upon which ShuleOS is being engineered, and we are grateful for those contributions.

---

# Project Identity

<div align="center">

# ShuleOS

### School in Clouds

**Cloud-native • Multi-Tenant • Secure • Scalable**

---

Building secure, reliable and intelligent technology for modern education.

---

**Backend Repository**

`shuleos-api`

**Frontend Repository**

`shuleos-web`

**Current Status**

Active Development

**Engineering Constitution**

112 Rules

</div>

---

> **No code enters ShuleOS because it works.  
> Code enters ShuleOS because it has been proven secure, scalable, performant, tenant-safe, maintainable and reliable.**

---

© ShuleOS. All rights reserved.
