# ShuleOS Database Standards

> School in Clouds

## Document Information

| Field                | Value                                                                |
| -------------------- | -------------------------------------------------------------------- |
| Document             | Database Standards                                                   |
| Document ID          | DB-STD-0001                                                          |
| Version              | 1.0                                                                  |
| Status               | Approved                                                             |
| Owner                | Platform Engineering                                                 |
| Repository           | `shuleos-api`                                                        |
| Effective Date       | 02 August 2026                                                       |
| Related Constitution | Engineering Constitution v1.1                                        |
| Related ADRs         | ADR-0001, ADR-0002, ADR-0004, ADR-0005, ADR-0007, ADR-0010, ADR-0011 |

## Purpose

This document defines the mandatory database engineering standards for ShuleOS.

It governs:

- PostgreSQL schema design
- Laravel migrations
- Multi-tenant ownership
- Primary keys
- Foreign keys
- Unique constraints
- Indexes
- Transactions
- Locking
- Financial integrity
- Archiving
- Data retention
- Row-Level Security
- Database testing
- Backup and recovery
- Performance review
- Continuous Integration checks

These standards apply to every ShuleOS domain and every database change.

## Authority

The ShuleOS Engineering Constitution is the highest engineering authority.

Architecture Decision Records define approved architectural decisions.

This document defines how those decisions are implemented at database level.

Where this document conflicts with the Engineering Constitution, the Constitution prevails.

## Core Principles

ShuleOS database engineering follows these principles:

1. Migrations are the schema source of truth.
2. Tenant ownership is explicit.
3. Database integrity does not depend only on application code.
4. Foreign keys preserve ownership and relationships.
5. Uniqueness is tenant-scoped by default.
6. Indexes are designed from real query patterns.
7. Financial records are append-only.
8. Transactions preserve business invariants.
9. Destructive changes require rollback and recovery plans.
10. Tests use the real schema.
11. Performance is measured.
12. Backups are verified through restore testing.

## PostgreSQL

PostgreSQL is the authoritative relational database for ShuleOS.

PostgreSQL-specific capabilities may be used where they improve:

- Data integrity
- Tenant isolation
- Performance
- Concurrency control
- Search
- Reporting
- Auditability
- Operational safety

PostgreSQL-specific features must remain documented and testable.

Examples include:

- Partial indexes
- Expression indexes
- Check constraints
- Generated columns
- JSONB
- Advisory locks
- Row-Level Security
- Deferrable constraints
- Transaction isolation levels
- Native UUID support
- Full-text search

Database features must not be introduced merely because they are available.

They must solve a documented requirement.

# Schema Source of Truth

## Migrations Define the Schema

The Laravel migrations directory is the authoritative schema definition.

The schema is not defined by:

- A developer's local database
- A production database snapshot
- A SQL dump in the repository root
- Manual pgAdmin changes
- Test setup code
- Memory
- Documentation alone

The following command must be capable of rebuilding the application schema:

```bash
php artisan migrate:fresh
```

Where seed data is required for development or testing:

```bash
php artisan migrate:fresh --seed
```

## Manual Schema Changes

Manual production or development schema changes are prohibited unless required for emergency incident response.

Any emergency database change must be:

- Documented
- Reviewed
- Reproduced in a migration
- Tested
- Audited
- Included in the incident postmortem

No permanent schema change may exist only in a running database.

## Migration Ordering

Migrations must have deterministic ordering.

Dependencies must be introduced before dependent tables.

A migration must not rely on a table, constraint, index, enum, function, or extension that has not yet been created.

# Migration Standards

## One Purpose per Migration

Each migration should have one clear purpose.

Good examples:

```text
create_learners_table
add_school_id_to_roles_table
add_tenant_unique_constraint_to_streams
add_payment_idempotency_key
create_notification_outbox_table
```

Avoid migrations that combine unrelated changes across many domains.

## Migration Naming

Migration names must describe the change clearly.

Examples:

```text
2026_08_02_000001_create_school_roles_table.php
2026_08_02_000002_add_school_id_to_role_assignments_table.php
2026_08_02_000003_add_unique_school_role_name_constraint.php
```

Avoid vague names such as:

```text
update_tables
fix_database
changes
new_fields
```

## Reversibility

Every migration must define a valid rollback unless a documented exception exists.

The `down()` method must reverse the migration safely.

A migration is incomplete if rollback is impossible and no approved recovery procedure exists.

## Destructive Migrations

Destructive changes include:

- Dropping columns
- Dropping tables
- Changing column types
- Reducing field length
- Making nullable columns non-null
- Removing indexes
- Removing constraints
- Renaming columns used by deployed clients
- Rewriting large tables

Destructive changes require:

- Data impact analysis
- Backup plan
- Rollback plan
- Deployment sequencing
- Application compatibility plan
- Test evidence
- Operational approval

## Expand-and-Contract Pattern

Breaking schema changes should use the expand-and-contract pattern.

Example:

1. Add the new column.
2. Deploy code that writes both old and new columns.
3. Backfill existing data.
4. Switch reads to the new column.
5. Stop writing the old column.
6. Verify.
7. Remove the old column in a later deployment.

## Data Backfills

Large data backfills should not run as unbounded migration logic.

Use:

- Dedicated commands
- Queue jobs
- Bounded batches
- Progress tracking
- Idempotent processing
- Restart capability
- Operational monitoring

Migrations should remain predictable and time-bounded.

## Production Safety

Production migrations must avoid unnecessarily long locks.

Large-table operations require review of:

- Lock duration
- Table size
- Index build strategy
- Write traffic
- Rollback
- Deployment window
- PostgreSQL version capabilities

Where appropriate, indexes may be created concurrently through an approved process.

# Table Standards

## Table Names

Table names use:

- Lowercase
- Snake case
- Plural nouns

Examples:

```text
learners
school_roles
role_assignments
payment_transactions
notification_deliveries
```

Avoid abbreviations unless they are universally understood within ShuleOS.

## Primary Keys

Every persistent table must have a primary key unless it is a documented pure junction table with an approved composite primary key.

The chosen identifier strategy must be consistent within the domain.

Possible strategies include:

- Auto-incrementing bigint
- UUID
- ULID
- Composite key where justified

Public identifiers must not expose predictable internal sequences where doing so increases security or privacy risk.

## Timestamps

Most mutable domain tables should include:

```text
created_at
updated_at
```

Where archival is supported:

```text
archived_at
```

Where soft deletion is appropriate:

```text
deleted_at
```

Where publication or approval exists:

```text
published_at
approved_at
rejected_at
```

Status timestamps must have clear meanings.

## Actor Columns

Sensitive state changes may include:

```text
created_by
updated_by
approved_by
rejected_by
archived_by
deleted_by
```

Actor relationships must be tenant-safe where applicable.

## Boolean Columns

Boolean column names should describe the true state.

Good examples:

```text
is_active
is_verified
requires_password_reset
is_platform_protected
```

Avoid ambiguous names:

```text
status_flag
enabled_value
check
```

A boolean account-state field must have an enforcement point.

## Status Columns

Status values must be controlled.

Approved approaches include:

- Database check constraints
- PHP enums combined with database validation
- Reference tables where extensibility is required

Free-form status strings are prohibited.

## Money Columns

Authoritative financial values must not use floating-point types.

Use integer minor units where practical.

Examples:

```text
amount_minor
balance_minor
tax_minor
discount_minor
```

Currency should be explicit:

```text
currency_code
```

For Kenyan shillings:

```text
KES 1.00 = 100 minor units
```

## Percentage Values

Percentage storage must define scale and meaning.

Examples:

```text
percentage_basis_points
percentage_decimal
```

Do not store ambiguous values where `10` could mean either `10%` or `0.10%`.

## JSONB

JSONB may be used for:

- Provider payload metadata
- Versioned configuration
- Audit context
- Flexible low-risk attributes
- External integration metadata

JSONB must not replace normalized relational structure for core business ownership and relationships.

Do not hide:

- `school_id`
- Foreign keys
- Financial ownership
- Workflow state
- Role assignments
- Permission relationships

inside JSONB.

## Text and String Lengths

Use explicit lengths where meaningful.

Examples:

```text
email
phone number
currency code
country code
status
provider reference
```

Use `text` for genuinely unbounded or large content.

Field length decisions should reflect real business requirements.

# Tenant Ownership

## School as Primary Tenant

The school is the primary tenant for ordinary school operational data.

Most tenant-owned tables must include:

```text
school_id
```

The column should normally be:

- Non-null
- Foreign-key constrained
- Indexed
- Included in ownership validation
- Included in tenant-scoped uniqueness where applicable

## Documented Exemptions

A table may omit `school_id` only when it is clearly:

- Platform-owned
- Brand-owned
- Global reference data
- A pure platform configuration table
- A justified shared lookup
- A documented relationship deriving ownership safely

Every exemption must be documented.

## Tenant Column Naming

Use:

```text
school_id
brand_id
campus_id
```

Avoid generic ambiguous names such as:

```text
tenant
tenant_key
owner
organization
```

unless the domain has an approved polymorphic governance model.

## Client-Supplied Tenant IDs

The database may store tenant identifiers, but application writes must derive them from trusted server context.

Client-supplied `school_id` must not determine ownership.

## Tenant-Safe Inserts

On insert, the application must:

1. Resolve the authoritative tenant.
2. Validate referenced resources.
3. Confirm all referenced resources belong to the same tenant.
4. Set ownership server-side.
5. Persist inside an approved transaction where required.

## Tenant-Safe Updates

Updates must scope the target record by tenant before modification.

Conceptually:

```php
Model::query()
    ->where('school_id', $tenantContext->schoolId())
    ->whereKey($id)
    ->firstOrFail();
```

Actual implementation must use the approved ShuleOS tenant abstraction.

## Tenant-Safe Deletes and Archives

Delete, archive, and restore operations must remain tenant scoped.

A user must not be able to archive or restore another school's record by guessing an identifier.

# Foreign Keys

## Foreign Keys Are Mandatory

Relationships must use database foreign keys unless a documented reason prohibits them.

Foreign keys protect against:

- Orphaned records
- Invalid references
- Cross-domain inconsistency
- Broken ownership
- Incomplete deletion

## Foreign-Key Naming

Constraint names should be deterministic where custom naming is useful.

Example:

```text
role_assignments_school_id_foreign
```

## Delete Behaviour

Delete behaviour must be intentional.

Possible behaviours include:

- Restrict
- Cascade
- Set null
- No action

Cascade deletion must not be selected merely for convenience.

For sensitive and financial records, restrict or archival is often safer.

## Tenant-Aware Relationships

Foreign keys must preserve tenant ownership.

A learner in School A must not reference a stream in School B.

Where practical, use composite tenant-aware relationships.

Conceptually:

```text
(school_id, stream_id)
    references
(school_id, id)
```

This may require a unique constraint on:

```text
school_id, id
```

## Polymorphic Relationships

Polymorphic relationships require extra review because ordinary foreign keys cannot fully enforce them.

Where polymorphism is used, the design must include:

- Allowed type list
- Ownership checks
- Tenant checks
- Automated tests
- Orphan detection
- Cleanup strategy

Polymorphism should not be used merely to avoid designing explicit relationships.

# Unique Constraints

## Tenant-Scoped by Default

Uniqueness is tenant-scoped by default.

Examples:

```text
school_id + admission_number
school_id + employee_number
school_id + role_name
school_id + stream_name
school_id + invoice_number
school_id + receipt_number
```

## Global Uniqueness

Global uniqueness requires written justification.

Valid examples may include:

- Platform-generated licence identifier
- Provider transaction reference within a provider scope
- Immutable public UUID
- Platform API-key fingerprint
- Global migration version

## Case-Insensitive Uniqueness

Where names or emails require case-insensitive uniqueness, the design must specify normalization.

Possible approaches include:

- Lowercased normalized column
- PostgreSQL `citext`
- Expression index on `lower(column)`

Normalization must be consistent in both application and database behaviour.

## Soft Deletion and Uniqueness

Soft-deleted records complicate unique constraints.

The design must define whether archived or deleted values may be reused.

PostgreSQL partial unique indexes may be used where appropriate.

Conceptually:

```sql
UNIQUE WHERE deleted_at IS NULL
```

This requires explicit migration and rollback testing.

# Index Standards

## Every Index Must Serve a Query

Indexes are created for known query patterns.

An index is not added simply because a column appears important.

Every index review should consider:

- Query filter
- Join pattern
- Sort order
- Tenant scope
- Cardinality
- Table size
- Write cost
- Existing indexes
- PostgreSQL query plan

## Tenant-First Indexing

Tenant-owned queries commonly begin with `school_id`.

Common composite patterns may include:

```text
school_id, status
school_id, created_at
school_id, learner_id
school_id, academic_year_id
school_id, term_id
school_id, normalized_name
```

Column order must reflect actual query patterns.

## Foreign-Key Indexes

Foreign-key columns used in joins, filters, delete checks, or updates should be indexed.

Do not assume every foreign key is automatically indexed by PostgreSQL.

## Composite Indexes

Composite indexes should match query order and selectivity.

A composite index on:

```text
school_id, learner_id, term_id
```

does not automatically replace every possible index involving those columns.

## Duplicate Indexes

Duplicate and overlapping indexes should be removed after review.

Examples of possible duplication:

```text
index(school_id)
index(school_id, status)
```

Whether both are needed depends on query patterns.

## Partial Indexes

Partial indexes may be used for frequently queried subsets.

Examples:

```text
active records
pending payments
unread notifications
unsent outbox entries
non-archived assignments
```

Partial index predicates must match application queries reliably.

## Index Naming

Index names should describe:

- Table
- Columns
- Purpose where required

Example:

```text
learners_school_id_admission_number_unique
```

## Index Review

Every migration adding or changing a large-table index requires:

- Query justification
- Expected benefit
- Write-cost review
- Lock review
- Rollback plan
- Query-plan verification after deployment where practical

# Query Standards

## Every Query Is Tenant Scoped

Every query against tenant-owned data must include the authoritative tenant scope.

## Avoid Unbounded Queries

Production code must not load unbounded record sets.

Use:

- Pagination
- Cursor pagination
- Bounded batches
- Limits
- Streaming
- Chunking

## Avoid N+1 Queries

Relationships must be loaded intentionally.

Use eager loading where justified.

Do not eager load large relationship graphs without limits or purpose.

## Select Required Columns

Select only required columns for performance-sensitive queries.

Avoid loading large text, JSON, or blob metadata where not required.

## Query Plans

Important and slow queries must be reviewed with PostgreSQL tools such as:

```sql
EXPLAIN
EXPLAIN ANALYZE
```

Production-sensitive analysis must avoid unsafe load.

## Search

Search architecture must define:

- Tenant boundary
- Indexed fields
- Language handling
- Ranking
- Pagination
- Privacy
- Maximum query cost

Wildcard searches such as:

```sql
LIKE '%value%'
```

must not be introduced on large tables without review.

## Reporting Queries

Reports should not fetch all data and filter in PHP.

Filtering, aggregation, grouping, and pagination should occur in PostgreSQL where appropriate.

Cross-school reports must use an explicit authorized school set.

# Transactions

## Transactions Preserve Invariants

Use transactions when multiple writes must succeed or fail together.

Examples include:

- Learner admission
- Role assignment
- Payment posting
- Payment allocation
- Receipt generation
- Subscription activation
- SMS wallet crediting
- Examination publication
- Offline sync operation application

## Transaction Boundaries

Transaction boundaries belong in the application service layer.

Controllers should not coordinate complex transactional workflows.

## External Calls

Avoid making slow external network calls while holding database transactions open.

Preferred pattern:

1. Persist authoritative state.
2. Persist an outbox event.
3. Commit.
4. Perform external action asynchronously.

## Nested Transactions

Nested transaction behaviour must be understood and tested.

Laravel savepoints must not be assumed to behave as independent full transactions.

## Exception Handling

A failed transaction must roll back.

Exceptions must not be swallowed in a way that leaves partial state.

# Concurrency and Locking

## Concurrency Must Be Designed

Concurrent operations must not violate business rules.

Examples include:

- Duplicate payment callbacks
- Two users assigning the same bed
- Concurrent role assignment
- Simultaneous receipt numbering
- Multiple SMS-credit reservations
- Duplicate learner admission
- Concurrent mark publication

## Pessimistic Locks

Use row-level locks where strong serialized updates are required.

Conceptually:

```php
->lockForUpdate()
```

Locks must be:

- Inside a transaction
- Narrowly scoped
- Time-bounded
- Ordered consistently
- Tested for deadlocks

## Optimistic Concurrency

Version columns may be used for:

- Offline sync
- Draft editing
- Configuration updates
- Conflict detection

Examples:

```text
version
revision
lock_version
```

## Deadlocks

Deadlocks may still occur.

Critical workflows should:

- Use consistent lock ordering
- Keep transactions short
- Retry safe deadlock failures where appropriate
- Remain idempotent
- Be observable

## Advisory Locks

PostgreSQL advisory locks may be used for rare coordination problems where row locks are insufficient.

Their use requires documentation and careful release handling.

# Financial Integrity

## Append-Only Financial Records

Posted financial records must not be edited destructively.

Corrections use:

- Reversal
- Adjustment
- Reallocation
- Credit note
- Debit note
- Refund workflow

## Idempotency Keys

Every payment-creating endpoint and provider callback must use a stored idempotency key.

The key must be checked before the write.

Repeated requests return the original result.

## Provider References

Provider transaction references must use unique constraints within the correct provider and merchant scope.

## Server-Derived Ownership

Financial ownership must be established through server-validated relationships.

A payment must not trust caller-supplied school, learner, or invoice ownership.

## Ledger Balances

Balances should be derived from ledger entries or updated through transactionally protected accounting rules.

Balance mutations must not bypass ledger history.

## Receipt Numbers

Receipt numbering must be:

- Tenant-aware
- Unique
- Concurrency-safe
- Auditable
- Historically stable

# Archiving and Deletion

## Archive First

Business records should be archived before permanent deletion where appropriate.

Archiving preserves:

- Ownership
- History
- Audit evidence
- Relationships
- Recovery capability

## Soft Deletion

Soft deletion is appropriate when:

- Records may need restoration
- Historical relationships must remain
- Auditability is required
- Immediate physical deletion is unsafe

Soft deletion is not a substitute for a retention policy.

## Permanent Deletion

Permanent deletion requires:

- Legal and policy basis
- Permission
- Tenant validation
- Retention review
- Dependency review
- Backup lifecycle consideration
- Audit logging

## Referential Integrity

Archiving a parent record must not leave active dependent records in invalid states.

## Restore

Restore operations must revalidate:

- Tenant ownership
- Current permission
- Current relationships
- Unique constraints
- Workflow state
- Retention rules

# Audit Data

## Audit Records

Sensitive actions must create audit records.

Audit tables should contain only necessary data.

Typical fields include:

- Actor
- Tenant
- Action
- Resource type
- Resource identifier
- Previous state summary
- New state summary
- Outcome
- Reason
- Correlation identifier
- Timestamp

## Audit Immutability

Audit records should be append-only.

Ordinary users must not update or delete audit records.

## Sensitive Data

Audit logs must not contain:

- Passwords
- OTP values
- Raw tokens
- API keys
- Provider secrets
- Full file contents
- Unnecessary child personal data

# Row-Level Security

## Direction

PostgreSQL Row-Level Security is part of the defense-in-depth direction for tenant isolation.

RLS must not be described as active until it is:

- Designed
- Implemented
- Tested
- Enabled
- Verified in production-like conditions
- Documented

## Application and Database Enforcement

RLS does not replace:

- Application tenant context
- Authorization policies
- Ownership validation
- Tenant-aware foreign keys
- Cross-tenant tests

The application and database must both enforce isolation.

## RLS Context

Any future RLS implementation must define:

- How tenant context reaches PostgreSQL
- Connection pooling behaviour
- Transaction lifecycle
- Queue behaviour
- Platform and brand scopes
- Support access
- Migration behaviour
- Testing strategy
- Fail-closed defaults

## Bypass Roles

Database roles capable of bypassing RLS must be tightly controlled.

Ordinary application connections must not receive unrestricted bypass authority.

## Dedicated ADR

Production RLS implementation requires a dedicated ADR or approved amendment to ADR-0002.

# Test Database Standards

## Tests Use Real Migrations

Tests must exercise the real schema.

Tests must not create fictional replacements using ad hoc `Schema::create()` calls in setup code.

## Migration Testing

CI must verify:

```bash
php artisan migrate:fresh
php artisan migrate:rollback
php artisan migrate
```

Exact commands may vary by test strategy, but forward and rollback paths must be tested.

## PostgreSQL Testing

Database-sensitive behaviour should be tested using PostgreSQL.

SQLite must not be treated as equivalent for features involving:

- JSONB
- Partial indexes
- PostgreSQL constraints
- RLS
- Locking
- Transactions
- Deferrable constraints
- Native UUID behaviour
- Query planning

## Cross-Tenant Tests

Every tenant-owned domain requires tests proving that:

- School A cannot read School B's record.
- School A cannot update School B's record.
- School A cannot delete or archive School B's record.
- School A cannot create a relationship to School B's resource.
- School A cannot infer existence through unsafe responses.

## Constraint Tests

Important database constraints require tests.

Examples include:

- Tenant-scoped uniqueness
- Foreign-key ownership
- Financial idempotency
- Prohibited null values
- Status constraints
- Append-only invariants
- Role protection

## Transaction Tests

Critical workflows must test rollback when intermediate operations fail.

# Backup and Recovery

## Backups Are Mandatory

Production database backups must be automated.

The backup policy must define:

- Frequency
- Retention
- Encryption
- Storage location
- Access control
- Monitoring
- Failure alerts
- Recovery Point Objective
- Recovery Time Objective

## Restore Testing

A backup is not trusted until it has been restored successfully.

Restore tests must verify:

- Schema
- Data
- Constraints
- Indexes
- Audit history
- Financial records
- Tenant ownership
- File metadata relationships
- Subscription state

## Backup Isolation

Backup access must be restricted.

Backups contain data from many tenants and require platform-level security.

## Disaster Recovery

Disaster recovery procedures must include:

- Database restore
- Object storage reconciliation
- Queue state handling
- Cache rebuild
- Secret restoration
- DNS and infrastructure dependencies
- Verification before reopening access

# Data Retention

## Retention Categories

Every data category must have a documented retention policy.

Examples include:

- Learner records
- Guardian records
- Staff records
- Financial records
- Academic results
- Attendance
- Discipline
- Notification history
- Authentication logs
- Audit logs
- Files
- Offline sync records
- Provider callback payloads

## Retention Metadata

Where practical, records should identify:

- Retention category
- Retention start
- Retention expiry
- Legal hold
- Archive state
- Deletion state

## Child Data

Learner data receives the highest privacy classification.

Retention must be limited to educational, administrative, contractual, or legal necessity.

# Database Security

## Least Privilege

Database users must receive only required privileges.

Separate roles may be used for:

- Application runtime
- Migrations
- Read-only reporting
- Backup
- Monitoring
- Administration

## Credentials

Database credentials must:

- Remain outside source control
- Be environment-specific
- Be rotatable
- Be protected in secret storage
- Be excluded from logs
- Be reviewed periodically

## SQL Injection

All dynamic values must use parameter binding.

Raw SQL requires:

- Justification
- Review
- Parameterization
- Tests
- Tenant validation

## Database Exposure

PostgreSQL must not be publicly exposed without approved network controls.

Production access requires:

- Restricted network paths
- TLS where supported and configured
- Authentication
- Monitoring
- Auditability
- Least privilege

# Observability

Database monitoring should include:

- Connection count
- Connection saturation
- Query latency
- Slow queries
- Lock waits
- Deadlocks
- Transaction duration
- Cache hit ratio
- Index usage
- Sequential scans
- Table growth
- Index growth
- Replication lag where applicable
- Backup success
- Restore-test success
- Migration failures
- Tenant-heavy workloads

Critical thresholds must generate alerts.

# Performance Standards

## Performance Is Measured

Database performance must be based on evidence.

Evidence may include:

- Query plans
- Timings
- Production metrics
- Load tests
- Table sizes
- Index statistics
- Lock analysis

## Slow Query Review

Slow queries require:

- Query identification
- Tenant-scope verification
- Query-plan review
- Index review
- Application review
- Regression test where practical

## Pagination

API list endpoints must use bounded pagination.

Offset pagination may be acceptable for small data sets.

Cursor pagination is preferred for large and continuously changing data sets.

## Aggregation

Heavy reports may use:

- Pre-aggregation
- Materialized views
- Reporting tables
- Queue-generated reports
- Cached summaries

These approaches require documented freshness and invalidation rules.

## Partitioning

Table partitioning may be considered when evidence shows that ordinary indexing and archival are insufficient.

Partitioning requires a dedicated design review.

# CI Enforcement

Continuous Integration should verify database standards automatically where practical.

Required or planned checks include:

- Migrations rebuild the schema.
- Migrations roll back.
- Tenant-owned tables include `school_id` or approved exemptions.
- Tenant columns are indexed.
- Tenant-scoped unique constraints are used.
- Foreign keys exist.
- Cross-tenant tests exist.
- No test creates fictional core tables.
- Financial idempotency constraints exist.
- Formatting passes.
- Static analysis passes.
- Database tests pass on PostgreSQL.
- Schema documentation is updated.

# Database Review Checklist

Every database Pull Request should answer:

## Ownership

- Who owns this table?
- Is it platform, brand, or school owned?
- Does it require `school_id`?
- Is any exemption documented?

## Integrity

- Does it have a primary key?
- Are foreign keys present?
- Are relationships tenant-safe?
- Are status values constrained?
- Are required columns non-null?

## Uniqueness

- Is uniqueness tenant scoped?
- Is global uniqueness justified?
- Does soft deletion affect uniqueness?

## Performance

- Which queries use the table?
- Which indexes support those queries?
- What is the write cost?
- Has the query plan been reviewed?

## Migration Safety

- Can the migration roll back?
- Does it lock a large table?
- Does it require backfill?
- Is expand-and-contract needed?
- Is production sequencing documented?

## Security

- Can cross-tenant relationships be created?
- Does the schema protect financial ownership?
- Are secrets stored correctly?
- Is sensitive data minimized?

## Testing

- Are real migrations used?
- Are constraint tests present?
- Are cross-tenant tests present?
- Are rollback tests present?
- Are transaction failures tested?

# Definition of Done

A database change is complete only when:

- Migration exists.
- Rollback exists or an approved exception is documented.
- Tenant ownership is correct.
- Primary and foreign keys are present.
- Tenant-aware constraints are present.
- Indexes support actual queries.
- Cross-tenant access is tested.
- Performance impact is reviewed.
- Documentation is updated.
- CI checks pass.
- Backup and recovery impact is understood.
- Engineering Constitution compliance is verified.

# Constitution Compliance

This standard supports:

- Rule 2 — Security before features
- Rule 3 — Privacy by Design
- Rule 4 — Tenant First
- Rule 5 — Archive First
- Rule 6 — Consistency over cleverness
- Rule 8 — Clean Code
- Rule 9 — Every feature belongs to a Domain
- Rule 10 — Design first. Code second
- Rule 11 — Every API request is untrusted
- Rule 12 — Never trust client input
- Rule 13 — Protect against IDOR
- Rule 15 — Prevent SQL Injection
- Rule 17 — Audit important actions
- Rule 19 — Every security feature is tested
- Rule 21 — Database first
- Rule 22 — Every table has keys, constraints, and indexes
- Rule 23 — Indexes are reviewed
- Rule 24 — Every query is reviewed
- Rule 25 — Use transactions
- Rule 26 — Every database change includes a rollback plan
- Rule 27 — Performance is measured, not guessed
- Rule 28 — TenantContext is mandatory
- Rule 30 — Every query is tenant scoped
- Rule 31 — Foreign keys respect tenant ownership
- Rule 32 — Cross-tenant tests are mandatory
- Rule 55 — Financial operations are idempotent
- Rule 56 — Every payment is auditable
- Rule 66 — Every feature has tests
- Rule 67 — Security tests are mandatory
- Rule 68 — Cross-tenant tests are mandatory
- Rule 69 — Performance tests are mandatory
- Rule 70 — Rollback tests are mandatory
- Rule 87 — Tenant isolation is enforced by application and database
- Rule 90 — Uniqueness is tenant scoped
- Rule 97 — Migrations define the schema
- Rule 98 — Tests use the real schema
- Rule 99 — Tenant-owned tables are complete only when tenant safe
- Rule 100 — Idempotency is enforced using stored keys
- Rule 101 — Financial ownership is established server-side
- Rule 102 — Security-critical invariants are verified automatically
- Rule 106 — Backups are verified through restore testing
- Rule 107 — Production systems are observable
- Rule 109 — Every data category has a retention policy
- Rule 110 — Architecture rules are enforced by CI
- Rule 111 — Tenant schema requirements are automatically validated
- Rule 114 — ShuleOS is continuously hardened
- Rule 119 — Tenant hierarchy is explicit and governed
- Rule 121 — Learner information receives the highest privacy classification
- Rule 125 — Data residency decisions are documented

# Related ADRs

- ADR-0001 — Modular Monolith Architecture
- ADR-0002 — Multi-Tenant Architecture
- ADR-0004 — Offline-First Architecture
- ADR-0005 — School Payment Architecture
- ADR-0007 — Cloudflare R2
- ADR-0010 — Role Template System
- ADR-0011 — Multi-Level Tenant Hierarchy

# Final Standard

The ShuleOS database is not a passive storage layer.

It is an active enforcement boundary for:

- Tenant isolation
- Referential integrity
- Financial correctness
- Workflow consistency
- Auditability
- Performance
- Recovery
- Long-term maintainability

Application bugs must not be allowed to silently bypass database truth.

Every database change must be deliberate, reversible, tenant-safe, tested, measurable, documented, and compliant with the ShuleOS Engineering Constitution.
