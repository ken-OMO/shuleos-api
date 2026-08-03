# ShuleOS Deprecation Policy

> School in Clouds

## Document Information

| Field                | Value                                                           |
| -------------------- | --------------------------------------------------------------- |
| Document             | Deprecation Policy                                              |
| Document ID          | REL-STD-0009                                                    |
| Version              | 1.0                                                             |
| Status               | Approved                                                        |
| Owner                | Product Management                                              |
| Repository           | `shuleos-api` & `shuleos-web`                                   |
| Effective Date       | 04 August 2026                                                  |
| Related Constitution | Engineering Constitution v1.1                                   |
| Related Standards    | Breaking Changes Standard, Semantic Versioning, Release Process |

---

# Purpose

This document defines the policy for deprecating features, APIs, configurations, and functionality within the ShuleOS platform.

The objective is to ensure changes are introduced in a predictable manner while giving schools, administrators, developers, and integrators sufficient time to prepare before functionality is removed.

---

# Philosophy

Features should not be removed unexpectedly.

Whenever practical, functionality should first be deprecated, clearly communicated, and supported for a reasonable transition period before removal.

---

# Objectives

The deprecation policy should:

- Protect existing users
- Reduce upgrade risk
- Encourage orderly migrations
- Improve platform stability
- Minimize operational disruption
- Support long-term maintainability

---

# Scope

This policy applies to:

- Public APIs
- User interface features
- Configuration options
- Environment variables
- Database structures
- Reports
- Commands
- Integrations
- Internal platform services exposed to customers

---

# Definition

A deprecated feature remains available but is scheduled for removal in a future release.

Deprecated functionality should continue working during the supported deprecation period unless an emergency security issue requires immediate removal.

---

# Reasons for Deprecation

Features may be deprecated when:

- Better alternatives exist
- Technology becomes obsolete
- Security risks are identified
- Maintenance costs become excessive
- Architecture changes require replacement
- Product direction evolves

---

# Deprecation Lifecycle

The standard lifecycle is:

1. Identify feature
2. Approve deprecation
3. Document the change
4. Announce deprecation
5. Provide migration guidance
6. Maintain compatibility
7. Remove in a future major release

---

# Deprecation Notice

Every deprecation should document:

- Feature name
- Reason for deprecation
- Recommended replacement
- First deprecated version
- Planned removal version
- Migration guidance
- Support period

---

# Communication

Deprecation announcements should appear in:

- Release notes
- Upgrade guides
- Migration guides
- API documentation
- User documentation
- Administrator documentation

Communication should begin before removal.

---

# Compatibility Period

Deprecated functionality should remain supported for at least one major release whenever practical.

Exceptions may apply for:

- Critical security vulnerabilities
- Regulatory requirements
- Unsupported third-party dependencies

---

# API Deprecation

Deprecated APIs should:

- Remain operational during the support period
- Be clearly marked as deprecated
- Recommend replacement endpoints
- Be removed only in a future major release

---

# User Interface Deprecation

Deprecated user interface features should:

- Continue functioning during the transition period
- Clearly direct users toward replacement functionality
- Avoid disrupting existing workflows unnecessarily

---

# Configuration Deprecation

Configuration changes should document:

- Deprecated settings
- Replacement settings
- Migration instructions
- Removal schedule

---

# Database Deprecation

Database changes should:

- Preserve existing data
- Support migration scripts
- Maintain integrity
- Avoid destructive operations until removal is approved

---

# Exceptions

Immediate removal may be permitted when:

- A critical security vulnerability exists
- Legal or regulatory compliance requires removal
- Data protection obligations require immediate action

Such removals should be documented and communicated as soon as possible.

---

# Responsibilities

## Product Management

Responsible for:

- Approving deprecations
- Scheduling removals
- Communicating product direction

## Engineering

Responsible for:

- Implementing deprecation warnings
- Maintaining compatibility
- Providing migration support

## Documentation

Responsible for:

- Updating documentation
- Recording removal schedules
- Publishing migration guidance

---

# Review

Deprecated functionality should be reviewed regularly to determine whether it should:

- Continue to be supported
- Receive an extended support period
- Be removed in the next major release

---

# Best Practices

Engineering teams should:

- Deprecate before removing functionality.
- Provide clear replacement guidance.
- Allow sufficient migration time.
- Communicate changes early.
- Keep documentation current.
- Avoid unnecessary deprecations.

---

# Definition of Done

A deprecation is complete only when:

- Approval has been received.
- Documentation is updated.
- Release notes include the deprecation.
- Migration guidance is available.
- Removal version is identified.
- Users have been informed.

---

# Constitution Compliance

This policy reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 107 — Production systems are observable

---

# Related Documents

- Release-Notes-Standard.md
- Semantic-Versioning.md
- Release-Process.md
- Release-Checklist.md
- Breaking-Changes.md
- Migration-Guide.md
- Upgrade-Procedures.md
- Known-Issues.md
- Changelog-Management.md
- Release-Template.md
- Release-Review-Checklist.md

---

# Final Standard

Every deprecated feature in ShuleOS must follow a transparent, documented, and predictable lifecycle that provides adequate notice, migration guidance, and support before removal.

A disciplined deprecation policy enables the School in the Clouds platform to evolve responsibly while maintaining trust, stability, and long-term compatibility for every institution.
