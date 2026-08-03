# ShuleOS Logging Standards

> School in Clouds

## Document Information

| Field                | Value                                                              |
| -------------------- | ------------------------------------------------------------------ |
| Document             | Logging Standards                                                  |
| Document ID          | DEVOPS-STD-0008                                                    |
| Version              | 1.0                                                                |
| Status               | Approved                                                           |
| Owner                | Platform Engineering                                               |
| Repository           | `shuleos-api` & `shuleos-web`                                      |
| Effective Date       | 03 August 2026                                                     |
| Related Constitution | Engineering Constitution v1.1                                      |
| Related Standards    | DevOps Standards, Monitoring and Observability, Security Standards |

---

# Purpose

This document defines the logging standards for the ShuleOS platform.

Consistent logging enables troubleshooting, auditing, security monitoring, performance analysis, and operational visibility while protecting sensitive information and tenant privacy.

---

# Philosophy

Logs should explain what happened, when it happened, where it happened, and why it happened.

Logging should support engineers without exposing confidential information.

---

# Objectives

Logging should:

- Support troubleshooting
- Improve observability
- Detect security incidents
- Enable auditing
- Support compliance
- Assist performance analysis
- Preserve operational history

---

# Core Principles

Logs should be:

- Structured
- Consistent
- Searchable
- Timestamped
- Tenant-aware
- Privacy-conscious
- Secure

---

# Log Categories

ShuleOS uses multiple log categories.

## Application Logs

Application behaviour including:

- Requests
- Business events
- Validation failures
- API execution
- Background jobs

---

## Security Logs

Security-related activities including:

- Login attempts
- Authentication failures
- Authorization failures
- Password resets
- Role changes
- Permission changes

---

## Audit Logs

Business actions including:

- Learner admission
- Assessment publication
- Fee payment
- Report generation
- Record modification

Audit logs support accountability.

---

## Infrastructure Logs

Infrastructure events including:

- Server startup
- Deployment
- Container restart
- Resource usage
- Service failures

---

## Database Logs

Database activity including:

- Connection failures
- Slow queries
- Migration execution
- Backup status

---

## Queue Logs

Queue processing including:

- Job execution
- Failed jobs
- Retry attempts
- Processing duration

---

# Log Levels

Standard levels include:

- DEBUG
- INFO
- WARNING
- ERROR
- CRITICAL

Levels should be used consistently.

---

# Structured Logging

Structured logging should be preferred.

Example:

```json
{
    "timestamp": "2026-08-03T14:30:15Z",
    "environment": "production",
    "service": "shuleos-api",
    "tenant_id": "school_123",
    "user_id": 42,
    "request_id": "req_abc123",
    "level": "INFO",
    "message": "Learner admitted successfully"
}
```

---

# Timestamps

Logs should include:

- UTC timestamp
- Time zone awareness
- High precision where supported

Consistent timestamps simplify incident analysis.

---

# Correlation IDs

Every request should include a unique request or correlation identifier.

Related logs should reference the same identifier.

---

# Tenant Awareness

Where applicable, logs should identify:

- Tenant
- School
- User
- Request

Tenant context improves troubleshooting while preserving isolation.

---

# User Context

When appropriate, logs may include:

- User ID
- Role
- Request origin
- Session identifier

Sensitive personal information should be minimized.

---

# Error Logging

Errors should record:

- Error type
- Location
- Correlation ID
- Severity
- Relevant context

Errors should support diagnosis without exposing secrets.

---

# Exception Logging

Unhandled exceptions should be logged automatically.

Exception logs should support debugging while remaining safe for production.

---

# Sensitive Data Protection

Logs must never expose:

- Passwords
- JWT secrets
- API keys
- Database credentials
- Payment credentials
- Encryption keys
- Personal secrets

Sensitive values should be redacted.

---

# Privacy

Logs should respect:

- Tenant isolation
- Personal privacy
- Data protection requirements

Logging must not become a source of data leakage.

---

# Retention

Logs should be retained according to operational and regulatory requirements.

Retention periods should balance investigative needs with storage costs.

---

# Rotation

Log rotation should prevent uncontrolled storage growth.

Archived logs should remain accessible for authorized investigations.

---

# Centralized Logging

Production logs should be aggregated into a centralized logging solution where practical.

Centralized logging improves search, correlation, and incident response.

---

# Searchability

Logs should support searching by:

- Timestamp
- Tenant
- User
- Request ID
- Service
- Severity
- Component

---

# Monitoring Integration

Monitoring systems should consume log events where appropriate.

Critical log events should trigger alerts.

---

# Deployment Logging

Deployments should log:

- Version
- Timestamp
- Environment
- Operator or automation
- Success or failure
- Rollback events

---

# Queue Logging

Queue workers should log:

- Job start
- Job completion
- Failure
- Retry
- Execution time

---

# Security Monitoring

Security logs should support detection of:

- Brute-force attacks
- Permission abuse
- Suspicious activity
- Configuration changes
- Unauthorized access

---

# Performance Logging

Performance logging may include:

- Slow requests
- Slow queries
- Long-running jobs
- API latency

Performance trends support optimization.

---

# Backup Protection

Archived logs should remain:

- Protected
- Encrypted where appropriate
- Access-controlled
- Recoverable

---

# Testing

Logging should be validated through:

- Integration testing
- Deployment testing
- Security testing
- Failure simulation
- Monitoring verification

---

# Engineering Guidelines

Engineers should:

- Log meaningful events.
- Use appropriate log levels.
- Include correlation IDs.
- Avoid sensitive information.
- Keep messages consistent.
- Review logging during code reviews.

---

# Review Checklist

Verify:

- Logs are structured.
- Timestamps are consistent.
- Correlation IDs exist.
- Sensitive values are redacted.
- Tenant context is preserved.
- Log rotation is configured.
- Retention policy exists.
- Monitoring consumes important logs.
- Documentation is updated.
- Review is complete.

---

# Definition of Done

A logging change is complete only when:

- Structured logging is used.
- Sensitive information is protected.
- Correlation IDs are present.
- Monitoring integration is verified.
- Documentation is updated.
- Testing succeeds.
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
- Rule 107 — Production systems are observable

---

# Related Documents

- DevOps-Standards.md
- Monitoring-and-Observability.md
- Queue-and-Worker-Management.md
- Backup-and-Retention.md
- Security Standards

---

# Final Standard

Every ShuleOS component must produce structured, secure, and actionable logs that support troubleshooting, auditing, monitoring, and operational excellence without compromising tenant privacy or platform security.
