# ShuleOS Deployment Architecture

> School in Clouds

## Document Information

| Field                | Value                                                         |
| -------------------- | ------------------------------------------------------------- |
| Document             | Deployment Architecture                                       |
| Document ID          | ARCH-STD-0010                                                 |
| Version              | 1.0                                                           |
| Status               | Approved                                                      |
| Owner                | Platform Engineering                                          |
| Repository           | `shuleos-api` & `shuleos-web`                                 |
| Effective Date       | 03 August 2026                                                |
| Related Constitution | Engineering Constitution v1.1                                 |
| Related Standards    | System Architecture, Security Standards, Deployment Standards |

---

# Purpose

This document defines the deployment architecture for the ShuleOS platform.

It establishes how the platform is deployed, configured, monitored, secured, and maintained across all supported environments while ensuring reliability, scalability, and tenant isolation.

---

# Philosophy

Deployments should be:

- Reliable
- Repeatable
- Secure
- Automated
- Observable
- Reversible

Production deployments should never depend upon manual configuration changes.

---

# Deployment Environments

ShuleOS supports the following environments:

- Development
- Testing
- Staging
- Production

Each environment should remain isolated.

---

# High-Level Deployment

```text
Internet
      │
      ▼
 Load Balancer
      │
      ▼
────────────────────────────
│      Application Nodes    │
│    Laravel + PHP-FPM      │
────────────────────────────
      │
      ├────────────► Queue Workers
      │
      ├────────────► Scheduler
      │
      ▼
 PostgreSQL Database
      │
      ▼
 Backups
```

---

# Infrastructure Components

Production infrastructure includes:

- Linux servers
- Nginx
- PHP-FPM
- Laravel
- PostgreSQL
- Queue workers
- Scheduler
- File storage
- Monitoring services

---

# Application Deployment

Application deployment should include:

- Source retrieval
- Dependency installation
- Configuration validation
- Cache generation
- Database migrations
- Queue restart
- Health verification

---

# Frontend Deployment

Frontend deployment should:

- Build optimized assets
- Generate static resources
- Validate environment configuration
- Publish production artifacts

---

# Backend Deployment

Backend deployment should:

- Install Composer dependencies
- Optimize autoloading
- Cache configuration
- Cache routes
- Cache events
- Restart workers

---

# Database Migrations

Database migrations should:

- Be version controlled
- Be repeatable
- Be reversible where practical
- Preserve tenant data

Destructive changes require special review.

---

# Queue Workers

Queue workers should restart safely after deployment.

Long-running jobs should complete without data loss whenever possible.

---

# Scheduler

The Laravel scheduler should execute automatically through the operating system scheduler.

Scheduled jobs should remain tenant-aware.

---

# File Storage

Production storage should include:

- Learner photos
- Staff photos
- Reports
- Imports
- Exports

Storage should remain secure and tenant-aware.

---

# Configuration Management

Configuration should be environment-specific.

Environment configuration should never be committed to source control.

---

# Secret Management

Secrets include:

- Database credentials
- API keys
- SMTP credentials
- JWT secrets

Secrets should be stored securely outside the application source.

---

# CI/CD Pipeline

Deployment pipeline stages include:

- Static analysis
- Automated testing
- Security checks
- Build
- Deployment
- Health verification

Failed stages prevent deployment.

---

# Zero-Downtime Deployment

Where practical, deployments should minimize user disruption through rolling or zero-downtime deployment techniques.

---

# Rollback Strategy

Rollback procedures should allow rapid recovery from failed deployments.

Rollback plans should be documented and tested periodically.

---

# Health Checks

Production health verification should include:

- Application availability
- Database connectivity
- Queue health
- Scheduler status
- Storage accessibility

---

# Monitoring

Production monitoring should track:

- Response times
- Error rates
- Queue performance
- Database performance
- Resource utilization

Monitoring should support proactive issue detection.

---

# Logging

Deployment activities should be logged.

Logs should record:

- Deployment version
- Deployment time
- Operator or automation
- Success or failure
- Rollback actions

---

# Security

Deployment security should include:

- Secure transport
- Restricted access
- Principle of least privilege
- Environment isolation
- Secret protection

---

# Scalability

Infrastructure should support:

- Horizontal application scaling
- Queue worker scaling
- Database optimization
- Load balancing

Growth should require minimal architectural changes.

---

# High Availability

Critical production services should minimize single points of failure wherever practical.

Availability targets should be monitored continuously.

---

# Backup Integration

Deployment procedures should verify that backup mechanisms remain operational after significant infrastructure changes.

---

# Testing

Deployment processes should be verified through:

- Deployment rehearsals
- Staging validation
- Smoke tests
- Health checks

---

# Engineering Guidelines

Engineers should:

- Automate deployments.
- Validate deployments before release.
- Monitor production continuously.
- Protect secrets.
- Keep deployments reproducible.
- Document infrastructure changes.

---

# Architecture Governance

Changes affecting deployment require:

- Architecture review
- Security review
- Documentation update
- Deployment testing

---

# Constitution Compliance

This architecture reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 66 — Every feature has tests

---

# Related Documents

- System-Architecture.md
- Data-Flow.md
- Caching-Architecture.md
- Disaster-Recovery-Architecture.md
- Architecture-Decision-Records.md

---

# Final Standard

Every ShuleOS deployment must be secure, repeatable, observable, and recoverable.

The deployment architecture ensures that the School in the Clouds can evolve safely through automated, well-governed releases while preserving platform stability, tenant isolation, and service availability.
