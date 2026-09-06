# ShuleOS CI/CD Pipeline

> School in Clouds

## Document Information

| Field                | Value                                                        |
| -------------------- | ------------------------------------------------------------ |
| Document             | CI/CD Pipeline                                               |
| Document ID          | DEVOPS-STD-0002                                              |
| Version              | 1.0                                                          |
| Status               | Approved                                                     |
| Owner                | Platform Engineering                                         |
| Repository           | `shuleos-api` & `shuleos-web`                                |
| Effective Date       | 03 August 2026                                               |
| Related Constitution | Engineering Constitution v1.1                                |
| Related Standards    | DevOps Standards, Testing Standards, Deployment Architecture |

---

# Purpose

This document defines the Continuous Integration and Continuous Delivery (CI/CD) pipeline used by ShuleOS.

The pipeline ensures that every code change is validated, tested, reviewed, and deployed consistently while protecting production stability.

---

# Philosophy

Every change should be automatically verified before reaching production.

Automation improves quality, consistency, repeatability, and deployment confidence.

---

# Objectives

The CI/CD pipeline should:

- Detect defects early
- Prevent broken builds
- Enforce coding standards
- Verify security
- Execute automated tests
- Produce deployable artifacts
- Support repeatable deployments

---

# Pipeline Overview

```text
Developer
      │
      ▼
Git Commit
      │
      ▼
Pull Request
      │
      ▼
CI Pipeline
      │
      ├── Formatting
      ├── Static Analysis
      ├── Security Scan
      ├── Backend Tests
      ├── Frontend Tests
      ├── Build
      └── Artifact Creation
      │
      ▼
Review & Approval
      │
      ▼
Staging Deployment
      │
      ▼
Smoke Tests
      │
      ▼
Production Approval
      │
      ▼
Production Deployment
      │
      ▼
Health Verification
```

---

# Continuous Integration

Every Pull Request should automatically execute:

- Code formatting
- Static analysis
- Dependency validation
- Security scanning
- Backend unit tests
- Feature tests
- Frontend tests
- Build verification

A failed stage blocks merging.

---

# Source Control

All changes should:

- Use feature branches
- Be reviewed through Pull Requests
- Pass automated checks
- Preserve clean commit history

Direct commits to the production branch are discouraged.

---

# Backend Validation

Backend validation includes:

- Composer install
- Pint formatting
- Static analysis
- PHPUnit tests
- API contract verification

---

# Frontend Validation

Frontend validation includes:

- Dependency installation
- TypeScript checks
- Linting
- Build validation
- Component tests

---

# Security Validation

Automated security validation should include:

- Dependency scanning
- Secret detection
- Configuration review
- Security policy checks

Critical vulnerabilities block release.

---

# Build Stage

Successful validation produces deployable artifacts.

Builds should be:

- Repeatable
- Versioned
- Immutable where practical

---

# Artifact Management

Artifacts should include:

- Build version
- Build timestamp
- Source revision
- Build metadata

Artifacts should remain traceable to source code.

---

# Staging Deployment

Every release should be deployed to staging before production.

Staging should closely resemble production.

---

# Smoke Testing

Smoke tests verify:

- Application startup
- Login
- Database connectivity
- API availability
- Queue processing
- Scheduler operation

---

# Production Approval

Production deployment requires:

- Successful CI
- Successful staging validation
- Required approvals
- Deployment readiness confirmation

---

# Production Deployment

Production deployment should include:

- Configuration validation
- Database migration
- Cache refresh
- Queue restart
- Health checks

---

# Database Migrations

Migrations should:

- Be version controlled
- Preserve tenant data
- Be reviewed
- Be tested in staging

---

# Rollback

Every deployment should include a rollback strategy.

Rollback procedures should be documented and tested.

---

# Post-Deployment Verification

Verify:

- Application health
- Database health
- Queue health
- Scheduler
- Authentication
- Monitoring
- Logs

---

# Monitoring

Deployment monitoring should include:

- Deployment duration
- Build success rate
- Test success rate
- Deployment failures
- Rollback frequency

---

# Notifications

Pipeline notifications should inform the engineering team of:

- Successful builds
- Failed builds
- Failed deployments
- Rollbacks
- Security failures

---

# Multi-Tenant Safety

CI/CD processes must preserve:

- Tenant isolation
- Database integrity
- Authorization rules
- Security controls

---

# Engineering Guidelines

Engineers should:

- Keep pipelines automated.
- Fix failing builds immediately.
- Keep deployments repeatable.
- Protect production.
- Review deployment failures.
- Continuously improve automation.

---

# Architecture Governance

Pipeline changes require:

- DevOps review
- Security review
- Documentation update
- Validation in staging

---

# Constitution Compliance

This pipeline reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 66 — Every feature has tests

---

# Related Documents

- DevOps-Standards.md
- Deployment-Architecture.md
- Testing Standards
- Security Standards
- Infrastructure-as-Code.md

---

# Final Standard

Every ShuleOS change must pass an automated CI/CD pipeline before deployment.

The pipeline provides a consistent, secure, and reliable path from source code to production, ensuring that the School in the Clouds evolves through verified, repeatable, and well-governed software delivery.
