# ShuleOS Encryption Standard

> School in Clouds

## Document Information

| Field                | Value                                  |
| -------------------- | -------------------------------------- |
| Document             | Encryption Standard                    |
| Document ID          | SEC-STD-0005                           |
| Version              | 1.0                                    |
| Status               | Approved                               |
| Owner                | Platform Engineering                   |
| Repository           | `shuleos-api`                          |
| Effective Date       | 03 August 2026                         |
| Related Constitution | Engineering Constitution v1.1          |
| Related ADRs         | ADR-0003, ADR-0007, ADR-0008, ADR-0009 |

---

# Purpose

This document defines the mandatory encryption standards for the ShuleOS platform.

It governs:

- Encryption in transit
- Encryption at rest
- Password hashing
- Key management
- JWT signing
- Backup encryption
- File encryption
- Database encryption
- Cloud storage encryption
- Certificate management
- Cryptographic algorithms
- Random number generation
- Security testing

Every component handling sensitive information must comply with these standards.

---

# Security Principles

Encryption must be:

- Modern
- Well-tested
- Standards-based
- Properly configured
- Auditable
- Maintainable

Only approved cryptographic algorithms may be used.

---

# Objectives

Encryption protects:

- Learner information
- Parent information
- Teacher records
- Financial data
- Authentication credentials
- Uploaded documents
- Audit logs
- Platform backups
- API traffic

---

# Encryption in Transit

All production traffic must use:

```text
HTTPS (TLS 1.2 or higher)
```

Preferred:

```text
TLS 1.3
```

Plain HTTP is prohibited in production.

---

# Encryption at Rest

Sensitive information stored by the platform should be protected using approved encryption mechanisms where appropriate.

Examples include:

- Database backups
- File storage
- Export archives
- Disaster recovery media

---

# Password Hashing

Passwords must never be encrypted.

Passwords must always be hashed using:

```text
Argon2id
```

Passwords are never recoverable.

---

# JWT Security

JWTs must:

- Be digitally signed
- Have expiration
- Support revocation
- Never contain secrets
- Never expose sensitive personal information

JWT signing keys must be protected as platform secrets.

---

# Database Encryption

Where supported, encryption should protect:

- Backup files
- Database snapshots
- Storage volumes

Application-level encryption may be used for particularly sensitive fields.

---

# File Encryption

Sensitive uploaded files should remain protected throughout their lifecycle.

Examples:

- Medical records
- Financial reports
- Examination archives
- Official documents

Storage locations must enforce appropriate access controls.

---

# Cloud Storage

Cloudflare R2 storage must use:

- Secure transport
- Authenticated requests
- Provider-supported encryption
- Tenant isolation

Public access is prohibited unless explicitly required.

---

# Backup Encryption

Backups must be:

- Encrypted
- Versioned
- Access controlled
- Tested periodically

Unencrypted production backups are prohibited.

---

# Key Management

Encryption keys must:

- Have designated owners
- Be stored securely
- Support rotation
- Never be committed to source control
- Never appear in logs

Key management follows the Secrets Management Standard.

---

# Key Rotation

Key rotation should occur:

- Following suspected compromise
- During major infrastructure changes
- According to organizational policy
- When required by providers

Old keys should be revoked after successful migration.

---

# Certificates

Certificates must:

- Be valid
- Be monitored
- Be renewed before expiration

Expired production certificates are unacceptable.

---

# Random Number Generation

Security-sensitive values must use cryptographically secure random generators.

Examples include:

- Password reset tokens
- Email verification tokens
- Session identifiers
- API secrets
- Cryptographic keys

Predictable random values are prohibited.

---

# Approved Algorithms

Approved examples include:

- Argon2id (password hashing)
- AES-256 (symmetric encryption where required)
- RSA or ECC (where applicable)
- SHA-256 or stronger for integrity functions

Deprecated algorithms must not be introduced into new development.

---

# Deprecated Algorithms

Do not use:

- MD5
- SHA-1
- DES
- 3DES
- RC4

Legacy cryptography should be removed during modernization efforts.

---

# Logging

Logs must never expose:

- Encryption keys
- Passwords
- JWT secrets
- API keys
- Session secrets

Sensitive values should be redacted.

---

# Monitoring

Security monitoring should detect:

- Certificate expiration
- Failed TLS negotiation
- Key rotation failures
- Unauthorized key access
- Cryptographic configuration changes

---

# Testing

Encryption tests should verify:

- HTTPS enforcement
- Password hashing
- JWT validation
- Secure random generation
- Backup encryption
- Certificate validity
- Key rotation procedures

---

# Continuous Integration

CI should verify:

- Approved algorithms are used
- Deprecated algorithms are absent
- Secrets are not committed
- Security tests pass
- Documentation remains current

---

# Definition of Done

Encryption implementation is complete only when:

- TLS enforced
- Password hashing configured
- Keys protected
- Backups encrypted
- Tests pass
- Documentation complete

---

# Constitution Compliance

This standard reinforces:

- Rule 2 — Security before features
- Rule 3 — Privacy by Design
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
- Secrets-Management.md
- Secure-Development.md
- Backup-Recovery.md

---

# Final Standard

Encryption preserves the confidentiality and integrity of ShuleOS data throughout its lifecycle.

Every sensitive asset must be protected using approved cryptographic practices, secure key management, modern transport security, and continuous monitoring to maintain the trust of every school using the platform.
