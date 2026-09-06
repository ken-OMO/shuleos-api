# ShuleOS Git Commit Standards

> School in Clouds

## Document Information

| Field                | Value                         |
| -------------------- | ----------------------------- |
| Document             | Git Commit Standards          |
| Document ID          | CODE-STD-0006                 |
| Version              | 1.0                           |
| Status               | Approved                      |
| Owner                | Platform Engineering          |
| Repository           | `shuleos-api`, `shuleos-web`  |
| Effective Date       | 03 August 2026                |
| Related Constitution | Engineering Constitution v1.1 |

---

# Purpose

This document defines the Git workflow and commit message standards for all ShuleOS repositories.

It standardizes:

- Branch naming
- Commit messages
- Pull requests
- Merge strategy
- Release tagging
- Reverting changes
- Repository history

A clean Git history improves collaboration, code reviews, debugging, and long-term maintenance.

---

# General Principles

Every commit should be:

- Atomic
- Meaningful
- Reviewable
- Reversible
- Tested

One logical change equals one commit.

---

# Commit Message Format

ShuleOS follows the Conventional Commits specification.

Format:

```text
type(scope): short description
```

Examples:

```text
feat(auth): add JWT refresh endpoint
fix(exams): correct grade calculation
docs(api): define pagination standard
refactor(users): simplify user service
test(learners): add admission feature tests
```

---

# Commit Types

Use the following commit types:

```text
feat
fix
docs
refactor
test
perf
style
build
ci
chore
revert
```

Choose the type that best represents the primary change.

---

# Scope

The scope identifies the affected area.

Examples:

```text
auth
api
database
finance
learners
teachers
transport
boarding
docs
security
ui
```

Keep scopes concise and meaningful.

---

# Description

Descriptions should:

- Use the imperative mood.
- Start with a lowercase letter.
- Avoid ending with a period.

Good:

```text
define database naming standards
```

Bad:

```text
Defined database naming standards.
```

---

# Atomic Commits

Each commit should represent one logical unit of work.

Avoid combining unrelated changes in a single commit.

Example:

✅ One commit for documentation.

✅ Another commit for API implementation.

---

# Branch Naming

Use lowercase with hyphens.

Examples:

```text
feature/learner-admission
feature/finance-module
bugfix/report-card
docs/api-standards
refactor/teacher-service
hotfix/login
release/v1.0.0
```

---

# Pull Requests

Every pull request should include:

- Summary
- Motivation
- Testing performed
- Documentation updates
- Screenshots where applicable

PRs should remain focused on one topic.

---

# Merge Strategy

Preferred strategy:

- Squash merge for small iterative work.
- Merge commit where preserving commit history is valuable.

Avoid unnecessary merge commits from frequent synchronization.

---

# Reverting Changes

Use:

```bash
git revert
```

Avoid rewriting shared history.

Do not use `git reset --hard` on shared branches.

---

# Commit Frequency

Commit regularly.

Recommended milestones:

- Feature complete
- Standard completed
- Bug fixed
- Tests passing
- Documentation updated

Avoid extremely large commits.

---

# Code Reviews

Before requesting review:

- Tests pass
- Documentation updated
- Formatting completed
- Static analysis passes
- No debug code remains

---

# Release Tags

Release tags should use semantic versioning.

Examples:

```text
v0.1.0-foundation
v0.2.0-alpha
v1.0.0
```

Tags should identify significant milestones.

---

# Repository Hygiene

Before pushing:

- Review `git status`
- Review `git diff`
- Remove temporary files
- Verify commit messages
- Ensure generated files are intentional

Do not commit:

- Secrets
- Passwords
- Temporary logs
- Local IDE settings (unless shared intentionally)

---

# Continuous Integration

Every commit should pass:

- Formatting
- Static analysis
- Automated tests
- Security checks
- Documentation validation

Failed CI blocks merging.

---

# Definition of Done

A Git contribution is complete only when:

- Commit follows naming standard
- Branch follows naming standard
- Tests pass
- Documentation updated
- Review completed
- CI successful

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
- Code-Review-Checklist.md
- Documentation-Standards.md
- Testing-Conventions.md

---

# Final Standard

Every Git commit contributes to the long-term history of ShuleOS.

Commit messages should clearly communicate intent, changes should remain focused and reviewable, and repository history should reflect the same engineering quality expected from the code itself.
