# ShuleOS Feedback and Notifications Standards

> School in Clouds

## Document Information

| Field                | Value                                                                                   |
| -------------------- | --------------------------------------------------------------------------------------- |
| Document             | Feedback and Notifications Standards                                                    |
| Document ID          | UIUX-STD-0008                                                                           |
| Version              | 1.0                                                                                     |
| Status               | Approved                                                                                |
| Owner                | Product Design and Platform Engineering                                                 |
| Repository           | `shuleos-web`                                                                           |
| Effective Date       | 03 August 2026                                                                          |
| Related Constitution | Engineering Constitution v1.1                                                           |
| Related Standards    | UI/UX Standards, Design System, Accessibility Standards, Forms and Validation Standards |

---

# Purpose

This document defines the standards for all user feedback and notification mechanisms within ShuleOS.

It governs:

- Success messages
- Error messages
- Warning messages
- Information messages
- Toast notifications
- Inline feedback
- Confirmation dialogs
- Progress indicators
- Background processing notifications
- Email notifications
- SMS notifications
- Future push notifications
- Notification centre
- User notification preferences

Users should always understand:

- What happened
- Why it happened
- What happens next
- Whether action is required

---

# Design Philosophy

Feedback should reduce uncertainty.

The interface should never leave users wondering:

- Did it save?
- Is it loading?
- Was the payment recorded?
- Was the learner admitted?
- Did the report generate?

Every important action deserves appropriate feedback.

---

# Core Principles

Feedback must be:

- Immediate
- Clear
- Helpful
- Consistent
- Accessible
- Respectful
- Actionable
- Non-disruptive

---

# Feedback Levels

ShuleOS uses four primary feedback categories.

## Success

Examples:

- Learner admitted successfully.
- Payment recorded.
- Attendance submitted.
- Report published.

---

## Information

Examples:

- Report generation started.
- Backup scheduled.
- New timetable available.

---

## Warning

Examples:

- Trial expires in 3 days.
- Fee balance remains outstanding.
- Duplicate admission number detected.

---

## Error

Examples:

- Payment failed.
- Report generation failed.
- Connection lost.
- Validation failed.

---

# Message Writing

Messages should:

- Use plain language
- Explain what happened
- Suggest the next step where appropriate
- Avoid technical jargon

Good:

```text
Learner admitted successfully.
```

Avoid:

```text
Operation completed.
```

---

# Tone

The system should remain:

- Professional
- Calm
- Helpful
- Respectful

Avoid blaming users.

Instead of:

```text
You entered invalid data.
```

Prefer:

```text
Please correct the highlighted fields.
```

---

# Toast Notifications

Toasts are suitable for brief events.

Examples:

- Saved
- Copied
- Deleted
- Export started

They should:

- Appear consistently
- Dismiss automatically where appropriate
- Remain accessible
- Avoid stacking excessively

---

# Inline Feedback

Inline messages belong close to the relevant content.

Examples:

- Validation errors
- Upload progress
- Duplicate record warnings

Inline feedback is preferred when users need immediate context.

---

# Confirmation Dialogs

Confirmation should be required for actions with meaningful consequences.

Examples:

- Delete learner
- Reverse payment
- Archive teacher
- Publish results

Confirmation dialogs should explain:

- What will happen
- Whether it can be undone

---

# Destructive Actions

Destructive actions should use:

- Clear wording
- Warning styling
- Explicit confirmation

Avoid generic buttons such as:

```text
OK
```

Prefer:

```text
Delete learner
Reverse payment
```

---

# Progress Indicators

Long-running operations should communicate progress.

Examples:

- Uploading documents
- Importing learners
- Generating reports
- Sending SMS

Use:

- Progress bars
- Percentage where available
- Status text

---

# Background Jobs

Some operations continue after the user leaves the page.

Examples:

- Bulk SMS
- Report generation
- Backup
- Data export

Users should receive confirmation that the job started and later notification when it completes or fails.

---

# Notification Centre

The platform should include a notification centre for important events.

Examples:

- Fee reminders
- New assessments
- Timetable changes
- Parent messages
- Approval requests

Notifications should be grouped and searchable.

---

# Notification Priorities

Priority levels:

Critical

Immediate attention required.

High

Action required soon.

Normal

Useful operational information.

Low

General updates.

Priority influences presentation but not authorization.

---

# Email Notifications

Email may be used for:

- Password reset
- Invitations
- Reports
- Receipts
- Billing
- Platform communication

Emails should use approved templates.

---

# SMS Notifications

SMS should support:

- Fee reminders
- Attendance alerts
- Emergency announcements
- Exam notifications

Messages should remain concise.

---

# Push Notifications

Future push notifications should support:

- Mobile devices
- Browsers
- User preferences
- Quiet hours where supported

---

# Notification Preferences

Users should control appropriate notification types.

Possible preferences:

- Email
- SMS
- Push
- In-app

Critical security notifications may override preferences where justified.

---

# Accessibility

Notifications must support:

- Screen readers
- Keyboard users
- Colour-independent meaning
- Appropriate live regions

Accessibility standards remain mandatory.

---

# Localization

All notification text must support English and Kiswahili.

Avoid embedding fixed strings inside components.

---

# Audit Requirements

Important notifications should be auditable.

Examples:

- Fee receipt emailed
- SMS delivered
- Password reset requested
- Report published

Audit records should not expose sensitive message content unnecessarily.

---

# Review Checklist

Every notification should be reviewed for:

- Clarity
- Timing
- Accessibility
- Localization
- Appropriate priority
- Duplicate prevention
- Correct destination
- Actionability

---

# Definition of Done

A feedback mechanism is complete only when:

- Users understand what happened.
- Required actions are clear.
- Accessibility passes.
- Localization is supported.
- Notification timing is appropriate.
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
- Rule 66 — Every feature has tests

---

# Related Documents

- UI-UX-Standards.md
- Design-System.md
- Forms-and-Validation.md
- Accessibility-Standards.md
- Navigation-Standards.md

---

# Final Standard

Every ShuleOS notification should increase user confidence by clearly communicating system state, outcomes, and next steps.

Feedback must always be timely, understandable, accessible, consistent, and appropriate to the importance of the event, ensuring that users across the School in the Clouds always know what happened and what to do next.
