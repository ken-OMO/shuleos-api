# ShuleOS Secrets Management Standard

> School in Clouds

## Document Information

| Field                | Value                                                      |
| -------------------- | ---------------------------------------------------------- |
| Document             | Secrets Management Standard                                |
| Document ID          | SEC-STD-0004                                               |
| Version              | 1.0                                                        |
| Status               | Approved                                                   |
| Owner                | Platform Engineering                                       |
| Repository           | `shuleos-api`                                              |
| Effective Date       | 02 August 2026                                             |
| Related Constitution | Engineering Constitution v1.1                              |
| Related ADRs         | ADR-0003, ADR-0005, ADR-0006, ADR-0007, ADR-0008, ADR-0009 |

---

# Purpose

This document defines the mandatory standard for managing secrets throughout the ShuleOS platform.

It governs:

- Environment variables
- API keys
- Database credentials
- JWT secrets
- Cloud credentials
- CI/CD secrets
- Secret rotation
- Secret auditing
- Secret storage
- Secret access control
- Emergency rotation
- Security testing

Secrets are among the most sensitive assets in the platform and must be protected throughout their lifecycle.

---

# Security Principles

Secrets management must be:

- Secure by default
- Least privilege
- Encrypted
- Auditable
- Rotatable
- Environment-specific
- Never committed to source control

Every secret has an owner and a defined lifecycle.

---

# What Is a Secret?

A secret is any value that grants access to protected systems or sensitive data.

Examples include:

- JWT signing keys
- Database passwords
- PostgreSQL credentials
- Cloudflare R2 keys
- Resend API keys
- Africa's Talking API keys
- M-Pesa Daraja credentials
- SMTP credentials
- Redis passwords
- OAuth client secrets
- Encryption keys
- Session secrets

---

# Environment Variables

Secrets must be stored in environment variables.

Examples:

```env
APP_KEY=
JWT_SECRET=

DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=

RESEND_API_KEY=
AFRICASTALKING_API_KEY=
```

Application code must never hard-code secrets.

---

# Source Control

Secrets must never be committed to Git.

The repository must include:

```
.env.example
```

but never:

```
.env
```

Secret scanning should be enabled where possible.

---

# Environment Separation

Each environment maintains independent secrets.

Examples:

- Local Development
- Testing
- Staging
- Production

Secrets must not be reused across environments unless explicitly approved.

---

# Access Control

Access to secrets must follow least privilege.

Only users and services that require a secret should have access to it.

Administrative access must be logged.

---

# Secret Rotation

Secrets must support rotation without requiring major application changes.

Rotation should occur:

- On suspected compromise
- During major infrastructure changes
- On a defined schedule for high-risk credentials
- When personnel with privileged access leave

Old secrets should be revoked promptly after rotation.

---

# Secret Storage

Approved storage locations include:

- Environment variables
- Managed secret stores
- Secure deployment platforms

Secrets must never be stored in:

- Source code
- Configuration committed to Git
- Documentation examples
- Public issue trackers
- Screenshots

---

# CI/CD

Build pipelines must retrieve secrets securely.

Secrets must never be printed in build logs.

Deployment systems should inject secrets at runtime.

---

# Logging

Applications must never log:

- API keys
- Passwords
- JWT secrets
- Access tokens
- Refresh tokens
- Encryption keys

Logs should redact sensitive values automatically.

---

# Secret Auditing

Maintain an inventory of critical secrets including:

- Owner
- Purpose
- Environment
- Rotation date
- Last review
- Expiration (if applicable)

Audit records should support compliance and incident response.

---

# Backup

Critical secrets required for disaster recovery must be backed up securely.

Backups must:

- Be encrypted
- Have controlled access
- Be tested periodically

---

# Emergency Rotation

The platform must support rapid secret replacement following:

- Credential leakage
- Unauthorized access
- Infrastructure compromise
- Third-party provider breach

Emergency procedures should minimize service disruption.

---

# Third-Party Integrations

Credentials for external services such as:

- Cloudflare R2
- Resend
- Africa's Talking
- M-Pesa Daraja

must each use separate credentials and follow provider-specific security guidance.

---

# Development

Developers should use local development secrets that never match production credentials.

Shared development credentials should be avoided where practical.

---

# Monitoring

Security monitoring should detect:

- Failed authentication to external providers
- Unexpected credential usage
- Secret access anomalies
- Frequent rotation failures

Critical events should generate alerts.

---

# Testing

Secrets management tests should verify:

- Secrets are loaded from environment variables
- Missing secrets fail safely
- Secret values are never exposed in responses
- Secret rotation procedures work
- Logging redacts sensitive values

---

# Continuous Integration

CI should verify:

- No secrets committed
- Secret scanning passes
- Environment configuration complete
- Documentation updated
- Security checks pass

Builds must fail if committed secrets are detected.

---

# Definition of Done

Secrets management is complete only when:

- Secrets stored securely
- Source control protected
- Rotation supported
- Logging redacted
- Tests pass
- Documentation complete

---

# Constitution Compliance

This standard reinforces:

- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 11 — Every API request is untrusted
- Rule 17 — Audit important actions
- Rule 19 — Every security feature is tested
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- Security-Standards.md
- Authentication-Security.md
- Authorization.md
- Encryption.md
- Secure-Development.md
- API Authentication Standard

---

# Final Standard

Secrets are critical security assets.

Every secret used by ShuleOS must be stored securely, protected from disclosure, rotated when necessary, monitored for misuse, and managed throughout its lifecycle using consistent engineering practices.

Protecting secrets protects every school that relies on the platform.
