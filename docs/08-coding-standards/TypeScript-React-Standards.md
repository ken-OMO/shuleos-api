# ShuleOS TypeScript & React Engineering Standards

> School in Clouds

## Document Information

| Field                | Value                                    |
| -------------------- | ---------------------------------------- |
| Document             | TypeScript & React Engineering Standards |
| Document ID          | CODE-STD-0003                            |
| Version              | 1.0                                      |
| Status               | Approved                                 |
| Owner                | Platform Engineering                     |
| Repository           | `shuleos-web`                            |
| Effective Date       | 03 August 2026                           |
| Framework            | Next.js 16                               |
| Language             | TypeScript 5+                            |
| React Version        | React 19                                 |
| Related Constitution | Engineering Constitution v1.1            |

---

# Purpose

This document defines the mandatory engineering standards for all frontend development within the ShuleOS platform.

It applies to:

- Next.js App Router
- React Components
- TypeScript
- Zustand
- TanStack Query
- Tailwind CSS
- shadcn/ui
- Forms
- API Clients
- Hooks
- Testing

Every frontend contributor must follow these standards.

---

# Engineering Philosophy

Frontend code should be:

- Predictable
- Reusable
- Accessible
- Testable
- Maintainable
- Type-safe
- Performance-conscious

Interfaces should remain simple for users and consistent for developers.

---

# TypeScript

TypeScript strict mode is mandatory.

Developers should:

- Prefer explicit types where clarity improves understanding.
- Avoid the `any` type.
- Use interfaces and type aliases appropriately.
- Keep types close to the domain they describe.

---

# Project Structure

Organize code by feature and responsibility.

Typical areas include:

- Components
- Features
- Hooks
- Services
- Stores
- Types
- Utilities

Avoid deeply nested directories.

---

# Components

Components should:

- Have one primary responsibility.
- Accept typed props.
- Be reusable where appropriate.
- Avoid unnecessary complexity.

Large components should be decomposed into smaller pieces.

---

# Server Components

Use Server Components by default.

Choose Client Components only when browser-specific functionality is required, such as:

- Local state
- Event handlers
- Browser APIs
- Interactive UI

---

# Client Components

Client Components should:

- Minimize client-side JavaScript.
- Avoid unnecessary rendering.
- Keep state localized where practical.

---

# Hooks

Custom hooks should:

- Encapsulate reusable logic.
- Begin with `use`.
- Avoid side effects outside React lifecycle hooks.

Hooks should remain focused and testable.

---

# State Management

Use Zustand for application state.

Keep global state minimal.

Prefer local component state unless information must be shared across multiple parts of the application.

---

# Data Fetching

Use TanStack Query for server state.

Benefits include:

- Caching
- Background refetching
- Retry behavior
- Optimistic updates

Avoid manually duplicating server state.

---

# API Clients

All API communication should pass through centralized service modules.

Components should not construct HTTP requests directly.

Authentication headers and error handling should remain consistent.

---

# Forms

Forms should:

- Validate user input.
- Display clear validation messages.
- Handle loading states.
- Prevent duplicate submissions.

Validation should occur on both client and server.

---

# Tailwind CSS

Use Tailwind CSS consistently.

Prefer utility classes over custom CSS where appropriate.

Avoid duplicated styling patterns.

---

# shadcn/ui

Use shadcn/ui components whenever they satisfy project requirements.

Customize through composition rather than modifying library source code.

---

# Accessibility

Interfaces should:

- Support keyboard navigation.
- Use semantic HTML.
- Include accessible labels.
- Maintain sufficient color contrast.
- Support screen readers.

Accessibility is a functional requirement.

---

# Performance

Optimize by:

- Lazy loading
- Code splitting
- Memoization where appropriate
- Image optimization
- Avoiding unnecessary re-renders

Measure before optimizing.

---

# Error Handling

Provide consistent error handling.

Users should receive clear, actionable messages without exposing internal implementation details.

---

# Security

Frontend code must:

- Never expose secrets.
- Respect authentication requirements.
- Respect authorization rules.
- Protect tenant isolation.

Security responsibilities are shared with the backend.

---

# Multi-Tenant Rules

The interface must always display data belonging only to the authenticated tenant.

Tenant context should remain consistent throughout navigation.

---

# Testing

Frontend testing should include:

- Component tests
- Integration tests
- Accessibility testing
- User interaction testing

Critical user workflows should have automated coverage.

---

# Documentation

Reusable components should include documentation where appropriate.

Complex UI patterns should reference the relevant ADR or design document.

---

# Code Reviews

Reviews should verify:

- Type safety
- Accessibility
- Performance
- Maintainability
- Testing
- Documentation

---

# Continuous Integration

CI should verify:

- TypeScript compilation
- ESLint
- Formatting
- Tests
- Build success

Quality failures block merges.

---

# Definition of Done

Frontend work is complete only when:

- Components implemented
- Types validated
- Tests pass
- Documentation updated
- Accessibility verified
- Review approved
- CI successful

---

# Constitution Compliance

This standard reinforces:

- Rule 1 — Quality over speed
- Rule 2 — Security before features
- Rule 4 — Tenant First
- Rule 10 — Design first. Code second
- Rule 17 — Audit important actions
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- Coding-Standards.md
- PHP-Laravel-Standards.md
- API-Naming.md
- Documentation-Standards.md
- Testing-Conventions.md
- Performance-Guidelines.md

---

# Final Standard

Every frontend component in ShuleOS must be developed with consistency, type safety, accessibility, performance, and long-term maintainability in mind.

These standards ensure that the School in the Clouds delivers a modern, responsive, and reliable user experience while remaining scalable for future growth.
