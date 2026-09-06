# ShuleOS API Contract Testing Standards

> School in Clouds

## Document Information

| Field                | Value                                                                                   |
| -------------------- | --------------------------------------------------------------------------------------- |
| Document             | API Contract Testing Standards                                                          |
| Document ID          | TEST-STD-0006                                                                           |
| Version              | 1.0                                                                                     |
| Status               | Approved                                                                                |
| Owner                | Platform Engineering                                                                    |
| Repository           | `shuleos-api` & `shuleos-web`                                                           |
| Effective Date       | 03 August 2026                                                                          |
| Related Constitution | Engineering Constitution v1.1                                                           |
| Related Standards    | Testing Standards, Backend Testing Standards, Feature and Integration Testing Standards |

---

# Purpose

This document establishes the mandatory standards for API Contract Testing throughout the ShuleOS platform.

API contract testing ensures that every interaction between the frontend and backend remains stable, predictable, and backward compatible.

---

# Scope

API contract testing applies to:

- REST endpoints
- Request payloads
- Response payloads
- HTTP status codes
- Authentication
- Authorization
- Pagination
- Filtering
- Sorting
- Error responses
- Versioning

---

# Philosophy

The API is a contract.

Frontend and backend teams should be able to evolve independently without unexpected breaking changes.

Every API change must preserve documented behaviour unless an intentional versioned breaking change is introduced.

---

# Core Principles

API contracts should be:

- Stable
- Predictable
- Documented
- Versioned
- Backward compatible
- Consistent
- Testable

---

# Request Validation

Verify:

- Required fields
- Optional fields
- Data types
- Length limits
- Formats
- Business validation
- Unknown fields

Malformed requests should return predictable validation responses.

---

# Response Validation

Verify:

- JSON structure
- Field names
- Data types
- Nullable values
- Relationships
- Collections
- Metadata

Responses should remain consistent.

---

# HTTP Status Codes

Verify correct usage of:

- 200 OK
- 201 Created
- 204 No Content
- 400 Bad Request
- 401 Unauthorized
- 403 Forbidden
- 404 Not Found
- 409 Conflict
- 422 Validation Error
- 429 Too Many Requests
- 500 Internal Server Error

Status codes should accurately describe outcomes.

---

# Authentication Contracts

Verify:

- Login response
- Refresh token response
- Logout response
- Unauthorized requests
- Expired tokens
- Invalid tokens

Authentication responses should remain stable.

---

# Authorization Contracts

Verify:

- Allowed actions
- Forbidden actions
- Resource ownership
- Tenant boundaries

Authorization failures should use consistent responses.

---

# Resource Serialization

Verify:

- Visible attributes
- Hidden attributes
- Conditional attributes
- Relationships
- Nested resources

Sensitive information must never be exposed.

---

# Error Contracts

Error responses should contain predictable fields.

Example:

```json
{
    "message": "...",
    "errors": {
        "field": ["..."]
    }
}
```

Avoid exposing stack traces or internal implementation details.

---

# Pagination

Verify:

- Current page
- Per-page values
- Total records
- Links
- Metadata

Pagination responses should remain consistent across endpoints.

---

# Filtering

Verify:

- Supported filters
- Invalid filters
- Empty results
- Combined filters

---

# Sorting

Verify:

- Ascending
- Descending
- Invalid fields
- Multiple sort fields where supported

---

# Version Compatibility

Every API change should evaluate:

- Existing consumers
- Deprecated fields
- New optional fields
- Breaking changes

Breaking changes require version planning.

---

# Backward Compatibility

Avoid:

- Renaming fields
- Removing fields
- Changing data types
- Altering response structure

Existing integrations should continue working whenever possible.

---

# Consumer-Driven Contracts

Where practical, frontend expectations should be validated automatically against backend responses.

Contract tests should fail whenever agreed behaviour changes unexpectedly.

---

# Multi-Tenant Contracts

Verify:

- Tenant scoping
- School ownership
- Cross-school isolation
- Resource visibility

No endpoint should expose another school's data.

---

# Security Contracts

Verify:

- Authentication required
- Authorization enforced
- Validation performed
- Rate limiting applied
- Sensitive fields hidden

---

# Performance

Contract testing should verify acceptable response behaviour under realistic request sizes.

Large payloads should remain manageable.

---

# Documentation Alignment

API behaviour should remain consistent with:

- API documentation
- Resource definitions
- Version notes
- Changelog

Documentation and implementation should never diverge.

---

# Regression Testing

Every resolved API defect should receive a contract test.

Regression tests prevent accidental reintroduction of previous issues.

---

# Continuous Integration

Every pull request should verify:

- Contract tests
- Feature tests
- Static analysis
- Formatting
- Security checks

Contract failures block merging.

---

# Review Checklist

Verify:

- Request contract stable
- Response contract stable
- Status codes correct
- Error responses consistent
- Authentication verified
- Authorization verified
- Tenant isolation verified
- Documentation updated

---

# Definition of Done

API contract testing is complete only when:

- Request contracts verified.
- Response contracts verified.
- Authentication verified.
- Authorization verified.
- Tenant isolation verified.
- Backward compatibility maintained.
- Continuous integration passes.
- Documentation updated.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 46 — Backend authorization is mandatory
- Rule 66 — Every feature has tests

---

# Related Documents

- Testing-Standards.md
- Backend-Testing.md
- Feature-and-Integration-Testing.md
- Database-Testing.md
- Multi-Tenant-Testing.md

---

# Final Standard

Every ShuleOS API endpoint must maintain a stable, documented, and thoroughly tested contract.

Reliable API contracts allow the frontend and backend to evolve independently while protecting schools from unexpected regressions, preserving tenant isolation, and ensuring long-term maintainability throughout the School in the Clouds.
