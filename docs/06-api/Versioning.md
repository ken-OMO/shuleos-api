# ShuleOS API Versioning Standard

> School in Clouds

## Document Information

| Field                | Value                                            |
| -------------------- | ------------------------------------------------ |
| Document             | API Versioning Standard                          |
| Document ID          | API-STD-0004                                     |
| Version              | 1.0                                              |
| Status               | Approved                                         |
| Owner                | Platform Engineering                             |
| Repository           | `shuleos-api`                                    |
| Effective Date       | 02 August 2026                                   |
| Related Constitution | Engineering Constitution v1.1                    |
| Related ADRs         | ADR-0001, ADR-0002, ADR-0003, ADR-0004, ADR-0011 |

---

# Purpose

This document defines the mandatory versioning strategy for every ShuleOS API.

It governs:

- API lifecycle
- URL versioning
- Breaking changes
- Non-breaking changes
- Backward compatibility
- Deprecation
- Sunset policy
- Client migration
- Documentation
- OpenAPI version alignment
- Testing
- Continuous Integration enforcement

Versioning ensures ShuleOS APIs evolve without unexpectedly breaking schools, mobile applications, or third-party integrations.

---

# Core Principles

API versioning must be:

- Predictable
- Stable
- Explicit
- Documented
- Testable
- Backward compatible where practical
- Safe for long-term integrations

Clients should always know which API contract they are using.

---

# Versioning Strategy

ShuleOS uses **URL-based versioning**.

Example:

```text
/api/v1
/api/v2
```

Development:

```text
http://localhost:8000/api/v1
```

Production:

```text
https://api.shuleos.com/v1
```

Every public endpoint belongs to exactly one API version.

---

# Current Version

Current production version:

```text
v1
```

All new endpoints are introduced into the current supported version unless a new major version is required.

---

# Version Naming

Major versions use:

```text
v1
v2
v3
```

Do not use:

```text
v1.1
v1.5
v2026
latest
current
```

Minor implementation improvements do not create new API URLs.

---

# Semantic Versioning

Internal services and packages may use Semantic Versioning.

Example:

```text
1.0.0
1.2.3
2.0.0
```

Public REST APIs use major URL versions.

---

# Breaking Changes

A new API version is required when a change would break existing clients.

Examples:

- Removing an endpoint
- Renaming a field
- Removing a response field
- Changing a field type
- Changing required request fields
- Changing authentication requirements
- Changing business meaning
- Removing supported status codes
- Incompatible pagination format
- Changing response envelope

---

# Non-Breaking Changes

These normally do not require a new API version:

- Adding optional request fields
- Adding optional response fields
- Performance improvements
- Internal refactoring
- Bug fixes
- Documentation improvements
- Additional error details that do not change the contract

---

# Backward Compatibility

Every supported API version remains stable.

Clients built against a supported version should continue functioning without modification.

Compatibility takes precedence over convenience.

---

# Deprecation Policy

Deprecated APIs remain functional during the published deprecation period.

Documentation must clearly identify:

- Deprecated endpoint
- Reason
- Replacement
- Migration guide
- Planned removal date

Example:

```text
Deprecated: 01 January 2027
Removal: 01 July 2027
Replacement:
/api/v2/learners
```

---

# Sunset Policy

When an API version reaches end of life:

- Announce early
- Publish migration guidance
- Notify customers
- Update documentation
- Remove only after the published sunset date

Unsupported APIs must not disappear without notice.

---

# Endpoint Stability

Once released, an endpoint contract is considered stable.

Avoid changing:

- URLs
- JSON structure
- Error format
- Authentication requirements
- Pagination structure

without following the versioning policy.

---

# Response Compatibility

Adding new optional fields is acceptable.

Removing existing fields from a supported version is prohibited.

Clients should ignore unknown response fields.

---

# Request Compatibility

Required request fields must not be added to an existing version.

New required fields require either:

- A backward-compatible default, or
- A new major API version

---

# Error Compatibility

Error envelope structure must remain stable within a version.

New error codes may be added.

Existing documented error codes must not change meaning.

---

# Authentication Compatibility

Authentication behaviour should remain stable throughout a version.

Changes to token format or authentication workflow that affect clients require version review.

---

# OpenAPI Version Alignment

Every API version has a matching OpenAPI specification.

Example:

```text
openapi-v1.yaml
openapi-v2.yaml
```

Documentation and implementation must remain synchronized.

---

# Client Migration

Migration guidance must include:

- Breaking changes
- Replacement endpoints
- Field mappings
- Example requests
- Example responses
- Timeline
- Frequently asked questions

Migration documentation should be published before removing support.

---

# Mobile Applications

Older mobile applications may continue using older API versions during the supported lifecycle.

The backend must clearly communicate unsupported versions.

---

# Third-Party Integrations

External integrations depend on stable contracts.

Breaking integrations without notice is prohibited.

Integration partners must receive sufficient migration time.

---

# Version Discovery

The API root may expose supported versions.

Example:

```json
{
    "supported_versions": ["v1"],
    "latest_version": "v1"
}
```

This endpoint is informational and does not replace documentation.

---

# Documentation

Every API version must document:

- Supported endpoints
- Authentication
- Request schemas
- Response schemas
- Error responses
- Pagination
- Filtering
- Rate limits
- Changelog
- Deprecation notices

---

# Testing

Every supported API version requires automated tests.

Tests should verify:

- Endpoint availability
- Response structure
- Authentication
- Authorization
- Error envelopes
- Pagination
- Backward compatibility

Regression tests protect existing clients.

---

# CI Enforcement

Continuous Integration should verify:

- OpenAPI specification is updated
- Breaking changes are reviewed
- Version-specific tests pass
- Documentation is current
- Deprecated endpoints remain documented
- Unsupported versions are not accidentally reintroduced

---

# Version Lifecycle

Every API version follows:

```text
Design
    ↓
Implementation
    ↓
Testing
    ↓
Documentation
    ↓
Release
    ↓
Maintenance
    ↓
Deprecation
    ↓
Sunset
    ↓
Removal
```

No version skips lifecycle governance.

---

# Definition of Done

An API version is complete only when:

- URL version exists
- Documentation complete
- OpenAPI updated
- Tests pass
- Backward compatibility reviewed
- Migration guidance prepared where required
- CI checks pass

---

# Constitution Compliance

This standard supports:

- Rule 2 — Security before features
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 11 — Every API request is untrusted
- Rule 28 — TenantContext is mandatory
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 69 — Performance tests are mandatory
- Rule 70 — Rollback tests are mandatory
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- API-Standards.md
- Authentication.md
- Error-Handling.md
- Pagination.md
- Filtering.md
- Rate-Limiting.md
- OpenAPI-Guidelines.md

---

# Final Standard

API versions are long-term contracts between ShuleOS and every client.

A released API version must remain predictable, stable, secure, and well documented throughout its supported lifecycle.

Breaking changes are introduced deliberately, communicated clearly, tested thoroughly, and released only through a new major API version.
