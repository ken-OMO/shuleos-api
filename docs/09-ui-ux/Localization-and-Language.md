# ShuleOS Localization and Language Standards

> School in Clouds

## Document Information

| Field                | Value                                                                                |
| -------------------- | ------------------------------------------------------------------------------------ |
| Document             | Localization and Language Standards                                                  |
| Document ID          | UIUX-STD-0011                                                                        |
| Version              | 1.0                                                                                  |
| Status               | Approved                                                                             |
| Owner                | Product Design and Platform Engineering                                              |
| Repository           | `shuleos-web`                                                                        |
| Effective Date       | 03 August 2026                                                                       |
| Related Constitution | Engineering Constitution v1.1                                                        |
| Related Standards    | UI/UX Standards, Accessibility Standards, Mobile Experience Standards, Design System |

---

# Purpose

This document defines the localization and language standards for the ShuleOS platform.

It governs:

- Platform languages
- Translation architecture
- User language preferences
- Date and time formatting
- Numbers
- Currency
- Validation messages
- Reports
- Notifications
- PDFs
- Email
- SMS
- Future language expansion

Localization is a core platform capability, not an afterthought.

---

# Vision

Every teacher, learner, parent and administrator should be able to use ShuleOS comfortably in their preferred supported language.

The platform must feel natural rather than translated.

---

# Supported Languages

Version 1 officially supports:

- English
- Kiswahili

Future support may include additional Kenyan and international languages.

Every new language must integrate without changing application code.

---

# Default Language

Default platform language:

```text
English
```

Every user may choose a preferred language where permissions allow.

---

# Language Switching

Users should be able to change language without:

- Logging out
- Losing work
- Reloading the application unnecessarily

Language preference should persist across sessions.

---

# Translation Architecture

User-facing text must never be hardcoded.

Instead, use centralized translation resources.

Example:

```text
common.save
learner.add
finance.payment.success
attendance.submit
```

Avoid duplicate translation keys.

---

# Source Language

English serves as the source language for translation.

All additional languages should derive from the same translation keys.

---

# Consistency

The same concept must always use the same wording.

Examples:

Always:

Learner

Never mix:

Learner
Student
Pupil

unless intentionally distinguished.

---

# Kiswahili Quality

Kiswahili translations should use clear, standard Kiswahili suitable for schools.

Avoid:

- Machine translation
- Mixed English
- Inconsistent terminology
- Regional slang

---

# Educational Terminology

Educational vocabulary must remain consistent.

Examples include:

- Grade
- Stream
- Assessment
- Learning Area
- Attendance
- Scheme of Work
- Lesson Plan
- Curriculum Coverage

Approved terminology should be reused everywhere.

---

# User Preferences

User language preference should be stored per user.

Changing language should update:

- Navigation
- Forms
- Reports
- Menus
- Validation
- Notifications

---

# Date Formatting

Dates should use a consistent localized format.

Example:

```text
03 Aug 2026
```

Avoid ambiguous formats.

---

# Time

Display local school time.

Support both:

- 12-hour
- 24-hour

according to user preference where appropriate.

---

# Time Zone

Primary timezone:

```text
Africa/Nairobi
```

All timestamps should be stored consistently and displayed correctly.

---

# Numbers

Numbers should use localized formatting.

Example:

```text
12,500
```

Avoid difficult-to-read long numbers.

---

# Currency

Official currency:

```text
KES
```

Example:

```text
KES 5,250
```

Currency formatting should remain consistent throughout the platform.

---

# Percentages

Example:

```text
84%
```

Avoid unnecessary decimal places unless required.

---

# Validation Messages

Validation messages must be translated.

Example:

English

```text
Admission Number is required.
```

Kiswahili

```text
Nambari ya uandikishaji inahitajika.
```

---

# Buttons

Button labels should remain short.

Examples:

Save

Cancel

Submit

Delete

Print

Avoid long translated text that breaks layouts.

---

# Navigation

Navigation labels should remain:

- Consistent
- Short
- Recognizable

Avoid changing terminology between modules.

---

# Reports

Reports should support localization.

Examples:

- Headings
- Table labels
- Totals
- Footers
- Dates

Generated reports should match the selected language where appropriate.

---

# PDFs

PDF exports should preserve localized text correctly.

Fonts must support:

- English
- Kiswahili
- Future Unicode languages

---

# Email

Emails should respect the recipient's preferred language whenever available.

---

# SMS

SMS notifications should support:

- English
- Kiswahili

Messages should remain concise.

---

# Notifications

In-app notifications should use translated text rather than concatenated strings.

---

# Search

Search should remain usable regardless of selected interface language.

Where practical:

- Ignore case
- Handle accented characters
- Match translated content appropriately

---

# Accessibility

Localization must preserve:

- Screen-reader support
- Accessible labels
- Reading order
- Semantic meaning

Translations must never reduce accessibility.

---

# Mobile

Translations should fit mobile layouts.

Avoid wording that causes:

- Overflow
- Truncation
- Broken buttons

---

# Right-to-Left Readiness

Although ShuleOS initially supports left-to-right languages, components should avoid assumptions that prevent future right-to-left language support.

---

# Unicode

All platform components must support Unicode.

This includes:

- Database
- API
- Frontend
- PDFs
- Email
- SMS
- Reports

---

# Missing Translations

Missing translations should never display raw keys to users.

Fallback order:

1. Preferred language
2. English
3. Generic fallback

Missing keys should be logged for correction.

---

# Translation Review

Every translation should be reviewed for:

- Accuracy
- Grammar
- Educational terminology
- Consistency
- Layout impact

---

# Testing

Localization testing should verify:

- Navigation
- Forms
- Reports
- Notifications
- Validation
- Mobile layouts
- PDFs
- Emails
- SMS

---

# Review Checklist

Verify:

- Translation keys exist
- No hardcoded strings
- Dates localized
- Currency formatted
- Numbers formatted
- Validation translated
- Accessibility preserved
- Mobile layouts remain intact

---

# Definition of Done

Localization is complete only when:

- Supported languages work.
- No hardcoded user-facing strings remain.
- Reports localize correctly.
- Notifications localize correctly.
- Accessibility passes.
- Mobile layouts remain usable.
- Tests pass.
- Documentation is updated.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 3 — Privacy by Design
- Rule 6 — Consistency over cleverness
- Rule 7 — Human Experience
- Rule 10 — Design first. Code second
- Rule 66 — Every feature has tests
- Rule 126 — Localization is a platform capability
- Rule 127 — English and Kiswahili are first-class languages

---

# Related Documents

- UI-UX-Standards.md
- Accessibility-Standards.md
- Mobile-Experience.md
- Forms-and-Validation.md
- Feedback-and-Notifications.md

---

# Final Standard

Localization is fundamental to the ShuleOS vision of serving schools across Kenya.

Every interface, message, report, notification, and document must communicate naturally in the user's preferred supported language while maintaining consistency, accessibility, and educational accuracy throughout the School in the Clouds.
