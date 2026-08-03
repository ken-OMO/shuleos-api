# ShuleOS Design System

> School in Clouds

## Document Information

| Field                | Value                                                                              |
| -------------------- | ---------------------------------------------------------------------------------- |
| Document             | Design System                                                                      |
| Document ID          | UIUX-STD-0002                                                                      |
| Version              | 1.0                                                                                |
| Status               | Approved                                                                           |
| Owner                | Product Design and Platform Engineering                                            |
| Repository           | `shuleos-web`                                                                      |
| Effective Date       | 03 August 2026                                                                     |
| Related Constitution | Engineering Constitution v1.1                                                      |
| Related Standards    | UI/UX Standards, TypeScript & React Engineering Standards, Accessibility Standards |

---

# Purpose

This document defines the visual and component design system for ShuleOS.

It governs:

- Design tokens
- Colour
- Typography
- Spacing
- Layout
- Icons
- Components
- States
- Elevation
- Motion
- Branding
- Dark mode
- Component documentation

The design system is the single source of truth for reusable interface decisions across the ShuleOS platform.

---

# Design System Objectives

The ShuleOS design system should:

- Create consistency across modules.
- Reduce duplicated UI work.
- Improve accessibility.
- Accelerate development.
- Support multiple roles and devices.
- Preserve brand identity.
- Simplify maintenance.
- Enable reliable scaling.

---

# Design Principles

The design system must be:

- Consistent
- Accessible
- Composable
- Predictable
- Responsive
- Brand-aware
- Token-driven
- Documented

Components should solve common product needs without becoming unnecessarily complex.

---

# Technology Foundation

The ShuleOS frontend uses:

- Next.js 16
- React 19
- TypeScript
- Tailwind CSS
- shadcn/ui
- Radix UI primitives where applicable

The design system should build on these technologies rather than creating an unrelated component framework.

---

# Design Tokens

Reusable visual decisions must be represented through design tokens.

Token categories include:

- Colour
- Typography
- Spacing
- Radius
- Shadow
- Border
- Motion
- Breakpoints
- Z-index

Avoid hard-coded visual values inside feature components where a shared token is appropriate.

---

# Token Naming

Token names should describe purpose rather than raw appearance.

Prefer:

```text
background
foreground
primary
primary_foreground
destructive
muted
border
success
warning
```

Avoid names such as:

```text
blue_500_button
light_grey_box
red_text
```

Semantic naming makes themes and future redesigns easier.

---

# Colour System

The colour system must support:

- Brand identity
- Readability
- Accessibility
- Status communication
- Light mode
- Future dark mode
- Data visualization

Colour must never be the only way information is communicated.

---

# Core Colour Roles

Required semantic roles include:

```text
background
foreground
surface
surface_muted
primary
primary_foreground
secondary
secondary_foreground
accent
accent_foreground
border
input
ring
destructive
destructive_foreground
success
success_foreground
warning
warning_foreground
info
info_foreground
```

Exact colour values should be configured centrally.

---

# Brand Colours

The official ShuleOS brand palette should be defined and reviewed before production rollout.

Brand colours must:

- Meet accessibility contrast requirements.
- Work across digital displays.
- Remain recognizable in print where applicable.
- Avoid conflicting with semantic warning and error colours.

Brand colour usage should remain controlled.

---

# Neutral Colours

Neutral colours support:

- Backgrounds
- Borders
- Disabled controls
- Secondary text
- Table separators
- Cards
- Panels

Neutral scales should provide enough contrast levels without creating unnecessary variation.

---

# Status Colours

Status colours should represent consistent meanings.

Recommended semantic mapping:

```text
success     completed, active, paid, approved
warning     pending, partial, expiring, needs attention
destructive failed, rejected, overdue, blocked
info        informational, scheduled, processing
neutral     inactive, draft, archived, unknown
```

Always pair colour with text or an icon.

---

# Contrast

Text and interactive components must meet approved accessibility contrast requirements.

The design system must define accessible foreground/background combinations.

Do not rely on visual judgment alone; contrast should be tested.

---

# Typography

Typography should support:

- Readability
- Hierarchy
- Dense administrative interfaces
- Mobile screens
- English and Kiswahili
- Numerical data
- Long school names and labels

Use a limited, consistent type scale.

---

# Font Family

The primary font should:

- Be highly readable.
- Support required characters.
- Render reliably across browsers.
- Work well for both text and numbers.
- Be licensed appropriately.

The application should define fallback system fonts.

Example strategy:

```text
Primary UI font
System fallback
Sans-serif fallback
```

---

# Type Scale

The type scale should include:

- Display
- Page title
- Section heading
- Subheading
- Body
- Small body
- Caption
- Label

Avoid arbitrary font sizes.

---

# Recommended Hierarchy

Typical use:

```text
Display        rare dashboard or marketing emphasis
Page title     primary page heading
Section title  major content grouping
Subheading     card or panel heading
Body           standard content
Small          supporting information
Caption        metadata and helper text
Label          form controls and compact UI
```

---

# Font Weight

Use weight deliberately.

Recommended roles:

- Regular for body text
- Medium for labels and navigation
- Semibold for headings and primary actions
- Bold only for strong emphasis

Avoid excessive bold text.

---

# Line Height

Line height should support comfortable reading.

Dense data interfaces may use compact line heights, but body text must remain readable.

---

# Text Alignment

Use:

- Left alignment for most text
- Right alignment for numeric table values where useful
- Centre alignment sparingly
- Consistent alignment within data columns

Avoid centred paragraphs in operational interfaces.

---

# Spacing System

Use a consistent spacing scale.

Spacing should control:

- Padding
- Margins
- Gaps
- Layout rhythm
- Component density

Avoid arbitrary spacing values unless justified.

---

# Spacing Principles

Spacing should communicate relationships.

- Smaller space indicates stronger relationship.
- Larger space separates sections.
- Consistent spacing creates rhythm.
- Dense views must still remain scannable.

---

# Layout Grid

Layouts should use a responsive grid system.

The grid should support:

- Full-width pages
- Constrained content
- Dashboard panels
- Forms
- Tables
- Mobile stacking

Avoid fixed-width layouts that fail on smaller screens.

---

# Content Width

Use appropriate content widths:

- Wide layouts for tables and dashboards
- Medium widths for forms
- Narrow widths for focused reading or confirmation tasks

Do not stretch short form fields unnecessarily across large screens.

---

# Breakpoints

Breakpoints should follow the project's responsive design standard.

They must be defined centrally and used consistently.

Do not create feature-specific breakpoints without strong justification.

---

# Border Radius

Use a limited radius scale.

Typical roles:

- Small radius for compact controls
- Medium radius for inputs and cards
- Large radius for prominent surfaces
- Full radius for pills and avatars

Avoid inconsistent corner styles.

---

# Borders

Borders should provide structure without overwhelming the interface.

Use them for:

- Inputs
- Tables
- Cards
- Dividers
- Focus states

Avoid excessive boxed layouts.

---

# Elevation and Shadows

Elevation should communicate layering.

Use shadows sparingly for:

- Dialogs
- Popovers
- Dropdowns
- Floating controls
- Elevated cards where necessary

Do not use heavy shadows as decoration.

---

# Z-Index

Z-index levels should be standardized.

Suggested categories:

```text
base
sticky
dropdown
popover
overlay
modal
toast
critical
```

Avoid arbitrary values such as:

```text
z-index: 99999
```

---

# Icons

Use a consistent icon library.

Icons should:

- Match visual weight.
- Use consistent sizing.
- Include accessible labels where needed.
- Support text rather than replace it in ambiguous actions.
- Avoid decorative inconsistency.

Icons alone should not represent unfamiliar actions.

---

# Icon Sizes

Define standard icon sizes for:

- Inline text
- Buttons
- Navigation
- Status
- Empty states
- Large illustrations

Avoid arbitrary scaling.

---

# Buttons

Button variants should include:

- Primary
- Secondary
- Outline
- Ghost
- Destructive
- Link

Button sizes should include:

- Small
- Default
- Large
- Icon-only where appropriate

---

# Button Rules

Buttons must:

- Use action-oriented labels.
- Show loading state.
- Prevent duplicate submission.
- Include focus states.
- Meet touch target requirements.
- Avoid vague labels.

Prefer:

```text
Save learner
Record payment
Publish results
```

Avoid:

```text
OK
Submit
Proceed
```

when a clearer action is available.

---

# Primary Button

Primary buttons represent the most important action on a screen.

Use them sparingly.

Multiple primary buttons in the same visual area should be avoided.

---

# Destructive Button

Destructive buttons must clearly indicate risk.

Use destructive styling only for genuinely destructive actions.

Examples:

- Delete
- Reverse
- Remove access
- Archive where consequences are significant

---

# Icon-Only Buttons

Icon-only buttons require:

- Accessible names
- Tooltips where useful
- Sufficient touch target
- Familiar icons

Do not rely on icons users may not recognize.

---

# Inputs

Standard input components include:

- Text input
- Email input
- Password input
- Number input
- Date input
- Search input
- Text area
- Select
- Combobox
- Checkbox
- Radio group
- Switch
- File input

All controls must share consistent states.

---

# Input States

Every input should support:

- Default
- Hover
- Focus
- Filled
- Disabled
- Read-only
- Error
- Success where appropriate
- Loading where applicable

---

# Labels and Help Text

Every form control must have a visible label unless the control has an approved accessible pattern.

Helper text should explain:

- Format
- Constraints
- Consequences
- Examples

Error text must be associated with the relevant control.

---

# Cards

Cards may group related information.

A card should have a clear purpose.

Typical card anatomy:

- Header
- Title
- Description
- Content
- Footer or actions

Avoid placing every section inside a card.

---

# Panels

Panels are appropriate for:

- Dashboard sections
- Filter areas
- Settings groups
- Summary areas
- Side information

Panel styles should remain consistent.

---

# Dialogs

Dialogs should be used for focused tasks requiring user attention.

Requirements:

- Clear title
- Clear purpose
- Keyboard accessibility
- Focus management
- Escape behaviour where safe
- Explicit action buttons
- Destructive confirmation where applicable

Do not place large, complex workflows inside small dialogs.

---

# Drawers and Sheets

Drawers or sheets may support:

- Mobile navigation
- Filters
- Supporting detail
- Compact editing

They should not hide essential context unnecessarily.

---

# Dropdown Menus

Dropdowns should contain related secondary actions.

Primary actions should not be hidden in overflow menus without reason.

---

# Tooltips

Tooltips should provide supplementary information.

They must not contain essential instructions required to complete a task.

---

# Badges

Badges represent compact status or classification.

Examples:

- Active
- Pending
- Paid
- Overdue
- Draft
- Published

Badge meaning must remain consistent across modules.

---

# Alerts

Alert variants should include:

- Informational
- Success
- Warning
- Error

Alerts should contain:

- Clear message
- Optional explanation
- Optional action
- Accessible semantics

---

# Toasts

Toasts are appropriate for temporary feedback.

Examples:

- Saved successfully
- Export started
- Link copied

Toasts should not be the only location for critical information.

---

# Tables

The design system should provide reusable table primitives for:

- Header
- Body
- Rows
- Cells
- Sorting
- Selection
- Actions
- Empty state
- Loading state
- Pagination

Detailed behaviour is defined in `Tables-and-Data-Display.md`.

---

# Tabs

Tabs should switch between closely related views.

Requirements:

- Clear labels
- Keyboard support
- Visible active state
- Persistent selection where appropriate

Avoid using tabs for unrelated workflows.

---

# Breadcrumbs

Breadcrumbs may support deep administrative navigation.

They should:

- Reflect hierarchy
- Use clear labels
- Avoid excessive depth
- Keep the current page identifiable

---

# Pagination

Pagination components should be consistent across modules.

They should support:

- Current page
- Previous and next
- Page size where appropriate
- Total result context
- Accessible labels

---

# Search

Search components should:

- Use clear placeholder guidance
- Support keyboard operation
- Indicate loading
- Display no-results state
- Avoid unnecessary requests through appropriate debouncing

---

# Filters

Filter components should:

- Show active filters
- Support reset
- Use meaningful labels
- Preserve state where appropriate
- Work on smaller screens

---

# Avatars

Avatars may represent users or institutions.

Fallbacks should use:

- Initials
- Approved generic icons
- Accessible names

Do not expose private images without authorization.

---

# Data Visualization

Charts should:

- Use accessible colour combinations
- Include labels or legends
- Avoid colour-only interpretation
- Provide textual summaries where practical
- Show loading and empty states
- Use consistent scales

Charts must support decision-making rather than decoration.

---

# Component Variants

Variants should exist only when they represent a meaningful, reusable design need.

Avoid creating many nearly identical variants.

Every variant should have:

- Purpose
- Usage guidance
- States
- Accessibility notes
- Examples

---

# Component Composition

Prefer composition over deeply configurable components with many boolean props.

Good component APIs should remain understandable.

Avoid patterns such as:

```text
compact
small
minimal
simple
flat
borderless
dense
tiny
```

all existing without clear definitions.

---

# Component Ownership

Every shared component must have an owner or responsible team.

Owners are responsible for:

- Accessibility
- Stability
- Documentation
- Testing
- Deprecation
- Migration support

---

# Component Documentation

Each shared component should document:

- Purpose
- Props
- Variants
- States
- Examples
- Accessibility
- Responsive behaviour
- Do and don't guidance

---

# Component Testing

Shared components should include:

- Rendering tests
- Interaction tests
- Accessibility tests
- Keyboard tests
- State tests
- Responsive checks where practical

---

# Loading Components

The system should define consistent:

- Spinner
- Skeleton
- Progress bar
- Progress message
- Inline loading indicator

Use the pattern appropriate to the expected wait.

---

# Empty-State Components

Reusable empty states should support:

- Title
- Explanation
- Optional illustration or icon
- Primary next action
- Secondary help action

Empty-state language should remain specific to the user's situation.

---

# Error Components

Reusable error patterns should include:

- Inline field error
- Form-level error
- Page error
- Permission denied
- Not found
- Connectivity error
- Unexpected error

Each should guide the next step.

---

# Density

Administrative users may need dense data interfaces.

The design system may support documented density modes such as:

- Comfortable
- Compact

Density must not compromise accessibility or touch targets.

---

# Motion

Motion should:

- Explain change
- Reinforce hierarchy
- Provide feedback
- Remain subtle
- Respect reduced-motion preferences

Avoid decorative animation that delays task completion.

---

# Motion Duration

Use a limited duration scale.

Typical categories:

- Instant
- Fast
- Standard
- Slow

Long animations are inappropriate for routine operational work.

---

# Reduced Motion

Users who prefer reduced motion should receive minimal or no non-essential animation.

---

# Dark Mode

Dark mode may be introduced when fully supported.

It must:

- Use semantic tokens
- Meet contrast requirements
- Support all components
- Preserve status meaning
- Avoid simple colour inversion
- Be tested comprehensively

Do not launch an incomplete dark mode.

---

# Branding

Branding should include:

- ShuleOS name
- Logo
- Approved colour usage
- Typography
- Product voice
- School in Clouds identity

Branding must not reduce usability.

---

# Tenant Branding

Schools may receive controlled branding capabilities where supported.

Possible options:

- School logo
- School name
- Limited accent colour
- Report branding

Tenant branding must not:

- Break contrast
- Hide ShuleOS-required notices
- Override security states
- Damage component consistency
- introduce arbitrary CSS

---

# White-Label Boundaries

If white-label capabilities are introduced, the permitted customization surface must be explicitly documented.

Security and accessibility remain non-negotiable.

---

# Print Design

Some ShuleOS outputs will be printed.

Examples:

- Report cards
- Receipts
- Statements
- Attendance registers
- Official reports

Print styles should:

- Remove irrelevant navigation
- Preserve hierarchy
- Use readable contrast
- Handle page breaks
- Display school identity appropriately
- Avoid clipping tables

---

# Localization

Components must support translated text.

Requirements:

- Flexible widths
- No text embedded in images
- No assumptions about label length
- Locale-aware dates and numbers
- English and Kiswahili compatibility

---

# Content Voice

Interface language should be:

- Clear
- Respectful
- Direct
- Professional
- Encouraging where appropriate

Avoid blaming users.

---

# Deprecation

Deprecated components must have:

- Replacement guidance
- Migration instructions
- Removal timeline
- Usage tracking where practical

Do not remove widely used components without migration planning.

---

# Versioning

Major design system changes should be versioned and documented.

Breaking component changes require migration guidance.

---

# Governance

New shared components or major variants should be reviewed for:

- Reusability
- Accessibility
- Consistency
- API clarity
- Maintenance cost
- Existing alternatives

Not every feature-specific UI belongs in the shared design system.

---

# Continuous Integration

CI should verify where practical:

- Type safety
- Component tests
- Accessibility checks
- Visual regression tests
- Build integrity
- Deprecated component usage
- Design token validity

---

# Definition of Done

A design system component is complete only when:

- Purpose is clear.
- Design tokens are used.
- All required states exist.
- Accessibility is verified.
- Responsive behaviour is verified.
- Localization is supported.
- Tests pass.
- Documentation is complete.
- Review is approved.
- CI passes.

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

The ShuleOS design system is the shared visual and interaction language of the School in the Clouds.

Every shared token, component, state, pattern, and branded element must improve consistency, accessibility, usability, maintainability, and trust across the platform while supporting the real operational needs of schools.
