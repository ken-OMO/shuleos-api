# ShuleOS Database Testing Standards

> School in Clouds

## Document Information

| Field                | Value                                                                        |
| -------------------- | ---------------------------------------------------------------------------- |
| Document             | Database Testing Standards                                                   |
| Document ID          | TEST-STD-0007                                                                |
| Version              | 1.0                                                                          |
| Status               | Approved                                                                     |
| Owner                | Platform Engineering                                                         |
| Repository           | `shuleos-api`                                                                |
| Effective Date       | 03 August 2026                                                               |
| Related Constitution | Engineering Constitution v1.1                                                |
| Related Standards    | Testing Standards, Backend Testing Standards, API Contract Testing Standards |

---

# Purpose

This document establishes the mandatory standards for database testing throughout the ShuleOS platform.

Database testing ensures that data remains accurate, consistent, secure, and isolated across all schools using the platform.

---

# Scope

Database testing applies to:

- PostgreSQL
- Migrations
- Seeders
- Factories
- Tables
- Views
- Constraints
- Relationships
- Transactions
- Indexes
- Soft Deletes
- Multi-tenancy

---

# Philosophy

The database is the platform's permanent source of truth.

Every change to database structure or behaviour must be verified through automated testing before deployment.

---

# Core Principles

Database tests should be:

- Repeatable
- Deterministic
- Independent
- Fast
- Reliable
- Maintainable

---

# Migration Testing

Every migration should verify:

- Successful execution
- Successful rollback
- Correct table creation
- Correct column definitions
- Correct indexes
- Correct constraints

Rollback failures block release.

---

# Schema Validation

Verify:

- Column names
- Data types
- Length limits
- Nullability
- Default values
- Generated columns where applicable

Schema should match documentation.

---

# Primary Keys

Verify:

- Presence
- Uniqueness
- Correct data type

Every table should have an appropriate primary key.

---

# Foreign Keys

Verify:

- Relationships
- Cascading behaviour
- Restrict behaviour
- Nullable relationships
- Integrity enforcement

Broken relationships are unacceptable.

---

# Constraints

Verify:

- Unique constraints
- Check constraints
- Foreign key constraints
- Composite constraints

Constraints should enforce business rules.

---

# Transactions

Verify:

- Successful commit
- Rollback on failure
- Nested transactions where applicable
- Atomic behaviour

Partial writes should never persist.

---

# Soft Deletes

Verify:

- Soft deletion
- Restoration
- Query behaviour
- Hidden records
- Permanent deletion where appropriate

---

# Index Testing

Verify:

- Required indexes exist
- Composite indexes behave correctly
- Performance benefits are measurable

Avoid unnecessary indexes.

---

# Query Validation

Verify:

- Correct results
- Filtering
- Sorting
- Pagination
- Aggregation
- Grouping

Queries should remain predictable.

---

# Data Integrity

Verify:

- Required relationships
- Referential integrity
- Duplicate prevention
- Consistency across related tables

Integrity failures block release.

---

# Multi-Tenant Testing

Verify:

- School isolation
- Tenant ownership
- Scoped queries
- Cross-school protection

Tenant isolation is mandatory.

---

# Factories

Factories should generate:

- Realistic schools
- Teachers
- Learners
- Guardians
- Grades
- Streams
- Assessments
- Finance records

Factories should remain deterministic.

---

# Seeders

Seeders should:

- Populate realistic development data
- Avoid production assumptions
- Support automated testing

---

# Performance

Verify:

- Query execution time
- Index usage
- Large datasets
- Bulk inserts
- Bulk updates

Avoid unnecessary database load.

---

# Backup Verification

Testing should confirm:

- Successful backups
- Successful restoration
- Data consistency after restore

Backup validation should be performed regularly.

---

# Concurrency

Verify behaviour under concurrent updates.

Prevent:

- Lost updates
- Dirty reads
- Race conditions

---

# Security

Verify:

- Sensitive data protection
- Tenant isolation
- Authorization enforcement
- SQL injection prevention

Security testing complements application testing.

---

# Error Handling

Verify:

- Constraint violations
- Missing records
- Duplicate values
- Transaction failures
- Database exceptions

Errors should remain predictable.

---

# Continuous Integration

Every database change should execute:

- Migration tests
- Rollback tests
- Feature tests
- Integration tests
- Static analysis

Database failures block merging.

---

# Review Checklist

Verify:

- Migrations tested
- Rollbacks tested
- Relationships verified
- Constraints verified
- Transactions tested
- Tenant isolation verified
- Performance acceptable
- Documentation updated

---

# Definition of Done

Database testing is complete only when:

- Migrations verified.
- Rollbacks verified.
- Relationships verified.
- Data integrity maintained.
- Tenant isolation verified.
- Performance acceptable.
- Continuous integration passes.
- Documentation updated.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 10 — Design first. Code second
- Rule 66 — Every feature has tests

---

# Related Documents

- Testing-Standards.md
- Backend-Testing.md
- API-Contract-Testing.md
- Multi-Tenant-Testing.md
- Security-Testing.md

---

# Final Standard

Every database change within ShuleOS must be verified through comprehensive automated testing before deployment.

Reliable database testing protects school information, preserves business rules, enforces tenant isolation, and ensures the long-term integrity of the School in the Clouds.
