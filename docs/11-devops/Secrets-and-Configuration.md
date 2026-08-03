# ShuleOS Secrets and Configuration Standards

> School in Clouds

## Document Information

| Field                | Value                                                        |
| -------------------- | ------------------------------------------------------------ |
| Document             | Secrets and Configuration Standards                          |
| Document ID          | DEVOPS-STD-0005                                              |
| Version              | 1.0                                                          |
| Status               | Approved                                                     |
| Owner                | Platform Engineering                                         |
| Repository           | `shuleos-api` & `shuleos-web`                                |
| Effective Date       | 03 August 2026                                               |
| Related Constitution | Engineering Constitution v1.1                                |
| Related Standards    | DevOps Standards, Environment Management, Security Standards |

---

# Purpose

This document defines the mandatory standards for managing secrets and configuration throughout the ShuleOS platform.

It ensures that credentials, keys, tokens, connection details, and environment-specific settings remain secure, traceable, and separate from application source code.

---

# Philosophy

Configuration should be explicit.

Secrets should remain secret.

Application code must never depend on undocumented, manually configured, or insecure values.

---

# Scope

These standards apply to:

- Environment variables
- Database credentials
- JWT secrets
- Encryption keys
- API credentials
- SMTP credentials
- SMS gateway credentials
- Cloud access keys
- Payment integration credentials
- Storage credentials
- Monitoring tokens
- Webhook secrets
- Third-party integration keys

---

# Core Principles

Secrets and configuration must be:

- Externalized
- Environment-specific
- Secure
- Validated
- Auditable
- Rotatable
- Least-privilege
- Documented

---

# Configuration vs Secrets

Configuration includes non-sensitive values such as:

- Application name
- Application URL
- Logging level
- Queue connection
- Cache driver
- Feature flags
- Locale
- Time zone

Secrets include sensitive values such as:

- Passwords
- Private keys
- API tokens
- Access keys
- Signing secrets
- Encryption keys

Secrets require stronger protection.

---

# Source Control Protection

Secrets must never be committed to Git.

Protected files include:

```text
.env
.env.*
auth.json
private keys
certificate keys
credential exports
secret backups
```

Only approved example files such as `.env.example` may be committed.

---

# Environment Variables

Environment variables should:

- Use clear names
- Follow consistent prefixes
- Be documented
- Remain environment-specific
- Be validated during startup
- Avoid unnecessary duplication

Examples:

```text
APP_ENV
APP_URL
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
JWT_SECRET
CACHE_STORE
QUEUE_CONNECTION
MAIL_HOST
SMS_PROVIDER
```

---

# Naming Conventions

Configuration names should use uppercase snake case.

Prefer:

```text
SMS_API_KEY
MAIL_FROM_ADDRESS
AWS_ACCESS_KEY_ID
```

Avoid unclear names such as:

```text
KEY1
TOKEN_VALUE
SETTING_X
```

---

# Required Configuration

Required configuration must be validated before the application begins serving requests.

Missing critical values should cause startup or deployment validation to fail safely.

---

# Safe Defaults

Defaults may be provided only when they are secure and unambiguous.

Production-sensitive settings must not silently fall back to insecure development values.

---

# Environment Separation

Each environment must use separate secrets.

Development, testing, staging, and production must not share:

- Database passwords
- JWT secrets
- API keys
- Cloud credentials
- SMTP credentials
- SMS credentials

---

# Secret Storage

Production secrets should be stored through an approved secret-management mechanism.

Possible mechanisms include:

- Cloud secret managers
- Encrypted deployment variables
- Protected CI/CD secrets
- Restricted server environment configuration

Plain-text shared documents are prohibited.

---

# Secret Access

Secret access must follow least privilege.

Only authorized systems and personnel should access the secrets required for their responsibilities.

---

# CI/CD Secrets

CI/CD platforms should store secrets in protected secret stores.

Pipeline logs must not print secret values.

Secrets should be scoped to:

- Repository
- Environment
- Deployment stage
- Required workflow

---

# Local Development

Local development secrets should remain in ignored environment files.

Developers should use test or sandbox credentials rather than production credentials.

---

# Configuration Templates

The repository should maintain an up-to-date configuration template such as:

```text
.env.example
```

The template should:

- List required variables
- Avoid real credentials
- Include safe example values
- Explain non-obvious settings

---

# Secret Rotation

Secrets should be rotated:

- Periodically
- After suspected exposure
- After staff access changes
- When required by a provider
- Following security incidents

Rotation procedures should minimize service interruption.

---

# JWT Secret Rotation

JWT key rotation should account for:

- Existing token validity
- Controlled transition
- Forced reauthentication where necessary
- Audit logging
- Rollback planning

---

# Database Credential Rotation

Database credential rotation should include:

- New credential creation
- Application update
- Connection verification
- Old credential revocation
- Audit confirmation

---

# Third-Party Credentials

Third-party credentials should:

- Use sandbox accounts outside production
- Have minimum required permissions
- Be monitored for unusual use
- Be revoked when no longer needed

---

# Encryption Keys

Encryption keys require strong controls.

Keys should:

- Never appear in source control
- Be backed up securely where required
- Be access-restricted
- Support rotation
- Remain separate by environment

---

# Certificate Management

TLS certificates should be:

- Issued by approved authorities
- Renewed before expiration
- Monitored
- Stored securely
- Replaced promptly if compromised

Private certificate keys must remain confidential.

---

# Configuration Ownership

Every important configuration area should have a clear owner.

Examples:

- Application configuration
- Database configuration
- Email configuration
- SMS configuration
- Storage configuration
- Monitoring configuration

Ownership improves accountability.

---

# Configuration Changes

Configuration changes require:

- Review
- Testing
- Documentation
- Deployment validation
- Rollback planning where appropriate

Emergency changes must be documented afterward.

---

# Logging Protection

Logs must never contain:

- Passwords
- Full access tokens
- Private keys
- Database credentials
- Complete payment credentials
- Sensitive secret values

Sensitive values should be redacted.

---

# Error Protection

Errors must not expose secrets through:

- API responses
- Browser output
- Stack traces
- Deployment output
- Monitoring alerts

---

# Secret Detection

Repositories and pipelines should use secret-detection checks where practical.

Detected secrets require immediate investigation and rotation.

---

# Exposure Response

If a secret is exposed:

1. Revoke or rotate it immediately.
2. Assess unauthorized use.
3. Remove the secret from active systems.
4. Review logs.
5. Document the incident.
6. Update preventive controls.

Deleting a secret from the latest commit alone is not sufficient.

---

# Backup Protection

Secret backups, where required, must be:

- Encrypted
- Access-controlled
- Retained according to policy
- Tested for recoverability
- Destroyed securely when expired

---

# Frontend Configuration

Only explicitly public configuration may be exposed to the frontend.

Values bundled into browser code must be treated as public.

Private credentials must never be embedded in Next.js client bundles.

---

# Backend Configuration

Sensitive configuration should remain on the backend.

The backend must validate critical configuration before accepting production traffic.

---

# Tenant Configuration

Tenant-specific configuration must remain scoped to the correct school.

Sensitive tenant configuration must not be exposed across tenants.

---

# Configuration Caching

Configuration caching may improve performance.

Deployment procedures must rebuild configuration caches whenever relevant values change.

---

# Monitoring

Secret and configuration management should monitor:

- Expiring certificates
- Failed credential use
- Rotation status
- Missing configuration
- Unauthorized access
- Secret-scanning alerts

---

# Audit Logging

Sensitive configuration actions should be auditable.

Examples:

- Secret creation
- Secret rotation
- Access changes
- Credential revocation
- Production configuration changes

Audit logs must not record actual secret values.

---

# Testing

Testing should verify:

- Required variables are validated
- Missing configuration fails safely
- Environment isolation is preserved
- Public and private configuration remain separated
- Secrets are not exposed in logs or responses
- Rotation procedures work

---

# Engineering Guidelines

Engineers should:

- Never commit secrets.
- Use approved secret stores.
- Keep `.env.example` current.
- Validate required configuration.
- Rotate exposed credentials immediately.
- Avoid printing secrets.
- Use least-privilege credentials.
- Document configuration changes.

---

# Review Checklist

Verify:

- No secrets are committed.
- Environment variables are documented.
- Required values are validated.
- Secrets are environment-specific.
- CI/CD secrets are protected.
- Frontend bundles contain no private values.
- Logs redact sensitive values.
- Rotation procedures exist.
- Access follows least privilege.
- Configuration documentation is current.

---

# Definition of Done

A secrets or configuration change is complete only when:

- Values are externalized.
- Secrets are protected.
- Environment isolation is preserved.
- Startup validation passes.
- Access controls are verified.
- Rotation impact is assessed.
- Logging does not expose secrets.
- Documentation is updated.
- Review is approved.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 66 — Every feature has tests

---

# Related Documents

- DevOps-Standards.md
- CI-CD-Pipeline.md
- Infrastructure-as-Code.md
- Environment-Management.md
- Containerization.md
- Logging-Standards.md
- Security Standards
- Encryption Standard

---

# Final Standard

Every ShuleOS secret and configuration value must be managed securely, explicitly, and independently from application source code.

Strong secret and configuration management protects platform access, tenant data, external integrations, and the operational integrity of the School in the Clouds.
