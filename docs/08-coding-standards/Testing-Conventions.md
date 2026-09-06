# ShuleOS Testing Conventions

> School in Clouds

## Document Information

| Field                | Value                         |
| -------------------- | ----------------------------- |
| Document             | Testing Conventions           |
| Document ID          | CODE-STD-0010                 |
| Version              | 1.0                           |
| Status               | Approved                      |
| Owner                | Platform Engineering          |
| Repository           | `shuleos-api`, `shuleos-web`  |
| Effective Date       | 03 August 2026                |
| Related Constitution | Engineering Constitution v1.1 |

---

# Purpose

This document defines the mandatory testing conventions for the ShuleOS platform.

It governs:

- Unit testing
- Feature testing
- Integration testing
- API testing
- Frontend testing
- Database testing
- Multi-tenant testing
- Security testing
- Performance testing
- Continuous Integration

Testing is a mandatory engineering practice for all production code.

---

# Testing Principles

Every test should be:

- Repeatable
- Deterministic
- Independent
- Fast
- Readable
- Reliable

A failing test should identify a real defect rather than environmental instability.

---

# Test Pyramid

ShuleOS follows the testing pyramid.

```text
                End-to-End
             Integration Tests
            Feature/API Tests
              Unit Tests
```

Prefer many fast unit tests, fewer feature tests, and a limited number of end-to-end tests for critical workflows.

---

# Unit Tests

Unit tests verify individual classes and methods.

Examples include:

- Services
- Helpers
- Policies
- Value Objects
- Utility functions
- Business rules

Unit tests should not depend on external services.

---

# Feature Tests

Feature tests verify complete application behavior.

Typical coverage includes:

- Authentication
- Authorization
- CRUD operations
- Validation
- API responses
- Business workflows

Feature tests should verify user-visible behavior.

---

# Integration Tests

Integration tests verify interaction between components.

Examples:

- Database integration
- Queue processing
- Email delivery
- SMS delivery
- File storage
- External provider integration

---

# API Tests

Every public API should test:

- Success responses
- Validation errors
- Authentication
- Authorization
- Pagination
- Filtering
- Sorting
- Error handling
- Tenant isolation

API contracts should remain stable.

---

# Database Tests

Database tests should verify:

- Relationships
- Foreign keys
- Constraints
- Migrations
- Transactions
- Soft deletes
- Cascade behavior

Migration tests should run on clean databases.

---

# Multi-Tenant Tests

Tenant isolation is mandatory.

Verify:

- School scoping
- Data visibility
- Cross-tenant access prevention
- Tenant-aware relationships
- Tenant-aware caching
- Tenant-aware queues

Cross-tenant regressions must block release.

---

# Security Tests

Security testing should verify:

- Authentication
- Authorization
- JWT validation
- Role enforcement
- Permission enforcement
- Rate limiting
- Input validation
- CSRF protection where applicable
- File access restrictions

Security tests are mandatory.

---

# Performance Tests

Performance testing should measure:

- Response times
- Query counts
- Queue throughput
- Memory usage
- Large dataset handling

Performance claims should be supported by measurements.

---

# Frontend Tests

Frontend tests should include:

- Component rendering
- User interactions
- Form validation
- Accessibility
- State management
- Error states
- Loading states

Critical user journeys should have automated coverage.

---

# Test Naming

Use descriptive test names.

Examples:

```text
it_creates_a_teacher_successfully

it_rejects_cross_tenant_access

it_returns_validation_errors_for_invalid_input
```

Names should clearly describe expected behavior.

---

# Test Data

Test data should be:

- Minimal
- Realistic
- Isolated
- Repeatable

Factories should be preferred over hardcoded fixtures.

---

# Mocking

Mock external dependencies when appropriate:

- Email
- SMS
- Payment providers
- Cloud storage
- Third-party APIs

Do not mock the code under test.

---

# Regression Tests

Every production bug should receive a regression test before the fix is merged.

Regression tests help prevent recurring defects.

---

# Code Coverage

Coverage should prioritize meaningful behavior rather than arbitrary percentages.

Critical business workflows must have comprehensive automated coverage.

---

# Continuous Integration

CI should execute:

- Unit tests
- Feature tests
- Integration tests
- Static analysis
- Formatting
- Security checks

Failed tests block merges.

---

# Test Reviews

Code reviewers should verify:

- Correct assertions
- Edge cases
- Tenant isolation
- Security coverage
- Meaningful test names
- Appropriate scope

---

# Definition of Done

Development is complete only when:

- Required tests added
- Existing tests updated
- Tests pass
- Documentation updated
- Review approved
- CI successful

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 19 — Every security feature is tested
- Rule 30 — Every query is tenant scoped
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 70 — Rollback tests are mandatory
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- Coding-Standards.md
- PHP-Laravel-Standards.md
- TypeScript-React-Standards.md
- Code-Review-Checklist.md
- Refactoring-Standards.md
- Performance-Guidelines.md

---

# Final Standard

Testing is the primary mechanism for protecting the quality, security, and reliability of ShuleOS.

Every feature delivered to production must be supported by appropriate automated tests that verify correctness, protect tenant isolation, enforce security requirements, and prevent regressions as the School in the Clouds continues to evolve.
