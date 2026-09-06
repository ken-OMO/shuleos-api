# ShuleOS API Standards

> School in Clouds

## Document Information

| Field                | Value                                                                          |
| -------------------- | ------------------------------------------------------------------------------ |
| Document             | API Standards                                                                  |
| Document ID          | API-STD-0001                                                                   |
| Version              | 1.0                                                                            |
| Status               | Approved                                                                       |
| Owner                | Platform Engineering                                                           |
| Repository           | `shuleos-api`                                                                  |
| Effective Date       | 02 August 2026                                                                 |
| Related Constitution | Engineering Constitution v1.1                                                  |
| Related ADRs         | ADR-0001, ADR-0002, ADR-0003, ADR-0004, ADR-0005, ADR-0006, ADR-0010, ADR-0011 |

---

# Purpose

This document defines the mandatory API engineering standards for ShuleOS.

It governs:

- REST API design
- Resource naming
- URI structure
- HTTP methods
- Authentication
- Authorization
- Request validation
- Response structure
- Error handling
- Pagination
- Filtering
- Sorting
- Versioning
- Idempotency
- Rate limiting
- OpenAPI documentation
- Testing
- API lifecycle

These standards apply to every HTTP endpoint exposed by ShuleOS.

---

# Authority

The Engineering Constitution is the highest engineering authority.

Architecture Decision Records define architectural decisions.

This document defines how HTTP APIs are designed and implemented.

Where conflict exists:

```
Engineering Constitution
        ↓
Architecture Decision Records
        ↓
API Standards
        ↓
Implementation
```

---

# API Philosophy

Every API must be:

- Consistent
- Predictable
- Secure
- Versioned
- Documented
- Tenant-aware
- Observable
- Testable
- Backward compatible where practical

Clients should never need to guess API behaviour.

---

# API Style

ShuleOS uses:

- RESTful JSON APIs
- HTTPS only
- Stateless requests
- JWT authentication
- UTF-8 encoding

Content-Type:

```http
application/json
```

---

# API Base URL

Development:

```
http://localhost:8000/api/v1
```

Production:

```
https://api.shuleos.com/v1
```

Version is part of the URL.

---

# URI Standards

Use nouns.

Good:

```
/learners
/teachers
/grades
/streams
/assessments
/guardians
```

Avoid verbs.

Bad:

```
/getLearners
/createTeacher
/deleteStream
```

---

# Resource Naming

Resources use:

- lowercase
- kebab-case where needed
- plural nouns

Examples:

```
learning-areas
lesson-plans
curriculum-coverage
assessment-results
```

---

# HTTP Methods

GET

Retrieve resources.

POST

Create resources.

PUT

Replace a resource.

PATCH

Partial update.

DELETE

Archive or delete according to business rules.

OPTIONS

Framework-managed.

HEAD

Rarely required.

---

# HTTP Status Codes

Success

```
200 OK
201 Created
202 Accepted
204 No Content
```

Client Errors

```
400 Bad Request
401 Unauthorized
403 Forbidden
404 Not Found
409 Conflict
410 Gone
412 Precondition Failed
422 Validation Failed
429 Too Many Requests
```

Server Errors

```
500 Internal Server Error
502 Bad Gateway
503 Service Unavailable
504 Gateway Timeout
```

Do not misuse status codes.

---

# Authentication

All protected endpoints require JWT.

Example:

```
Authorization: Bearer <token>
```

Authentication verifies identity.

Authentication alone never authorizes an action.

---

# Authorization

Authorization evaluates:

- authenticated user
- active tenant
- account state
- role
- permission
- policy
- ownership
- workflow state

Every request must fail closed.

---

# Tenant Resolution

Clients never choose their tenant.

Tenant context is resolved server-side.

Every tenant-owned query must be scoped.

---

# Request Validation

Every request must be validated before business logic executes.

Validation must include:

- types
- required fields
- ownership
- existence
- permissions
- workflow rules

Never trust client input.

---

# Request Body

JSON only.

Example:

```json
{
    "first_name": "Jane",
    "last_name": "Otieno",
    "admission_number": "2026-001"
}
```

---

# Response Format

Successful responses should follow one consistent envelope.

Example:

```json
{
    "success": true,
    "message": "Learner created successfully.",
    "data": {
        "...": "..."
    }
}
```

---

# Error Format

Errors should follow one structure.

Example:

```json
{
    "success": false,
    "message": "Validation failed.",
    "errors": {
        "email": ["The email field is required."]
    }
}
```

---

# Resource Representation

Responses expose only necessary data.

Never expose:

- passwords
- password hashes
- JWT secrets
- provider secrets
- internal security flags
- unnecessary personal data

---

# Pagination

Large collections must paginate.

Response should include:

- data
- current_page
- per_page
- total
- last_page

Cursor pagination may be used where appropriate.

---

# Filtering

Filtering must use query parameters.

Example:

```
GET /learners?grade_id=4
```

---

# Sorting

Sorting uses query parameters.

Example:

```
GET /learners?sort=last_name
```

Descending:

```
GET /learners?sort=-created_at
```

---

# Searching

Search uses explicit query parameters.

Example:

```
GET /learners?search=Omondi
```

Search must remain tenant scoped.

---

# Relationships

Nested resources are permitted only where ownership is clear.

Example:

```
GET /grades/{grade}/streams
```

Avoid deeply nested URLs.

---

# Versioning

Every public API belongs to a version.

Example:

```
/api/v1
```

Breaking changes require a new version.

---

# Idempotency

Endpoints that create financial or external operations must support idempotency.

Examples:

- payments
- SMS
- email
- callbacks

Repeated requests must not create duplicate operations.

---

# Rate Limiting

Sensitive endpoints must be rate limited.

Examples:

- login
- password reset
- OTP
- payment callbacks

Limits must be documented.

---

# OpenAPI

Every public endpoint must appear in the OpenAPI specification.

Documentation must include:

- request schema
- response schema
- authentication
- status codes
- examples

---

# Security

APIs must defend against:

- SQL Injection
- IDOR
- Mass Assignment
- CSRF (where applicable)
- Replay attacks
- Enumeration
- Timing attacks

Security is mandatory.

---

# Observability

Every request should support:

- correlation ID
- request logging
- audit logging where required
- metrics
- tracing

Sensitive data must never be logged.

---

# Performance

Avoid:

- N+1 queries
- over-fetching
- under-indexed endpoints
- unbounded collections

Measure performance before optimizing.

---

# Testing

Every endpoint requires:

- success tests
- validation tests
- authorization tests
- tenant isolation tests
- error tests

Critical APIs require performance tests.

---

# Documentation

Every endpoint must document:

- purpose
- authentication
- permissions
- request body
- response
- errors
- examples

Undocumented APIs are incomplete.

---

# API Lifecycle

New APIs must follow:

1. Design
2. Review
3. Implementation
4. Tests
5. Documentation
6. CI
7. Release

No endpoint skips this process.

---

# Definition of Done

An API is complete only when:

- endpoint implemented
- validation complete
- authorization enforced
- tenant safe
- documented
- tested
- OpenAPI updated
- CI passes

---

# Constitution Compliance

This standard supports:

- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 10 — Design first. Code second
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 13 — Protect against IDOR
- Rule 14 — No endpoint bypasses the security pipeline
- Rule 17 — Audit important actions
- Rule 28 — TenantContext is mandatory
- Rule 30 — Every query is tenant scoped
- Rule 46 — Frontend authorization never replaces backend authorization
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- Error-Handling.md
- Authentication.md
- Pagination.md
- Filtering.md
- Rate-Limiting.md
- Versioning.md
- OpenAPI-Guidelines.md

---

# Final Standard

Every ShuleOS API must be secure, predictable, tenant-aware, well documented, fully tested, and consistent across the platform.

A client should be able to understand one ShuleOS endpoint and confidently use every other endpoint because the same engineering standards apply everywhere.
