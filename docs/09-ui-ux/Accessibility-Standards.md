# ShuleOS Accessibility Standards

> School in Clouds

## Document Information

| Field                | Value                                                                    |
| -------------------- | ------------------------------------------------------------------------ |
| Document             | Accessibility Standards                                                  |
| Document ID          | UIUX-STD-0003                                                            |
| Version              | 1.0                                                                      |
| Status               | Approved                                                                 |
| Owner                | Product Design and Platform Engineering                                  |
| Repository           | `shuleos-web`                                                            |
| Effective Date       | 03 August 2026                                                           |
| Related Constitution | Engineering Constitution v1.1                                            |
| Related Standards    | UI/UX Standards, Design System, TypeScript & React Engineering Standards |

---

# Purpose

This document defines the mandatory accessibility standards for the ShuleOS platform.

It governs:

- Keyboard navigation
- Screen-reader compatibility
- Semantic HTML
- Focus management
- Colour contrast
- Forms
- Tables
- Dialogs
- Status messages
- Motion
- Touch targets
- Responsive accessibility
- Accessibility testing
- Review and acceptance criteria

Accessibility is a functional requirement, not an optional enhancement.

---

# Accessibility Objectives

ShuleOS must support users with different:

- Visual abilities
- Hearing abilities
- Motor abilities
- Cognitive abilities
- Literacy levels
- Device types
- Connection conditions
- Assistive technologies

Every user should be able to understand, navigate, and operate the platform with confidence.

---

# Standard Target

ShuleOS should align with:

```text
WCAG 2.2 Level AA
```

Where a requirement cannot be met immediately, the limitation must be documented with:

- Impact
- Risk
- Temporary mitigation
- Owner
- Target resolution

---

# Core Principles

Accessible interfaces must be:

- Perceivable
- Operable
- Understandable
- Robust
- Consistent
- Predictable
- Testable

Accessibility must be considered during design, implementation, review, and testing.

---

# Semantic HTML

Use native semantic HTML wherever possible.

Examples:

```html
<header>
    <nav>
        <main>
            <section>
                <article>
                    <footer>
                        <button>
                            <form>
                                <label> <table></table></label>
                            </form>
                        </button>
                    </footer>
                </article>
            </section>
        </main>
    </nav>
</header>
```

Do not replace native elements with generic containers unless necessary.

Prefer:

```html
<button type="button">Save learner</button>
```

Avoid:

```html
<div onclick="saveLearner()">Save learner</div>
```

Native elements provide built-in keyboard and assistive technology support.

---

# Page Landmarks

Each page should provide meaningful landmarks.

Typical structure:

```html
<header>
    <nav aria-label="Primary navigation">
        <main>
            <footer></footer>
        </main>
    </nav>
</header>
```

Landmarks help screen-reader users navigate quickly.

---

# Heading Structure

Use headings in a logical hierarchy.

Example:

```text
H1 — Page title
H2 — Major section
H3 — Subsection
H4 — Nested subsection
```

Requirements:

- One primary page heading where practical
- No skipped heading levels without reason
- Headings must describe content
- Visual size must not determine semantic level

Do not use headings only for styling.

---

# Keyboard Navigation

Every interactive element must be operable using the keyboard.

Users must be able to:

- Move through controls using Tab and Shift+Tab
- Activate buttons using Enter or Space
- Operate menus
- Operate dialogs
- Navigate tabs
- Select options
- Close overlays
- Submit forms
- Access all meaningful actions

No essential action may require a mouse.

---

# Keyboard Order

Focus order must follow the visual and logical reading order.

Avoid:

- Unexpected focus jumps
- Hidden elements receiving focus
- Repeated navigation traps
- Positive `tabindex` values

Use natural document order wherever possible.

---

# Focus Visibility

Focused interactive elements must have a clearly visible focus indicator.

Focus indicators must:

- Be easy to see
- Have sufficient contrast
- Not rely on subtle colour change alone
- Remain visible in all themes
- Be consistent across components

Do not remove browser focus styles unless replacing them with an accessible alternative.

---

# Focus Management

Focus must be managed deliberately when the interface changes.

Examples:

- Move focus into an opened dialog.
- Return focus to the trigger after closing.
- Move focus to the first invalid field after failed form submission where appropriate.
- Move focus to a meaningful heading after major navigation.
- Keep focus stable during background updates.

Avoid unexpected focus movement.

---

# Skip Links

Long pages and dashboard layouts should provide a skip link such as:

```text
Skip to main content
```

The link should become visible on focus.

---

# Screen-Reader Support

Screen readers must receive meaningful information about:

- Page structure
- Controls
- Labels
- Status
- Errors
- Relationships
- Expanded or collapsed state
- Selected state
- Loading state

Do not depend on visual layout alone.

---

# Accessible Names

Every interactive element must have an accessible name.

Examples:

- Visible text
- Associated label
- `aria-label`
- `aria-labelledby`

Icon-only controls require explicit accessible names.

Example:

```html
<button aria-label="Delete learner">
    <TrashIcon aria-hidden="true" />
</button>
```

---

# ARIA Usage

Use ARIA only when native HTML cannot express the required semantics.

General rule:

```text
No ARIA is better than incorrect ARIA.
```

ARIA must:

- Match actual behaviour
- Remain synchronized with component state
- Use valid roles and attributes
- Be tested with assistive technology

Do not add ARIA roles to native elements unnecessarily.

---

# Images

Meaningful images require alternative text.

Examples:

```html
<img src="school-logo.png" alt="Lakeview Junior School" />
```

Decorative images should use:

```html
alt=""
```

Avoid repeating visible captions in alternative text.

---

# Icons

Decorative icons should be hidden from screen readers.

Example:

```html
<CheckIcon aria-hidden="true" />
```

Meaningful icon-only actions require accessible names.

---

# Colour Contrast

Text and interactive elements must meet approved contrast requirements.

Minimum targets:

```text
Normal text: 4.5:1
Large text: 3:1
UI components and focus indicators: 3:1
```

Contrast must be tested in:

- Default state
- Hover state
- Focus state
- Disabled state
- Selected state
- Error state
- Light theme
- Future dark theme

---

# Colour Independence

Do not use colour as the only way to communicate meaning.

Bad:

```text
Red row means overdue.
```

Better:

```text
Overdue badge + icon + text + colour
```

Status must remain understandable without colour perception.

---

# Typography

Text must remain readable.

Requirements:

- Sufficient size
- Comfortable line height
- Clear font
- Reasonable line length
- Good contrast
- No text embedded in images
- Support browser zoom

Do not prevent text resizing.

---

# Zoom and Reflow

The interface should remain usable at:

```text
200% browser zoom
```

Content should reflow without:

- Horizontal scrolling for standard content
- Hidden actions
- Overlapping text
- Clipped controls
- Loss of information

Large tables may use approved responsive alternatives.

---

# Forms

Every form control must have a programmatically associated label.

Prefer:

```html
<label for="first_name">First name</label>
<input id="first_name" name="first_name" />
```

Do not use placeholder text as the only label.

---

# Required Fields

Required fields must be indicated in text or accessible semantics.

Example:

```text
Admission number (required)
```

Do not rely only on an asterisk without explanation.

---

# Form Instructions

Forms should explain:

- Required information
- Accepted formats
- Limits
- Consequences
- Examples where useful

Instructions should appear before users encounter errors.

---

# Validation Errors

Errors must:

- Be associated with the relevant field
- Use plain language
- Explain how to correct the issue
- Remain visible until resolved
- Be announced to assistive technology
- Not rely on colour alone

Example:

```text
Enter a valid phone number, for example 0712 345 678.
```

Avoid:

```text
Invalid input.
```

---

# Error Summary

Long forms should provide an error summary when submission fails.

The summary should:

- State that errors were found
- List affected fields
- Link to each field
- Receive focus where appropriate

---

# Input Purpose

Use appropriate input types and autocomplete attributes.

Examples:

```html
<input type="email" autocomplete="email" />
<input type="tel" autocomplete="tel" />
<input autocomplete="given-name" />
```

This improves accessibility and mobile usability.

---

# Checkboxes, Radios, and Switches

Groups require:

- Clear group label
- Individual labels
- Keyboard support
- Visible selected state
- Programmatic relationship

Use fieldsets and legends where appropriate.

---

# Selects and Comboboxes

Custom selects and comboboxes must support:

- Keyboard navigation
- Search where needed
- Screen-reader announcement
- Selected state
- Expanded state
- Clear focus indication

Prefer native controls when they meet the requirement.

---

# Buttons

Buttons must:

- Have clear labels
- Support keyboard activation
- Show focus
- Communicate disabled state
- Communicate loading state
- Avoid duplicate submission

Do not use links for button actions or buttons for navigation without a clear reason.

---

# Links

Link text should describe the destination.

Prefer:

```text
View learner report
```

Avoid:

```text
Click here
Read more
```

Links opening a new tab should communicate that behaviour where appropriate.

---

# Dialogs

Dialogs must:

- Have an accessible title
- Move focus inside when opened
- Trap focus while active
- Close using Escape where safe
- Return focus to the trigger
- Hide background content from assistive technology
- Provide explicit actions

Do not open dialogs unexpectedly.

---

# Alerts and Status Messages

Important updates should be announced appropriately.

Examples:

- Form saved
- Payment failed
- Report generation started
- Connection lost
- Upload completed

Use appropriate live-region behaviour.

Avoid announcing every minor update, which can overwhelm users.

---

# Loading States

Loading indicators must communicate purpose.

Prefer:

```text
Loading learners…
Saving payment…
Generating report…
```

Avoid an unlabeled spinner with no accessible text.

Loading states should not repeatedly steal focus.

---

# Empty States

Empty states must be understandable to screen-reader users.

They should include:

- Clear heading
- Explanation
- Next action where appropriate

---

# Tables

Data tables require:

- Table caption or accessible name
- Header cells
- Correct header associations
- Logical reading order
- Keyboard-accessible actions
- Accessible sorting state
- Clear empty state

Use:

```html
<th scope="col"></th>
<th scope="row"></th>
```

where appropriate.

---

# Sortable Tables

Sortable headers must communicate:

- That sorting is available
- Current sort column
- Current sort direction

Do not rely on arrow icons alone.

---

# Complex Tables

Complex tables should be simplified where possible.

If complexity is unavoidable:

- Define row and column relationships
- Provide summaries
- Support responsive alternatives
- Test with screen readers

---

# Charts and Data Visualization

Charts must include accessible alternatives.

Requirements:

- Clear title
- Legend
- Labels
- Text summary
- Data table where practical
- Colour-independent interpretation

Charts must communicate insight, not merely decoration.

---

# Navigation

Navigation must support:

- Keyboard use
- Visible current location
- Clear labels
- Consistent order
- Accessible menu controls
- Collapsed state announcement
- Mobile navigation accessibility

The active page must not rely only on colour.

---

# Tabs

Tabs must follow established keyboard patterns.

Expected behaviour:

- Arrow keys move between tabs where appropriate
- Active tab is announced
- Tab panel relationship is programmatic
- Focus remains predictable

---

# Accordions

Accordion controls must communicate:

- Expanded state
- Collapsed state
- Associated content
- Keyboard operation

---

# Breadcrumbs

Breadcrumbs should use semantic navigation and identify the current page.

Example:

```html
<nav aria-label="Breadcrumb"></nav>
```

---

# Notifications

Notifications must be:

- Readable
- Dismissible where appropriate
- Keyboard accessible
- Announced only when necessary
- Persistent when action is required

Critical notifications must not disappear before users can understand them.

---

# Touch Targets

Interactive touch targets should be at least:

```text
44 × 44 CSS pixels
```

Where smaller visual controls are necessary, the interactive area should remain sufficiently large.

---

# Pointer Gestures

Do not require complex gestures for essential actions.

Any functionality using:

- Swiping
- Dragging
- Multi-touch
- Path-based gestures

must have a simpler alternative.

---

# Drag and Drop

Drag-and-drop interfaces must provide keyboard alternatives.

Examples:

- Move up/down buttons
- Position selectors
- Action menus

---

# Motion

Animations must:

- Be subtle
- Avoid flashing
- Avoid causing disorientation
- Respect reduced-motion preferences
- Not delay essential actions

---

# Reduced Motion

Use the user's reduced-motion preference.

Example:

```css
@media (prefers-reduced-motion: reduce) {
    /* reduce or remove non-essential animation */
}
```

---

# Flashing Content

Do not introduce content that flashes rapidly.

Avoid any pattern that could trigger photosensitive reactions.

---

# Time Limits

Avoid unnecessary time limits.

When time limits are required:

- Explain them
- Allow extension where possible
- Warn users before expiration
- Preserve work where practical

---

# Session Expiry

Before session expiry:

- Warn the user
- Explain remaining time
- Allow continuation where secure
- Preserve unsaved work where practical
- Provide an accessible re-authentication path

---

# Audio and Video

Media should provide:

- Captions for spoken content
- Transcripts where appropriate
- Controls
- Keyboard accessibility
- No unexpected autoplay with sound

Instructional videos should remain understandable without audio alone.

---

# Language

The page language must be declared.

Example:

```html
<html lang="en"></html>
```

When content switches language, mark the relevant section where practical.

This is especially important for English and Kiswahili content.

---

# Localization

Translated interfaces must retain:

- Correct labels
- Accessible names
- Error associations
- Reading order
- Layout integrity
- Clear abbreviations

Accessibility testing must include both English and Kiswahili.

---

# Mobile Accessibility

Mobile interfaces must support:

- Screen readers
- Touch targets
- Orientation changes
- Browser zoom
- Clear keyboard behaviour
- Minimal typing
- Accessible bottom navigation
- Safe virtual keyboard interaction

---

# Responsive Accessibility

Responsive changes must not:

- Remove essential content
- Change logical reading order unexpectedly
- Hide focusable elements incorrectly
- Make actions unreachable
- Depend on hover

---

# Hover Content

Information shown on hover must also be available through:

- Keyboard focus
- Touch
- Persistent text
- Another accessible control

---

# Disabled Controls

Disabled controls should be used carefully.

When users need to understand why an action is unavailable:

- Provide nearby explanation
- Use helper text
- Consider read-only or permission messaging

Disabled controls should not trap focus or create confusion.

---

# Permission States

Permission-denied interfaces must:

- Explain that access is unavailable
- Avoid exposing sensitive resource details
- Provide an appropriate next step
- Remain keyboard and screen-reader accessible

---

# Authentication Accessibility

Authentication flows must support:

- Password managers
- Paste into password fields
- Visible password option where appropriate
- Clear error messages
- Keyboard-only operation
- Accessible verification codes
- Recovery options

Do not block password managers without a strong security reason.

---

# CAPTCHA and Human Verification

Avoid inaccessible CAPTCHA mechanisms.

If human verification is required, provide accessible alternatives.

---

# File Uploads

File uploads must communicate:

- Accepted file types
- Size limits
- Upload progress
- Success
- Failure
- Removal action

File selection and removal must be keyboard accessible.

---

# PDF and Print Outputs

Generated documents intended for broad use should be readable and structurally clear.

Where practical:

- Use logical headings
- Ensure contrast
- Use readable typography
- Avoid clipped content
- Include text equivalents for essential visual information

---

# Accessibility Testing

Accessibility testing must include:

- Automated testing
- Keyboard testing
- Screen-reader testing
- Zoom testing
- Contrast testing
- Responsive testing
- Reduced-motion testing
- English and Kiswahili checks

Automated tools alone are not sufficient.

---

# Automated Testing

Automated accessibility checks may use tools such as:

- axe
- Lighthouse
- Testing Library accessibility assertions
- Browser accessibility audits

Automated checks should run in CI where practical.

---

# Manual Keyboard Test

For every critical workflow, verify:

- All controls are reachable.
- Focus is visible.
- Focus order is logical.
- No keyboard trap exists.
- Dialogs manage focus correctly.
- Menus and tabs operate correctly.
- Submission is possible without a mouse.

---

# Screen-Reader Test

Critical workflows should be tested with at least one supported screen reader and browser combination.

Testing should verify:

- Headings
- Landmarks
- Labels
- Errors
- Status messages
- Dialogs
- Tables
- Navigation

---

# Critical Workflows

Priority accessibility testing should cover:

- Login
- Password recovery
- Learner admission
- Attendance recording
- Mark entry
- Fee payment
- Report access
- Parent portal
- Timetable use
- Lesson planning

---

# Accessibility Defects

Accessibility defects should be classified by impact.

Examples:

## Critical

- Essential workflow cannot be completed.
- Keyboard trap.
- Screen reader cannot identify required controls.
- Authentication cannot be completed accessibly.

## High

- Major task is significantly difficult.
- Focus is lost in dialogs.
- Errors are not announced.
- Navigation is not operable by keyboard.

## Medium

- Contrast failure.
- Inconsistent heading structure.
- Missing supplementary labels.

## Low

- Minor wording or semantic improvement.

Critical and high accessibility defects must block release.

---

# Review Checklist

Every UI review should confirm:

- Semantic HTML used
- Keyboard operation verified
- Focus visible
- Accessible names present
- Labels associated
- Errors accessible
- Contrast passes
- Colour is not the only signal
- Motion respects preference
- Touch targets are sufficient
- Screen-reader behaviour is reasonable
- Responsive behaviour preserves access
- Localization remains accessible

---

# Continuous Integration

CI should verify where practical:

- Accessibility test suite
- Component semantics
- Automated contrast checks
- Invalid ARIA usage
- Missing accessible names
- Broken labels
- Build integrity

CI does not replace manual review.

---

# Definition of Done

An interface is accessibility-complete only when:

- Semantic structure is correct.
- Keyboard navigation works.
- Focus behaviour is verified.
- Labels and accessible names exist.
- Errors are understandable and announced.
- Colour contrast is verified.
- Colour is not the only communication method.
- Responsive and zoom behaviour works.
- Motion preferences are respected.
- Critical screen-reader flows are tested.
- English and Kiswahili remain usable.
- Automated checks pass.
- Manual review is complete.
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
- Rule 126 — Localization is a platform capability
- Rule 127 — English and Kiswahili are first-class languages

---

# Related Documents

- UI-UX-Standards.md
- Design-System.md
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

Accessibility is essential to the quality and trustworthiness of ShuleOS.

Every page, component, form, table, dialog, message, and workflow must remain understandable and operable for users with different abilities, devices, and assistive technologies.

The School in the Clouds must not exclude users from essential school work because of avoidable interface barriers.
