# ShuleOS API Pagination Standard

> School in Clouds

## Document Information

| Field                | Value                                  |
| -------------------- | -------------------------------------- |
| Document             | API Pagination Standard                |
| Document ID          | API-STD-0005                           |
| Version              | 1.0                                    |
| Status               | Approved                               |
| Owner                | Platform Engineering                   |
| Repository           | `shuleos-api`                          |
| Effective Date       | 02 August 2026                         |
| Related Constitution | Engineering Constitution v1.1          |
| Related ADRs         | ADR-0001, ADR-0002, ADR-0004, ADR-0011 |

---

# Purpose

This document defines the mandatory pagination standard for all ShuleOS API collection endpoints.

It governs:

- Offset pagination
- Cursor pagination
- Request parameters
- Response metadata
- Page sizing
- Sorting interaction
- Filtering interaction
- Performance
- Large datasets
- Infinite scrolling
- OpenAPI documentation
- Testing
- CI enforcement

Every endpoint returning a collection must paginate unless explicitly documented otherwise.

---

# Core Principles

Pagination must be:

- Predictable
- Consistent
- Tenant-aware
- Efficient
- Documented
- Testable

Clients should receive the same pagination structure from every collection endpoint.

---

# Default Pagination

Default page size:

```text
25
```

Unless documented otherwise.

---

# Maximum Page Size

Maximum allowed page size:

```text
100
```

Requests above the maximum must be limited by the server.

Example:

```
GET /learners?per_page=500
```

Server response uses:

```text
per_page = 100
```

---

# Request Parameters

Standard parameters:

```
?page=1
&per_page=25
```

Example:

```
GET /learners?page=2&per_page=50
```

---

# Offset Pagination

Offset pagination is the default.

Example:

```
GET /teachers?page=3
```

Suitable for:

- Administration
- Reports
- Moderate datasets
- User navigation

---

# Cursor Pagination

Cursor pagination is recommended for:

- Audit logs
- Notifications
- Payments
- Activity feeds
- Infinite scrolling
- Large datasets

Example:

```
GET /notifications?cursor=abc123
```

Cursor values are opaque.

Clients must never attempt to interpret them.

---

# Response Envelope

Paginated responses follow the standard API envelope.

Example:

```json
{
    "success": true,
    "message": "Learners retrieved successfully.",
    "data": [
        {
            "...": "..."
        }
    ],
    "meta": {
        "pagination": {
            "current_page": 2,
            "per_page": 25,
            "total": 534,
            "last_page": 22,
            "from": 26,
            "to": 50
        }
    }
}
```

---

# Cursor Response Example

```json
{
    "success": true,
    "data": [
        {
            "...": "..."
        }
    ],
    "meta": {
        "pagination": {
            "next_cursor": "eyJpZCI6...",
            "previous_cursor": null,
            "has_more": true
        }
    }
}
```

---

# Empty Collections

Empty collections are successful responses.

Example:

```json
{
    "success": true,
    "message": "No learners found.",
    "data": [],
    "meta": {
        "pagination": {
            "current_page": 1,
            "per_page": 25,
            "total": 0,
            "last_page": 1
        }
    }
}
```

Never return:

```
404 Not Found
```

for an empty collection.

---

# Sorting Interaction

Sorting occurs before pagination.

Example:

```
GET /learners?sort=last_name&page=1
```

---

# Filtering Interaction

Filtering occurs before pagination.

Example:

```
GET /learners?grade_id=6&page=1
```

Pagination metadata reflects the filtered dataset.

---

# Stable Ordering

Paginated endpoints must define a deterministic ordering.

Example:

```
ORDER BY created_at DESC, id DESC
```

Avoid unstable ordering.

---

# Performance

Pagination queries must:

- Use indexes
- Remain tenant scoped
- Avoid N+1 queries
- Avoid loading unnecessary columns
- Avoid full table scans where possible

---

# Large Datasets

Large datasets should use cursor pagination where appropriate.

Examples:

- Audit logs
- SMS history
- Email history
- Sync events
- Notifications

---

# Infinite Scrolling

Infinite scrolling should use cursor pagination.

Clients request the next cursor until:

```json
{
    "has_more": false
}
```

---

# Validation

Invalid pagination values return:

```
422 Unprocessable Content
```

Examples:

```
page=0
per_page=-5
per_page=text
```

---

# Security

Pagination must remain tenant scoped.

A client must never page through another tenant's data.

---

# Documentation

Every paginated endpoint documents:

- parameters
- defaults
- maximums
- response structure
- examples

---

# OpenAPI

OpenAPI specifications should define:

- page
- per_page
- cursor
- pagination metadata
- examples

---

# Testing

Pagination tests include:

- first page
- middle page
- last page
- empty result
- invalid parameters
- maximum page size
- tenant isolation
- cursor progression

---

# CI Enforcement

CI should verify:

- pagination metadata exists
- page size limits enforced
- deterministic ordering
- tenant-safe queries
- OpenAPI documentation updated

---

# Definition of Done

Pagination is complete only when:

- request parameters documented
- response metadata consistent
- limits enforced
- tests pass
- OpenAPI updated
- tenant safety verified

---

# Constitution Compliance

This standard supports:

- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 24 — Every query is reviewed
- Rule 27 — Performance is measured
- Rule 30 — Every query is tenant scoped
- Rule 66 — Every feature has tests
- Rule 68 — Cross-tenant tests are mandatory
- Rule 69 — Performance tests are mandatory
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- API-Standards.md
- Filtering.md
- Versioning.md
- OpenAPI-Guidelines.md

---

# Final Standard

Every ShuleOS collection endpoint must paginate consistently, remain tenant-safe, provide predictable metadata, and scale from small school datasets to platform-wide workloads without changing its public contract.
