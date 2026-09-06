# ShuleOS Multi-Tenant Testing Standards

> School in Clouds

## Document Information

| Field                | Value                                                                                                |
| -------------------- | ---------------------------------------------------------------------------------------------------- |
| Document             | Multi-Tenant Testing Standards                                                                       |
| Document ID          | TEST-STD-0008                                                                                        |
| Version              | 1.0                                                                                                  |
| Status               | Approved                                                                                             |
| Owner                | Platform Engineering                                                                                 |
| Repository           | `shuleos-api`                                                                                        |
| Effective Date       | 03 August 2026                                                                                       |
| Related Constitution | Engineering Constitution v1.1                                                                        |
| Related Standards    | Testing Standards, Backend Testing Standards, Database Testing Standards, Security Testing Standards |

---

# Purpose

This document establishes the mandatory standards for testing ShuleOS's multi-tenant architecture.

Every school operates as an independent tenant while sharing the same application infrastructure. Testing must ensure complete logical isolation between tenants.

---

# Scope

Multi-tenant testing applies to:

- Authentication
- Authorization
- School resolution
- Tenant context
- Database queries
- API endpoints
- Reports
- Notifications
- Background jobs
- File storage
- Caching
- Scheduled tasks

---

# Philosophy

Tenant isolation is non-negotiable.

No user should ever access, modify, or infer another school's data unless explicitly authorized by platform-level functionality.

---

# Core Principles

Tenant tests should verify:

- Isolation
- Ownership
- Authorization
- Consistency
- Reliability
- Security

---

# Tenant Resolution

Verify:

- Correct school identification
- Tenant context initialization
- Invalid tenant handling
- Missing tenant handling

Every request should execute within the correct tenant context.

---

# Authentication

Verify:

- Users authenticate only within their school
- Platform Owner access behaves correctly
- Expired sessions
- Invalid credentials
- Token isolation

Authentication should never cross tenant boundaries.

---

# Authorization

Verify:

- Role permissions
- School ownership
- Administrative privileges
- Resource ownership

Permissions should remain tenant-aware.

---

# Database Isolation

Verify:

- Queries return only tenant records
- Cross-school queries are impossible
- Foreign keys remain tenant-consistent
- Shared tables behave correctly

Database isolation failures block release.

---

# API Testing

Every tenant-aware endpoint should verify:

- Tenant filtering
- Resource ownership
- Unauthorized access
- Missing tenant context
- Invalid tenant identifiers

---

# Resource Ownership

Verify ownership for:

- Learners
- Teachers
- Guardians
- Grades
- Streams
- Assessments
- Finance records
- Attendance
- Timetables

Ownership violations are release blockers.

---

# Reports

Verify reports include only tenant data.

Examples:

- Report cards
- Fee statements
- Attendance summaries
- Assessment reports
- Financial reports

---

# Background Jobs

Verify queued jobs execute within the correct tenant.

Examples:

- SMS
- Email
- Report generation
- Imports
- Exports

---

# Notifications

Verify notifications:

- Reach only intended tenant users
- Contain correct tenant data
- Never expose another school's information

---

# File Storage

Verify uploaded files remain associated with the correct tenant.

Examples:

- Learner photos
- Documents
- Report exports
- Certificates

---

# Caching

Verify cache keys remain tenant-aware.

Cached data must never leak across schools.

---

# Search

Verify searches return only tenant-owned results.

Search indexes should remain scoped appropriately.

---

# Pagination

Verify pagination returns only records belonging to the active tenant.

---

# Imports

Verify imported records:

- Belong to the correct tenant
- Preserve relationships
- Reject invalid ownership

---

# Exports

Verify exports include only tenant-owned records.

Exports should never contain another school's data.

---

# Scheduled Tasks

Scheduled jobs should:

- Execute within tenant context
- Produce tenant-specific output
- Respect ownership boundaries

---

# Multi-School Regression Testing

Every defect involving tenant isolation should receive a permanent regression test.

Regression failures block release.

---

# Security Testing

Verify:

- Unauthorized access denied
- Tenant switching prevented
- URL manipulation prevented
- Identifier guessing prevented
- Cross-tenant data leakage impossible

---

# Performance

Verify acceptable behaviour with:

- Hundreds of schools
- Thousands of learners
- Large datasets
- Concurrent tenant activity

---

# Test Data

Use realistic multiple-school datasets.

Example:

- School A
- School B
- School C

Each should contain independent:

- Learners
- Teachers
- Finance
- Attendance
- Assessments

---

# Continuous Integration

CI should execute tenant-isolation tests for every pull request affecting:

- Database
- Authentication
- Authorization
- API
- Reporting
- File storage

Failures block merging.

---

# Review Checklist

Verify:

- Tenant context resolved
- Database isolated
- API isolated
- Reports isolated
- Notifications isolated
- Files isolated
- Background jobs isolated
- Security verified

---

# Definition of Done

Multi-tenant testing is complete only when:

- Tenant isolation verified.
- Authorization verified.
- Ownership verified.
- Database isolation verified.
- Reports isolated.
- Notifications isolated.
- Continuous integration passes.
- Documentation updated.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 46 — Backend authorization is mandatory
- Rule 66 — Every feature has tests

---

# Related Documents

- Testing-Standards.md
- Backend-Testing.md
- Database-Testing.md
- Security-Testing.md
- API-Contract-Testing.md

---

# Final Standard

Every ShuleOS release must prove complete tenant isolation through comprehensive automated testing.

Protecting the independence, privacy, and integrity of every school's data is a fundamental engineering requirement of the School in the Clouds.
