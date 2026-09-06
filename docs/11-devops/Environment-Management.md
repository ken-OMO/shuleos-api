# ShuleOS Environment Management Standards

> School in Clouds

## Document Information

| Field                | Value                                                    |
| -------------------- | -------------------------------------------------------- |
| Document             | Environment Management Standards                         |
| Document ID          | DEVOPS-STD-0004                                          |
| Version              | 1.0                                                      |
| Status               | Approved                                                 |
| Owner                | Platform Engineering                                     |
| Repository           | `shuleos-api` & `shuleos-web`                            |
| Effective Date       | 03 August 2026                                           |
| Related Constitution | Engineering Constitution v1.1                            |
| Related Standards    | DevOps Standards, CI/CD Pipeline, Infrastructure as Code |

---

# Purpose

This document defines how ShuleOS environments are created, configured, secured, maintained, and promoted throughout the software delivery lifecycle.

It ensures that development, testing, staging, and production remain isolated, predictable, and suitable for their intended purpose.

---

# Philosophy

Each environment should have a clear purpose and should behave consistently with the environment that follows it.

Environment differences must be intentional, documented, and controlled.

---

# Supported Environments

ShuleOS supports:

- Development
- Testing
- Staging
- Production

Additional temporary environments may be created for review, performance testing, or incident investigation.

---

# Development Environment

The development environment supports local engineering work.

It may include:

- Local PostgreSQL
- Local API server
- Local frontend server
- Test email or SMS adapters
- Debugging tools
- Seeded development data

Development should never connect directly to production services unless explicitly approved.

---

# Testing Environment

The testing environment supports automated verification.

It should provide:

- Isolated test databases
- Deterministic test data
- Mocked external services
- Automated reset capability
- CI compatibility

Tests should never depend on shared production data.

---

# Staging Environment

Staging validates release candidates before production.

Staging should closely resemble production in:

- Application configuration
- Infrastructure layout
- Database engine
- Queue behaviour
- Storage strategy
- Monitoring

Staging data should remain synthetic or properly masked.

---

# Production Environment

Production serves real schools and users.

Production requires:

- Restricted access
- Secure configuration
- Monitoring
- Backups
- Audit logging
- Change control
- Incident response readiness

Production should never be used for experimentation.

---

# Environment Isolation

Each environment should have separate:

- Databases
- Storage
- Cache
- Queue infrastructure
- Credentials
- External service configuration
- Monitoring context

Cross-environment access should be prohibited unless explicitly approved.

---

# Configuration Management

Configuration should be externalized from application code.

Environment-specific values may include:

- Database connection
- Application URL
- Cache configuration
- Queue configuration
- Mail configuration
- SMS configuration
- Storage configuration
- Logging level

Configuration should remain version-aware but secret-free.

---

# Environment Variables

Environment variables should:

- Use consistent naming
- Be documented
- Avoid unnecessary duplication
- Have safe defaults where appropriate
- Be validated at startup

Required variables should fail fast when missing.

---

# Secret Separation

Secrets must remain environment-specific.

Production secrets must never be reused in development or testing.

---

# Database Separation

Each environment must use a dedicated database.

Production data must never be copied into lower environments without:

- Approval
- Data masking
- Privacy review
- Security controls

---

# Storage Separation

Uploaded files, reports, and exports should remain isolated by environment.

Production files must not be exposed in development or testing.

---

# Cache Separation

Cache keys and infrastructure should include environment context.

One environment must never read cached values from another.

---

# Queue Separation

Queue names and workers should remain environment-specific.

Testing or staging jobs must never be processed by production workers.

---

# External Services

External integrations should use environment-specific credentials and endpoints.

Examples:

- SMS gateways
- Email providers
- Payment gateways
- Cloud storage
- Monitoring services

Non-production environments should use sandbox or mock services where available.

---

# Feature Flags

Feature flags may be used to control rollout.

Flags should:

- Be documented
- Have an owner
- Have an expiration plan
- Be environment-aware
- Avoid becoming permanent configuration debt

---

# Data Masking

When production-like data is required outside production, sensitive data must be masked.

Examples:

- Names
- Phone numbers
- Email addresses
- Identification numbers
- Financial records

Masking must be irreversible where practical.

---

# Environment Provisioning

Environments should be provisioned through Infrastructure as Code where practical.

Provisioning should be:

- Repeatable
- Reviewable
- Auditable
- Recoverable

---

# Environment Promotion

Changes should move through environments in this order:

```text
Development
    ↓
Testing
    ↓
Staging
    ↓
Production
```

Promotion should use the same verified artifact where possible.

---

# Validation Before Promotion

Before promoting a release, verify:

- Build success
- Automated tests
- Security checks
- Configuration validation
- Database migration readiness
- Health checks
- Rollback readiness

---

# Access Control

Environment access should follow least privilege.

Production access should be limited, logged, and reviewed regularly.

---

# Monitoring

Each environment should provide appropriate monitoring.

Production requires the highest monitoring and alerting coverage.

---

# Logging

Logs should identify:

- Environment
- Application
- Component
- Tenant where applicable
- Timestamp
- Severity

Logs from different environments must remain separated.

---

# Configuration Drift

Environment drift should be detected and corrected.

Infrastructure as Code and automated validation should reduce unintended differences.

---

# Environment Reset

Testing and temporary environments should support safe reset and recreation.

Production resets are prohibited.

---

# Temporary Environments

Preview or temporary environments may be created for:

- Pull requests
- Release validation
- Performance testing
- Demonstrations

Temporary environments should have automatic expiration where practical.

---

# Backup and Recovery

Production requires full backup and recovery coverage.

Staging may maintain limited backups for release validation.

Development and testing environments should be reproducible from code and seed data.

---

# Security

Environment management should enforce:

- Separate credentials
- Restricted network access
- Secure transport
- Secret protection
- Audit logging
- Least privilege

---

# Engineering Guidelines

Engineers should:

- Never use production as a test environment.
- Keep environments isolated.
- Document configuration requirements.
- Use masked data outside production.
- Promote verified artifacts.
- Avoid manual drift.
- Review environment access regularly.

---

# Governance

Changes to environment architecture require:

- DevOps review
- Security review
- Documentation update
- Validation in a non-production environment

---

# Review Checklist

Verify:

- Environment purpose is clear.
- Databases are isolated.
- Storage is isolated.
- Queues are isolated.
- Secrets are environment-specific.
- Production access is restricted.
- Data masking is applied where required.
- Promotion checks pass.
- Monitoring is configured.
- Documentation is current.

---

# Definition of Done

An environment-management change is complete only when:

- Isolation is preserved.
- Configuration is documented.
- Secrets are protected.
- Provisioning is repeatable.
- Access controls are verified.
- Monitoring is updated.
- Promotion behaviour is tested.
- Documentation is updated.
- Review is approved.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 66 — Every feature has tests

---

# Related Documents

- DevOps-Standards.md
- CI-CD-Pipeline.md
- Infrastructure-as-Code.md
- Secrets-and-Configuration.md
- Containerization.md
- Monitoring-and-Observability.md
- Backup-and-Retention.md

---

# Final Standard

Every ShuleOS environment must be isolated, secure, reproducible, and appropriate for its role in the delivery lifecycle.

Environment management protects production, reduces configuration drift, enables reliable releases, and supports the safe operation of the School in the Clouds.
