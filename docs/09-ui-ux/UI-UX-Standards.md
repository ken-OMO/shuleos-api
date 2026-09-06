# ShuleOS UI/UX Standards

> School in Clouds

## Document Information

| Field                | Value                                                                       |
| -------------------- | --------------------------------------------------------------------------- |
| Document             | UI/UX Standards                                                             |
| Document ID          | UIUX-STD-0001                                                               |
| Version              | 1.0                                                                         |
| Status               | Approved                                                                    |
| Owner                | Product Design and Platform Engineering                                     |
| Repositories         | `shuleos-web`, `shuleos-api`                                                |
| Effective Date       | 03 August 2026                                                              |
| Related Constitution | Engineering Constitution v1.1                                               |
| Related Standards    | TypeScript & React Engineering Standards, API Standards, Security Standards |

---

# Purpose

This document establishes the mandatory user interface and user experience standards for the ShuleOS platform.

It governs:

- Visual consistency
- Interaction design
- Accessibility
- Responsive behaviour
- Navigation
- Forms
- Tables
- Feedback
- Loading states
- Empty states
- Mobile experience
- Localization
- UI review

Every ShuleOS interface must provide a clear, predictable, accessible, and trustworthy experience.

---

# Product Experience Philosophy

ShuleOS serves users with different levels of digital literacy, including:

- School owners
- Administrators
- Principals
- Teachers
- Finance officers
- Parents
- Learners
- Platform operators

The interface must reduce confusion, support fast task completion, and communicate system state clearly.

The product should feel:

- Calm
- Clear
- Familiar
- Reliable
- Professional
- Fast
- Inclusive

---

# Core Principles

Every interface must prioritize:

- Clarity over decoration
- Consistency over novelty
- Accessibility over visual preference
- Task completion over unnecessary complexity
- Familiar patterns over surprising behaviour
- Progressive disclosure over crowded screens
- User confidence over technical detail

---

# User-Centred Design

Design decisions should be based on:

- User goals
- User roles
- Common school workflows
- Device constraints
- Connectivity constraints
- Accessibility needs
- Kenyan education context

Technical architecture must support the experience, not dictate it.

---

# Role-Aware Experience

Each role should see only the information and actions relevant to its responsibilities.

Examples:

- Teachers see assigned classes, lessons, attendance, and assessments.
- Finance officers see invoices, payments, balances, and reconciliation.
- Parents see only their linked learners.
- Learners see only their own records.
- Platform operators see platform-level administration.

The interface must never expose unauthorized actions merely because the backend will reject them.

Frontend restrictions complement, but never replace, backend authorization.

---

# Consistency

Consistent patterns must be used for:

- Page layouts
- Navigation
- Buttons
- Forms
- Tables
- Filters
- Dialogs
- Notifications
- Empty states
- Error states

The same action should look and behave the same across modules.

---

# Visual Hierarchy

Every screen should make the following clear:

1. Where the user is.
2. What the page is for.
3. What information matters most.
4. What action should be taken next.
5. What happened after an action.

Use headings, spacing, grouping, and emphasis deliberately.

---

# Page Structure

Standard pages should include:

- Page title
- Optional description
- Primary action
- Secondary actions
- Filters or search where applicable
- Main content
- Loading, empty, and error states

Avoid placing too many competing primary actions on one screen.

---

# Primary Actions

Every screen should have at most one clearly dominant primary action where practical.

Examples:

- Add learner
- Record payment
- Publish results
- Create timetable
- Save changes

Secondary actions should have lower visual emphasis.

---

# Navigation

Navigation must be:

- Predictable
- Role-aware
- Consistent
- Keyboard accessible
- Responsive

Users should always understand:

- Their current location
- How to return
- Where related features are found

Navigation standards are defined in `Navigation-Standards.md`.

---

# Forms

Forms must:

- Use clear labels
- Group related fields
- Show required fields
- Validate input
- Preserve user input after recoverable errors
- Explain how to fix errors
- Prevent duplicate submissions
- Provide clear success feedback

Placeholder text must not replace field labels.

Detailed standards are defined in `Forms-and-Validation.md`.

---

# Tables and Data Display

Tables should support:

- Readable headers
- Appropriate alignment
- Search
- Filtering
- Sorting
- Pagination
- Responsive behaviour
- Accessible row actions

Tables must not become unusable on small screens.

Detailed standards are defined in `Tables-and-Data-Display.md`.

---

# Feedback

Every meaningful action must provide feedback.

Examples:

- Save succeeded
- Save failed
- Payment is pending
- Report is being generated
- Record was deleted
- Permission was denied

Feedback should be timely, clear, and proportionate.

Detailed standards are defined in `Feedback-and-Notifications.md`.

---

# Loading States

Loading states should communicate that work is in progress.

Use appropriate patterns such as:

- Skeletons
- Inline spinners
- Progress indicators
- Disabled controls with status text

Avoid leaving users uncertain whether the system responded.

Detailed standards are defined in `Loading-and-Empty-States.md`.

---

# Empty States

Empty states should explain:

- Why no information is shown
- Whether this is expected
- What the user can do next

Good empty states guide action.

Example:

```text
No learners have been added to this stream yet.

Add a learner to begin.
```

---

# Error States

Error messages must:

- Use plain language
- Explain the problem
- Suggest the next step
- Avoid technical implementation details
- Include a support reference where appropriate

Do not display raw API responses or stack traces.

---

# Accessibility

Accessibility is a mandatory product requirement.

Interfaces must support:

- Keyboard navigation
- Screen readers
- Semantic HTML
- Visible focus states
- Sufficient colour contrast
- Clear labels
- Accessible error messages
- Reduced-motion preferences where applicable

Detailed standards are defined in `Accessibility-Standards.md`.

---

# Responsive Design

The product must work across:

- Desktop computers
- Laptops
- Tablets
- Mobile phones

Layouts should adapt without hiding critical functionality.

Detailed standards are defined in `Responsive-Design.md`.

---

# Mobile Experience

Mobile interfaces must prioritize:

- Essential tasks
- Touch-friendly controls
- Minimal typing
- Clear navigation
- Fast loading
- Resilience on slower connections

Detailed standards are defined in `Mobile-Experience.md`.

---

# Connectivity Awareness

ShuleOS serves users who may experience unstable or expensive connectivity.

The interface should:

- Minimize unnecessary requests
- Avoid repeated large downloads
- Preserve unsaved work where practical
- Indicate offline or reconnecting states
- Support retry safely
- Avoid duplicate submissions

Offline-first behaviour must follow the approved architecture.

---

# Language and Localization

ShuleOS must support English and Kiswahili as first-class languages.

Interface text must:

- Be clear
- Avoid unnecessary jargon
- Support translation
- Avoid being embedded directly in reusable components where localization is required
- Handle longer translated labels without breaking layouts

Detailed standards are defined in `Localization-and-Language.md`.

---

# Terminology

Use consistent domain terminology.

Examples:

- Learner, not student, where the platform standard uses learner
- Guardian, where the relationship may not be a parent
- Learning area, where appropriate in the Kenyan curriculum
- Academic year
- Term
- Stream
- Admission number

Do not use multiple terms for the same concept without a documented reason.

---

# Design System

All reusable interface decisions should follow the ShuleOS design system.

The design system governs:

- Typography
- Spacing
- Colour
- Components
- Icons
- States
- Elevation
- Layout
- Motion

Detailed standards are defined in `Design-System.md`.

---

# Component Reuse

Use reusable components for repeated patterns.

Examples:

- Buttons
- Inputs
- Dialogs
- Tables
- Page headers
- Empty states
- Error banners
- Status badges
- Confirmation prompts

Do not create visually inconsistent local versions of established components without justification.

---

# Destructive Actions

Destructive actions require special treatment.

Examples:

- Delete user
- Archive learner
- Reverse payment
- Remove role
- Unpublish results

Requirements:

- Clear wording
- Appropriate visual warning
- Confirmation
- Consequence explanation
- Stronger confirmation for irreversible actions
- Audit logging where required

Avoid vague labels such as:

```text
Confirm
```

Prefer:

```text
Delete learner
Reverse payment
Archive teacher
```

---

# Status Communication

Status should not rely on colour alone.

Use combinations of:

- Text
- Icons
- Badges
- Shape
- Colour

Examples:

```text
Active
Pending
Suspended
Failed
Paid
Partially paid
```

---

# Dates, Times, and Numbers

Display values consistently.

Requirements include:

- Clear date formats
- Local time where appropriate
- Currency identification
- Thousands separators
- Consistent decimal handling
- Explicit percentages
- Human-readable file sizes

Kenyan currency should be displayed clearly, for example:

```text
KES 12,500
```

---

# Confirmation and Undo

Use confirmation for actions with meaningful consequences.

Use undo where the action is safely reversible and immediate.

Do not require confirmation for harmless actions unnecessarily.

---

# Permissions and Disabled Actions

When users cannot perform an action:

- Hide it if it is irrelevant to their role.
- Disable it only when explaining the requirement is useful.
- Never imply that the frontend is the source of authorization truth.

Disabled controls should provide context where practical.

---

# Privacy

Interfaces must minimize exposure of sensitive information.

Examples:

- Mask sensitive identifiers where appropriate.
- Avoid showing unnecessary personal information.
- Prevent data from appearing in unauthorized previews.
- Avoid exposing sensitive information in URLs.
- Protect screens from accidental over-sharing where practical.

Learner information receives the highest privacy consideration.

---

# Notifications

Notifications must be:

- Relevant
- Timely
- Actionable
- Non-duplicative
- Respectful of user preferences

Avoid notification fatigue.

---

# Performance Experience

Users experience performance through:

- Initial load time
- Response time
- Visual stability
- Interaction delay
- Feedback speed

The UI must remain responsive even when backend work continues asynchronously.

---

# Progressive Disclosure

Show essential information first.

Advanced settings, uncommon options, and detailed diagnostics should appear only when needed.

This reduces cognitive load.

---

# Accessibility of Language

Use plain language.

Prefer:

```text
We could not save the learner. Check the highlighted fields and try again.
```

Avoid:

```text
Entity persistence failed due to invalid payload.
```

---

# User Testing

Important workflows should be tested with representative users where practical.

Priority workflows include:

- Learner admission
- Attendance
- Mark entry
- Fee payment
- Report generation
- Parent access
- Timetable creation
- Lesson planning

Feedback should improve the design before broad rollout.

---

# Analytics and Privacy

Product analytics may be used to improve usability.

Analytics must:

- Respect privacy
- Avoid unnecessary personal data
- Avoid capturing secrets
- Be documented
- Follow consent and legal requirements where applicable

---

# UI Documentation

Reusable patterns should be documented with:

- Purpose
- Usage
- Variants
- States
- Accessibility notes
- Examples
- Misuse cases

---

# Review Requirements

Every UI change should be reviewed for:

- Clarity
- Consistency
- Accessibility
- Responsiveness
- Role awareness
- Tenant safety
- Loading states
- Empty states
- Error states
- Performance
- Localization
- Test coverage

The operational checklist is defined in `UI-Review-Checklist.md`.

---

# Testing Requirements

UI testing should include:

- Component tests
- Interaction tests
- Accessibility tests
- Responsive checks
- Loading states
- Empty states
- Error states
- Permission states
- Localization checks
- Critical user journeys

---

# Continuous Integration

CI should verify where practical:

- TypeScript compilation
- Linting
- Component tests
- Accessibility checks
- Build success
- Broken translations
- Visual regression tests for critical components

Quality failures block merging.

---

# Definition of Done

A UI/UX feature is complete only when:

- User goal is supported.
- Design follows established patterns.
- Responsive behaviour is verified.
- Accessibility is verified.
- Loading, empty, success, and error states exist.
- Permissions are represented appropriately.
- Localization is supported where required.
- Tests pass.
- Documentation is updated.
- Review is approved.
- CI passes.

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 6 — Consistency over cleverness
- Rule 7 — Human Experience
- Rule 10 — Design first. Code second
- Rule 46 — Frontend authorization never replaces backend authorization
- Rule 66 — Every feature has tests
- Rule 107 — Production systems are observable
- Rule 126 — Localization is a platform capability
- Rule 127 — English and Kiswahili are first-class languages

---

# Related Documents

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
- UI-Review-Checklist.md
- TypeScript-React-Standards.md

---

# Final Standard

The ShuleOS interface must help users complete school work confidently, efficiently, and safely.

Every screen, component, interaction, message, and workflow must be clear, consistent, accessible, responsive, role-aware, privacy-conscious, and suitable for the real operating conditions of schools using the School in the Clouds.
