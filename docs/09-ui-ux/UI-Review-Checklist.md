# ShuleOS UI Review Checklist

> School in Clouds

## Document Information

| Field                | Value                                   |
| -------------------- | --------------------------------------- |
| Document             | UI Review Checklist                     |
| Document ID          | UIUX-STD-0012                           |
| Version              | 1.0                                     |
| Status               | Approved                                |
| Owner                | Product Design and Platform Engineering |
| Repository           | `shuleos-web`                           |
| Effective Date       | 03 August 2026                          |
| Related Constitution | Engineering Constitution v1.1           |
| Related Standards    | All UI/UX Standards                     |

---

# Purpose

This checklist is the mandatory quality gate for every user interface change before it is merged into the ShuleOS codebase.

No new page, component, workflow, dialog, form, dashboard, or feature should be approved without completing this review.

---

# Review Philosophy

Every UI review should answer one question:

> **Would a teacher, school administrator, parent, learner, or platform administrator confidently complete this task without confusion?**

If the answer is "No", the feature is not ready.

---

# General Review

Verify:

- Design follows the Design System.
- UI is visually consistent.
- Layout is clean.
- Typography is consistent.
- Icons are consistent.
- Spacing follows standards.
- Colors follow design tokens.
- No placeholder content remains.
- No debug information is visible.

---

# Navigation

Verify:

- Navigation hierarchy is correct.
- Sidebar behaves correctly.
- Active page is highlighted.
- Breadcrumbs are correct.
- URLs are meaningful.
- Browser Back button behaves correctly.
- Deep links work.
- Navigation respects permissions.

---

# Responsive Design

Test:

- Mobile phone
- Tablet
- Laptop
- Desktop

Verify:

- No broken layouts.
- No horizontal scrolling.
- Buttons remain visible.
- Tables remain usable.
- Dialogs resize correctly.

---

# Mobile Experience

Verify:

- Touch targets are comfortable.
- Mobile navigation works.
- Forms remain usable.
- Keyboard does not hide fields.
- Camera/file uploads work where applicable.
- Orientation changes are supported.

---

# Accessibility

Verify:

- Keyboard navigation works.
- Focus indicators are visible.
- Semantic HTML is used.
- Labels exist.
- Screen reader support is reasonable.
- Color is not the only indicator.
- Contrast requirements pass.
- Images have appropriate alternative text.
- Dialogs manage focus correctly.

---

# Forms

Verify:

- Labels exist.
- Helper text is useful.
- Validation messages are clear.
- Required fields are identified.
- Input values persist after validation errors.
- Duplicate submission is prevented.
- Success feedback appears.
- Backend validation exists.

---

# Tables

Verify:

- Search works.
- Filters work.
- Sorting works.
- Pagination works.
- Status badges are correct.
- Row actions work.
- Export functions work.
- Empty states are useful.

---

# Loading States

Verify:

- Skeletons appear appropriately.
- Progress indicators work.
- Empty states are helpful.
- Offline state behaves correctly.
- Retry options exist where appropriate.
- Long-running operations communicate progress.

---

# Notifications

Verify:

- Success messages are clear.
- Error messages are helpful.
- Warning messages are understandable.
- Confirmation dialogs exist where needed.
- Notification timing is appropriate.
- Duplicate notifications are avoided.

---

# Localization

Verify:

- No hardcoded strings.
- English translations exist.
- Kiswahili translations exist.
- Currency formatting is correct.
- Date formatting is correct.
- Numbers are localized.
- Layout remains intact after translation.

---

# Performance

Verify:

- Page loads quickly.
- Large tables perform well.
- Images are optimized.
- No unnecessary API calls.
- No layout shifts.
- Lazy loading works.
- Bundle size remains reasonable.

---

# Security

Verify:

- Unauthorized actions are hidden appropriately.
- Backend authorization protects all operations.
- Sensitive data is protected.
- CSRF protection exists.
- Forms use secure submission.
- No sensitive information appears in URLs.
- Error messages do not expose internal implementation details.

---

# Browser Compatibility

Test supported browsers.

Verify:

- Layout consistency.
- Form behaviour.
- Navigation.
- Tables.
- Dialogs.
- Reports.
- Printing.

---

# Reports

Verify:

- Report layout is correct.
- Totals are accurate.
- Export works.
- Print layout works.
- Localization is correct.

---

# Charts

Verify:

- Responsive behaviour.
- Labels remain readable.
- Empty states exist.
- Accessibility considerations are met.
- Export works where supported.

---

# Role-Based Review

Verify each supported role only sees permitted functionality.

Examples:

- Platform Owner
- School Owner
- Principal
- Deputy Principal
- Teacher
- Finance Officer
- Librarian
- Parent
- Learner

---

# Tenant Review

Verify:

- Data belongs only to the active school.
- Navigation reflects current tenant.
- Switching schools updates context correctly.
- No cross-school information leakage occurs.

---

# Error Handling

Verify:

- Validation errors.
- API failures.
- Network failures.
- Permission errors.
- Timeout handling.
- Unexpected server failures.

Users should always receive clear guidance.

---

# Testing

Confirm:

- Unit tests pass.
- Component tests pass.
- Integration tests pass.
- End-to-end tests pass.
- Accessibility tests pass.
- Manual testing completed.

---

# Documentation

Verify:

- Relevant documentation updated.
- Screenshots updated where required.
- API documentation updated if affected.
- Changelog updated where applicable.

---

# Code Quality

Verify:

- TypeScript passes.
- ESLint passes.
- Formatting passes.
- No unused imports.
- No dead code.
- No console debugging remains.

---

# Review Questions

The reviewer should ask:

- Is this intuitive?
- Is it consistent?
- Is it accessible?
- Is it responsive?
- Is it secure?
- Is it localized?
- Is it performant?
- Would I confidently deploy this to production?

---

# Release Blockers

The following block release:

- Accessibility failure
- Security issue
- Tenant isolation issue
- Broken navigation
- Critical responsive issue
- Broken forms
- Data corruption risk
- Failed tests

---

# Approval

A UI feature may be approved only when:

- All checklist items pass.
- Tests pass.
- Documentation is complete.
- Reviewer approval recorded.
- Product requirements satisfied.

---

# Definition of Done

A UI feature is complete only when:

- Design standards are followed.
- Responsive behaviour works.
- Accessibility passes.
- Navigation is consistent.
- Forms behave correctly.
- Tables perform well.
- Notifications are appropriate.
- Localization is complete.
- Performance is acceptable.
- Security requirements are satisfied.
- Tests pass.
- Documentation is complete.
- Code review is approved.

---

# Constitution Compliance

This checklist reinforces the entire Engineering Constitution, especially:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 7 — Human Experience
- Rule 10 — Design first. Code second
- Rule 46 — Frontend authorization never replaces backend authorization
- Rule 66 — Every feature has tests
- Rule 126 — Localization is a platform capability
- Rule 127 — English and Kiswahili are first-class languages

---

# Related Documents

- UI-UX-Standards.md
- Design-System.md
- Accessibility-Standards.md
- Responsive-Design.md
- Navigation-Standards.md
- Forms-and-Validation.md
- Tables-and-Data-Display.md
- Feedback-and-Notifications.md
- Loading-and-Empty-States.md
- Mobile-Experience.md
- Localization-and-Language.md

---

# Final Standard

Every ShuleOS user interface must earn approval through objective review rather than subjective opinion.

This checklist serves as the final quality gate, ensuring that every screen, workflow, and interaction delivered within the School in the Clouds meets the platform's standards for usability, accessibility, security, performance, consistency, and maintainability before reaching production.
