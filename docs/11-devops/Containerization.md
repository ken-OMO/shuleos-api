# ShuleOS Containerization Standards

> School in Clouds

## Document Information

| Field                | Value                                                             |
| -------------------- | ----------------------------------------------------------------- |
| Document             | Containerization Standards                                        |
| Document ID          | DEVOPS-STD-0006                                                   |
| Version              | 1.0                                                               |
| Status               | Approved                                                          |
| Owner                | Platform Engineering                                              |
| Repository           | `shuleos-api` & `shuleos-web`                                     |
| Effective Date       | 03 August 2026                                                    |
| Related Constitution | Engineering Constitution v1.1                                     |
| Related Standards    | DevOps Standards, Infrastructure as Code, Deployment Architecture |

---

# Purpose

This document defines the containerization standards for the ShuleOS platform.

Containerization provides consistent application execution across development, testing, staging, and production while improving portability, scalability, and deployment reliability.

---

# Philosophy

Containers package applications together with their runtime dependencies.

Applications should behave consistently regardless of where they are executed.

---

# Objectives

Containerization should:

- Improve deployment consistency
- Simplify local development
- Enable horizontal scaling
- Reduce configuration drift
- Support automated deployments
- Improve infrastructure portability

---

# Core Principles

Containers should be:

- Immutable
- Lightweight
- Secure
- Reproducible
- Versioned
- Observable
- Replaceable

---

# Container Strategy

ShuleOS components may execute in separate containers including:

- Laravel API
- Queue Workers
- Scheduler
- Frontend
- Reverse Proxy
- Monitoring components

Each container should have a single primary responsibility.

---

# High-Level Architecture

```text
                 Internet
                     │
                     ▼
             Reverse Proxy
                     │
      ┌──────────────┼──────────────┐
      ▼              ▼              ▼
 Laravel API    Queue Worker    Scheduler
      │
      ▼
 PostgreSQL
```

---

# Docker Images

Container images should:

- Use trusted base images
- Be versioned
- Be reproducible
- Be vulnerability-scanned
- Remain minimal

Unused software should not be included.

---

# Base Images

Base images should:

- Be officially maintained
- Receive security updates
- Match supported runtime versions
- Avoid unnecessary packages

---

# Multi-Stage Builds

Production images should use multi-stage builds where practical.

Benefits include:

- Smaller images
- Faster deployment
- Reduced attack surface
- Cleaner build process

---

# Image Versioning

Images should use semantic versioning where appropriate.

Avoid deploying mutable tags such as:

```text
latest
```

Prefer explicit version tags.

---

# Container Security

Containers should:

- Run as non-root users
- Use read-only filesystems where practical
- Limit Linux capabilities
- Avoid privileged execution
- Minimize installed software

Security should be reviewed regularly.

---

# Secrets

Secrets should never be baked into container images.

Secrets should be injected securely during deployment.

Examples include:

- Database passwords
- JWT secrets
- API keys
- SMTP credentials
- Cloud credentials

---

# Environment Variables

Configuration should be provided through environment variables or approved configuration mechanisms.

Container images should remain environment-independent.

---

# Networking

Containers should communicate through controlled internal networks.

Only required services should be publicly exposed.

---

# Persistent Storage

Persistent data should remain outside containers.

Examples include:

- PostgreSQL data
- Uploaded files
- Reports
- Backups

Containers should remain replaceable.

---

# Health Checks

Every production container should expose health checks.

Health verification may include:

- Application availability
- Database connectivity
- Queue status
- Scheduler readiness

Unhealthy containers should be replaced automatically where supported.

---

# Resource Limits

Containers should define reasonable:

- CPU limits
- Memory limits
- Storage limits
- Restart policies

Resource allocation should be monitored and adjusted using operational metrics.

---

# Logging

Containers should produce structured logs.

Logs should include:

- Timestamp
- Environment
- Service
- Severity
- Tenant context where applicable

Logs should be forwarded to centralized monitoring.

---

# Queue Workers

Queue workers should execute independently from API containers.

Worker scaling should not require scaling API containers.

---

# Scheduler

The scheduler should execute in a dedicated runtime.

Duplicate scheduler execution should be prevented.

---

# Image Registry

Container images should be stored in an approved registry.

Registries should:

- Require authentication
- Retain version history
- Support vulnerability scanning
- Enforce access controls

---

# Image Scanning

Images should be scanned regularly for:

- Known vulnerabilities
- Outdated dependencies
- Unsupported packages
- Security misconfigurations

Critical findings should block production deployment.

---

# Development Containers

Development containers may include debugging tools and additional utilities.

These tools should not be included in production images.

---

# Production Containers

Production containers should:

- Be optimized
- Contain only required software
- Disable debugging
- Minimize attack surface
- Support monitoring

---

# Scaling

Containers should support:

- Horizontal API scaling
- Independent worker scaling
- Independent scheduler deployment
- Rolling updates

Scaling decisions should be based on observed demand.

---

# Disaster Recovery

Container definitions should support rapid infrastructure reconstruction.

Container images should remain reproducible from source control.

---

# Monitoring

Container monitoring should include:

- CPU usage
- Memory usage
- Restart count
- Health status
- Network activity
- Response time

---

# Testing

Containerization should be verified through:

- Build validation
- Startup verification
- Health checks
- Integration tests
- Deployment testing
- Security scanning

---

# Engineering Guidelines

Engineers should:

- Keep images small.
- Use multi-stage builds.
- Avoid running as root.
- Externalize configuration.
- Never store secrets in images.
- Scan images regularly.
- Version images clearly.
- Keep containers replaceable.

---

# Review Checklist

Verify:

- Images are reproducible.
- Base images are supported.
- Containers run as non-root.
- Secrets are externalized.
- Health checks exist.
- Resource limits are configured.
- Logging is centralized.
- Images are scanned.
- Documentation is updated.
- Security review is complete.

---

# Definition of Done

A containerization change is complete only when:

- Images build successfully.
- Security scans pass.
- Health checks succeed.
- Secrets remain external.
- Resource limits are defined.
- Monitoring is configured.
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
- Infrastructure-as-Code.md
- Environment-Management.md
- Deployment-Architecture.md
- Scaling-Strategy.md
- Monitoring-and-Observability.md

---

# Final Standard

Every ShuleOS container must be secure, reproducible, lightweight, observable, and independently deployable.

Containerization enables the School in the Clouds to scale reliably while maintaining operational consistency, security, and deployment confidence across all environments.
