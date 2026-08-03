# ShuleOS Code Review Checklist

> School in Clouds

## Document Information

| Field                | Value                         |
| -------------------- | ----------------------------- |
| Document             | Code Review Checklist         |
| Document ID          | CODE-STD-0007                 |
| Version              | 1.0                           |
| Status               | Approved                      |
| Owner                | Platform Engineering          |
| Repository           | `shuleos-api`, `shuleos-web`  |
| Effective Date       | 03 August 2026                |
| Related Constitution | Engineering Constitution v1.1 |

---

# Purpose

This document defines the mandatory code review checklist for all ShuleOS repositories.

Every pull request must be reviewed against this checklist before merging.

The goal is to ensure:

- Correctness
- Maintainability
- Security
- Performance
- Consistency
- Documentation
- Testability

---

# General Review

Verify:

- [ ] The change solves the intended problem.
- [ ] Scope is appropriate.
- [ ] No unrelated changes are included.
- [ ] Code follows project standards.
- [ ] Commit history is clean.

---

# Architecture

Confirm:

- [ ] Architecture follows approved ADRs.
- [ ] Responsibilities are properly separated.
- [ ] Business logic is not placed in controllers.
- [ ] Services remain cohesive.
- [ ] No unnecessary coupling introduced.

---

# Security

Verify:

- [ ] Authentication enforced where required.
- [ ] Authorization verified.
- [ ] Tenant isolation maintained.
- [ ] Input validation implemented.
- [ ] Sensitive information protected.
- [ ] No secrets committed.

---

# Database

Check:

- [ ] Migrations follow standards.
- [ ] Foreign keys implemented.
- [ ] Indexes considered.
- [ ] Naming follows conventions.
- [ ] Queries remain tenant scoped.

---

# API

Review:

- [ ] Endpoints follow REST conventions.
- [ ] Resources used consistently.
- [ ] Error handling standardized.
- [ ] Pagination implemented where needed.
- [ ] Filtering documented.
- [ ] Response format consistent.

---

# Frontend

Verify:

- [ ] Components have a single responsibility.
- [ ] TypeScript types are correct.
- [ ] Accessibility considered.
- [ ] State management appropriate.
- [ ] Loading and error states handled.

---

# Performance

Check:

- [ ] No obvious N+1 queries.
- [ ] Expensive work is queued.
- [ ] Database access optimized.
- [ ] Unnecessary rendering avoided.
- [ ] Caching considered where appropriate.

---

# Logging

Confirm:

- [ ] Important actions logged.
- [ ] Logs are meaningful.
- [ ] No secrets written to logs.
- [ ] Correlation identifiers preserved where applicable.

---

# Testing

Verify:

- [ ] Unit tests updated.
- [ ] Feature tests updated.
- [ ] Regression risk considered.
- [ ] Authorization tested.
- [ ] Tenant isolation tested.

---

# Documentation

Confirm:

- [ ] Documentation updated.
- [ ] ADR references added where appropriate.
- [ ] API documentation updated.
- [ ] Public interfaces documented.

---

# Code Quality

Review:

- [ ] Naming is clear.
- [ ] Functions remain focused.
- [ ] Duplication avoided.
- [ ] Dead code removed.
- [ ] Readability maintained.

---

# Error Handling

Verify:

- [ ] Errors handled gracefully.
- [ ] Exceptions logged appropriately.
- [ ] Internal implementation details are not exposed.

---

# Continuous Integration

Before approval:

- [ ] Formatting passes.
- [ ] Static analysis passes.
- [ ] Tests pass.
- [ ] Security checks pass.
- [ ] CI pipeline successful.

---

# Final Approval

Reviewer confirms:

- [ ] Ready for production.
- [ ] Standards followed.
- [ ] Risks understood.
- [ ] No blocking issues remain.

---

# Constitution Compliance

This checklist reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 10 — Design first. Code second
- Rule 17 — Audit important actions
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- Coding-Standards.md
- Git-Commit-Standards.md
- Refactoring-Standards.md
- Testing-Conventions.md
- Documentation-Standards.md

---

# Final Standard

Every pull request merged into the ShuleOS codebase must pass this checklist.

Code review is not simply an approval step—it is a structured engineering practice that protects the quality, security, maintainability, and long-term reliability of the School in the Clouds platform.
