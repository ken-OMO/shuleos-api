# ShuleOS PHP & Laravel Engineering Standards

> School in Clouds

## Document Information

| Field                | Value                               |
| -------------------- | ----------------------------------- |
| Document             | PHP & Laravel Engineering Standards |
| Document ID          | CODE-STD-0002                       |
| Version              | 1.0                                 |
| Status               | Approved                            |
| Owner                | Platform Engineering                |
| Repository           | `shuleos-api`                       |
| Effective Date       | 03 August 2026                      |
| Framework            | Laravel 12                          |
| PHP Version          | PHP 8.2+                            |
| Related Constitution | Engineering Constitution v1.1       |

---

# Purpose

This document establishes the mandatory engineering standards for all PHP and Laravel development within the ShuleOS platform.

It applies to:

- Controllers
- Models
- Form Requests
- Services
- Policies
- Resources
- Middleware
- Events
- Jobs
- Notifications
- Commands
- Migrations
- Seeders
- Tests

All backend code must comply with these standards.

---

# Engineering Philosophy

Laravel is used to build maintainable, secure, scalable software.

Code should be:

- Simple
- Predictable
- Readable
- Testable
- Secure
- Consistent

Avoid framework abuse and unnecessary complexity.

---

# PHP Standards

The project follows:

- PSR-1
- PSR-4
- PSR-12

Formatting is enforced automatically using Laravel Pint.

---

# Project Structure

Use Laravel's standard directory layout.

Business logic belongs in dedicated services rather than controllers.

Avoid placing unrelated responsibilities in the same class.

---

# Controllers

Controllers should:

- Be thin
- Coordinate requests
- Delegate business logic to services
- Return API Resources
- Never contain complex business rules

Controllers should focus on HTTP concerns only.

---

# Form Requests

Every create and update endpoint should use a dedicated Form Request.

Validation belongs in Form Requests—not controllers.

Authorization checks should also be performed within Form Requests where appropriate.

---

# Services

Business logic belongs in service classes.

Services should:

- Perform one business responsibility
- Be reusable
- Be independently testable

Avoid duplicating business logic across controllers.

---

# Models

Models represent persistence.

Models should:

- Define relationships
- Define casts
- Define scopes
- Avoid large business workflows

Keep models focused on data representation.

---

# Resources

All API responses should use Laravel API Resources.

Resources provide:

- Consistent response formats
- Controlled serialization
- Easier versioning

Never return Eloquent models directly from controllers.

---

# Policies

Authorization should use Laravel Policies.

Permission logic should not be duplicated throughout controllers.

Policies should remain tenant-aware.

---

# Middleware

Middleware should address cross-cutting concerns such as:

- Authentication
- Tenant resolution
- Rate limiting
- Request logging
- Security headers

Avoid placing business logic inside middleware.

---

# Migrations

Migration standards:

- One responsibility per migration
- Use foreign keys where appropriate
- Create indexes deliberately
- Never modify production data manually

Migrations must remain reversible.

---

# Seeders

Seeders should be:

- Deterministic
- Repeatable
- Safe

Production seeders should avoid creating unnecessary sample data.

---

# Events & Jobs

Use events for significant domain actions.

Use queued jobs for:

- Emails
- SMS
- Report generation
- Long-running tasks
- File processing

Avoid blocking HTTP requests.

---

# Error Handling

Use Laravel exception handling.

Applications should:

- Return standardized API responses
- Avoid exposing stack traces
- Log server-side details securely

---

# Logging

Use Laravel logging facilities.

Logs should include:

- Correlation ID
- Tenant ID
- User ID where applicable

Never log secrets or passwords.

---

# Dependency Injection

Prefer constructor injection.

Avoid creating dependencies manually inside classes when they can be resolved through Laravel's service container.

---

# Database Access

Prefer Eloquent relationships where appropriate.

Avoid N+1 queries.

Use eager loading deliberately.

Transactions should protect multi-step operations.

---

# Multi-Tenant Rules

Every database query must respect tenant boundaries.

Never bypass tenant scoping.

Cross-tenant access requires explicit platform-level authorization.

---

# Performance

Optimize by:

- Eager loading relationships
- Using indexes
- Queuing expensive work
- Caching where appropriate

Measure performance before optimizing.

---

# Security

Backend code must comply with:

- Authentication Standards
- Authorization Standards
- Secrets Management
- Encryption
- Secure Development

Security takes priority over convenience.

---

# Testing

Backend features should include:

- Unit tests
- Feature tests
- Authorization tests
- Tenant isolation tests

Critical workflows require automated regression tests.

---

# Documentation

Major services and architectural decisions should reference the relevant ADR where appropriate.

Public APIs should remain documented.

---

# Code Reviews

Reviews should verify:

- Architecture
- Security
- Performance
- Maintainability
- Test coverage
- Documentation

---

# Continuous Integration

CI should verify:

- Laravel Pint
- PHPStan (or equivalent static analysis)
- PHPUnit
- Security checks
- Documentation updates

Build failures block merges.

---

# Definition of Done

Backend work is complete only when:

- Code implemented
- Tests passing
- Documentation updated
- Pint passes
- Static analysis passes
- Review approved
- CI successful

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 10 — Design first. Code second
- Rule 17 — Audit important actions
- Rule 30 — Every query is tenant scoped
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- Coding-Standards.md
- Database-Naming.md
- API-Naming.md
- Testing-Conventions.md
- Refactoring-Standards.md
- Documentation-Standards.md

---

# Final Standard

Every Laravel component in ShuleOS must be written with clarity, consistency, security, and long-term maintainability in mind.

These standards ensure that the backend remains scalable, testable, and reliable as the School in the Clouds grows to serve thousands of schools.
