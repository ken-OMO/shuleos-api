# ShuleOS Authentication Architecture

> School in Clouds

## Document Information

| Field                | Value                                                              |
| -------------------- | ------------------------------------------------------------------ |
| Document             | Authentication Architecture                                        |
| Document ID          | ARCH-STD-0006                                                      |
| Version              | 1.0                                                                |
| Status               | Approved                                                           |
| Owner                | Platform Engineering                                               |
| Repository           | `shuleos-api` & `shuleos-web`                                      |
| Effective Date       | 03 August 2026                                                     |
| Related Constitution | Engineering Constitution v1.1                                      |
| Related Standards    | System Architecture, Security Standards, Multi-Tenant Architecture |

---

# Purpose

This document defines the authentication architecture used throughout the ShuleOS platform.

Authentication verifies user identity before access is granted to any protected system resources.

---

# Philosophy

Authentication answers one question:

> **Who is making this request?**

Authorization determines what the authenticated user is permitted to do.

---

# Architectural Principles

Authentication should be:

- Secure
- Stateless
- Tenant-aware
- Reliable
- Auditable
- Scalable

---

# Authentication Flow

Every protected request follows this sequence.

```text
User
    │
    ▼
Login Request
    │
    ▼
Credential Validation
    │
    ▼
JWT Generation
    │
    ▼
Client Stores Token
    │
    ▼
Authenticated API Requests
    │
    ▼
JWT Validation
    │
    ▼
Tenant Resolution
    │
    ▼
Authorization
    │
    ▼
Business Logic
```

---

# Authentication Method

ShuleOS uses:

- JWT Authentication

The backend remains stateless.

No server-side session storage is required for authenticated API requests.

---

# Login Process

Login requires:

- Email (or username if supported)
- Password

Successful authentication returns:

- Access token
- Token type
- Expiration
- Authenticated user information

---

# Credential Validation

Credentials should be verified against securely stored password hashes.

Invalid credentials must return generic error messages.

Authentication should never reveal whether a username exists.

---

# JWT Tokens

JWT tokens provide:

- User identity
- Expiration
- Integrity protection

Sensitive business information should never be embedded inside the token.

---

# Access Tokens

Access tokens authorize API requests.

Tokens should:

- Expire automatically
- Be validated on every request
- Be rejected if invalid or expired

---

# Token Refresh

Clients should request a new token before or after expiration according to the platform's authentication policy.

Refresh operations should preserve security while minimizing unnecessary logins.

---

# Logout

Logout should invalidate the authenticated session according to the configured JWT strategy.

Clients should immediately discard stored authentication tokens.

---

# Password Reset

Password reset workflow should include:

- Identity verification
- Secure reset token
- Expiration
- Password update
- Audit logging

Reset tokens should be single-use.

---

# Session Management

Although JWT is stateless, the platform should support:

- Token expiration
- Token refresh
- Logout
- Forced logout where supported

---

# Multi-Tenant Authentication

Authentication identifies the user.

Tenant resolution determines which school's resources may be accessed.

Authentication must never bypass tenant isolation.

---

# Platform Owner Authentication

Platform Owner authentication follows the same security standards while allowing access to platform-level resources.

Platform Owner privileges should never be assigned through ordinary school administration.

---

# Role Awareness

Authentication establishes identity.

Role evaluation occurs during authorization.

Authentication should remain independent of business permissions.

---

# Frontend Responsibilities

The frontend should:

- Present login forms
- Store tokens securely
- Attach tokens to API requests
- Handle expired tokens
- Redirect unauthenticated users

Business authorization remains on the backend.

---

# Backend Responsibilities

The backend should:

- Validate credentials
- Generate JWT tokens
- Validate every authenticated request
- Resolve tenant context
- Reject invalid authentication

---

# API Security

Protected API endpoints require valid authentication.

Unauthenticated requests should return appropriate HTTP status codes without exposing internal details.

---

# Token Storage

Clients should store tokens securely.

Sensitive authentication information should never be exposed through URLs, logs, or browser history.

---

# Audit Logging

Authentication events should be logged.

Examples:

- Login
- Failed login
- Logout
- Password reset
- Account lockout

Logs improve security monitoring and incident response.

---

# Error Handling

Authentication failures should return safe, consistent responses.

Internal implementation details must never be exposed.

---

# Monitoring

Authentication monitoring should include:

- Login success rates
- Failed logins
- Token validation failures
- Password reset activity
- Suspicious authentication patterns

---

# Security Considerations

Authentication architecture should protect against:

- Credential theft
- Token tampering
- Replay attacks
- Brute-force attacks
- Session hijacking

---

# Engineering Guidelines

Engineers should:

- Keep authentication stateless
- Never trust client input
- Validate every token
- Protect credentials
- Log significant authentication events
- Keep authentication separate from authorization

---

# Architecture Governance

Changes affecting authentication require:

- Security review
- Architecture review
- Documentation updates
- Test updates

---

# Constitution Compliance

This architecture reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 46 — Backend authorization is mandatory
- Rule 66 — Every feature has tests

---

# Related Documents

- System-Architecture.md
- Multi-Tenant-Architecture.md
- Authorization-Architecture.md
- Data-Flow.md
- Security Standards
- Security Testing Standards

---

# Final Standard

Every authenticated request within ShuleOS must pass through a secure, stateless, and tenant-aware authentication process before any business logic is executed.

The authentication architecture protects platform access while providing the secure foundation required for the School in the Clouds.
