# ShuleOS API Error Handling Standard

> School in Clouds

## Document Information

| Field                | Value                                                                |
| -------------------- | -------------------------------------------------------------------- |
| Document             | API Error Handling Standard                                          |
| Document ID          | API-STD-0002                                                         |
| Version              | 1.0                                                                  |
| Status               | Approved                                                             |
| Owner                | Platform Engineering                                                 |
| Repository           | `shuleos-api`                                                        |
| Effective Date       | 02 August 2026                                                       |
| Related Constitution | Engineering Constitution v1.1                                        |
| Related ADRs         | ADR-0002, ADR-0003, ADR-0004, ADR-0005, ADR-0006, ADR-0010, ADR-0011 |

---

# Purpose

This document defines the mandatory error-handling standard for all ShuleOS APIs.

It governs:

- Error envelopes
- HTTP status codes
- Validation errors
- Authentication failures
- Authorization failures
- Tenant-safe resource handling
- Conflict errors
- Idempotency errors
- External-provider failures
- Correlation identifiers
- Exception redaction
- Retryability
- Logging
- Observability
- Error documentation
- Error testing

Every endpoint must use predictable, secure, and machine-readable error responses.

---

# Core Principles

ShuleOS API errors must be:

- Consistent
- Safe
- Machine-readable
- Human-readable
- Tenant-aware
- Non-revealing
- Traceable
- Testable
- Documented

An error response must help the client recover without exposing internal implementation details.

---

# Standard Error Envelope

All API errors must use one approved envelope.

```json
{
    "success": false,
    "message": "Validation failed.",
    "code": "VALIDATION_FAILED",
    "errors": {
        "email": ["The email field is required."]
    },
    "meta": {
        "correlation_id": "01J4EXAMPLE9PGJ2Y9A8V4Z3R6"
    }
}
```

Fields:

| Field     |    Required | Purpose                                   |
| --------- | ----------: | ----------------------------------------- |
| `success` |         Yes | Always `false` for errors                 |
| `message` |         Yes | Safe user-facing summary                  |
| `code`    |         Yes | Stable machine-readable error code        |
| `errors`  | Conditional | Field or item-level details               |
| `meta`    |         Yes | Correlation and safe operational metadata |

---

# Success Flag

For every error response:

```json
{
    "success": false
}
```

The value must never be omitted or set to `true`.

---

# Error Message

The `message` field must:

- Be safe for end users
- Avoid internal class names
- Avoid stack traces
- Avoid SQL details
- Avoid provider secrets
- Avoid revealing unauthorized resource existence
- Use professional language
- Be localizable where practical

Good:

```json
{
    "message": "You are not authorized to perform this action."
}
```

Bad:

```json
{
    "message": "Policy App\\Policies\\LearnerPolicy::update returned false for user 472."
}
```

---

# Error Code

Every error response must include a stable application error code.

Examples:

```text
VALIDATION_FAILED
AUTHENTICATION_REQUIRED
AUTHENTICATION_FAILED
ACCOUNT_SUSPENDED
ACCESS_DENIED
RESOURCE_NOT_FOUND
RESOURCE_CONFLICT
IDEMPOTENCY_CONFLICT
RATE_LIMIT_EXCEEDED
PAYMENT_RECONCILIATION_REQUIRED
PROVIDER_UNAVAILABLE
INTERNAL_ERROR
```

Error codes must:

- Use uppercase snake case
- Be stable across releases
- Represent business or protocol meaning
- Avoid leaking implementation details
- Be documented in OpenAPI
- Be covered by tests

Display messages may change without changing the error code.

---

# Correlation ID

Every error response must include a correlation identifier.

Example:

```json
{
    "meta": {
        "correlation_id": "01J4EXAMPLE9PGJ2Y9A8V4Z3R6"
    }
}
```

The correlation ID must:

- Be generated or accepted through an approved request pipeline
- Be safe to share with support
- Be included in structured logs
- Be included in relevant audit records
- Not encode personal or tenant data
- Be unique enough for operational tracing

Clients may display it as:

```text
Reference: 01J4EXAMPLE9PGJ2Y9A8V4Z3R6
```

---

# HTTP Status Mapping

## 400 Bad Request

Use when the request is malformed or semantically invalid outside ordinary field validation.

Examples:

- Invalid JSON
- Unsupported operation type
- Invalid sync payload structure
- Missing required idempotency header for a protected operation

Example code:

```text
BAD_REQUEST
```

---

## 401 Unauthorized

Use when authentication is missing, invalid, expired, or revoked.

Examples:

- Missing bearer token
- Invalid JWT
- Expired access token
- Revoked session
- OTP challenge incomplete for a protected route

Recommended response:

```json
{
    "success": false,
    "message": "Authentication is required.",
    "code": "AUTHENTICATION_REQUIRED",
    "errors": null,
    "meta": {
        "correlation_id": "01J4EXAMPLE"
    }
}
```

Do not use `401` for an authenticated user who lacks permission.

---

## 403 Forbidden

Use when the user is authenticated but lacks authority.

Examples:

- Missing permission
- Scope violation
- Separation-of-duty restriction
- School-level user attempting platform action
- Role assignment outside delegated scope

Recommended code:

```text
ACCESS_DENIED
```

---

## 404 Not Found

Use when the resource is unavailable within the caller's authorized scope.

This includes:

- Resource does not exist
- Resource belongs to another tenant
- Resource is hidden because disclosure would create an IDOR risk
- Resource is archived and ordinary access is prohibited

Recommended response:

```json
{
    "success": false,
    "message": "The requested resource was not found.",
    "code": "RESOURCE_NOT_FOUND",
    "errors": null,
    "meta": {
        "correlation_id": "01J4EXAMPLE"
    }
}
```

The API must not reveal that a cross-tenant resource exists.

---

## 409 Conflict

Use when the request conflicts with current server state.

Examples:

- Duplicate active role name
- Optimistic locking conflict
- Sync version conflict
- Duplicate provider reference
- Invalid workflow transition
- Resource already exists
- Bed already allocated
- Marks already published

Recommended codes may include:

```text
RESOURCE_CONFLICT
VERSION_CONFLICT
DUPLICATE_RESOURCE
WORKFLOW_CONFLICT
```

---

## 410 Gone

Use when a resource intentionally existed but is no longer available and clients should stop requesting it.

Examples:

- Expired one-time resource
- Permanently retired API resource
- Deleted export link

Use sparingly.

---

## 412 Precondition Failed

Use when a required precondition header or version condition fails.

Examples:

- `If-Match` version mismatch
- Required resource revision does not match
- Conditional update rejected

Recommended code:

```text
PRECONDITION_FAILED
```

---

## 422 Unprocessable Content

Use for field-level validation and domain validation failures.

Examples:

- Required field missing
- Invalid email
- Cross-tenant relationship in submitted identifiers
- Invalid date range
- Amount outside business rules
- Unsupported role assignment
- Invalid mark range

Recommended code:

```text
VALIDATION_FAILED
```

---

## 423 Locked

May be used when a resource or account is temporarily locked and the distinction is safe and useful.

Examples:

- Account locked after security action
- Record locked for moderation
- Tenant in locked operational state

Use only when this does not create account-enumeration or tenant-information leakage.

Otherwise use a safer generic response.

---

## 429 Too Many Requests

Use when rate limits are exceeded.

Response should include approved retry metadata.

Example:

```json
{
    "success": false,
    "message": "Too many requests. Please try again later.",
    "code": "RATE_LIMIT_EXCEEDED",
    "errors": null,
    "meta": {
        "correlation_id": "01J4EXAMPLE",
        "retry_after_seconds": 60
    }
}
```

---

## 500 Internal Server Error

Use for unexpected internal failures.

The external response must remain generic.

```json
{
    "success": false,
    "message": "An unexpected error occurred.",
    "code": "INTERNAL_ERROR",
    "errors": null,
    "meta": {
        "correlation_id": "01J4EXAMPLE"
    }
}
```

Never return:

- Exception message
- File path
- Stack trace
- SQL query
- Environment value
- Secret
- Internal host
- Provider credential

---

## 502 Bad Gateway

Use when ShuleOS receives an invalid response from an upstream dependency.

Examples:

- Invalid payment provider response
- Invalid email provider response
- Malformed storage-provider response

Recommended code:

```text
UPSTREAM_INVALID_RESPONSE
```

---

## 503 Service Unavailable

Use when a required service is temporarily unavailable.

Examples:

- Database unavailable
- Queue unavailable for a mandatory synchronous prerequisite
- Payment provider outage
- Email provider outage where the request cannot be safely accepted
- Maintenance mode

Recommended codes:

```text
SERVICE_UNAVAILABLE
PROVIDER_UNAVAILABLE
MAINTENANCE_IN_PROGRESS
```

Where possible, authoritative business writes should complete and external work should queue instead of returning failure.

---

## 504 Gateway Timeout

Use when a required upstream dependency fails to respond within the approved timeout.

Recommended code:

```text
UPSTREAM_TIMEOUT
```

Timeout does not always prove that the provider did not complete the action.

Financial and notification workflows must reconcile uncertain outcomes before retrying blindly.

---

# Validation Errors

Validation errors must use:

```http
422 Unprocessable Content
```

Example:

```json
{
    "success": false,
    "message": "Validation failed.",
    "code": "VALIDATION_FAILED",
    "errors": {
        "first_name": ["The first name field is required."],
        "email": ["The email must be a valid email address."]
    },
    "meta": {
        "correlation_id": "01J4EXAMPLE"
    }
}
```

Validation error keys should match request field names.

Nested fields may use dot notation:

```json
{
    "errors": {
        "guardian.email": ["The guardian email is invalid."]
    }
}
```

Array entries may use indexed paths:

```json
{
    "errors": {
        "learners.2.admission_number": [
            "The admission number is already in use in this school."
        ]
    }
}
```

---

# Domain Validation

Business-rule failures may also use `422` when the request is structurally valid but unacceptable.

Examples:

- Learner age outside permitted admission rule
- Payment allocation exceeds available amount
- Teacher assignment conflicts with current allocation
- Attempt to publish incomplete results
- Invalid role delegation boundary

The error code should be more specific where clients need different recovery logic.

Example:

```json
{
    "success": false,
    "message": "The payment allocation exceeds the available amount.",
    "code": "PAYMENT_ALLOCATION_EXCEEDS_AVAILABLE_AMOUNT",
    "errors": {
        "amount_minor": [
            "The requested allocation exceeds the unallocated payment balance."
        ]
    },
    "meta": {
        "correlation_id": "01J4EXAMPLE"
    }
}
```

---

# Authentication Errors

Authentication responses must avoid account enumeration.

A failed login should not reveal whether:

- The email exists
- The password was correct
- The school exists
- The account is suspended
- The school is locked

Recommended response:

```json
{
    "success": false,
    "message": "The provided credentials could not be verified.",
    "code": "AUTHENTICATION_FAILED",
    "errors": null,
    "meta": {
        "correlation_id": "01J4EXAMPLE"
    }
}
```

More detailed reasons may be recorded internally.

---

# Account-State Errors

Account-state responses must balance usability and security.

Possible internal reasons include:

- Suspended
- Locked
- Archived
- Password reset required
- Email verification required
- OTP pending

External details should be exposed only where safe and required for the next step.

Example for an already authenticated session:

```json
{
    "success": false,
    "message": "Your account cannot access this service at this time.",
    "code": "ACCOUNT_ACCESS_RESTRICTED",
    "errors": null,
    "meta": {
        "correlation_id": "01J4EXAMPLE"
    }
}
```

---

# Authorization Errors

Authorization failures must not reveal protected policy details.

Good:

```json
{
    "success": false,
    "message": "You are not authorized to perform this action.",
    "code": "ACCESS_DENIED",
    "errors": null,
    "meta": {
        "correlation_id": "01J4EXAMPLE"
    }
}
```

Bad:

```json
{
    "message": "You need finance.reverse_payment, but you only have finance.capture_payment."
}
```

Detailed permission diagnostics may exist only in authorized administration or internal tooling.

---

# Tenant-Safe Not Found Behaviour

For tenant-owned resources, the API should generally return the same `404` response when:

- The ID does not exist
- The ID belongs to another school
- The user lacks resource visibility
- The resource is unavailable in the active governance scope

This prevents resource enumeration.

Example:

```json
{
    "success": false,
    "message": "The requested resource was not found.",
    "code": "RESOURCE_NOT_FOUND",
    "errors": null,
    "meta": {
        "correlation_id": "01J4EXAMPLE"
    }
}
```

---

# Conflict Errors

Conflict responses must explain the recovery action safely.

Example:

```json
{
    "success": false,
    "message": "The record was changed by another operation.",
    "code": "VERSION_CONFLICT",
    "errors": null,
    "meta": {
        "correlation_id": "01J4EXAMPLE",
        "current_version": 8
    }
}
```

Do not include sensitive server state merely to help merge conflicts.

---

# Idempotency Errors

Idempotent endpoints must distinguish:

## Same Key, Same Request

Return the original result.

## Same Key, Different Request

Return:

```http
409 Conflict
```

Example:

```json
{
    "success": false,
    "message": "The idempotency key has already been used for a different request.",
    "code": "IDEMPOTENCY_CONFLICT",
    "errors": null,
    "meta": {
        "correlation_id": "01J4EXAMPLE"
    }
}
```

The response must not reveal another tenant's idempotent operation.

---

# Offline Sync Errors

Sync operations require per-operation results.

Example:

```json
{
    "success": false,
    "message": "Some synchronization operations could not be applied.",
    "code": "SYNC_PARTIAL_FAILURE",
    "data": {
        "operations": [
            {
                "operation_id": "client-op-001",
                "status": "applied"
            },
            {
                "operation_id": "client-op-002",
                "status": "conflict",
                "code": "VERSION_CONFLICT"
            },
            {
                "operation_id": "client-op-003",
                "status": "rejected",
                "code": "ACCESS_DENIED"
            }
        ]
    },
    "meta": {
        "correlation_id": "01J4EXAMPLE"
    }
}
```

Batch-level success must not hide individual failures.

---

# Payment Errors

Financial errors must be specific enough for reconciliation while remaining safe.

Examples:

```text
PAYMENT_PENDING
PAYMENT_FAILED
PAYMENT_DUPLICATE
PAYMENT_AMOUNT_MISMATCH
PAYMENT_RECONCILIATION_REQUIRED
PAYMENT_PROVIDER_UNAVAILABLE
PAYMENT_OWNERSHIP_INVALID
INSUFFICIENT_UNALLOCATED_AMOUNT
```

A timeout or provider error must not automatically mark a payment as failed when the result is uncertain.

---

# Notification Errors

Examples:

```text
NOTIFICATION_SUPPRESSED
RECIPIENT_UNAVAILABLE
INSUFFICIENT_SMS_CREDITS
PROVIDER_UNAVAILABLE
DELIVERY_DEFERRED
TEMPLATE_NOT_AVAILABLE
```

Business transactions should not be rolled back merely because an asynchronous notification fails.

---

# File Errors

Examples:

```text
FILE_TYPE_NOT_ALLOWED
FILE_TOO_LARGE
FILE_QUARANTINED
FILE_SCAN_PENDING
FILE_REJECTED
FILE_NOT_AVAILABLE
FILE_ACCESS_DENIED
```

Do not return private object keys, provider URLs, or scan-engine details.

---

# Provider Errors

External providers must be mapped into ShuleOS error codes.

The API must not expose raw provider errors directly.

Provider-specific details belong in structured internal logs.

Example external response:

```json
{
    "success": false,
    "message": "The requested service is temporarily unavailable.",
    "code": "PROVIDER_UNAVAILABLE",
    "errors": null,
    "meta": {
        "correlation_id": "01J4EXAMPLE",
        "retryable": true
    }
}
```

---

# Retryability

Where useful, error metadata may indicate whether retry is appropriate.

```json
{
    "meta": {
        "correlation_id": "01J4EXAMPLE",
        "retryable": true,
        "retry_after_seconds": 30
    }
}
```

Clients must not retry non-idempotent operations automatically unless the endpoint supports idempotency.

---

# Error Metadata

Approved metadata may include:

- `correlation_id`
- `retryable`
- `retry_after_seconds`
- `current_version`
- `minimum_supported_version`
- `documentation_code`
- `operation_id`

Metadata must not include:

- Internal database IDs unrelated to the caller
- Stack traces
- SQL
- Secrets
- Provider credentials
- Private storage paths
- Unauthorized tenant identifiers

---

# Exception Handling

All unhandled exceptions must pass through one centralized exception-rendering layer.

The renderer must:

- Map known domain exceptions
- Map validation exceptions
- Map authentication exceptions
- Map authorization exceptions
- Map model-not-found exceptions
- Map provider exceptions
- Generate a correlation ID
- Return a safe envelope
- Log internal details securely
- Avoid environment-specific leakage

Controllers must not invent their own inconsistent exception formats.

---

# Domain Exceptions

Business services may throw approved domain exceptions.

Examples:

```text
PaymentOwnershipException
VersionConflictException
InsufficientSmsCreditsException
InvalidRoleDelegationException
SubscriptionLockedException
```

Every domain exception must define or map to:

- HTTP status
- Stable error code
- Safe message
- Retryability
- Log severity
- Audit requirement where applicable

---

# Internal Logging

Internal logs may include:

- Correlation ID
- Exception class
- Safe error code
- Stack trace
- Route
- HTTP method
- User identifier
- Tenant identifier
- Governance scope
- Safe request metadata
- Provider reference
- Database error code
- Timing

Logs must not include:

- Passwords
- OTP values
- Raw tokens
- API keys
- Provider secrets
- Payment credentials
- Full sensitive request bodies
- Unnecessary learner data

---

# Audit Logging

An error requires an audit record where it represents a sensitive attempted action.

Examples:

- Cross-tenant access attempt
- Privilege-escalation attempt
- Payment reversal denial
- Authentication lockout
- Provider credential change failure
- Support access denial
- Examination publication denial
- File malware rejection

Ordinary field validation failures do not always require audit records.

---

# Error Severity

Internal severity may use:

- Debug
- Info
- Warning
- Error
- Critical

Examples:

## Info

Expected user validation failure.

## Warning

Repeated denied action or recoverable provider issue.

## Error

Unexpected application failure or permanent provider failure.

## Critical

Cross-tenant exposure risk, payment integrity failure, credential compromise, or major outage.

External HTTP status does not determine internal log severity by itself.

---

# Observability

Error monitoring must track:

- Error rate by endpoint
- Error rate by code
- Validation failure trends
- Authentication failures
- Authorization denials
- Cross-tenant denials
- Provider failure rates
- Database failures
- Timeout rates
- Conflict rates
- Retry rates
- Dead-letter volume
- Internal server errors
- Per-tenant anomaly patterns

Critical thresholds must generate alerts.

---

# Localization

Error codes remain language-independent.

Messages may be localized.

Example:

```text
Code: VALIDATION_FAILED
English: Validation failed.
Kiswahili: Uthibitishaji wa taarifa umeshindikana.
```

Clients must rely on `code`, not message text, for program logic.

---

# Frontend Behaviour

The frontend should:

- Display the safe message
- Render field errors near inputs
- Use error codes for recovery logic
- Display correlation IDs for unexpected errors
- Avoid exposing raw response objects
- Avoid retrying unsafe operations automatically
- Preserve user input where practical
- Prompt re-authentication for `401`
- Show access-denied guidance for `403`
- Show not-found guidance for `404`
- Respect `retry_after_seconds` for `429`

Frontend handling never replaces backend validation or authorization.

---

# OpenAPI Documentation

Every documented endpoint must define:

- Error status codes
- Error envelope schema
- Stable error codes
- Validation-error example
- Authentication error
- Authorization error
- Not-found error
- Conflict error where applicable
- Rate-limit error
- Provider error where applicable
- Internal error

Shared error components should be reused in OpenAPI.

---

# Testing Requirements

Every endpoint must test applicable errors.

Tests should include:

- Invalid request
- Missing authentication
- Invalid token
- Missing permission
- Cross-tenant resource
- Missing resource
- Validation failure
- Conflict
- Rate limit
- Provider failure
- Internal exception redaction
- Correlation ID presence
- Stable error code
- Safe message
- No stack trace
- No secret leakage

Critical domains also require:

- Idempotency conflict tests
- Replay tests
- Timeout uncertainty tests
- Error-state transition tests
- Audit tests
- Observability tests where practical

---

# CI Enforcement

CI should verify where practical:

- All errors follow the standard envelope.
- Every error contains a code.
- Every error contains a correlation ID.
- Internal exception text is not exposed.
- Cross-tenant access returns a safe response.
- OpenAPI includes documented error schemas.
- Sensitive fields do not appear in snapshots.
- Authentication and authorization failures remain consistent.

---

# Error Code Governance

New error codes must:

1. Have a clear purpose.
2. Avoid duplicating an existing code.
3. Use uppercase snake case.
4. Be documented.
5. Be tested.
6. Be added to OpenAPI where public.
7. Remain stable after release.

Error codes should not be renamed casually.

If semantics change incompatibly, introduce a new code.

---

# Definition of Done

Error handling for an endpoint is complete only when:

- Appropriate HTTP statuses are used.
- Standard envelope is used.
- Stable codes are defined.
- Validation details are safe.
- Tenant existence is not leaked.
- Correlation ID is present.
- Internal exceptions are redacted.
- Retry behaviour is documented.
- Logs are structured.
- Sensitive actions are audited.
- OpenAPI is updated.
- Tests pass.

---

# Constitution Compliance

This standard supports:

- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 7 — Human Experience
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 13 — Protect against IDOR
- Rule 14 — No endpoint bypasses the security pipeline
- Rule 17 — Audit important actions
- Rule 18 — Never expose internal exceptions
- Rule 19 — Every security feature is tested
- Rule 30 — Every query is tenant scoped
- Rule 46 — Frontend authorization never replaces backend authorization
- Rule 55 — Financial operations are idempotent
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 88 — Authentication has one source of truth
- Rule 89 — Authorization fails closed
- Rule 93 — Access revocation takes effect on the next request
- Rule 100 — Idempotency uses stored keys
- Rule 102 — Security-critical invariants are verified automatically
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI
- Rule 121 — Learner information receives the highest privacy classification
- Rule 126 — Localization is a platform capability
- Rule 127 — English and Kiswahili are first-class languages

---

# Related Documents

- `API-Standards.md`
- `Authentication.md`
- `Pagination.md`
- `Filtering.md`
- `Rate-Limiting.md`
- `Versioning.md`
- `OpenAPI-Guidelines.md`

---

# Final Standard

Every ShuleOS API error must be safe, consistent, traceable, machine-readable, tenant-aware, and useful to an authorized client.

Internal failures must remain internal.

The API must reveal enough information for recovery, but never enough information to weaken security, expose another tenant, disclose secrets, or leak implementation details.
