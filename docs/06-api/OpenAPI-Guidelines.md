# ShuleOS OpenAPI Documentation Guidelines

> School in Clouds

## Document Information

| Field                | Value                                                      |
| -------------------- | ---------------------------------------------------------- |
| Document             | OpenAPI Documentation Guidelines                           |
| Document ID          | API-STD-0008                                               |
| Version              | 1.0                                                        |
| Status               | Approved                                                   |
| Owner                | Platform Engineering                                       |
| Repository           | `shuleos-api`                                              |
| Effective Date       | 02 August 2026                                             |
| Related Constitution | Engineering Constitution v1.1                              |
| Related ADRs         | ADR-0001, ADR-0002, ADR-0003, ADR-0004, ADR-0005, ADR-0006 |

---

# Purpose

This document defines the mandatory OpenAPI documentation standard for every ShuleOS REST API.

It governs:

- OpenAPI specification format
- Endpoint documentation
- Schema reuse
- Authentication documentation
- Error documentation
- Pagination documentation
- Filtering documentation
- Examples
- Versioning
- CI validation
- Documentation publication
- Client SDK generation

Every public API endpoint must be documented using OpenAPI.

---

# Core Principles

OpenAPI documentation must be:

- Accurate
- Complete
- Versioned
- Consistent
- Machine-readable
- Human-readable
- Testable

Documentation is part of the product.

---

# OpenAPI Version

ShuleOS adopts:

```text
OpenAPI 3.1
```

Older OpenAPI versions are not used for new specifications.

---

# Specification Files

One specification per API version.

Examples:

```text
docs/openapi/
    openapi-v1.yaml
    openapi-v2.yaml
```

Specifications must remain synchronized with implementation.

---

# API Information

Every specification defines:

- title
- description
- version
- contact
- license
- servers

Example:

```yaml
info:
    title: ShuleOS API
    version: v1
    description: Multi-tenant School ERP API
```

---

# Server Definitions

Example:

```yaml
servers:
    - url: https://api.shuleos.com/v1
      description: Production

    - url: https://staging-api.shuleos.com/v1
      description: Staging

    - url: http://localhost:8000/api/v1
      description: Local Development
```

---

# Tags

Endpoints must be grouped logically.

Examples:

```text
Authentication
Schools
Users
Teachers
Learners
Guardians
Grades
Streams
Learning Areas
Timetable
Attendance
Finance
Payments
Reports
Notifications
Administration
```

---

# Path Naming

Paths must match API standards.

Good:

```
/learners
/teachers
/grades
```

Bad:

```
/getLearners
/createTeacher
```

---

# Operations

Every operation must include:

- summary
- description
- tags
- operationId
- security
- parameters
- requestBody
- responses

---

# Operation IDs

Operation IDs must be unique.

Examples:

```text
listLearners
createLearner
showLearner
updateLearner
deleteLearner
```

---

# Schemas

Reusable schemas belong under:

```yaml
components:
    schemas:
```

Avoid duplicated schema definitions.

---

# Schema Naming

Use PascalCase.

Examples:

```
Learner
Teacher
Invoice
Payment
Guardian
Assessment
```

---

# Parameters

Reusable parameters belong under:

```yaml
components:
    parameters:
```

Examples:

- page
- per_page
- search
- sort
- grade_id

---

# Responses

Reusable responses belong under:

```yaml
components:
    responses:
```

Examples:

- Unauthorized
- ValidationError
- Forbidden
- NotFound
- Conflict

---

# Security Schemes

Define reusable authentication.

Example:

```yaml
components:
    securitySchemes:
        bearerAuth:
            type: http
            scheme: bearer
            bearerFormat: JWT
```

---

# Authentication

Protected endpoints require:

```yaml
security:
    - bearerAuth: []
```

Public endpoints explicitly document when authentication is not required.

---

# Request Bodies

Every request body documents:

- content type
- schema
- examples

Example:

```yaml
requestBody:
    required: true
```

---

# Response Bodies

Every response includes:

- schema
- examples
- status code
- description

---

# Error Documentation

Every endpoint documents:

- 400
- 401
- 403
- 404
- 422
- 429
- 500

when applicable.

---

# Pagination

Paginated endpoints document:

- page
- per_page
- pagination metadata
- examples

---

# Filtering

Supported filters must be documented.

Example:

```
grade_id
stream_id
search
status
```

---

# Sorting

Supported sort fields must be documented.

Example:

```
sort=last_name
sort=-created_at
```

---

# Examples

Every endpoint should provide:

- request example
- success response
- validation failure
- authentication failure
- authorization failure

Examples improve developer experience.

---

# Enumerations

Enumerated values should be documented.

Example:

```yaml
status:
    type: string
    enum:
        - active
        - suspended
        - archived
```

---

# File Uploads

Document:

- supported media types
- size limits
- validation rules

---

# Webhooks

Webhook endpoints must document:

- signature verification
- retry behaviour
- payload schema
- response expectations

---

# Deprecation

Deprecated endpoints must use:

```yaml
deprecated: true
```

Documentation should explain replacements.

---

# Versioning

Every specification belongs to one API version.

Do not mix v1 and v2 endpoints in one specification.

---

# SDK Generation

Specifications should support automated SDK generation.

Stable schemas improve client compatibility.

---

# Validation

Specifications must validate successfully before release.

Broken specifications block release.

---

# Swagger UI

Specifications should render correctly in:

- Swagger UI
- Scalar
- Redoc

Documentation must remain readable.

---

# Continuous Integration

CI should verify:

- OpenAPI syntax
- schema validity
- duplicate operation IDs
- broken references
- undocumented endpoints
- reusable components

Builds fail on validation errors.

---

# Documentation Publication

Documentation should be published automatically during release.

Public documentation must match deployed APIs.

---

# Testing

Documentation tests should verify:

- endpoint coverage
- schema correctness
- examples
- authentication
- responses
- pagination
- filtering

---

# Definition of Done

API documentation is complete only when:

- OpenAPI updated
- examples included
- reusable schemas used
- validation passes
- CI passes
- published

---

# Constitution Compliance

This standard supports:

- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 18 — Never expose internal exceptions
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 69 — Performance tests are mandatory
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- API-Standards.md
- Authentication.md
- Error-Handling.md
- Versioning.md
- Pagination.md
- Filtering.md
- Rate-Limiting.md

---

# Final Standard

OpenAPI documentation is the official contract between ShuleOS and every API consumer.

Every endpoint must be documented before release, validated automatically, versioned consistently, and maintained as a first-class engineering artifact alongside the source code.
