# 6A.11 School Finance database contract

Status: unmerged implementation; no deployment authorized.
Baseline: e69df2a9209fbd1da8b7651c876baa6527cff497.
Review date: 2 November 2026.

## Scope

School finance is separate from ShuleOS subscription billing.
This patch does not change Daraja, subscription billing, payment gateways or
parent-provider callbacks. Development and PostgreSQL testing target only
shuleos_assessment_fk_test. CI may use its separately isolated shuleos_testing.
The main shuleos database is not a development or rollback target.

Schools define their own category names and rates. Meals and transport may be
compulsory, bundled into another charge, or participation-based according to
school policy. Names alone never establish eligibility or prevent overlapping
bundles. Grade pricing can reflect education levels through the grade model;
direct education-level-wide price authoring is outside this patch.

## Operating mode and learner classification

| School operating_mode | Supported learner mode_of_study |
| --- | --- |
| day | day_scholar |
| boarding | boarder |
| day_and_boarding | day_scholar or boarder |
| NULL | Explicit school configuration required |

Operating mode is distinct from school_type, such as Primary or Junior School.
Existing schools remain unclassified until an authorized explicit selection.
Onboarding and profile completion require a valid operating mode.

Existing-school changes use manage_school_profile, authenticated school scope,
a school row lock and transactional audit. Day mode rejects active occupancy,
including allocations held by inactive learners. Day and boarding modes reject
active, nondeleted learners with the opposite classification.

Admission requires compatible classification. Reactivation and restoration to
active status validate classification and may require an explicit correction.
Restoring a terminal learner does not itself activate that learner.

Dedicated Day Scholar / Boarder changes require a meaningful reason and an
effective date no later than today. They update current classification immediately,
with history and audit in the same transaction. Historical rows retain unknown
effective dates as NULL. This is not a scheduled or date-replayed classification
system. An active bed must be released before becoming a day scholar.

School locks precede learner locks in affected classification and charging paths.
Boarding allocation and transfer also validate school mode. Service-level release
remains occupancy cleanup; boarding HTTP routes remain subject to module gating.

## Charging eligibility

Fee structures have charge_target: all, day_scholar or boarder, and a boolean
requires_participation. New structures default to all and false. Draft edits that
omit these fields retain their existing values; explicit changes remain supported.

Invoice generation selects active structures in the authenticated school's
year, term, grade and applicable stream. It validates current learner classification.
Matching classification-specific rates precede all-learner rates; within that
priority, stream-specific rates precede grade-wide rates. Eligibility filtering
occurs before selecting one item per category.

Participation-based structures require an active fee_charge_participations row
for the same school, learner, category, academic year and term. GET and PUT
/api/finance/charge-participations require manage_fee_structures. Mutations validate
same-school resources and calendar, lock the learner and audit changes atomically.
Repeating the same active state does not create another mutation audit.

Participation records identify eligibility; they are not a transport route,
meal attendance or general activity-management module. Their current state is
used for new invoices; they do not implement dated enrolment scheduling.

An existing noncancelled invoice is returned unchanged. Classification changes,
participation changes and future rate changes do not reprice existing invoice
items, payments, allocations or ledger entries. Midterm corrections require
explicit approved financial workflows; no automatic prorating is introduced.

## Payments and financial workflows

Manual recording accepts active, school-owned, offline M-Pesa and Bank methods.
Cash, cheque and other manual channels are rejected at this boundary.
Positive amounts and a nonblank transaction reference are required.
References are trimmed and uppercased; service duplicate checks compare normalized
references within a school, serialized by a school row lock. The database's raw
reference unique index is not a normalized-reference guarantee for direct SQL.

Manual recording is not provider verification. Confirmation, allocation and
reversal remain explicit workflows. Scholarships and bursaries use discounts;
a dedicated sponsor pledge/account ledger is not introduced. Refund approval is
separate from external disbursement. Unallocated-credit refunds need separate design.

## Database guarantees

PostgreSQL 15+ is required for NULLS NOT DISTINCT indexes.

- Tenant-owned finance rows require school ownership. Composite foreign keys
  protect same-school parents, actors and applicable learner/account relationships.
- Invoice item ownership is derived from the invoice; conflicting ownership fails.
  Allocation invoice_id references invoice headers, with payment/invoice learner
  identity protected by composite keys and a derived learner_id.
- Supplied year/term and grade/stream contexts must agree.
- Monetary checks enforce finite values, positive charges and bounded counters,
  invoice balance equations and installment balance equations.
- Ledger rows require a complete posting key, an account, a finite balance and
  exactly one positive debit or credit. UPDATE and DELETE are rejected.
  Reversals append rows. Owner-level trigger disabling, TRUNCATE and schema changes
  require separate privilege controls.
- Category names are unique per school, case-insensitively, for nondeleted rows.
  Live structure uniqueness includes charge_target and treats NULL stream as one
  scope. Existing global generated-number uniqueness is preserved.
- Participation uniqueness and composite foreign keys protect learner, category,
  calendar and updating actor ownership.

Cross-row totals, workflow permissions, transitions, refund reservations and
atomic business audit remain service responsibilities. These constraints do not
certify every module's authorization or direct SQL classification changes.

## Migrations and rollback

Migrations add finance integrity, allocation updated_at, school operating mode,
classification effective_date and charging eligibility in timestamp order.
DDL executes transactionally under Laravel's PostgreSQL migration support.
The integrity migration uses lock and statement timeouts; eligibility adds a
lock timeout. Existing invalid finance data aborts validation rather than being
deleted or assigned guessed ownership. Ownership backfills derive from parents.

The integrity migration refuses rollback when allocations exist. Eligibility
rollback refuses when any participation row exists, including inactive rows, or
any structure uses targeting or participation. Roll back dependent eligibility
schema before the integrity migration in isolated rollback tests.
A populated deployment requires a reviewed forward correction and backup plan.
Do not use main-database rollback as a development experiment.

## Verification and remaining limits

Verified after the review correction on 2 November 2026:
1,708 tests passed with 28,382 assertions in shuleos_assessment_fk_test;
Pint checked 1,259 files; Assessment coverage/PHPStan, Composer validation/audit
and git diff --check passed. Assessment PHPStan does not cover Finance.

The review correction preserves omitted draft eligibility fields. Regression
tests verify preservation, explicit eligibility changes, creation defaults,
and rollback refusal for targeted structures and participation records.
The main database was not targeted. Nothing has been merged or deployed.

Acceptance coverage includes bidirectional tenant checks, manual payment rules,
financial workflows, operating-mode configuration, admission/reactivation,
audited classification changes, preservation of financial history, targeted
charging and explicit participation. Shared fixture builders retain legacy defaults.

Real concurrent transaction contention has not been demonstrated by the passing
suite. Lock-contract tests and transactional rollback tests are not substitutes
for load or contention testing. No production migration, merge or deployment
has been authorized by these results.
