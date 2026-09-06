# ShuleOS Documentation Standards

> School in Clouds

## Document Information

| Field                | Value                         |
| -------------------- | ----------------------------- |
| Document             | Documentation Standards       |
| Document ID          | CODE-STD-0009                 |
| Version              | 1.0                           |
| Status               | Approved                      |
| Owner                | Platform Engineering          |
| Repository           | `shuleos-api`, `shuleos-web`  |
| Effective Date       | 03 August 2026                |
| Related Constitution | Engineering Constitution v1.1 |

---

# Purpose

This document establishes the mandatory documentation standards for the ShuleOS platform.

It governs:

- Engineering documentation
- Markdown standards
- Architecture documentation
- API documentation
- Code documentation
- Operational documentation
- User documentation
- Versioning
- Review process

Documentation is treated as a core engineering asset and must evolve together with the software.

---

# Documentation Principles

Documentation should be:

- Accurate
- Complete
- Clear
- Current
- Consistent
- Reviewable
- Version controlled

Outdated documentation is considered technical debt.

---

# Scope

These standards apply to:

- README files
- ADRs
- Engineering standards
- API documentation
- Architecture diagrams
- Runbooks
- Deployment guides
- User manuals
- Release notes
- Code comments

---

# Markdown Standards

Documentation should use:

- ATX headings (`#`, `##`, `###`)
- Tables where appropriate
- Fenced code blocks
- Ordered steps for procedures
- Bullet lists for collections

Avoid inconsistent formatting.

---

# Document Structure

Every major document should include:

- Title
- Document Information
- Purpose
- Scope
- Main Content
- Related Documents
- Final Standard or Conclusion

Maintain a consistent structure across the repository.

---

# Document Metadata

Each engineering document should define:

- Document name
- Document ID
- Version
- Status
- Owner
- Repository
- Effective date
- Related Constitution
- Related ADRs (where applicable)

---

# Naming

Documentation filenames should:

- Use PascalCase with hyphens
- End with `.md`
- Clearly describe their purpose

Examples:

```text
API-Standards.md
Security-Checklist.md
Database-Naming.md
```

---

# Language

Use:

- Professional English
- Consistent terminology
- Active voice where practical

Avoid ambiguous wording.

---

# Code Examples

Examples should:

- Be complete
- Be realistic
- Follow project standards
- Remain concise

Outdated examples should be updated promptly.

---

# Architecture References

When documenting architectural decisions:

- Reference the appropriate ADR.
- Avoid duplicating ADR content.
- Link related standards where useful.

---

# API Documentation

Public APIs should document:

- Purpose
- Authentication
- Request
- Response
- Validation
- Error responses
- Examples

API documentation should stay synchronized with implementation.

---

# Code Comments

Comments should explain:

- Why
- Architectural decisions
- Complex algorithms
- Business rules

Avoid comments that merely repeat obvious code.

---

# Diagrams

Where diagrams are used:

- Keep them current.
- Use consistent terminology.
- Version them with the documentation.

Remove obsolete diagrams promptly.

---

# Versioning

Documentation should be versioned alongside the codebase.

Significant changes should update the document version where appropriate.

---

# Review Process

Documentation changes should be reviewed for:

- Accuracy
- Completeness
- Consistency
- Grammar
- Technical correctness
- Broken references

---

# Ownership

Every major document should have a defined owner responsible for keeping it current.

---

# Documentation Debt

Missing or outdated documentation should be tracked and resolved similarly to technical debt.

---

# Continuous Integration

CI should verify:

- Markdown formatting
- Internal links
- File structure
- Required documents
- Documentation completeness where automated checks are available

---

# Definition of Done

Documentation work is complete only when:

- Content is accurate
- Structure follows this standard
- Related documents updated
- Examples verified
- Review completed
- CI passes

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 10 — Design first. Code second
- Rule 17 — Audit important actions
- Rule 66 — Every feature has tests
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- Coding-Standards.md
- Git-Commit-Standards.md
- Code-Review-Checklist.md
- All ADRs
- API Standards
- Security Standards

---

# Final Standard

Documentation is part of the product.

Every significant engineering decision, interface, process, and operational procedure should be documented with the same level of care applied to the source code itself.

Clear, accurate, and maintainable documentation ensures that the School in the Clouds can continue to grow, onboard new contributors efficiently, and remain understandable throughout its lifetime.
