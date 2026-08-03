# ShuleOS Known Issues Standard

> School in Clouds

## Document Information

| Field                | Value                                                      |
| -------------------- | ---------------------------------------------------------- |
| Document             | Known Issues Standard                                      |
| Document ID          | REL-STD-0008                                               |
| Version              | 1.0                                                        |
| Status               | Approved                                                   |
| Owner                | Product Management                                         |
| Repository           | `shuleos-api` & `shuleos-web`                              |
| Effective Date       | 04 August 2026                                             |
| Related Constitution | Engineering Constitution v1.1                              |
| Related Standards    | Release Notes Standard, Release Process, Incident Response |

---

# Purpose

This document defines how known issues are identified, documented, communicated, tracked, and resolved throughout the ShuleOS release lifecycle.

Maintaining an accurate Known Issues register promotes transparency, supports operational planning, and helps users make informed decisions during software adoption.

---

# Philosophy

No software release is assumed to be completely free of defects.

Known issues should be documented honestly, monitored continuously, and resolved according to their business impact and operational risk.

---

# Objectives

Known issue management should:

- Improve transparency
- Reduce operational surprises
- Support informed upgrades
- Assist troubleshooting
- Prioritize corrective work
- Improve release quality

---

# Definition

A known issue is a documented problem that has been identified but has not yet been permanently resolved.

The issue may have:

- A workaround
- A planned fix
- A deferred resolution
- An accepted operational limitation

---

# Scope

Known issues may include:

- Application defects
- User interface problems
- Performance limitations
- Browser compatibility issues
- Reporting inaccuracies
- Integration limitations
- Deployment limitations
- Infrastructure constraints

---

# Issue Classification

Known issues should be categorized according to severity.

| Severity | Description                             |
| -------- | --------------------------------------- |
| Critical | Prevents core platform operation        |
| High     | Major functionality affected            |
| Medium   | Noticeable limitation with workaround   |
| Low      | Minor inconvenience with minimal impact |

---

# Information Required

Each known issue should include:

- Issue identifier
- Summary
- Description
- Affected modules
- Severity
- User impact
- Workaround
- Planned resolution
- Target release
- Status

---

# Status Values

Typical status values include:

- Open
- Investigating
- In Progress
- Deferred
- Monitoring
- Resolved
- Closed

Status should be reviewed regularly.

---

# User Impact

Every issue should explain:

- Who is affected
- Which workflows are impacted
- Operational consequences
- Recommended user actions

Clear impact descriptions help schools assess operational risk.

---

# Workarounds

Where available, document:

- Temporary procedures
- Alternative workflows
- Configuration changes
- Operational recommendations

Workarounds should be tested before publication.

---

# Communication

Known issues should be communicated through:

- Release notes
- Support documentation
- User documentation
- Internal operational communications

Critical issues should be communicated promptly.

---

# Issue Prioritization

Issue priority should consider:

- Severity
- Number of affected institutions
- Security implications
- Data integrity
- Regulatory requirements
- Business impact

---

# Verification

Before publishing a known issue verify:

- Issue reproduction
- Accurate description
- Correct severity
- Tested workaround
- Appropriate owner assignment

---

# Resolution

When resolving an issue:

- Verify the fix
- Execute regression testing
- Update documentation
- Remove temporary workarounds where appropriate
- Publish the resolution in release notes

---

# Deferred Issues

Some issues may be intentionally deferred when:

- Operational impact is low
- Risk of immediate correction is higher
- Better architectural changes are planned
- Resources are prioritized elsewhere

Deferred issues should continue to be monitored.

---

# Reporting

Known issue reports should summarize:

- Open issues
- Resolved issues
- Deferred issues
- Severity distribution
- Resolution trends

These reports support release planning and continuous improvement.

---

# Best Practices

Engineering teams should:

- Document issues promptly.
- Describe issues clearly.
- Provide verified workarounds.
- Review issue status regularly.
- Prioritize high-impact problems.
- Update users after resolution.

---

# Definition of Done

A known issue record is complete only when:

- The issue is verified.
- Severity is assigned.
- User impact is documented.
- Workaround is provided where available.
- Resolution plan exists.
- Documentation has been reviewed.

---

# Constitution Compliance

This standard reinforces:

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
- Deprecation-Policy.md
- Changelog-Management.md
- Release-Template.md
- Release-Review-Checklist.md

---

# Final Standard

Every ShuleOS release should include an accurate and up-to-date record of known issues to support transparency, operational planning, and user confidence.

Documenting known issues responsibly helps schools make informed decisions while enabling continuous improvement of the School in the Clouds platform.
