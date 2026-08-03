# ShuleOS Coding Standards

> School in Clouds

## Document Information

| Field                | Value                         |
| -------------------- | ----------------------------- |
| Document             | Coding Standards              |
| Document ID          | CODE-STD-0001                 |
| Version              | 1.0                           |
| Status               | Approved                      |
| Owner                | Platform Engineering          |
| Repository           | `shuleos-api`                 |
| Effective Date       | 03 August 2026                |
| Related Constitution | Engineering Constitution v1.1 |

---

# Purpose

This document establishes the mandatory coding standards for the ShuleOS platform.

It defines the engineering principles that govern how source code is written, reviewed, tested, documented, and maintained.

These standards apply to:

- Laravel Backend
- React Frontend
- Shared Libraries
- Infrastructure Scripts
- Database Migrations
- API Development
- Background Workers
- Testing
- Documentation

Every contributor must follow these standards.

---

# Engineering Philosophy

ShuleOS is designed for long-term maintainability.

Every line of code should be:

- Readable
- Simple
- Secure
- Testable
- Maintainable
- Consistent
- Documented

Code is written for people first and computers second.

---

# Guiding Principles

Every implementation should prioritize:

- Correctness
- Clarity
- Consistency
- Security
- Performance
- Maintainability

Avoid clever code when straightforward code communicates intent more clearly.

---

# Scope

These standards apply to:

- PHP
- Laravel
- TypeScript
- React
- SQL
- Shell scripts
- CI/CD pipelines
- Documentation

Language-specific requirements are defined in dedicated standards.

---

# General Rules

Engineers should:

- Prefer readability over brevity.
- Follow established project conventions.
- Reuse existing components where appropriate.
- Remove unused code.
- Keep implementations focused.
- Avoid unnecessary complexity.

---

# Naming

Names should:

- Describe intent.
- Avoid abbreviations unless universally understood.
- Remain consistent throughout the project.

Naming conventions are defined in dedicated documents.

---

# Code Organization

Code should be organized into cohesive modules.

Responsibilities should be separated clearly.

Files should have a single primary responsibility.

---

# Reuse

Before creating new functionality:

- Search for existing implementations.
- Extend where appropriate.
- Avoid duplication.

Duplicate logic increases maintenance cost.

---

# Comments

Comments should explain:

- Why something exists.
- Non-obvious decisions.
- Architectural constraints.

Comments should not restate obvious code.

---

# Documentation

Public interfaces should be documented.

Major architectural decisions should reference the relevant ADR where applicable.

Documentation should evolve with the code.

---

# Error Handling

Errors should:

- Be handled consistently.
- Provide useful diagnostics.
- Avoid exposing sensitive information.

Applications should fail safely.

---

# Logging

Log messages should be:

- Meaningful
- Actionable
- Consistent

Sensitive information must never be logged.

---

# Security

Developers must follow all requirements defined in:

- Authentication Standards
- Authorization Standards
- Secrets Management
- Encryption
- Secure Development

Security requirements take precedence over convenience.

---

# Performance

Performance should be considered during implementation.

Avoid:

- Unnecessary database queries
- Excessive memory allocation
- Duplicate processing
- Blocking operations

Optimize only after measuring.

---

# Testing

Every new feature should include appropriate tests.

Testing should verify:

- Correctness
- Error handling
- Authorization
- Tenant isolation
- Regression prevention

Testing requirements are defined separately.

---

# Refactoring

Refactoring should:

- Preserve behavior.
- Improve maintainability.
- Reduce complexity.
- Eliminate duplication.

Large refactoring efforts should be reviewed carefully.

---

# Code Reviews

Every change should receive peer review.

Reviews should consider:

- Architecture
- Security
- Performance
- Maintainability
- Documentation
- Testing

Review standards are documented separately.

---

# Version Control

Git history should remain:

- Clean
- Meaningful
- Reviewable

Commit standards are defined separately.

---

# Continuous Integration

CI should verify:

- Formatting
- Static analysis
- Tests
- Security checks
- Documentation

Failed quality checks block merges.

---

# Technical Debt

Technical debt should:

- Be documented.
- Be minimized.
- Be reviewed periodically.

Avoid introducing debt without clear justification.

---

# Definition of Done

A coding task is complete only when:

- Requirements implemented
- Tests pass
- Documentation updated
- Code reviewed
- Standards followed
- CI passes

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 10 — Design first. Code second
- Rule 17 — Audit important actions
- Rule 66 — Every feature has tests
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- PHP-Laravel-Standards.md
- TypeScript-React-Standards.md
- Database-Naming.md
- API-Naming.md
- Git-Commit-Standards.md
- Code-Review-Checklist.md
- Refactoring-Standards.md
- Documentation-Standards.md
- Testing-Conventions.md
- Performance-Guidelines.md
- Clean-Code-Principles.md

---

# Final Standard

Coding standards establish a shared engineering language for the ShuleOS platform.

Every contributor is responsible for producing code that is readable, secure, maintainable, consistently structured, and aligned with the architectural, security, and engineering principles of the School in the Clouds.
