# ShuleOS Database Naming Standards

> School in Clouds

## Document Information

| Field                | Value                         |
| -------------------- | ----------------------------- |
| Document             | Database Naming Standards     |
| Document ID          | CODE-STD-0004                 |
| Version              | 1.0                           |
| Status               | Approved                      |
| Owner                | Platform Engineering          |
| Repository           | `shuleos-api`                 |
| Effective Date       | 03 August 2026                |
| Database             | PostgreSQL                    |
| Related Constitution | Engineering Constitution v1.1 |

---

# Purpose

This document establishes the mandatory database naming conventions for the ShuleOS platform.

It standardizes:

- Tables
- Columns
- Keys
- Constraints
- Indexes
- Pivot tables
- Enum values
- Audit columns
- Multi-tenant fields
- Migration names

Consistent naming improves readability, maintainability, and long-term scalability.

---

# General Principles

Database names should be:

- Descriptive
- Predictable
- Consistent
- Lowercase
- Snake case
- Singular only where explicitly defined

Avoid abbreviations unless universally understood.

---

# Table Names

Use:

- lowercase
- snake_case
- plural nouns

Examples:

```text
schools
users
teachers
learners
guardians
academic_years
assessment_results
transport_routes
hostel_rooms
```

Avoid:

```text
School
tblSchool
TeacherData
```

---

# Column Names

Use:

- lowercase
- snake_case

Examples:

```text
first_name
last_name
admission_number
phone_number
date_of_birth
school_id
created_at
```

---

# Primary Keys

Every table uses:

```text
id
```

Avoid:

```text
teacher_id
student_id
school_pk
```

---

# Foreign Keys

Foreign keys use:

```text
<related_table_singular>_id
```

Examples:

```text
school_id
teacher_id
stream_id
grade_id
parent_id
user_id
```

---

# Tenant Columns

Every tenant-owned table should include:

```text
school_id
```

Platform-level tables omit this column where appropriate.

---

# Pivot Tables

Pivot tables:

- lowercase
- snake_case
- singular model names
- alphabetical order

Examples:

```text
role_user
permission_role
teacher_learning_area
```

---

# Boolean Columns

Prefix with descriptive verbs.

Examples:

```text
is_active
is_deleted
is_verified
has_transport
has_disability
```

Avoid unclear names such as:

```text
active
status_flag
```

---

# Date Columns

Examples:

```text
date_of_birth
admission_date
employment_date
expiry_date
published_at
```

---

# Timestamp Columns

Laravel defaults:

```text
created_at
updated_at
deleted_at
```

Do not rename these unless absolutely necessary.

---

# Soft Deletes

Use Laravel's standard:

```text
deleted_at
```

---

# Audit Columns

Examples:

```text
created_by
updated_by
approved_by
deleted_by
```

These should reference the responsible user where appropriate.

---

# Enum Values

Enum values should be:

- lowercase
- descriptive
- stable

Examples:

```text
active
inactive
pending
approved
rejected
boarder
day_scholar
```

Avoid numeric "magic values" without documentation.

---

# Constraints

Constraint names should clearly identify their purpose.

Examples:

```text
fk_learners_school_id
fk_teachers_user_id
chk_fee_amount_positive
```

---

# Indexes

Use descriptive names.

Examples:

```text
idx_users_email
idx_learners_admission_number
idx_assessments_term_id
```

Composite indexes should include the participating columns.

---

# Unique Constraints

Examples:

```text
uq_users_email
uq_schools_code
uq_learners_admission_number
```

---

# Migration Names

Migration filenames should clearly describe the change.

Examples:

```text
create_teachers_table
add_school_id_to_users_table
create_transport_routes_table
```

Avoid vague names.

---

# Relationship Naming

Relationship methods in Eloquent should match the related model.

Examples:

```php
school()
teachers()
learners()
guardian()
stream()
```

Keep naming consistent across the project.

---

# Reserved Words

Avoid SQL reserved keywords such as:

```text
user
order
group
table
index
select
```

Use more descriptive alternatives where necessary.

---

# Multi-Tenant Rules

Tenant-aware tables must:

- include `school_id`
- enforce tenant scoping
- maintain referential integrity
- avoid cross-tenant relationships

---

# Documentation

Schema changes should include:

- updated migration
- model changes
- relationship updates
- documentation where required

---

# Continuous Integration

CI should verify:

- migration execution
- foreign keys
- naming consistency
- schema integrity

---

# Definition of Done

Database work is complete only when:

- Naming follows this standard
- Relationships validated
- Foreign keys implemented
- Indexes reviewed
- Documentation updated
- Migration tested

---

# Constitution Compliance

This standard reinforces:

- Rule 4 — Tenant First
- Rule 10 — Design first. Code second
- Rule 30 — Every query is tenant scoped
- Rule 66 — Every feature has tests
- Rule 107 — Production systems are observable
- Rule 110 — Architecture rules are enforced by CI

---

# Related Documents

- Coding-Standards.md
- PHP-Laravel-Standards.md
- API-Naming.md
- Documentation-Standards.md

---

# Final Standard

Consistent database naming enables ShuleOS to remain understandable and maintainable as it grows across admissions, finance, academics, examinations, transport, boarding, HR, library, inventory, and future modules.

Every schema change must follow these naming standards to preserve consistency across the School in the Clouds platform.
