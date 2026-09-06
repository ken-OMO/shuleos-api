# ShuleOS Unit Testing Standards

> School in Clouds

## Document Information

| Field                | Value                                                                    |
| -------------------- | ------------------------------------------------------------------------ |
| Document             | Unit Testing Standards                                                   |
| Document ID          | TEST-STD-0004                                                            |
| Version              | 1.0                                                                      |
| Status               | Approved                                                                 |
| Owner                | Platform Engineering                                                     |
| Repository           | `shuleos-api` & `shuleos-web`                                            |
| Effective Date       | 03 August 2026                                                           |
| Related Constitution | Engineering Constitution v1.1                                            |
| Related Standards    | Testing Standards, Backend Testing Standards, Frontend Testing Standards |

---

# Purpose

This document establishes the mandatory standards for unit testing throughout the ShuleOS platform.

Unit tests verify the smallest independently testable pieces of software while remaining fast, deterministic, and isolated from external systems.

---

# Scope

Unit testing applies to:

- Service classes
- Utility classes
- Domain logic
- Helper functions
- Business calculations
- Validators
- Transformers
- Value objects
- Frontend utility functions
- Custom hooks where appropriate

---

# Philosophy

Unit tests verify business behaviour rather than implementation details.

They should execute quickly and provide immediate feedback during development.

---

# Core Principles

Every unit test should be:

- Fast
- Independent
- Repeatable
- Deterministic
- Readable
- Maintainable

---

# Test Isolation

Unit tests must never depend upon:

- Databases
- Networks
- External APIs
- Shared state
- Execution order

External dependencies should be isolated or mocked appropriately.

---

# Arrange – Act – Assert

Every unit test should follow:

1. Arrange
2. Act
3. Assert

Keep this structure consistent throughout the codebase.

---

# Test Naming

Examples:

```text
it_calculates_fee_balance()

it_generates_admission_number()

it_rejects_invalid_grade()
```

Names should describe observable behaviour.

---

# One Behaviour Per Test

Each unit test should verify one primary behaviour.

Avoid testing multiple unrelated outcomes within the same test.

---

# Business Logic

Critical business rules should always have unit tests.

Examples:

- Fee calculations
- Grade calculations
- Assessment averages
- Report card generation logic
- Timetable conflict detection

---

# Edge Cases

Every unit should verify:

- Valid input
- Invalid input
- Boundary values
- Empty values
- Null handling
- Unexpected values

---

# Exception Testing

Verify expected exceptions.

Tests should confirm:

- Correct exception type
- Appropriate message where relevant
- Safe recovery

---

# Mocking

Mock only external dependencies.

Examples:

- HTTP clients
- Email services
- SMS providers
- File storage

Avoid mocking core business logic.

---

# Stubs

Use stubs when predictable responses are sufficient.

Keep stub behaviour simple and explicit.

---

# Factories

Factories may be used when creating realistic objects improves readability.

Factories should remain deterministic.

---

# Fixtures

Shared fixtures should remain small and easy to understand.

Avoid oversized fixture datasets.

---

# Assertions

Assertions should verify meaningful outcomes.

Prefer:

```text
assertEquals()
assertTrue()
assertFalse()
assertCount()
```

Avoid unnecessary assertions.

---

# Floating Point Values

Where calculations involve decimals, compare values appropriately rather than relying on exact floating-point equality.

---

# Date and Time

Time-dependent logic should use controllable clocks or test helpers.

Avoid relying on the actual system clock.

---

# Random Values

Avoid uncontrolled randomness.

Random generators should be seeded or replaced with deterministic values.

---

# File System

Unit tests should avoid writing to the real filesystem.

Use temporary or mocked storage.

---

# Configuration

Configuration values should remain predictable during tests.

Tests should not modify global configuration unexpectedly.

---

# Performance

Unit tests should execute quickly.

Slow unit tests should be investigated.

Long-running tests usually belong elsewhere.

---

# Code Coverage

Coverage should prioritize:

- Business rules
- Critical calculations
- Validation
- Authorization helpers

Coverage percentage alone is not the goal.

---

# Continuous Integration

Unit tests should run:

- Before commits where practical
- During pull requests
- During continuous integration
- Before deployment

Failing unit tests block merging.

---

# Review Checklist

Verify:

- Behaviour tested
- Edge cases covered
- Exceptions tested
- Mocks appropriate
- Tests isolated
- Naming clear
- Readability maintained
- Performance acceptable

---

# Definition of Done

Unit testing is complete only when:

- Business behaviour verified.
- Edge cases covered.
- Tests remain independent.
- Performance acceptable.
- Continuous integration passes.
- Documentation updated.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 66 — Every feature has tests

---

# Related Documents

- Testing-Standards.md
- Backend-Testing.md
- Frontend-Testing.md
- Feature-and-Integration-Testing.md
- API-Contract-Testing.md

---

# Final Standard

Every critical unit of business logic within ShuleOS must be protected by fast, reliable, and deterministic unit tests.

Well-designed unit tests provide the confidence needed to evolve the School in the Clouds while preserving correctness, maintainability, and long-term engineering quality.
