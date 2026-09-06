# ShuleOS Production Runbook

> School in Clouds

## Document Information

| Field                | Value                                                  |
| -------------------- | ------------------------------------------------------ |
| Document             | Production Runbook                                     |
| Document ID          | OPS-STD-0002                                           |
| Version              | 1.0                                                    |
| Status               | Approved                                               |
| Owner                | Platform Operations                                    |
| Repository           | `shuleos-api` & `shuleos-web`                          |
| Effective Date       | 03 August 2026                                         |
| Related Constitution | Engineering Constitution v1.1                          |
| Related Standards    | Operations Manual, DevOps Standards, Incident Response |

---

# Purpose

This runbook defines the standard operational procedures for managing the ShuleOS production environment.

It provides repeatable steps for monitoring, maintaining, validating, and recovering production services while minimizing disruption to schools.

---

# Philosophy

Production systems should remain stable, observable, recoverable, and predictable.

Operational activities should prioritize service continuity and data integrity.

---

# Operational Objectives

Production operations should:

- Maintain service availability
- Protect tenant data
- Detect failures early
- Recover quickly
- Minimize operational risk
- Ensure consistent procedures

---

# Production Environment

The production environment includes:

- Laravel API
- Frontend application
- PostgreSQL database
- Queue workers
- Scheduler
- Cache services
- Monitoring platform
- Logging infrastructure

---

# Daily Operational Checks

Verify:

- Platform availability
- Application health
- Database connectivity
- Queue processing
- Scheduler execution
- Cache health
- Storage capacity
- Backup completion
- Monitoring dashboards
- Security alerts

---

# Deployment Verification

After every deployment confirm:

- Deployment completed successfully
- Application starts correctly
- Database migrations succeed
- Health checks pass
- Queue workers restart correctly
- Scheduler operates normally
- Monitoring reports healthy status

---

# Health Checks

Verify:

- API health endpoint
- Database connectivity
- Cache connectivity
- Queue availability
- Storage availability
- External service connectivity where applicable

Failures should trigger incident procedures.

---

# Database Operations

Routine database tasks include:

- Backup verification
- Storage review
- Query performance review
- Slow query investigation
- Migration validation
- Connection monitoring

---

# Queue Operations

Verify:

- Workers running
- Queue backlog acceptable
- Failed jobs reviewed
- Retry processing healthy
- Queue throughput acceptable

---

# Scheduler Operations

Verify:

- Scheduled tasks execute successfully
- No duplicate execution
- No missed schedules
- Execution duration remains acceptable

---

# Cache Operations

Review:

- Cache health
- Cache utilization
- Cache hit ratio
- Cache invalidation events

Flush production cache only through approved procedures.

---

# Logging

Review:

- Critical errors
- Warning trends
- Authentication failures
- Queue failures
- Infrastructure events

Sensitive information must never be exposed through logs.

---

# Monitoring

Production dashboards should include:

- Availability
- Response time
- Error rate
- CPU utilization
- Memory utilization
- Storage utilization
- Queue status
- Database health

---

# Backup Verification

Daily verification should confirm:

- Successful backups
- Backup integrity
- Storage availability
- Retention compliance

Backup failures require immediate investigation.

---

# Incident Escalation

Operational incidents should follow documented escalation procedures.

Escalation should consider:

- Severity
- Business impact
- Tenant impact
- Recovery complexity

---

# Rollback Procedures

Rollback should be available for deployments that introduce unacceptable operational risk.

Rollback planning should include:

- Application rollback
- Database considerations
- Configuration restoration
- Validation steps

---

# Planned Maintenance

Maintenance activities should include:

- Security updates
- Dependency updates
- Infrastructure maintenance
- Database optimization
- Certificate renewal

Where practical, schools should be notified in advance.

---

# Emergency Operations

Emergency procedures should prioritize:

- Service restoration
- Data protection
- Tenant isolation
- Security
- Clear communication

---

# Operational Communication

Communicate:

- Planned maintenance
- Major incidents
- Recovery progress
- Service restoration
- Operational risks

Communication should remain accurate and timely.

---

# Documentation

Operational documentation should remain current and include:

- Runbooks
- Recovery guides
- Escalation contacts
- Architecture references
- Maintenance procedures

---

# Testing

Operational readiness should be validated through:

- Deployment testing
- Recovery testing
- Backup restoration
- Health check validation
- Incident simulations

---

# Engineering Guidelines

Operations engineers should:

- Follow documented procedures.
- Protect production stability.
- Verify changes after deployment.
- Monitor continuously.
- Escalate incidents promptly.
- Document operational actions.

---

# Review Checklist

Verify:

- Production health confirmed.
- Monitoring operational.
- Backups verified.
- Queue workers healthy.
- Scheduler operational.
- Security reviewed.
- Documentation updated.
- Recovery procedures validated.
- Communication completed.
- Review approved.

---

# Definition of Done

A production operation is complete only when:

- Platform health is verified.
- Monitoring confirms stability.
- Operational documentation is updated.
- Recovery impact assessed.
- No unresolved critical issues remain.
- Review is approved.

---

# Constitution Compliance

This runbook reinforces:

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

- Operations-Manual.md
- Incident-Response.md
- Maintenance-Procedures.md
- Backup-and-Restore.md
- Monitoring-Procedures.md
- Security-Operations.md

---

# Final Standard

Every production activity within ShuleOS must follow documented, repeatable procedures that prioritize availability, security, recoverability, and tenant trust.

Operational consistency ensures the School in the Clouds remains dependable for every institution it serves.
