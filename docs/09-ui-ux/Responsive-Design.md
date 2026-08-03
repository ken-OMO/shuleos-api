# ShuleOS Responsive Design Standards

> School in Clouds

## Document Information

| Field                | Value                                                   |
| -------------------- | ------------------------------------------------------- |
| Document             | Responsive Design Standards                             |
| Document ID          | UIUX-STD-0004                                           |
| Version              | 1.0                                                     |
| Status               | Approved                                                |
| Owner                | Product Design and Platform Engineering                 |
| Repository           | `shuleos-web`                                           |
| Effective Date       | 03 August 2026                                          |
| Related Constitution | Engineering Constitution v1.1                           |
| Related Standards    | UI/UX Standards, Design System, Accessibility Standards |

---

# Purpose

This document defines the responsive design standards for the ShuleOS platform.

It governs:

- Responsive layouts
- Breakpoints
- Grid systems
- Mobile-first design
- Navigation
- Forms
- Tables
- Cards
- Dashboards
- Images
- Touch interactions
- Performance
- Responsive testing

Every feature must remain usable across supported screen sizes without sacrificing functionality.

---

# Design Philosophy

Responsive design is not about shrinking desktop pages.

It is about delivering the best experience for the current device.

Users should never lose functionality simply because they changed devices.

---

# Supported Devices

ShuleOS officially supports:

- Desktop computers
- Laptops
- Tablets
- Mobile phones

Interfaces should adapt naturally to available space.

---

# Mobile-First Principle

Every interface should be designed mobile-first.

Enhancements for larger screens should progressively add:

- More space
- Additional panels
- Larger tables
- More simultaneous information

Core functionality must always exist on mobile.

---

# Breakpoints

The platform follows the standard Tailwind CSS responsive breakpoints.

Typical ranges include:

```text
Small (mobile)
Medium (tablet)
Large (laptop)
Extra Large (desktop)
```

Avoid creating custom breakpoints unless there is a strong justification.

---

# Flexible Layouts

Layouts should use flexible containers instead of fixed widths.

Prefer:

- Flexbox
- CSS Grid
- Responsive utility classes

Avoid absolute positioning for page layout.

---

# Content Width

Different content requires different widths.

Examples:

- Forms → medium width
- Dashboards → wide
- Reports → full width
- Settings → comfortable reading width

Do not stretch narrow forms across very wide screens.

---

# Grid System

Use a consistent responsive grid.

The grid should support:

- Single-column mobile layouts
- Multi-column desktop layouts
- Dashboard widgets
- Cards
- Reports
- Tables

Spacing should remain consistent across breakpoints.

---

# Dashboard Layouts

Dashboard widgets should:

- Stack vertically on mobile
- Expand into multiple columns on larger screens
- Preserve reading order
- Avoid horizontal scrolling

Important information should appear first.

---

# Responsive Navigation

Navigation should adapt by screen size.

Desktop:

- Sidebar
- Expanded navigation

Tablet:

- Collapsible sidebar

Mobile:

- Drawer or bottom navigation where appropriate

Navigation should remain keyboard accessible.

---

# Responsive Forms

Forms should:

- Use one column on mobile
- Expand to multiple columns only when readability improves
- Keep labels close to controls
- Preserve validation messages
- Maintain logical reading order

Required actions should remain visible.

---

# Responsive Tables

Large tables require special handling.

Possible strategies:

- Horizontal scrolling
- Responsive cards
- Priority columns
- Expandable rows
- Detail panels

Avoid making users zoom the page to read data.

---

# Cards

Cards should resize gracefully.

Requirements:

- Consistent spacing
- Responsive typography
- Flexible width
- Stable hierarchy

Avoid cards that become unusable on narrow screens.

---

# Buttons

Buttons must remain:

- Large enough to tap
- Clearly labelled
- Consistently positioned
- Responsive

Critical actions should remain easy to reach.

---

# Images

Images should:

- Scale automatically
- Preserve aspect ratio
- Avoid distortion
- Load efficiently
- Support high-density displays

Decorative images should never interfere with usability.

---

# Media

Embedded media should:

- Resize correctly
- Avoid overflow
- Preserve usability
- Support landscape and portrait orientations

---

# Typography

Typography should adapt without harming readability.

Requirements:

- Comfortable line length
- Appropriate font size
- Consistent hierarchy
- No clipped text

Avoid excessive text scaling between breakpoints.

---

# Spacing

Spacing should remain proportional across devices.

Use design-system spacing tokens rather than arbitrary values.

---

# Touch Targets

Touch controls should remain comfortably usable.

Interactive elements should support:

- Finger input
- Screen readers
- Keyboard alternatives where applicable

Small clickable areas should be avoided.

---

# Orientation

Interfaces should support both:

- Portrait
- Landscape

Important actions should remain available regardless of orientation.

---

# Scrolling

Vertical scrolling is expected.

Avoid unnecessary horizontal scrolling except for approved table behaviour.

---

# Sticky Elements

Sticky headers or actions may be used when they improve usability.

Examples:

- Save button
- Page header
- Table header

Sticky elements must not hide important content.

---

# Performance

Responsive layouts should not significantly increase:

- Bundle size
- Rendering cost
- Layout shifts
- Memory usage

Performance remains a first-class requirement.

---

# Loading States

Loading indicators should adapt to screen size.

Skeleton layouts should resemble the final responsive layout.

---

# Empty States

Responsive empty states should remain readable on small screens.

Primary actions should remain visible.

---

# Error States

Error messages should:

- Wrap correctly
- Remain readable
- Avoid overflow
- Keep actions visible

---

# Dialogs

Dialogs should adapt responsively.

Desktop:

- Centered modal

Mobile:

- Full-screen or bottom sheet where appropriate

Dialogs must remain accessible.

---

# Responsive Charts

Charts should:

- Resize correctly
- Preserve labels
- Support touch
- Maintain readability

Alternative textual summaries should remain available.

---

# Responsive Maps

Maps should:

- Resize correctly
- Preserve controls
- Avoid covering important content
- Support touch gestures

---

# Offline Behaviour

Responsive layouts should continue functioning when offline features are available.

Connectivity loss should not break the layout.

---

# Progressive Enhancement

Core functionality should work before advanced enhancements load.

Users on slower devices should still complete important tasks.

---

# Browser Support

Responsive behaviour should be verified across supported browsers.

Do not rely on experimental CSS features without fallback strategies.

---

# Responsive Testing

Every major page should be tested at:

- Mobile
- Tablet
- Laptop
- Desktop

Testing should verify:

- Layout
- Navigation
- Forms
- Tables
- Dialogs
- Charts
- Empty states
- Error states

---

# Device Testing

Testing should include real devices where practical.

Emulators alone are insufficient for critical workflows.

---

# Critical Workflows

Responsive testing should prioritize:

- Login
- Learner admission
- Attendance
- Mark entry
- Fee payment
- Report generation
- Parent portal
- Timetable
- Lesson planning

---

# Definition of Done

A responsive feature is complete only when:

- Layout adapts correctly.
- No critical functionality is lost.
- Navigation remains usable.
- Forms remain readable.
- Tables remain usable.
- Touch interactions work.
- Accessibility is preserved.
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
- Rule 126 — Localization is a platform capability

---

# Related Documents

- UI-UX-Standards.md
- Design-System.md
- Accessibility-Standards.md
- Navigation-Standards.md
- Forms-and-Validation.md
- Tables-and-Data-Display.md
- Mobile-Experience.md

---

# Final Standard

Responsive design is fundamental to the ShuleOS experience.

Every screen, workflow, and component must adapt gracefully across mobile phones, tablets, laptops, and desktop computers, ensuring that teachers, administrators, parents, and learners can complete their work effectively regardless of the device they use.
