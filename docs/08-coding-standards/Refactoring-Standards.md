# ShuleOS Refactoring Standards

> School in Clouds

## Document Information

| Field                | Value                         |
| -------------------- | ----------------------------- |
| Document             | Refactoring Standards         |
| Document ID          | CODE-STD-0008                 |
| Version              | 1.0                           |
| Status               | Approved                      |
| Owner                | Platform Engineering          |
| Repository           | `shuleos-api`, `shuleos-web`  |
| Effective Date       | 03 August 2026                |
| Related Constitution | Engineering Constitution v1.1 |

---

# Purpose

This document defines the mandatory standards for refactoring ShuleOS code.

It governs:

- Behavior-preserving change
- Technical debt reduction
- Code simplification
- Structural improvement
- Safe migration
- Regression prevention
- Test requirements
- Documentation updates
- Review expectations

Refactoring improves internal structure without changing approved external behavior.

---

# Core Principle

Refactoring must preserve behavior.

A refactor is not an opportunity to introduce unrelated features, silently change contracts, or weaken security controls.

---

# Objectives

Refactoring should improve one or more of the following:

- Readability
- Maintainability
- Testability
- Security
- Performance
- Consistency
- Modularity
- Observability

The expected improvement must be clear before work begins.

---

# When to Refactor

Refactoring is appropriate when code shows signs such as:

- Duplication
- Excessive complexity
- Large methods
- Large classes
- Unclear naming
- Tight coupling
- Hidden side effects
- Repeated authorization logic
- Repeated tenant-scoping logic
- Unstable tests
- Difficult extension
- Poor performance supported by evidence

---

# When Not to Refactor

Avoid refactoring when:

- The current behavior is not understood.
- Tests are missing for critical behavior.
- Production is unstable.
- The change would mix refactoring with unrelated features.
- The risk cannot be reviewed adequately.
- The required migration or rollback plan is absent.

Stabilize and characterize the behavior first.

---

# Behavior Characterization

Before changing legacy or poorly tested code:

1. Identify current behavior.
2. Add characterization tests.
3. Capture edge cases.
4. Confirm tenant and authorization behavior.
5. Record known defects separately.

A refactor must not silently “fix” undocumented behavior unless that correction is explicitly approved.

---

# Small, Incremental Changes

Prefer small refactors over large rewrites.

Good examples:

- Extract a service.
- Rename an unclear method.
- Remove duplicate validation.
- Split a large component.
- Introduce a reusable query scope.
- Replace repeated constants with an enum.
- Move external work into a queued job.

Each step should leave the application in a working state.

---

# Separate Refactoring from Features

Feature work and refactoring should be separated where practical.

Preferred sequence:

```text
Characterize existing behavior
        ↓
Refactor safely
        ↓
Verify behavior
        ↓
Introduce new feature
```

This improves reviewability and reduces regression risk.

---

# Refactoring Scope

Every refactoring change should define:

- Problem being addressed
- Affected modules
- Expected improvement
- Behavior that must remain unchanged
- Tests protecting the change
- Rollback approach
- Performance implications
- Security implications

---

# Backend Refactoring

Laravel refactoring may include:

- Extracting business logic from controllers
- Introducing application services
- Consolidating duplicate validation
- Replacing raw arrays with value objects
- Centralizing authorization policies
- Introducing tenant-aware scopes
- Moving slow work to queues
- Simplifying Eloquent relationships
- Removing N+1 query patterns

Controllers must remain thin after refactoring.

---

# Frontend Refactoring

React and TypeScript refactoring may include:

- Splitting large components
- Extracting reusable hooks
- Removing duplicated server state
- Moving shared state into appropriate stores
- Strengthening types
- Replacing unsafe `any`
- Improving accessibility
- Reducing unnecessary re-renders
- Consolidating repeated UI patterns

Refactoring must not change approved user workflows without explicit product review.

---

# Database Refactoring

Database refactoring requires special care.

Examples include:

- Renaming columns
- Splitting tables
- Introducing normalized relationships
- Replacing free-form status fields
- Adding tenant-aware constraints
- Rebuilding indexes
- Migrating identifiers

Database refactors must follow:

- Expand-and-contract deployment
- Backfill planning
- Rollback planning
- Data integrity verification
- Production lock review
- Compatibility testing

---

# API Refactoring

A refactor must not break a supported API contract.

Protected behavior includes:

- Endpoint paths
- Request fields
- Response fields
- Status codes
- Error codes
- Pagination shape
- Authentication requirements
- Authorization behavior

Breaking changes require the API versioning process.

---

# Security Refactoring

Security-sensitive refactors require explicit review.

Examples include:

- Authentication middleware
- JWT handling
- Tenant resolution
- Policies
- Role resolution
- Permission caching
- Payment idempotency
- File access
- Provider callbacks

Security behavior must fail closed throughout the transition.

---

# Tenant Isolation

Every refactor affecting data access must verify:

- School scoping
- Brand or governance scoping
- Ownership checks
- Tenant-aware foreign keys
- Cache namespace
- Queue context
- Export scope
- File ownership

Cross-tenant regression tests are mandatory.

---

# Test Requirements

Refactoring requires tests that prove behavior remains correct.

Depending on scope, include:

- Unit tests
- Feature tests
- Integration tests
- Authorization tests
- Tenant-isolation tests
- Regression tests
- Performance tests
- Migration tests
- Frontend interaction tests
- Accessibility tests

Tests should exist before or alongside the structural change.

---

# Performance

Performance refactoring must be evidence-based.

Use:

- Query plans
- Timings
- Profiling
- Load tests
- Render measurements
- Memory measurements
- Queue metrics

Do not claim performance improvement without measurement.

---

# Dead Code

Dead code should be removed when:

- No supported path uses it.
- Tests confirm safe removal.
- No migration or rollback depends on it.
- No external integration depends on it.
- Documentation is updated.

Do not preserve obsolete code indefinitely “just in case.”

---

# Duplication

Duplication should be removed when the repeated logic represents the same business rule.

Do not force abstraction merely because code looks similar.

Premature abstraction may create stronger coupling than duplication.

---

# Naming Improvements

Renaming is encouraged when it improves clarity.

Renames must be coordinated across:

- Code
- Tests
- Routes
- API documentation
- Database schema
- Event names
- Queue names
- Configuration
- Logs
- Monitoring

Public or persisted names require compatibility planning.

---

# Refactoring Large Modules

Large refactors should be divided into phases.

Example:

```text
Phase 1 — Add tests
Phase 2 — Introduce abstraction
Phase 3 — Migrate callers
Phase 4 — Remove legacy path
Phase 5 — Verify production behavior
```

Each phase should be deployable where practical.

---

# Feature Flags

Feature flags may support safe refactoring when:

- Two implementations must coexist temporarily.
- Rollback must be immediate.
- A gradual rollout is required.
- Production comparison is useful.

Flags must have:

- Owner
- Purpose
- Expiry or removal plan
- Auditability
- Safe default

Temporary flags must not become permanent architecture accidentally.

---

# Compatibility Layers

Compatibility adapters may be introduced temporarily.

They must:

- Have a documented removal plan
- Remain tested
- Avoid hiding permanent architectural debt
- Be removed after migration completes

---

# Rollback

Every risky refactor requires a rollback strategy.

Rollback may include:

- Reverting application code
- Re-enabling a feature flag
- Restoring a previous deployment
- Reversing a migration
- Switching to the legacy adapter
- Restoring data from backup

Rollback must not cause data corruption or duplicate side effects.

---

# Observability

Refactored code should preserve or improve observability.

Verify:

- Logs
- Metrics
- Traces
- Correlation IDs
- Audit events
- Alerts
- Dashboard continuity

A refactor must not remove operational visibility accidentally.

---

# Documentation

Update all affected documentation, including:

- ADRs
- API specifications
- Database standards
- Architecture diagrams
- Runbooks
- Code comments
- User-facing documentation where behavior changes intentionally

Documentation debt is part of technical debt.

---

# Code Review

Reviewers should confirm:

- Behavior is preserved.
- Scope is focused.
- Tests protect critical paths.
- Security controls remain intact.
- Tenant isolation is maintained.
- API contracts remain compatible.
- Performance claims are measured.
- Rollback is possible.
- Documentation is updated.

---

# Commit Strategy

Refactoring commits should remain focused.

Examples:

```text
refactor(auth): centralize session revocation
refactor(learners): extract admission service
refactor(ui): split learner profile component
perf(reports): eliminate repeated assessment queries
```

Avoid mixing broad formatting changes with functional refactoring.

---

# Continuous Integration

CI must verify:

- Formatting passes
- Static analysis passes
- Tests pass
- Security checks pass
- Migration checks pass
- Documentation remains valid
- API contracts remain compatible where applicable

Refactoring does not receive reduced quality gates.

---

# Technical Debt Register

Material technical debt should be documented with:

- Description
- Risk
- Affected area
- Proposed remediation
- Priority
- Owner
- Target milestone

Refactoring work should reference the relevant debt item where one exists.

---

# Definition of Done

A refactor is complete only when:

- Intended structure is improved.
- Approved behavior is preserved.
- Tests pass.
- Security and tenant isolation are verified.
- Performance impact is measured where relevant.
- Documentation is updated.
- Legacy paths are removed or have a removal plan.
- Review is approved.
- CI passes.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 8 — Clean Code
- Rule 10 — Design first. Code second
- Rule 19 — Every security feature is tested
- Rule 27 — Performance is measured, not guessed
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 70 — Rollback tests are mandatory
- Rule 75 — Merge only after acceptance gates pass
- Rule 93 — Access revocation takes effect on the next request
- Rule 94 — Every module follows the approved architecture
- Rule 102 — Security-critical invariants are verified automatically
- Rule 110 — Architecture rules are enforced by CI
- Rule 114 — ShuleOS is continuously hardened

---

# Related Documents

- Coding-Standards.md
- PHP-Laravel-Standards.md
- TypeScript-React-Standards.md
- Code-Review-Checklist.md
- Testing-Conventions.md
- Performance-Guidelines.md
- Clean-Code-Principles.md

---

# Final Standard

Refactoring is a controlled engineering activity that improves the internal quality of ShuleOS without weakening approved behavior, security, tenant isolation, or reliability.

Every refactor must be focused, incremental, test-backed, reviewable, reversible, documented, and aligned with the long-term architecture of the School in the Clouds.
