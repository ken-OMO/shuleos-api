# Contributing to ShuleOS

> **School in Clouds**

---

# Document Information

| Field            | Value                |
| ---------------- | -------------------- |
| **Document**     | Contribution Guide   |
| **Document ID**  | DOC-0003             |
| **Version**      | 1.0                  |
| **Status**       | Approved             |
| **Owner**        | Platform Engineering |
| **Repository**   | shuleos-api          |
| **Created**      | 22 July 2026         |
| **Last Updated** | 22 July 2026         |
| **Review Cycle** | Every Major Release  |

---

# Welcome

Thank you for contributing to ShuleOS.

ShuleOS is engineered as a long-term educational platform where security, maintainability, reliability and scalability are fundamental requirements.

Every contribution should improve the platform without compromising these principles.

---

# Engineering Philosophy

ShuleOS follows one guiding principle.

> **No code enters ShuleOS because it works. Code enters ShuleOS because it has been proven secure, scalable, performant, tenant-safe, maintainable and reliable.**

This principle governs every commit, review and release.

---

# Before You Begin

Every contributor should become familiar with:

- README.md
- docs/README.md
- Engineering Constitution
- Architecture Decision Records (ADRs)
- SECURITY.md

Understanding the platform is required before modifying it.

---

# Development Workflow

Every feature follows the same engineering lifecycle.

```
Requirement
      │
      ▼
Research
      │
      ▼
Architecture
      │
      ▼
Implementation
      │
      ▼
Testing
      │
      ▼
Documentation
      │
      ▼
Pull Request
      │
      ▼
Review
      │
      ▼
Merge
```

No stage may be skipped.

---

# Branch Strategy

## Production

```
main
```

Production-ready code only.

---

## Integration

```
develop
```

Primary development branch.

---

## Feature Branches

```
feature/*
```

Examples

```
feature/lesson-plans

feature/report-cards

feature/finance-engine
```

---

## Hardening

```
hardening/*
```

Security, architecture and performance improvements.

Examples

```
hardening/authentication

hardening/tenant-security

hardening/query-performance
```

---

## Documentation

```
docs/*
```

Documentation only.

---

## Bug Fixes

```
fix/*
```

Examples

```
fix/report-card-rounding

fix/payment-validation
```

---

# Commit Standards

Commits should describe one logical change.

Good examples:

```
feat(auth): add email OTP verification

feat(finance): implement invoice allocation

fix(tenant): prevent cross-school access

docs(readme): establish README v1.0

test(auth): add password reset tests

chore(deps): update guzzle security patches
```

Avoid:

```
Update

Changes

Fix

Work

Done
```

---

# Pull Request Requirements

Every Pull Request must include:

## Summary

What changed?

---

## Reason

Why was the change necessary?

---

## Testing

Explain how the change was verified.

---

## Documentation

List updated documentation.

---

## Engineering Constitution

Identify any relevant Constitution rules.

---

## Breaking Changes

Describe any incompatible changes.

---

# Required Quality Gates

Before opening a Pull Request, verify:

- Composer validates successfully.
- Dependency audit passes.
- Laravel Pint passes.
- PHPUnit passes.
- No debugging code remains.
- Documentation updated.
- Architecture preserved.
- Tenant isolation maintained.

---

# Code Review Checklist

Every reviewer evaluates:

## Security

- Authentication
- Authorization
- Input validation
- IDOR prevention
- Secrets handling

---

## Multi-Tenancy

- Tenant isolation
- school_id usage
- Cross-school protection
- Query scoping

---

## Database

- Indexes
- Constraints
- Foreign keys
- Performance

---

## Performance

- Query efficiency
- Caching
- Queue usage
- Memory usage

---

## Maintainability

- Naming
- Simplicity
- Consistency
- Documentation

---

## Testing

- Unit tests
- Feature tests
- Regression tests

---

# Definition of Done

A feature is complete only when:

- Requirements implemented.
- Tests passing.
- Documentation updated.
- Security reviewed.
- Performance reviewed.
- Engineering Constitution satisfied.
- Pull Request approved.

Working code alone is not considered complete.

---

# Documentation Standards

Every new feature should update documentation where necessary.

Examples include:

- API reference
- Architecture
- README
- Security documentation
- Database documentation
- ADRs

Documentation is developed together with code.

---

# Testing Expectations

Every feature should include appropriate automated tests.

Testing includes:

- Unit Tests
- Feature Tests
- Authorization Tests
- Tenant Isolation Tests
- Validation Tests
- Regression Tests

Production bugs should result in regression tests whenever practical.

---

# Security Expectations

Contributors must:

- Never commit secrets.
- Never bypass authorization.
- Never disable validation.
- Never weaken tenant isolation.
- Never ignore security review findings.

Security concerns should be reported privately.

---

# Architecture

Contributors should preserve the existing architecture.

Avoid introducing:

- Duplicate services
- Circular dependencies
- Business logic inside controllers
- Hidden side effects
- Unreviewed architectural patterns

Major architectural changes require an ADR.

---

# Performance

Every contribution should consider:

- Query count
- Index usage
- Queue suitability
- Memory allocation
- Scalability

Performance is reviewed alongside correctness.

---

# Engineering Culture

ShuleOS values:

- Integrity
- Simplicity
- Consistency
- Accountability
- Documentation
- Continuous Improvement

We optimise for long-term maintainability rather than short-term convenience.

---

# Contributor Promise

By contributing to ShuleOS, contributors agree to:

- Respect the Engineering Constitution.
- Maintain documentation.
- Preserve tenant isolation.
- Prioritize security.
- Write maintainable code.
- Accept constructive code review.
- Improve the platform for future contributors.

---

# Final Principle

Every review asks one question before approval.

> **Would I confidently deploy this change to a school with 10,000 learners tomorrow?**

If the answer is **No**, the Pull Request is not ready.

---

<div align="center">

## ShuleOS

### School in Clouds

Building secure, scalable and intelligent technology for modern education.

</div>
