# Security Policy

> **School in Clouds**

---

# Document Information

| Field            | Value                |
| ---------------- | -------------------- |
| **Document**     | Security Policy      |
| **Document ID**  | DOC-0004             |
| **Version**      | 1.0                  |
| **Status**       | Approved             |
| **Owner**        | Platform Engineering |
| **Repository**   | shuleos-api          |
| **Created**      | 22 July 2026         |
| **Last Updated** | 22 July 2026         |
| **Review Cycle** | Every Major Release  |

---

# Security Philosophy

Security is a core architectural principle of ShuleOS.

It is not a feature that is added after development. Every module, API endpoint, database table and deployment is designed with security as a primary requirement.

The platform protects:

- Learner information
- Parent information
- Teacher information
- Financial records
- Assessment records
- Examination material
- School operational data
- Platform infrastructure

Security decisions always take precedence over convenience.

---

# Engineering Principle

The guiding security principle of ShuleOS is:

> **No code enters ShuleOS because it works. Code enters ShuleOS because it has been proven secure, scalable, performant, tenant-safe, maintainable and reliable.**

Every Pull Request is evaluated against this principle.

---

# Responsible Disclosure

If you discover a security vulnerability:

- Do not create a public GitHub issue.
- Do not publicly disclose the vulnerability before a fix is available.
- Provide sufficient technical detail to reproduce the issue.
- Allow reasonable time for investigation and remediation.

Reports should include:

- Description
- Steps to reproduce
- Expected behaviour
- Actual behaviour
- Potential impact
- Suggested mitigation (if known)

Responsible disclosure helps protect schools and their data.

---

# Supported Versions

Only actively maintained versions of ShuleOS receive security updates.

| Version            | Status      |
| ------------------ | ----------- |
| Development Branch | Supported   |
| Current Release    | Supported   |
| Older Releases     | Best Effort |

---

# Security Architecture

Security is implemented using multiple independent layers.

```
HTTPS
    │
Authentication
    │
Identity
    │
Tenant Resolution
    │
Subscription Validation
    │
Authorization
    │
Validation
    │
Business Rules
    │
Database Constraints
    │
Audit Logging
    │
Secure Response
```

Each layer assumes another layer may fail.

---

# Authentication

Authentication uses:

- JWT
- Email Verification
- Email OTP
- Password Hashing
- Password Reset
- Session Invalidation

Future enhancements include:

- Authenticator Applications
- Passkeys
- Hardware Security Keys

---

# Authorization

Every protected request requires authorization.

Authorization combines:

- Roles
- Permissions
- Policies
- Object Ownership
- Tenant Validation

Business logic never bypasses authorization.

---

# Multi-Tenant Security

Each school operates as an isolated tenant.

Security guarantees include:

- Tenant-scoped queries
- Tenant-aware authorization
- Database ownership validation
- Cross-tenant protection
- Automated tenant isolation tests

Cross-tenant data exposure is treated as a critical security incident.

---

# Account Security

User accounts support:

- Email verification
- Password reset
- Account suspension
- Account locking
- Forced password change
- Immediate access revocation

Account state is enforced on every authenticated request.

---

# Password Policy

Passwords should:

- Meet minimum length requirements
- Be securely hashed
- Never be stored in plain text
- Never be logged
- Never be transmitted insecurely

Temporary passwords must be changed on first login.

---

# Secrets Management

Secrets include:

- JWT keys
- API keys
- SMTP credentials
- Database passwords
- Payment credentials
- SMS provider credentials

Rules:

- Never commit secrets.
- Store secrets in environment variables.
- Rotate compromised credentials immediately.
- Remove unused credentials.

---

# Dependency Security

Dependencies are reviewed continuously.

Every repository should regularly execute:

```bash
composer audit
```

Security updates are prioritized over feature work where vulnerabilities are identified.

---

# File Upload Security

Uploaded files are validated before use.

Validation includes:

- File type
- MIME type
- Size
- Malware scanning
- Metadata inspection
- Quarantine (planned)

Protected files are never directly exposed through predictable URLs.

---

# API Security

Every API endpoint should provide:

- Authentication
- Authorization
- Input validation
- Output sanitization
- Tenant validation
- Rate limiting
- Audit logging

APIs should fail securely.

---

# Database Security

Security measures include:

- Foreign keys
- Constraints
- Indexes
- Parameterized queries
- Least privilege
- Tenant ownership
- Soft deletion where appropriate

Database migrations are reviewed before merge.

---

# Logging and Auditing

Important events are recorded, including:

- Login attempts
- Permission changes
- Financial operations
- Examination publication
- Subscription changes
- Administrative actions

Audit logs should be immutable and protected from unauthorized modification.

---

# Incident Response

If a security incident occurs:

1. Identify the issue.
2. Contain the impact.
3. Preserve evidence.
4. Assess affected systems.
5. Develop and deploy a fix.
6. Notify affected parties where required.
7. Conduct a post-incident review.
8. Update documentation and Engineering Constitution if needed.

Every incident should result in measurable improvements.

---

# Security Review Checklist

Every Pull Request should be reviewed for:

- Authentication
- Authorization
- Tenant isolation
- Input validation
- Output encoding
- Secrets handling
- Database safety
- Performance impact
- Logging
- Test coverage

No Pull Request bypasses security review.

---

# Engineering Constitution

Security is governed by the ShuleOS Engineering Constitution.

Relevant topics include:

- Authentication
- Authorization
- Multi-tenancy
- Database integrity
- Payments
- Offline synchronization
- Privacy
- Performance
- Documentation

The Constitution is the authoritative source for engineering rules.

---

# Contact

Security concerns should be reported privately through the project's designated security contact once established.

Public issue trackers should not be used for undisclosed vulnerabilities.

---

# Final Commitment

Security is a continuous process.

Every release should improve:

- Confidentiality
- Integrity
- Availability
- Privacy
- Auditability
- Resilience

The trust schools place in ShuleOS is earned through disciplined engineering, transparent processes and continuous hardening.

---

<div align="center">

## ShuleOS

### School in Clouds

**Secure by Design • Multi-Tenant by Design • Built for Modern Education**

</div>
