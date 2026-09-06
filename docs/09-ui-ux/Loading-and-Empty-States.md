# ShuleOS Loading and Empty States Standards

> School in Clouds

## Document Information

| Field                | Value                                                                                         |
| -------------------- | --------------------------------------------------------------------------------------------- |
| Document             | Loading and Empty States Standards                                                            |
| Document ID          | UIUX-STD-0009                                                                                 |
| Version              | 1.0                                                                                           |
| Status               | Approved                                                                                      |
| Owner                | Product Design and Platform Engineering                                                       |
| Repository           | `shuleos-web`                                                                                 |
| Effective Date       | 03 August 2026                                                                                |
| Related Constitution | Engineering Constitution v1.1                                                                 |
| Related Standards    | UI/UX Standards, Design System, Accessibility Standards, Feedback and Notifications Standards |

---

# Purpose

This document defines the standards for loading, waiting, processing, offline, error, and empty states throughout ShuleOS.

These standards apply to every page, component, table, form, dashboard, report, and background process.

Users should always understand:

- What the system is doing
- Why they are waiting
- Whether work is progressing
- What to do if something fails
- What to do when no data exists

---

# Design Philosophy

Waiting is part of using cloud software.

A good interface never leaves users wondering:

- Is it loading?
- Has it frozen?
- Did it fail?
- Is my request still processing?
- Should I refresh?

The interface should always communicate system state clearly.

---

# Core Principles

Every loading and empty state must be:

- Clear
- Consistent
- Accessible
- Responsive
- Calm
- Informative
- Non-disruptive

---

# Loading Categories

ShuleOS recognizes several loading situations:

- Initial page load
- Partial content load
- Background processing
- File upload
- File download
- Search
- Pagination
- Report generation
- Bulk processing
- Background refresh

Each requires appropriate feedback.

---

# Initial Page Loading

When an entire page is loading:

Use:

- Skeleton layout
- Page title placeholder
- Table placeholder
- Card placeholder

Avoid blank white screens.

---

# Skeleton Loading

Skeletons are preferred over generic spinners for content-heavy pages.

Examples:

- Dashboard cards
- Learner profile
- Tables
- Reports

Skeletons should resemble the final layout.

---

# Spinner Usage

Spinners are appropriate for:

- Short operations
- Inline actions
- Small components

Avoid displaying only a spinner for large page loads.

---

# Progress Bars

Use progress bars for operations with measurable progress.

Examples:

- Uploading learner photos
- Importing CSV files
- Backup
- Export
- Bulk SMS

Show percentage whenever possible.

---

# Background Processing

Background jobs should:

- Start immediately
- Confirm initiation
- Continue independently
- Notify users upon completion

Examples:

- Report generation
- Fee reconciliation
- SMS delivery
- Backup
- Data import

---

# Background Refresh

Pages may refresh automatically.

Users should be informed when:

- Data updates
- Refresh completes
- Conflicts occur

Avoid sudden interface changes.

---

# Search Loading

Searching should:

- Debounce requests
- Display loading feedback
- Preserve previous results until new results arrive

Avoid flickering tables.

---

# Pagination Loading

During page changes:

- Preserve layout
- Show loading indicator
- Avoid resetting scroll unexpectedly

---

# Lazy Loading

Lazy loading should be used for:

- Images
- Large modules
- Reports
- Charts
- Historical records

Lazy loading should not hide important information indefinitely.

---

# Infinite Scrolling

Infinite scrolling should be used sparingly.

Administrative interfaces generally prefer pagination.

If infinite scrolling is used:

- Maintain position
- Indicate loading
- Provide end-of-results message

---

# File Upload

Upload interfaces should display:

- Selected files
- Upload progress
- Completion
- Failure
- Retry option

Users should never wonder whether upload succeeded.

---

# File Download

Downloads should indicate:

- Preparation
- Progress where available
- Completion
- Failure

---

# Save Operations

During saving:

- Disable duplicate submission
- Show progress
- Preserve entered data

Example:

```text
Saving learner...
```

---

# Long Operations

Long operations should explain:

- Current step
- Estimated progress where possible
- Expected completion

Examples:

- Generating report cards
- Importing learners
- Restoring backup

---

# Empty States

Empty states should explain:

- Why nothing is displayed
- Whether this is expected
- What users should do next

---

# Example Empty State

```text
No learners have been admitted yet.

Add your first learner to begin.
```

---

# Search Results

When search returns no matches:

Explain:

- No matching records found
- Suggest different search
- Offer filter reset

---

# Filtered Results

If filters remove every record:

Explain that filters are responsible.

Offer:

Reset Filters

---

# Permission Empty State

When users lack permission:

Explain:

- Access unavailable
- Reason where appropriate
- Contact administrator if needed

Avoid exposing restricted information.

---

# Offline State

When connectivity is lost:

Inform users clearly.

Example:

```text
You are currently offline.

Some features may be unavailable.
```

Retry automatically where appropriate.

---

# Timeout

When requests time out:

Explain:

- Request took too long
- Retry available

Avoid blaming users.

---

# Server Error

Server errors should explain:

- Operation failed
- Retry available
- Support reference where appropriate

Avoid displaying raw exceptions.

---

# Partial Failure

Some operations may complete partially.

Example:

```text
94 learners imported.

6 records require correction.
```

Provide details.

---

# Retry Pattern

Retry buttons should be available where safe.

Avoid automatic repeated retries that overload the server.

---

# Accessibility

Loading indicators should support:

- Screen readers
- Keyboard users
- Accessible status announcements

Skeletons should not confuse assistive technologies.

---

# Motion

Loading animations should remain subtle.

Respect reduced-motion preferences.

---

# Mobile Behaviour

Loading states should adapt to:

- Smaller screens
- Slower networks
- Touch interaction

Avoid large blocking overlays.

---

# Performance

Loading indicators should appear immediately.

Users should receive feedback within a short time after initiating an action.

---

# Notifications

Background completion should trigger appropriate notifications.

Examples:

- Report ready
- Export completed
- SMS delivery finished

---

# Dashboard Loading

Dashboard widgets may load independently.

One slow widget should not block the entire dashboard.

---

# Charts

Charts should display:

- Skeleton
- Loading message
- Empty state
- Error state

---

# Reports

Reports should communicate:

- Generation started
- Progress
- Completion
- Download ready

---

# Caching

Cached data should remain clearly distinguishable from refreshed data where appropriate.

---

# Review Checklist

Verify:

- Loading indicators exist
- Skeletons used appropriately
- Empty states helpful
- Offline handled
- Retry works
- Accessibility passes
- Responsive behaviour works
- Performance acceptable

---

# Definition of Done

Loading and empty states are complete only when:

- Users understand system state.
- Waiting is communicated clearly.
- Empty states guide next actions.
- Errors explain recovery.
- Accessibility passes.
- Responsive behaviour works.
- Performance remains acceptable.
- Tests pass.
- Documentation is updated.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 7 — Human Experience
- Rule 10 — Design first. Code second
- Rule 66 — Every feature has tests

---

# Related Documents

- UI-UX-Standards.md
- Design-System.md
- Feedback-and-Notifications.md
- Responsive-Design.md
- Accessibility-Standards.md

---

# Final Standard

Every loading, waiting, processing, offline, error, and empty state in ShuleOS must clearly communicate system status, reduce uncertainty, and help users continue their work confidently.

A responsive interface is not only about speed—it is about ensuring that users always understand what the School in the Clouds is doing on their behalf.
