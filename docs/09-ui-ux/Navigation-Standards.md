# ShuleOS Navigation Standards

> School in Clouds

## Document Information

| Field                | Value                                                                      |
| -------------------- | -------------------------------------------------------------------------- |
| Document             | Navigation Standards                                                       |
| Document ID          | UIUX-STD-0005                                                              |
| Version              | 1.0                                                                        |
| Status               | Approved                                                                   |
| Owner                | Product Design and Platform Engineering                                    |
| Repository           | `shuleos-web`                                                              |
| Effective Date       | 03 August 2026                                                             |
| Related Constitution | Engineering Constitution v1.1                                              |
| Related Standards    | UI/UX Standards, Design System, Accessibility Standards, Responsive Design |

---

# Purpose

This document defines the navigation standards for the ShuleOS platform.

It governs:

- Global navigation
- Sidebar navigation
- Top navigation
- Breadcrumbs
- Role-based navigation
- Tenant-aware navigation
- Mobile navigation
- Search
- Quick actions
- Navigation state
- URL structure
- Deep linking
- Accessibility
- Navigation review

Navigation should help users move confidently through the platform without confusion.

---

# Navigation Philosophy

Users should always know:

- Where they are
- How they got there
- What they can do next
- How to return
- Which school they are working in

Navigation should reduce thinking and increase task completion.

---

# Core Principles

Navigation must be:

- Consistent
- Predictable
- Role-aware
- Tenant-aware
- Accessible
- Responsive
- Scalable
- Searchable

Navigation should never become an obstacle to completing work.

---

# Global Navigation Structure

Navigation should follow a clear hierarchy:

```text
Platform
    ↓
Workspace
    ↓
Module
    ↓
Feature
    ↓
Action
```

Every page should fit naturally into this hierarchy.

---

# Platform Navigation

Platform-level navigation is visible only to platform users.

Examples:

- Platform Dashboard
- Schools
- Billing
- Licenses
- Platform Users
- Monitoring
- System Health
- Audit Logs

School users must never see platform-only navigation.

---

# School Navigation

School navigation contains operational modules.

Typical modules include:

- Dashboard
- Learners
- Teachers
- Guardians
- Academics
- Attendance
- Assessments
- Examinations
- Finance
- Timetable
- Library
- Boarding
- Transport
- Inventory
- HR
- Reports
- Settings

Only authorized modules should be displayed.

---

# Sidebar Navigation

Desktop navigation should primarily use a sidebar.

The sidebar should support:

- Expand/collapse
- Nested menus
- Icons
- Active state
- Keyboard navigation
- Responsive behaviour

Collapsed navigation must remain understandable.

---

# Top Navigation

The top bar should contain:

- Current workspace
- School name
- Search
- Notifications
- User profile
- Help
- Theme selector where applicable

Avoid placing unrelated actions in the top bar.

---

# Breadcrumbs

Breadcrumbs should reflect navigation hierarchy.

Example:

```text
Dashboard
→ Learners
→ Grade 7
→ Stream East
→ Learner Profile
```

Breadcrumbs should:

- Be consistent
- Use meaningful labels
- Show the current page
- Support keyboard navigation

---

# Active Navigation

The current page must be clearly identifiable.

Use:

- Highlighted menu item
- Icon state
- Text emphasis

Do not rely only on colour.

---

# Navigation Depth

Avoid excessive nesting.

Recommended maximum:

```text
Platform
→ Module
→ Feature
→ Detail
```

Deep hierarchies increase cognitive load.

---

# Role-Based Navigation

Navigation should reflect user responsibilities.

Examples:

Teacher

- Dashboard
- My Classes
- Attendance
- Lesson Plans
- Assessments

Finance Officer

- Dashboard
- Invoices
- Payments
- Reports

Parent

- Dashboard
- My Learners
- Fees
- Results
- Attendance

Users should not see irrelevant modules.

---

# Permission Awareness

Frontend navigation should hide actions users cannot perform where appropriate.

However:

Frontend navigation never replaces backend authorization.

---

# Tenant Awareness

Every navigation context must clearly indicate the active school.

Users must never become uncertain about which tenant they are working in.

Tenant switching should be explicit.

---

# Dashboard Navigation

Dashboards should provide shortcuts to:

- Frequent tasks
- Recent activity
- Pending work
- Reports
- Notifications

Dashboards should reduce navigation effort.

---

# Quick Actions

Provide contextual quick actions for common workflows.

Examples:

- Add learner
- Record payment
- Create assessment
- Mark attendance

Quick actions should never replace complete navigation.

---

# Search

Global search should help users locate:

- Learners
- Teachers
- Guardians
- Classes
- Reports
- Learning areas

Search results should respect permissions.

---

# Recently Used

Frequently or recently accessed items may be surfaced.

Examples:

- Recently viewed learners
- Recent reports
- Recent assessments

Recent history must remain tenant-aware.

---

# Navigation Persistence

Remember user preferences where appropriate.

Examples:

- Sidebar collapsed state
- Last selected tab
- Preferred density
- Last used filters

Preferences should not leak across users.

---

# URL Structure

URLs should be:

- Predictable
- Human-readable
- Stable
- REST-like where applicable

Example:

```text
/learners
/learners/123
/teachers
/finance/invoices
```

Avoid exposing sensitive information in URLs.

---

# Deep Linking

Important pages should support direct linking.

Examples:

- Learner profile
- Invoice
- Assessment
- Report

Users with permission should arrive directly at the intended page.

---

# Navigation History

Browser Back and Forward buttons should behave naturally.

Avoid breaking browser expectations.

---

# Tabs

Tabs should group closely related information.

Examples:

Learner Profile

- Overview
- Guardians
- Attendance
- Assessments
- Fees

Tabs should not replace unrelated modules.

---

# Context Switching

When switching schools or workspaces:

- Confirm current context
- Refresh permissions
- Update navigation
- Clear tenant-specific cached data where required

---

# Mobile Navigation

Mobile navigation should prioritize:

- Frequently used modules
- Simple hierarchy
- Thumb-friendly interaction

Possible patterns include:

- Bottom navigation
- Navigation drawer
- Collapsible menu

Navigation must remain accessible.

---

# Keyboard Navigation

Navigation should support:

- Tab
- Arrow keys where appropriate
- Escape
- Enter
- Space

Keyboard users should access every destination.

---

# Navigation Feedback

Users should receive feedback during navigation.

Examples:

- Loading indicator
- Active page state
- Error page
- Permission denied page

Avoid blank screens.

---

# Error Navigation

When navigation fails:

- Explain why
- Offer recovery
- Provide a route back

Examples:

- Page not found
- Permission denied
- Resource unavailable

---

# Empty Navigation States

Modules with no data should guide users.

Example:

```text
No learners found.

Add your first learner.
```

---

# Notifications

Navigation should expose unread notifications without overwhelming users.

Notifications should be:

- Relevant
- Actionable
- Permission-aware

---

# Help

Help should be consistently accessible.

Examples:

- Documentation
- Support
- Tutorials
- Contact support

---

# External Links

External destinations should be clearly identified.

Where appropriate:

- Warn before leaving
- Preserve user work
- Indicate new tabs

---

# Navigation Performance

Navigation should feel immediate.

Reduce unnecessary:

- Reloads
- Layout shifts
- Waiting

Prefetch routes where beneficial.

---

# Accessibility

Navigation must satisfy:

- Keyboard accessibility
- Screen-reader support
- Focus visibility
- Semantic landmarks
- Accessible labels

Accessibility standards remain mandatory.

---

# Responsive Behaviour

Navigation should adapt to:

- Desktop
- Laptop
- Tablet
- Mobile

Without removing essential functionality.

---

# Analytics

Navigation analytics may be used to improve usability.

Analytics should:

- Respect privacy
- Avoid unnecessary personal information
- Help identify friction

---

# Review Checklist

Navigation reviews should verify:

- Consistency
- Accessibility
- Role awareness
- Tenant awareness
- URL quality
- Responsive behaviour
- Keyboard support
- Search behaviour
- Breadcrumb accuracy

---

# Definition of Done

Navigation is complete only when:

- Users know where they are.
- Navigation is role-aware.
- Navigation is tenant-aware.
- Keyboard navigation works.
- Responsive layouts work.
- Search respects permissions.
- URLs remain stable.
- Accessibility passes.
- Tests pass.
- Documentation is updated.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 7 — Human Experience
- Rule 10 — Design first. Code second
- Rule 46 — Frontend authorization never replaces backend authorization
- Rule 66 — Every feature has tests
- Rule 126 — Localization is a platform capability

---

# Related Documents

- UI-UX-Standards.md
- Design-System.md
- Accessibility-Standards.md
- Responsive-Design.md
- Forms-and-Validation.md
- Mobile-Experience.md

---

# Final Standard

Navigation is the backbone of the ShuleOS user experience.

Every user—whether a platform administrator, principal, teacher, finance officer, parent, or learner—must be able to move through the School in the Clouds efficiently, confidently, and securely with clear orientation, consistent patterns, and role-aware access to the tools they need.
