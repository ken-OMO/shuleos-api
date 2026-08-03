# ShuleOS API Naming Standards

> School in Clouds

## Document Information

| Field                | Value                         |
| -------------------- | ----------------------------- |
| Document             | API Naming Standards          |
| Document ID          | CODE-STD-0005                 |
| Version              | 1.0                           |
| Status               | Approved                      |
| Owner                | Platform Engineering          |
| Repository           | `shuleos-api`                 |
| Effective Date       | 03 August 2026                |
| API Style            | REST                          |
| Related Constitution | Engineering Constitution v1.1 |

---

# Purpose

This document defines the mandatory naming conventions for all APIs developed within the ShuleOS platform.

It standardizes:

- Endpoints
- Routes
- Controllers
- Request classes
- Resource classes
- JSON fields
- Query parameters
- Route names
- Error codes
- Response objects

Consistent naming improves readability, discoverability, maintainability, and long-term scalability.

---

# General Principles

API names should be:

- Descriptive
- Predictable
- Consistent
- Resource-oriented
- Lowercase
- Plural where representing collections

Avoid abbreviations unless universally understood.

---

# URI Naming

Use:

- lowercase
- kebab-case for multi-word URI segments
- plural resource names

Examples:

```text
/api/v1/schools
/api/v1/teachers
/api/v1/learners
/api/v1/academic-years
/api/v1/assessment-results
```

Avoid:

```text
/api/v1/GetTeachers
/api/v1/TeacherList
/api/v1/get_students
```

---

# HTTP Methods

Use HTTP verbs consistently.

```text
GET     Retrieve resources
POST    Create resources
PUT     Replace resources
PATCH   Partially update resources
DELETE  Remove resources
```

Avoid verbs in the endpoint name.

Good:

```text
POST /teachers
```

Bad:

```text
POST /createTeacher
```

---

# Route Parameters

Use singular resource identifiers.

Examples:

```text
/teachers/{teacher}
/schools/{school}
/learners/{learner}
```

Avoid:

```text
/teachers/{teacher_id}
/teacher/{id}
```

Laravel route model binding should be preferred.

---

# Nested Resources

Represent relationships clearly.

Examples:

```text
/schools/{school}/teachers
/grades/{grade}/streams
/learners/{learner}/guardians
```

Avoid excessive nesting.

---

# Controller Names

Controllers should use singular resource names with the `Controller` suffix.

Examples:

```text
TeacherController
SchoolController
AssessmentController
```

Avoid:

```text
TeachersController
TeacherAPIController
ManageTeacherController
```

---

# Form Request Names

Use clear intent.

Examples:

```text
StoreTeacherRequest
UpdateTeacherRequest
AssignGuardianRequest
```

---

# Resource Names

Laravel API Resources should follow:

```text
TeacherResource
LearnerResource
SchoolResource
```

Collections:

```text
TeacherCollection
```

---

# Route Names

Use dot notation.

Examples:

```text
teachers.index
teachers.store
teachers.show
teachers.update
teachers.destroy
```

Nested examples:

```text
schools.teachers.index
grades.streams.index
```

---

# JSON Fields

JSON properties use:

- snake_case

Examples:

```json
{
    "first_name": "John",
    "last_name": "Otieno",
    "phone_number": "0712345678",
    "school_id": 1
}
```

Maintain consistency between API fields and backend models where practical.

---

# Query Parameters

Use descriptive names.

Examples:

```text
?page=2
?per_page=20
?search=kennedy
?sort=name
?direction=asc
```

Avoid ambiguous parameter names.

---

# Filtering

Filters should use:

```text
?grade_id=2
?stream_id=5
?status=active
```

Multiple filters should remain explicit and predictable.

---

# Sorting

Sorting parameters:

```text
?sort=created_at
?direction=desc
```

Only documented sortable fields should be accepted.

---

# Pagination

Standard pagination parameters:

```text
?page=1
?per_page=25
```

Do not invent custom pagination names.

---

# Error Codes

Use stable, descriptive error identifiers.

Examples:

```text
VALIDATION_ERROR
UNAUTHORIZED
FORBIDDEN
RESOURCE_NOT_FOUND
TENANT_ACCESS_DENIED
RATE_LIMIT_EXCEEDED
```

Avoid numeric-only application error identifiers.

---

# Response Objects

Success responses should remain consistent.

Typical structure:

```json
{
    "data": {},
    "message": "Teacher created successfully."
}
```

Errors should follow the project's API error handling standard.

---

# Versioning

Version APIs through the URI.

Example:

```text
/api/v1/
```

Do not mix multiple versioning strategies.

---

# Multi-Tenant Rules

Tenant-aware endpoints must:

- Respect tenant boundaries
- Never expose cross-tenant data
- Enforce authorization consistently

---

# Documentation

Every public endpoint should include:

- Purpose
- Request format
- Response format
- Validation rules
- Authentication requirements

Documentation should remain synchronized with implementation.

---

# Continuous Integration

CI should verify:

- Route registration
- Naming consistency
- OpenAPI generation
- Documentation updates

---

# Definition of Done

API work is complete only when:

- Endpoint naming follows this standard
- Documentation updated
- Tests pass
- Authorization verified
- Tenant isolation verified
- Review approved

---

# Constitution Compliance

This standard reinforces:

- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 10 — Design first. Code second
- Rule 30 — Every query is tenant scoped
- Rule 66 — Every feature has tests
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- Coding-Standards.md
- Database-Naming.md
- PHP-Laravel-Standards.md
- API Standards
- OpenAPI-Guidelines.md

---

# Final Standard

Consistent API naming enables ShuleOS to provide a predictable and maintainable interface for frontend applications, mobile clients, third-party integrations, and future services.

Every endpoint should follow these conventions to ensure the School in the Clouds platform remains intuitive, scalable, and easy to evolve.
