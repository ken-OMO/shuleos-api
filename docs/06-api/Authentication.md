# ShuleOS API Authentication Standard

> School in Clouds

## Document Information

| Field                | Value                                            |
| -------------------- | ------------------------------------------------ |
| Document             | API Authentication Standard                      |
| Document ID          | API-STD-0003                                     |
| Version              | 1.0                                              |
| Status               | Approved                                         |
| Owner                | Platform Engineering                             |
| Repository           | `shuleos-api`                                    |
| Effective Date       | 02 August 2026                                   |
| Related Constitution | Engineering Constitution v1.1                    |
| Related ADRs         | ADR-0002, ADR-0003, ADR-0004, ADR-0010, ADR-0011 |

---

# Purpose

This document defines the mandatory authentication standard for every ShuleOS API.

It governs:

- Identity verification
- JWT authentication
- Login
- Logout
- Token refresh
- Password reset
- Account state enforcement
- Tenant resolution
- Session revocation
- Authentication middleware
- Token lifecycle
- Authentication events
- Audit logging
- Security testing

Authentication answers one question:

> **Who is making this request?**

Authorization is handled separately.

---

# Core Principles

Authentication must be:

- Stateless
- Secure
- Predictable
- Auditable
- Tenant-aware
- Revocable
- Testable
- Observable

Authentication never replaces authorization.

---

# Authentication Architecture

ShuleOS uses:

- JWT Bearer Tokens
- HTTPS
- Laravel 12
- `php-open-source-saver/jwt-auth`
- Stateless APIs

Every authenticated request carries:

```http
Authorization: Bearer <access-token>
```

---

# Authentication Flow

Standard flow:

```text
User
    │
    ▼
POST /auth/login
    │
Credentials validated
    │
JWT issued
    │
Client stores token securely
    │
Authorization: Bearer TOKEN
    │
JWT Middleware
    │
Authenticated User
    │
Tenant Resolution
    │
Authorization Policies
    │
Business Logic
```

Authentication always precedes authorization.

---

# Login Endpoint

```
POST /api/v1/auth/login
```

Example request:

```json
{
    "email": "teacher@example.com",
    "password": "********"
}
```

Successful response:

```json
{
    "success": true,
    "message": "Login successful.",
    "data": {
        "token": "...",
        "token_type": "Bearer",
        "expires_in": 3600,
        "user": {
            "...": "..."
        }
    }
}
```

---

# Failed Login

Always return a generic response.

Example:

```json
{
    "success": false,
    "message": "The provided credentials could not be verified.",
    "code": "AUTHENTICATION_FAILED"
}
```

Do not reveal:

- Email existence
- School existence
- Password correctness
- Account status

---

# JWT Tokens

JWTs represent authenticated identity.

Tokens must contain only required claims.

Possible claims:

- subject
- issued at
- expiration
- issuer
- audience
- JWT identifier

Avoid storing unnecessary user information inside tokens.

---

# Token Lifetime

Access tokens must expire.

Expiry duration is configurable.

Very long-lived access tokens are prohibited.

---

# Token Refresh

Refresh endpoint:

```
POST /auth/refresh
```

Refresh:

- validates token
- issues new token
- invalidates previous token where configured

---

# Logout

Logout endpoint:

```
POST /auth/logout
```

Logout must invalidate the authenticated token according to the configured JWT strategy.

Clients must delete locally stored tokens immediately after logout.

---

# Password Reset

Password reset requires:

- secure reset tokens
- expiration
- one-time use
- audit logging

Reset links must never reveal account existence.

---

# Email Verification

Accounts may require verified email before accessing protected features.

Verification status is enforced server-side.

---

# Account State

Authentication must enforce account state.

Examples:

- active
- suspended
- locked
- archived
- pending verification
- password reset required

Restricted accounts must not receive ordinary authenticated access.

---

# Tenant Resolution

Authentication establishes identity.

Tenant resolution establishes operational scope.

Tenant context must never come from untrusted client input.

---

# Authentication Middleware

Protected endpoints must use JWT authentication middleware.

Unauthenticated requests return:

```
401 Unauthorized
```

---

# Authorization Separation

Authentication:

> Who are you?

Authorization:

> What may you do?

These responsibilities must remain separate.

---

# Session Revocation

Revocation events include:

- logout
- password reset
- compromised account
- administrator action
- subscription restrictions where applicable

Revoked tokens must no longer authenticate requests.

---

# Token Storage

Browser applications should avoid insecure token storage.

Sensitive tokens must never appear in:

- logs
- URLs
- screenshots
- analytics payloads

---

# HTTPS Requirement

Authentication is valid only over HTTPS in production.

Plain HTTP is prohibited for production deployments.

---

# Authentication Events

Events should include:

- login
- failed login
- logout
- token refresh
- password reset
- account lock
- account unlock

Sensitive events require audit records.

---

# Audit Logging

Authentication audit logs may include:

- user ID
- tenant ID
- timestamp
- IP address
- user agent
- outcome
- correlation ID

Never log passwords or raw JWTs.

---

# Rate Limiting

Protect authentication endpoints.

Examples:

- login
- password reset
- refresh
- OTP verification

Excessive attempts should trigger throttling.

---

# Security Requirements

Authentication must protect against:

- brute force
- replay attacks
- credential stuffing
- token theft
- session fixation
- enumeration attacks

---

# Offline Clients

Offline clients must re-authenticate when required.

Expired or revoked tokens must never grant continued server access.

Offline data synchronization must authenticate before upload.

---

# Error Responses

Authentication errors follow the standard API error envelope.

Typical codes:

- AUTHENTICATION_REQUIRED
- AUTHENTICATION_FAILED
- ACCOUNT_ACCESS_RESTRICTED
- TOKEN_EXPIRED
- TOKEN_INVALID
- TOKEN_REVOKED

---

# Testing Requirements

Authentication tests include:

- successful login
- failed login
- expired token
- revoked token
- missing token
- malformed token
- logout
- refresh
- account suspension
- tenant resolution
- audit events

---

# Observability

Authentication monitoring should include:

- login success rate
- failed login rate
- lockouts
- refresh frequency
- token expiry failures
- unusual geographic access
- repeated invalid tokens

---

# OpenAPI Requirements

Document:

- login request
- login response
- bearer authentication
- refresh endpoint
- logout endpoint
- authentication errors
- example requests
- example responses

---

# Definition of Done

Authentication implementation is complete only when:

- JWT authentication works
- HTTPS enforced
- tenant resolved
- tokens expire
- revocation works
- audit logging exists
- tests pass
- documentation complete

---

# Constitution Compliance

This standard supports:

- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 13 — Protect against IDOR
- Rule 17 — Audit important actions
- Rule 18 — Never expose internal exceptions
- Rule 19 — Every security feature is tested
- Rule 28 — TenantContext is mandatory
- Rule 46 — Frontend authorization never replaces backend authorization
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 88 — Authentication has one source of truth
- Rule 89 — Authorization fails closed
- Rule 93 — Access revocation takes effect on the next request
- Rule 107 — Production systems are observable

---

# Related Documents

- API-Standards.md
- Error-Handling.md
- Versioning.md
- Rate-Limiting.md
- OpenAPI-Guidelines.md
- ADR-0003 — JWT Authentication

---

# Final Standard

Authentication is the front door to ShuleOS.

Every authenticated request must establish identity securely, enforce tenant-aware access, protect user credentials, support rapid revocation, generate appropriate audit records, and provide a consistent experience across every API.

Authentication proves identity.

Authorization determines permission.

The two must never be confused.
