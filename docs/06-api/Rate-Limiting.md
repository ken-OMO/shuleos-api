# ShuleOS API Rate Limiting Standard

> School in Clouds

## Document Information

| Field                | Value                                            |
| -------------------- | ------------------------------------------------ |
| Document             | API Rate Limiting Standard                       |
| Document ID          | API-STD-0007                                     |
| Version              | 1.0                                              |
| Status               | Approved                                         |
| Owner                | Platform Engineering                             |
| Repository           | `shuleos-api`                                    |
| Effective Date       | 02 August 2026                                   |
| Related Constitution | Engineering Constitution v1.1                    |
| Related ADRs         | ADR-0003, ADR-0005, ADR-0006, ADR-0008, ADR-0009 |

---

# Purpose

This document defines the mandatory rate limiting standard for every ShuleOS API.

It governs:

- Request throttling
- Authentication protection
- Abuse prevention
- Per-user limits
- Per-IP limits
- Per-tenant limits
- Public endpoints
- Internal APIs
- Retry behaviour
- Rate limit headers
- Monitoring
- Testing
- CI enforcement

Rate limiting protects platform stability while ensuring fair access for all schools.

---

# Core Principles

Rate limiting must be:

- Predictable
- Fair
- Tenant-aware
- Configurable
- Observable
- Secure
- Testable

Rate limiting protects services without disrupting legitimate users.

---

# Objectives

Rate limiting prevents:

- Brute-force attacks
- Credential stuffing
- API abuse
- Denial-of-service amplification
- SMS abuse
- Email abuse
- Payment callback flooding
- Excessive synchronization requests

---

# Rate Limiting Scope

Limits may apply to:

- IP address
- Authenticated user
- School (tenant)
- API key
- Client application
- Specific endpoint

The appropriate scope depends on the endpoint.

---

# Standard Limits

General authenticated API:

```
120 requests / minute / user
```

General public endpoints:

```
60 requests / minute / IP
```

Administrative endpoints may use stricter limits where appropriate.

---

# Authentication Endpoints

Login:

```
10 requests / minute / IP
```

Password reset:

```
5 requests / 15 minutes / IP
```

Email verification resend:

```
5 requests / hour / account
```

OTP verification:

```
10 attempts / 15 minutes
```

---

# Notification Endpoints

SMS sending:

- Limited per tenant
- Limited per user
- Subject to available SMS credits

Email sending:

- Subject to provider quotas
- Subject to platform policies

---

# Payment Endpoints

Payment callback endpoints must verify:

- Provider signature
- Idempotency
- Duplicate callbacks

Rate limiting must never replace cryptographic verification.

---

# Synchronization Endpoints

Offline synchronization endpoints may use:

- Higher burst limits
- Tenant-aware quotas
- Payload size limits

Abusive synchronization must be throttled.

---

# Rate Limit Response

Exceeded limits return:

```http
429 Too Many Requests
```

Example:

```json
{
    "success": false,
    "message": "Too many requests. Please try again later.",
    "code": "RATE_LIMIT_EXCEEDED",
    "meta": {
        "retry_after_seconds": 60,
        "correlation_id": "01J4EXAMPLE"
    }
}
```

---

# Response Headers

Responses should include:

```
X-RateLimit-Limit
X-RateLimit-Remaining
Retry-After
```

These headers help clients behave responsibly.

---

# Retry Behaviour

Clients should:

- Respect `Retry-After`
- Avoid immediate retries
- Apply exponential backoff where appropriate

Automatic retries are not appropriate for every endpoint.

---

# Tenant Fairness

One school's traffic must not degrade service for another.

Rate limiting should isolate abusive tenants where practical.

---

# Burst Handling

Short bursts may be permitted.

Sustained excessive traffic should be throttled.

---

# Monitoring

Track:

- Throttled requests
- Login failures
- SMS abuse
- Email abuse
- High-volume tenants
- High-volume IP addresses
- Callback floods

Alerts should be generated for abnormal patterns.

---

# Logging

Rate limit events should log:

- Correlation ID
- Tenant ID
- User ID (if authenticated)
- Endpoint
- IP address
- Timestamp
- Applied policy

Do not log sensitive credentials.

---

# OpenAPI

Endpoints should document:

- Rate-limited behaviour
- `429` responses
- Relevant headers

---

# Testing

Tests should verify:

- Authenticated limits
- Public limits
- Login throttling
- Retry headers
- Tenant isolation
- Correct `429` response
- Header values

---

# CI Enforcement

CI should verify:

- Rate-limited endpoints are documented
- `429` responses follow the standard error envelope
- Tests pass
- Headers are present where applicable

---

# Definition of Done

Rate limiting is complete only when:

- Limits are defined
- Appropriate scopes selected
- Headers implemented
- Monitoring enabled
- Documentation complete
- Tests pass

---

# Constitution Compliance

This standard supports:

- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 11 — Every API request is untrusted
- Rule 17 — Audit important actions
- Rule 19 — Every security feature is tested
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- API-Standards.md
- Authentication.md
- Error-Handling.md
- OpenAPI-Guidelines.md

---

# Final Standard

Rate limiting is a platform protection mechanism, not a substitute for authentication, authorization, or input validation.

Every ShuleOS endpoint must apply appropriate throttling to preserve platform stability, protect shared resources, and ensure fair access for every tenant.
