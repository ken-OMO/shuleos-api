# ShuleOS Semantic Versioning Standard

> School in Clouds

## Document Information

| Field                | Value                                                         |
| -------------------- | ------------------------------------------------------------- |
| Document             | Semantic Versioning Standard                                  |
| Document ID          | REL-STD-0002                                                  |
| Version              | 1.0                                                           |
| Status               | Approved                                                      |
| Owner                | Product Management                                            |
| Repository           | `shuleos-api` & `shuleos-web`                                 |
| Effective Date       | 04 August 2026                                                |
| Related Constitution | Engineering Constitution v1.1                                 |
| Related Standards    | Release Notes Standard, Release Process, Changelog Management |

---

# Purpose

This standard defines the version numbering strategy used for all ShuleOS software releases.

Consistent versioning enables predictable upgrades, effective communication, dependency management, and reliable software maintenance.

---

# Philosophy

Every version number should clearly communicate the significance of changes introduced in a release.

Version numbers should be meaningful, consistent, and easy for both technical and non-technical users to understand.

---

# Scope

Semantic Versioning applies to:

- Backend API
- Web Application
- Shared Libraries
- Public APIs
- Infrastructure Packages
- Deployment Artifacts

---

# Version Format

ShuleOS follows Semantic Versioning (SemVer):

```text
MAJOR.MINOR.PATCH
```

Example:

```text
1.0.0
1.1.0
1.2.5
2.0.0
```

---

# Major Version

Increase the **MAJOR** version when introducing incompatible or breaking changes.

Examples include:

- Removing public APIs
- Significant architectural changes
- Incompatible database changes
- Major authentication changes
- Breaking configuration changes

Example:

```text
1.9.4 → 2.0.0
```

---

# Minor Version

Increase the **MINOR** version when adding new functionality while maintaining backward compatibility.

Examples include:

- New modules
- Additional API endpoints
- New reports
- New dashboards
- Additional user capabilities

Example:

```text
1.3.2 → 1.4.0
```

---

# Patch Version

Increase the **PATCH** version for backward-compatible fixes.

Examples include:

- Bug fixes
- Security fixes
- Performance improvements
- Documentation corrections
- UI refinements

Example:

```text
1.4.2 → 1.4.3
```

---

# Initial Release

The first production release begins with:

```text
1.0.0
```

Development versions prior to production should follow the team's development workflow.

---

# Pre-release Versions

Pre-release identifiers may be used during development.

Examples:

```text
1.0.0-alpha.1
1.0.0-beta.1
1.0.0-rc.1
```

Recommended stages:

- Alpha
- Beta
- Release Candidate (RC)

Pre-release versions are not intended for general production use.

---

# Build Metadata

Optional build metadata may be appended.

Example:

```text
1.0.0+20260804
1.2.0+build45
```

Build metadata identifies builds without affecting version precedence.

---

# Version Progression

Typical progression:

```text
0.x.x
↓
1.0.0
↓
1.0.1
↓
1.1.0
↓
1.2.0
↓
1.2.1
↓
2.0.0
```

---

# API Versioning

Public APIs should:

- Remain backward compatible whenever possible
- Clearly document breaking changes
- Version API endpoints when required
- Publish migration guidance for incompatible updates

---

# Database Versioning

Database changes should:

- Use version-controlled migrations
- Preserve data integrity
- Support rollback where practical
- Be documented in release notes

---

# Documentation Versioning

Documentation should remain synchronized with the corresponding software release.

Major platform changes should include updated documentation before release.

---

# Dependency Versioning

Third-party dependency upgrades should:

- Be reviewed carefully
- Maintain compatibility
- Undergo testing before release
- Be documented when they affect users or deployment

---

# Release Types

Typical release categories include:

| Release Type | Example |
| ------------ | ------- |
| Major        | 2.0.0   |
| Minor        | 2.1.0   |
| Patch        | 2.1.3   |
| Hotfix       | 2.1.4   |
| Security     | 2.1.5   |

---

# Breaking Changes

Whenever a major version is released:

- Document all breaking changes
- Provide migration guidance
- Publish upgrade procedures
- Communicate impacts before deployment

---

# Version Tagging

Every production release should be tagged in Git.

Example:

```text
v1.0.0
v1.1.0
v2.0.0
```

Tags should correspond exactly to released versions.

---

# Best Practices

Development teams should:

- Follow Semantic Versioning consistently.
- Avoid unnecessary major releases.
- Keep release notes synchronized.
- Tag every production release.
- Document breaking changes.
- Review version numbers before publishing.

---

# Definition of Done

A release version is complete only when:

- The version number follows SemVer.
- Release notes are updated.
- Git tags are created.
- Documentation matches the release.
- Migration guidance is provided where required.
- The release has been approved.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 6 — Consistency over cleverness
- Rule 10 — Design first. Code second
- Rule 107 — Production systems are observable

---

# Related Documents

- Release-Notes-Standard.md
- Release-Process.md
- Release-Checklist.md
- Breaking-Changes.md
- Migration-Guide.md
- Upgrade-Procedures.md
- Changelog-Management.md
- Release-Template.md
- Release-Review-Checklist.md

---

# Final Standard

All ShuleOS releases must follow Semantic Versioning to ensure predictable upgrades, clear communication of change, and long-term maintainability.

Consistent versioning strengthens release management, simplifies adoption, and supports the reliable evolution of the School in the Clouds platform.
