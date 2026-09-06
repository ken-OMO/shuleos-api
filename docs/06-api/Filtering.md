# ShuleOS API Filtering Standard

> School in Clouds

## Document Information

| Field                | Value                                  |
| -------------------- | -------------------------------------- |
| Document             | API Filtering Standard                 |
| Document ID          | API-STD-0006                           |
| Version              | 1.0                                    |
| Status               | Approved                               |
| Owner                | Platform Engineering                   |
| Repository           | `shuleos-api`                          |
| Effective Date       | 02 August 2026                         |
| Related Constitution | Engineering Constitution v1.1          |
| Related ADRs         | ADR-0001, ADR-0002, ADR-0004, ADR-0011 |

---

# Purpose

This document defines the mandatory filtering standard for every ShuleOS API collection endpoint.

It governs:

- Query parameter filtering
- Exact matching
- Partial matching
- Boolean filters
- Numeric filters
- Date filters
- Range filters
- Relationship filters
- Multi-value filters
- Search integration
- Sorting interaction
- Pagination interaction
- Tenant isolation
- Performance
- OpenAPI documentation
- Testing
- CI enforcement

Every collection endpoint that returns more than one resource should support appropriate filtering.

---

# Core Principles

Filtering must be:

- Predictable
- Consistent
- Tenant-aware
- Indexed
- Secure
- Testable
- Documented

Clients should never need endpoint-specific filtering syntax.

---

# Query Parameter Style

Filtering uses query parameters.

Example:

```text
GET /learners?grade_id=6
```

Multiple filters:

```text
GET /learners?grade_id=6&stream_id=2
```

---

# Exact Match Filters

Exact filters match a single value.

Examples:

```text
?school_id=1
?grade_id=7
?stream_id=3
?status=active
```

Exact filters should use indexed columns where practical.

---

# Partial Text Search

Partial text search uses the `search` parameter.

Example:

```text
GET /learners?search=Omondi
```

Search may include:

- admission number
- first name
- last name
- guardian name
- teacher number
- invoice number

Search implementation must remain tenant scoped.

---

# Boolean Filters

Boolean values should use:

```text
true
false
```

Example:

```text
?is_active=true
?is_boarder=false
```

Avoid:

```text
1
0
yes
no
```

unless explicitly documented.

---

# Numeric Filters

Examples:

```text
?grade_id=8
?score=75
?amount_minor=50000
```

Numeric validation is mandatory.

---

# Date Filters

Dates use ISO-8601 format.

Example:

```text
?created_after=2026-01-01
?created_before=2026-12-31
```

Do not accept locale-specific date formats.

---

# Date Ranges

Example:

```text
GET /payments?from=2026-01-01&to=2026-03-31
```

The server must validate:

- valid dates
- logical range
- supported limits

---

# Numeric Ranges

Example:

```text
?min_amount=10000
&max_amount=50000
```

Minimum must not exceed maximum.

---

# Multi-Value Filters

Collections use comma-separated values.

Example:

```text
?status=active,suspended
```

or

```text
?grade_id=7,8,9
```

Ordering of values must not affect results.

---

# Relationship Filters

Relationship filters reference related resources.

Examples:

```text
?teacher_id=17
?guardian_id=12
?subject_id=5
?academic_year_id=2026
```

Referenced resources must remain within the active tenant.

---

# Enumeration Filters

Enumerated values must use documented identifiers.

Example:

```text
?status=active
```

Invalid enumeration values return:

```text
422 Unprocessable Content
```

---

# Null Filters

When supported:

```text
?has_email=true
```

or

```text
?email_is_null=true
```

Null filtering must be explicitly documented.

---

# Archived Records

Archived records are excluded unless explicitly requested.

Example:

```text
?include_archived=true
```

Ordinary clients should not receive archived records by default.

---

# Search + Filter Combination

Search and filters may be combined.

Example:

```text
GET /learners?search=Omondi&grade_id=7
```

Filtering applies before pagination.

---

# Sorting Interaction

Sorting occurs after filtering.

Example:

```text
GET /learners?grade_id=6&sort=last_name
```

---

# Pagination Interaction

Filtering determines the dataset.

Pagination operates on the filtered result.

Metadata reflects the filtered collection.

---

# Tenant Isolation

Filtering must never bypass tenant isolation.

Examples:

```
?school_id=2
```

must never allow access to another school's records.

Tenant scope is resolved server-side.

---

# Validation

Invalid filters return:

```text
422 Unprocessable Content
```

Examples:

- invalid UUID
- invalid date
- invalid enum
- invalid boolean
- malformed list

---

# Performance

Filtering should:

- use indexes
- avoid table scans
- avoid N+1 queries
- remain tenant scoped
- return deterministic results

Frequently filtered fields should be indexed.

---

# Security

Filtering must protect against:

- SQL injection
- enumeration
- cross-tenant inference
- excessive query cost

Parameterized queries are mandatory.

---

# OpenAPI

Document:

- supported filters
- parameter types
- examples
- validation rules
- defaults
- limits

Undocumented filters are unsupported.

---

# Testing

Filtering tests should include:

- exact match
- partial search
- boolean filters
- date range
- numeric range
- relationship filters
- invalid values
- tenant isolation
- pagination interaction
- sorting interaction

---

# CI Enforcement

Continuous Integration should verify:

- supported filters documented
- validation enforced
- tenant isolation maintained
- OpenAPI updated
- automated tests passing

---

# Definition of Done

Filtering is complete only when:

- parameters documented
- validation implemented
- tenant-safe
- indexed
- tested
- OpenAPI updated
- CI passes

---

# Constitution Compliance

This standard supports:

- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 13 — Protect against IDOR
- Rule 24 — Every query is reviewed
- Rule 27 — Performance is measured
- Rule 30 — Every query is tenant scoped
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 69 — Performance tests are mandatory
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- API-Standards.md
- Pagination.md
- Versioning.md
- OpenAPI-Guidelines.md
- Error-Handling.md

---

# Final Standard

Filtering enables clients to retrieve only the information they need while preserving performance, tenant isolation, and predictable API behavior.

Every filtering feature must be secure, indexed, documented, validated, and consistent across the entire ShuleOS platform.
