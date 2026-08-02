# ShuleOS Authentication Security Standard

> School in Clouds

## Document Information

| Field                | Value                                                                                                   |
| -------------------- | ------------------------------------------------------------------------------------------------------- |
| Document             | Authentication Security Standard                                                                        |
| Document ID          | SEC-STD-0002                                                                                            |
| Version              | 1.0                                                                                                     |
| Status               | Approved                                                                                                |
| Owner                | Platform Engineering                                                                                    |
| Repository           | `shuleos-api`                                                                                           |
| Effective Date       | 02 August 2026                                                                                          |
| Related Constitution | Engineering Constitution v1.1                                                                           |
| Related ADRs         | ADR-0003 (JWT Authentication), ADR-0010 (Role Template System), ADR-0011 (Multi-Level Tenant Hierarchy) |

---

# Purpose

This document defines the mandatory authentication security requirements for ShuleOS.

It governs:

- Identity verification
- Password security
- JWT security
- Session security
- Token lifecycle
- Password reset
- Email verification
- Account lockout
- Multi-factor authentication
- Credential storage
- Device security
- Authentication logging
- Authentication monitoring
- Security testing

Authentication is the first security boundary protecting every school and every user.

---

# Security Principles

Authentication must be:

- Secure by default
- Stateless
- Tenant-aware
- Auditable
- Revocable
- Observable
- Testable

Authentication proves identity.

Authorization determines permissions.

---

# Identity Verification

Every authenticated request must verify:

- User identity
- Account status
- Tenant membership
- Token validity
- Session state

No protected endpoint may bypass authentication.

---

# Password Policy

Passwords must:

- Have a minimum length of 12 characters
- Encourage passphrases
- Support all printable Unicode characters
- Never be stored in plain text
- Never be logged
- Never be reversible

Password complexity rules should encourage strong passwords without forcing predictable patterns.

---

# Password Hashing

Passwords must be hashed using:

```text
Argon2id
```

Laravel's password hashing configuration must remain current with recommended parameters.

Passwords are never encrypted.

They are always hashed.

---

# Password Reset

Password reset tokens must:

- Be cryptographically secure
- Expire automatically
- Be single-use
- Be invalidated after success
- Generate audit records

Reset responses must never reveal whether an account exists.

---

# Email Verification

Email verification tokens must:

- Expire
- Be single-use
- Be cryptographically secure

Unverified accounts may have restricted functionality.

---

# Multi-Factor Authentication

The platform architecture must support MFA.

Supported future methods may include:

- TOTP authenticator applications
- Email verification codes
- SMS verification
- Passkeys (WebAuthn)

MFA policies should be configurable by tenant.

---

# JWT Security

JWT access tokens must:

- Expire
- Be signed securely
- Be validated on every request
- Support revocation
- Never contain secrets
- Never contain unnecessary personal information

JWTs are identity tokens—not data stores.

---

# Token Revocation

Tokens must be revoked when:

- User logs out
- Password changes
- Administrator disables account
- Security incident occurs
- Tenant access is revoked

Revoked tokens must immediately lose access.

---

# Session Security

Every session must support:

- Explicit logout
- Expiration
- Revocation
- Audit logging

Inactive sessions should expire automatically.

---

# Device Management

The platform should support:

- Multiple devices
- Session visibility
- Session termination
- Device identification

Future enhancements may include trusted-device management.

---

# Account Lockout

Repeated authentication failures should trigger temporary lockout.

Example policy:

- 10 failed attempts
- 15-minute lockout

Policies should remain configurable.

---

# Brute Force Protection

Authentication must resist:

- Brute-force attacks
- Password spraying
- Credential stuffing
- Automated login attempts

Rate limiting complements—but does not replace—secure authentication.

---

# Credential Storage

Credentials must never appear in:

- Source control
- Logs
- Screenshots
- Browser URLs
- Error messages

Secrets belong in secure environment configuration.

---

# Authentication Logging

Authentication logs should record:

- Login success
- Login failure
- Logout
- Password reset
- Password change
- Email verification
- Token revocation
- Account lockout

Logs must not contain passwords or raw tokens.

---

# Audit Logging

Authentication events requiring audit records include:

- Successful login
- Failed login
- Password reset
- Administrator account changes
- Role escalation
- Token revocation
- Account suspension

Audit logs must be immutable.

---

# Monitoring

Security monitoring should detect:

- Excessive failed logins
- Multiple geographic locations
- Repeated token failures
- Credential stuffing
- Suspicious session activity
- Unusual tenant activity

Critical events require alerting.

---

# Secure Defaults

Authentication defaults must include:

- HTTPS
- JWT expiration
- Strong password hashing
- Account status checks
- Audit logging
- Rate limiting

Secure defaults reduce operational risk.

---

# Testing

Authentication security tests must verify:

- Password hashing
- JWT validation
- Token expiration
- Token revocation
- Password reset
- Email verification
- Lockout
- Tenant isolation
- Audit logging

Critical authentication paths require automated regression tests.

---

# Continuous Integration

CI should verify:

- Authentication tests pass
- No secrets committed
- Password hashing configuration remains approved
- Security documentation updated
- Static analysis completed

---

# Definition of Done

Authentication security is complete only when:

- Passwords hashed
- JWT validated
- Tokens expire
- Revocation works
- Lockout enforced
- Audit logging enabled
- Tests pass
- Documentation complete

---

# Constitution Compliance

This standard reinforces:

- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 11 — Every API request is untrusted
- Rule 17 — Audit important actions
- Rule 19 — Every security feature is tested
- Rule 30 — Every query is tenant scoped
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 88 — Authentication has one source of truth
- Rule 89 — Authorization fails closed
- Rule 93 — Access revocation takes effect on the next request
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- Security-Standards.md
- Authorization.md
- Secrets-Management.md
- Encryption.md
- API Authentication Standard
- API Rate Limiting Standard

---

# Final Standard

Authentication is the foundation of trust within ShuleOS.

Every authentication decision must protect user identities, preserve tenant isolation, support rapid revocation, generate complete audit records, and resist modern attack techniques while providing a reliable and secure experience for every school using the platform.
