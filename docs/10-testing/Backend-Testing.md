# ShuleOS Backend Testing Standards

> School in Clouds

## Document Information

| Field                | Value                                                   |
| -------------------- | ------------------------------------------------------- |
| Document             | Backend Testing Standards                               |
| Document ID          | TEST-STD-0002                                           |
| Version              | 1.0                                                     |
| Status               | Approved                                                |
| Owner                | Platform Engineering                                    |
| Repository           | `shuleos-api`                                           |
| Effective Date       | 03 August 2026                                          |
| Related Constitution | Engineering Constitution v1.1                           |
| Related Standards    | Testing Standards, Coding Standards, Security Standards |

---

# Purpose

This document establishes the mandatory backend testing standards for the ShuleOS API.

These standards ensure every backend component behaves correctly, securely, consistently, and maintains strict tenant isolation.

---

# Scope

Backend testing applies to:

- Controllers
- Services
- Models
- Form Requests
- Policies
- Middleware
- Events
- Listeners
- Jobs
- Notifications
- Resources
- Authentication
- Authorization
- Multi-tenancy

---

# Philosophy

The backend is the source of truth.

Every business rule must be verified by automated tests.

No critical business logic should rely solely on frontend validation.

---

# Testing Framework

Backend testing uses:

- PHPUnit
- Laravel Testing Framework

Tests must integrate with the platform CI pipeline.

---

# Test Categories

Backend tests include:

- Unit Tests
- Feature Tests
- Integration Tests
- API Tests
- Authorization Tests
- Validation Tests
- Database Tests
- Multi-Tenant Tests
- Performance Tests

---

# Feature Testing

Every API endpoint should verify:

- Successful requests
- Invalid requests
- Unauthorized requests
- Forbidden requests
- Validation failures
- Resource not found
- Tenant isolation

---

# Authentication Testing

Verify:

- Login
- Logout
- Refresh Token
- Expired Token
- Invalid Token
- Missing Token
- Revoked Token

---

# Authorization Testing

Every protected endpoint must verify:

- Correct permissions
- Missing permissions
- Role restrictions
- Tenant restrictions

Frontend visibility never replaces backend authorization.

---

# Validation Testing

Every Form Request should verify:

- Required fields
- Data types
- Length limits
- Format
- Unique constraints
- Custom validation rules

Both valid and invalid scenarios must be tested.

---

# Service Testing

Business services should verify:

- Business rules
- Edge cases
- Error handling
- Transactions
- Domain behaviour

Services should remain independent of HTTP requests where practical.

---

# Controller Testing

Controllers should verify:

- Request handling
- Response status
- Response structure
- Authorization
- Validation
- Resource formatting

Controllers should remain thin.

---

# Resource Testing

API Resources should verify:

- JSON structure
- Field names
- Hidden fields
- Conditional fields
- Relationships
- Pagination

Responses should remain consistent.

---

# Database Testing

Verify:

- Record creation
- Updates
- Soft deletes
- Restores
- Relationships
- Cascading behaviour
- Transactions

Database assertions should confirm persisted state.

---

# Multi-Tenant Testing

Every tenant-aware feature must verify:

- School isolation
- Tenant ownership
- Cross-school protection
- Scoped queries
- Tenant switching

Cross-tenant data exposure is a release blocker.

---

# Policy Testing

Policies should verify:

- Allow
- Deny
- Ownership
- Administrative overrides
- Tenant boundaries

---

# Middleware Testing

Middleware should verify:

- Authentication
- Permissions
- Tenant context
- Request rejection
- Rate limiting

---

# Event Testing

Verify:

- Event dispatch
- Event listeners
- Side effects
- Failure handling

---

# Queue Testing

Queued jobs should verify:

- Dispatch
- Execution
- Retry behaviour
- Failure handling

Jobs should remain idempotent where appropriate.

---

# Notification Testing

Verify:

- Email notifications
- Database notifications
- SMS integration
- Queue behaviour

Notification content should remain accurate.

---

# Transaction Testing

Verify successful rollback when failures occur.

Partial writes should never leave inconsistent data.

---

# Error Handling

Verify:

- Exceptions
- Validation failures
- Database failures
- Authorization failures
- Missing resources

Sensitive implementation details must never be exposed.

---

# Security Testing

Backend security testing should verify:

- SQL injection prevention
- XSS protection
- CSRF protection where applicable
- Rate limiting
- Authorization
- Input validation

---

# Performance Testing

Verify:

- Large datasets
- Query counts
- Memory usage
- Response times
- Background jobs

Avoid unnecessary database queries.

---

# Factories

Tests should use factories for generating realistic data.

Factories should represent actual school scenarios.

---

# Seeders

Dedicated test seeders may be used where appropriate.

Production seed data should not be required.

---

# Mocking

Mock only external dependencies.

Avoid mocking core business behaviour unnecessarily.

---

# Test Naming

Examples:

```text
it_creates_a_new_teacher()

it_prevents_cross_school_access()

it_requires_manage_users_permission()
```

Names should describe behaviour.

---

# Continuous Integration

Every backend pull request should execute:

- Pint
- Static analysis
- PHPUnit
- Feature tests
- Coverage reporting
- Security checks

Merge is blocked if tests fail.

---

# Review Checklist

Verify:

- Business rules tested
- Validation tested
- Authorization tested
- Tenant isolation verified
- Database assertions present
- Edge cases covered
- Error handling tested
- Performance acceptable

---

# Definition of Done

Backend functionality is complete only when:

- Unit tests pass.
- Feature tests pass.
- Authorization verified.
- Tenant isolation verified.
- Database behaviour verified.
- Security requirements satisfied.
- Continuous integration passes.
- Documentation updated.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 10 — Design first. Code second
- Rule 46 — Backend authorization is mandatory
- Rule 66 — Every feature has tests

---

# Related Documents

- Testing-Standards.md
- Unit-Testing.md
- Feature-and-Integration-Testing.md
- Database-Testing.md
- Multi-Tenant-Testing.md
- Security-Testing.md

---

# Final Standard

Every ShuleOS backend feature must be verified through comprehensive automated testing before deployment.

Backend testing protects school data, enforces business rules, preserves tenant isolation, and ensures the School in the Clouds remains secure, reliable, and maintainable as the platform evolves.
