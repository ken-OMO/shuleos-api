# ShuleOS Tables and Data Display Standards

> School in Clouds

## Document Information

| Field                | Value                                                                      |
| -------------------- | -------------------------------------------------------------------------- |
| Document             | Tables and Data Display Standards                                          |
| Document ID          | UIUX-STD-0007                                                              |
| Version              | 1.0                                                                        |
| Status               | Approved                                                                   |
| Owner                | Product Design and Platform Engineering                                    |
| Repository           | `shuleos-web`                                                              |
| Effective Date       | 03 August 2026                                                             |
| Related Constitution | Engineering Constitution v1.1                                              |
| Related Standards    | UI/UX Standards, Design System, Responsive Design, Accessibility Standards |

---

# Purpose

This document defines the standards for displaying structured information throughout ShuleOS.

It applies to:

- Tables
- Lists
- Cards
- Reports
- Registers
- Grids
- Statistics
- Dashboards
- Analytics
- Timetables
- Audit logs

Every data display should maximize readability, consistency and efficiency.

---

# Design Philosophy

School administrators spend much of their time viewing data rather than entering it.

Therefore, tables must:

- Be easy to scan
- Minimize clutter
- Support quick decision-making
- Remain responsive
- Handle large datasets efficiently

---

# Core Principles

Every table must be:

- Readable
- Consistent
- Responsive
- Accessible
- Searchable
- Filterable
- Sortable
- Performant

---

# Standard Table Structure

Every standard table should contain:

- Title
- Description (optional)
- Search
- Filters
- Table
- Pagination
- Bulk actions (where applicable)

---

# Columns

Columns should:

- Have clear headings
- Use concise names
- Align consistently
- Avoid abbreviations unless standard

Example:

Good

Admission Number

Avoid

Adm No.

---

# Column Alignment

Recommended alignment:

Text

Left

Numbers

Right

Dates

Center or Left

Actions

Right

Status

Center

---

# Default Columns

Where appropriate include:

- Identifier
- Name
- Category
- Status
- Updated Date
- Actions

---

# Sorting

Sortable columns should display:

- Current sort direction
- Sort indicator
- Accessible label

Default sorting should match user expectations.

---

# Filtering

Filters should support:

- Status
- Grade
- Stream
- Academic Year
- Term
- Date Range
- Learning Area

Filters should remain visible and easy to reset.

---

# Search

Search should:

- Debounce requests
- Ignore case
- Handle partial matches
- Return relevant results
- Respect permissions

---

# Pagination

Standard pagination should include:

- Previous
- Next
- Current page
- Total pages
- Total records

Optional:

- Page size selector

---

# Page Sizes

Recommended options:

10

25

50

100

The default should prioritize performance.

---

# Sticky Headers

Large tables should use sticky headers.

Users should always know which column they are reading.

---

# Row Height

Rows should remain compact while preserving readability.

Avoid unnecessary whitespace.

---

# Row Selection

Bulk operations should support:

- Individual selection
- Select all current page
- Clear selection

Selected rows should be visually obvious.

---

# Bulk Actions

Examples:

- Archive
- Delete
- Export
- Assign
- Print

Bulk actions should require confirmation where appropriate.

---

# Row Actions

Typical actions:

- View
- Edit
- Print
- Archive
- Delete

Actions should be grouped consistently.

---

# Action Menus

Overflow menus may be used for secondary actions.

Primary actions should remain visible.

---

# Status Badges

Status should use consistent badges.

Examples:

- Active
- Inactive
- Pending
- Archived
- Paid
- Unpaid
- Approved

Badges should not rely on colour alone.

---

# Empty States

When no records exist:

Explain:

- Why
- What the user should do next

Example:

No learners found.

Add your first learner.

---

# Loading State

Large tables should display:

- Skeleton rows
- Loading indicator
- Stable layout

Avoid flashing layouts.

---

# Error State

If data cannot be loaded:

Explain:

- What happened
- Suggested action
- Retry option

---

# Responsive Behaviour

Desktop

Full table

Tablet

Reduced columns where appropriate

Mobile

Responsive cards or horizontal scrolling

Users must not lose important information.

---

# Responsive Cards

On smaller devices cards may replace tables.

Each card should contain:

- Primary information
- Secondary details
- Actions

---

# Data Formatting

Use consistent formatting.

Examples:

Currency

KES 12,500

Date

03 Aug 2026

Percentage

85%

---

# Numbers

Large numbers should include thousands separators.

Avoid:

1250000

Prefer:

1,250,000

---

# Dates

Display dates consistently.

Include year where helpful.

Avoid ambiguous formats.

---

# Currency

Always display currency explicitly.

Example:

KES 5,000

---

# Time

Display local school time where appropriate.

---

# Long Text

Long text should:

- Wrap appropriately
- Truncate with tooltip when needed

Avoid breaking layouts.

---

# Images

Images inside tables should:

- Be consistent
- Small
- Optional

Large images should not appear in tables.

---

# Icons

Icons should complement—not replace—text.

---

# Accessibility

Tables must support:

- Keyboard navigation
- Screen readers
- Captions
- Header associations
- Focus visibility

---

# Large Datasets

Large datasets should use:

- Server-side pagination
- Server-side filtering
- Server-side sorting

Avoid loading thousands of records into the browser.

---

# Virtual Scrolling

Virtual scrolling may be used for extremely large datasets.

It must preserve accessibility.

---

# Export

Supported exports:

- Excel
- CSV
- PDF

Exports should respect filters.

---

# Printing

Printable tables should:

- Remove unnecessary controls
- Fit page width
- Repeat headers
- Preserve readability

---

# Audit Tables

Audit tables should include:

- User
- Action
- Resource
- Timestamp
- IP Address where appropriate

Audit data must be immutable.

---

# Financial Tables

Financial tables should clearly display:

- Debit
- Credit
- Balance
- Currency

Totals should be visually distinct.

---

# Dashboard Tables

Dashboard tables should prioritize:

- Recent activity
- Pending work
- Alerts

Avoid overwhelming users.

---

# Timetable Display

Timetables should support:

- Colour grouping
- Responsive layout
- Print-friendly version

---

# Reports

Reports should:

- Group related information
- Show summaries
- Include totals
- Support export

---

# Performance

Tables should:

- Avoid unnecessary re-rendering
- Lazy-load data where appropriate
- Preserve scroll position where beneficial

---

# Review Checklist

Verify:

- Search works
- Filters work
- Sorting works
- Pagination works
- Responsive layout works
- Accessibility passes
- Export works
- Printing works
- Performance acceptable

---

# Definition of Done

A table is complete only when:

- Data is readable.
- Sorting works.
- Filtering works.
- Search works.
- Responsive behaviour works.
- Accessibility passes.
- Performance is acceptable.
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
- Responsive-Design.md
- Accessibility-Standards.md
- Forms-and-Validation.md

---

# Final Standard

Every ShuleOS table, report and data view must enable school staff to locate, understand and act on information quickly.

Data presentation should prioritize clarity, consistency, accessibility, responsiveness and performance across every module in the School in the Clouds.
