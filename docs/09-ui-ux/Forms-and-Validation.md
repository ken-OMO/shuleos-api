# ShuleOS Forms and Validation Standards

> School in Clouds

## Document Information

| Field                | Value                                                                         |
| -------------------- | ----------------------------------------------------------------------------- |
| Document             | Forms and Validation Standards                                                |
| Document ID          | UIUX-STD-0006                                                                 |
| Version              | 1.0                                                                           |
| Status               | Approved                                                                      |
| Owner                | Product Design and Platform Engineering                                       |
| Repository           | `shuleos-web`                                                                 |
| Effective Date       | 03 August 2026                                                                |
| Related Constitution | Engineering Constitution v1.1                                                 |
| Related Standards    | UI/UX Standards, Design System, Accessibility Standards, Navigation Standards |

---

# Purpose

This document defines the mandatory standards for every form used throughout the ShuleOS platform.

These standards apply to:

- Authentication
- Learner Admission
- Teacher Management
- Guardian Registration
- Academic Setup
- Assessment
- Finance
- Boarding
- Transport
- Library
- HR
- Inventory
- Parent Portal
- Platform Administration

Every form must be consistent, accessible, secure, responsive, and easy to complete.

---

# Design Philosophy

Forms exist to help users complete work—not to test their patience.

Every form should:

- Minimize effort
- Prevent mistakes
- Recover gracefully from errors
- Preserve entered information
- Clearly communicate progress
- Finish quickly

---

# Core Principles

Every form must be:

- Simple
- Predictable
- Accessible
- Secure
- Responsive
- Performant
- Recoverable
- Consistent

---

# Form Structure

Typical form structure:

1. Title
2. Description
3. Related sections
4. Form fields
5. Validation feedback
6. Primary action
7. Secondary action
8. Success feedback

Avoid placing unrelated information inside forms.

---

# Field Ordering

Arrange fields in the same order users naturally think.

Example:

Learner Admission

1. Admission Number
2. Learner Name
3. Gender
4. Date of Birth
5. Grade
6. Stream
7. Guardian
8. Save

Never arrange fields according to database column order.

---

# Labels

Every field requires a visible label.

Good:

```text
Admission Number
```

Avoid:

```text
Adm No
```

unless officially standardized.

Labels must remain visible even after data is entered.

---

# Placeholder Text

Placeholder text is optional.

It should provide examples rather than replace labels.

Example:

```text
0712 345 678
```

Never use placeholder text as the only label.

---

# Required Fields

Required fields must be clearly identified.

Example:

```text
Admission Number *
```

or

```text
Admission Number (Required)
```

Do not rely on colour alone.

---

# Optional Fields

Optional fields should be marked where appropriate.

Example:

```text
Middle Name (Optional)
```

---

# Helper Text

Helper text should explain:

- Format
- Constraints
- Examples
- Consequences

Example:

```text
The admission number must be unique within this school.
```

---

# Input Controls

Use the correct control.

Examples:

- Text input
- Email input
- Password
- Number
- Date
- Select
- Combobox
- Checkbox
- Radio
- Text area
- File upload

Avoid using text fields when structured controls exist.

---

# Default Values

Provide sensible defaults where appropriate.

Examples:

- Current academic year
- Current term
- Today's date

Never prefill sensitive information without reason.

---

# Validation Philosophy

Validation should prevent bad data without frustrating users.

Validation must be:

- Immediate where useful
- Consistent
- Helpful
- Understandable

---

# Client-Side Validation

Client-side validation should verify:

- Required fields
- Format
- Length
- Simple ranges
- Immediate feedback

Client-side validation improves experience but does not replace backend validation.

---

# Server-Side Validation

The backend remains the source of truth.

Every submitted field must be validated server-side.

Frontend validation is never a security mechanism.

---

# Validation Messages

Validation messages should:

- Explain the issue
- Explain how to fix it
- Avoid technical language

Good:

```text
Admission Number is required.
```

Bad:

```text
Validation Error.
```

---

# Error Placement

Field errors should appear near the affected field.

Large forms should also provide a summary.

---

# Preserving Input

After validation failure:

- Keep user-entered values
- Highlight errors
- Avoid clearing completed sections

Users should not need to start over.

---

# Duplicate Submission

Prevent duplicate submissions by:

- Disabling the submit button while saving
- Showing progress
- Ignoring repeated clicks

---

# Loading State

While saving:

- Disable primary action
- Display progress
- Keep layout stable

Example:

```text
Saving learner...
```

---

# Success Feedback

Successful submission should clearly indicate completion.

Examples:

- Learner created successfully.
- Payment recorded successfully.
- Assessment saved.

---

# Multi-Step Forms

Large workflows may be divided into steps.

Each step should:

- Show progress
- Allow previous review
- Preserve entered data
- Validate incrementally

---

# Keyboard Behaviour

Users should be able to:

- Navigate fields using Tab
- Submit where appropriate
- Use keyboard shortcuts when documented

---

# Accessibility

Forms must satisfy all accessibility standards including:

- Labels
- Keyboard support
- Focus visibility
- Screen-reader compatibility
- Error announcement

---

# Responsive Behaviour

Forms should:

- Use one column on mobile
- Expand where beneficial
- Avoid horizontal scrolling
- Keep actions visible

---

# Security

Forms must support:

- CSRF protection
- Authorization
- Rate limiting where appropriate
- Secure autocomplete handling
- Backend validation
- Audit logging for sensitive operations

---

# Sensitive Fields

Sensitive fields include:

- Passwords
- National IDs
- Payment information
- Staff credentials

They require additional protection.

---

# File Uploads

File uploads must show:

- Accepted types
- Maximum size
- Upload progress
- Success
- Failure

---

# Draft Saving

Long forms may support draft saving where appropriate.

Drafts must:

- Be clearly identified
- Support recovery
- Respect permissions

---

# Review Checklist

Every form review should verify:

- Labels exist
- Validation works
- Error messages are helpful
- Accessibility passes
- Responsive layout works
- Duplicate submission prevented
- Security requirements met
- Success feedback shown

---

# Definition of Done

A form is complete only when:

- Users understand every field.
- Validation works.
- Backend validation exists.
- Accessibility passes.
- Responsive behaviour works.
- Duplicate submission is prevented.
- Success feedback exists.
- Tests pass.
- Documentation is updated.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 6 — Consistency over cleverness
- Rule 7 — Human Experience
- Rule 10 — Design first. Code second
- Rule 46 — Frontend authorization never replaces backend authorization
- Rule 66 — Every feature has tests

---

# Related Documents

- UI-UX-Standards.md
- Design-System.md
- Accessibility-Standards.md
- Navigation-Standards.md
- Responsive-Design.md

---

# Final Standard

Every ShuleOS form should help users complete school work accurately, efficiently, and confidently.

Forms must minimize mistakes, preserve user effort, protect sensitive data, and provide clear feedback from the first field to successful submission.
