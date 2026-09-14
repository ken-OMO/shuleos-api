# Teacher Duty 6A.9I-A - Occurrence HTTP API Contract

## Status

FROZEN contract for Phase 6A.9I-A - Teacher Duty Occurrence HTTP API.

This exposes the existing 6A.9G-A occurrence domain through HTTP without changing its frozen persistence or lifecycle semantics.

## Scope

This phase introduces HTTP exposure for:

- Teacher Duty occurrence categories.
- Recording Teacher Duty occurrences.
- Reading Teacher Duty occurrences.
- Tenant-safe and responsibility-scoped access.
- HTTP validation, response and audit conventions.

Deferred:

- Daily report HTTP workflow.
- Weekly report and review HTTP workflow.
- Occurrence correction or amendment workflow.
- Category reactivation.
- Notifications, escalation and commercial entitlement enforcement.

## Existing Domain Contract

6A.9G-A remains authoritative for:

- occurrence ownership;
- category lifecycle;
- canonical category identity;
- occurrence historical preservation;
- duplicate rules;
- tenant integrity;
- server-owned actor and tenant fields;
- prohibition of generic occurrence deletion.

Occurrences remain school-wide factual records belonging to Teacher Duty periods.

Occurrences do not belong to individual Teacher assignments.

HTTP authorization determines who may act on a period but must not introduce assignment ownership into occurrence persistence.

## Permission and Responsibility Boundary

### Occurrence recording

Recording a Teacher Duty occurrence requires:

1. effective `submit_teacher_duty_reports`; and
2. responsibility for the concrete Teacher Duty period as resolved by
   `TeacherDutyAuthorizationService::reporter()`.

The HTTP layer must not authorize occurrence recording from permission possession alone.

The HTTP layer must pass the concrete school, period and authenticated actor to the frozen authorization service.

Preserved Teacher Duty assignment history remains the authoritative responsibility source.

No active-only assignment requirement is introduced.

### Occurrence reads

Any endpoint exposing Teacher Duty occurrence evidence for a concrete period requires:

1. effective `submit_teacher_duty_reports`; and
2. responsibility for the concrete period through
   `TeacherDutyAuthorizationService::reporter()`.

Possession of `submit_teacher_duty_reports` alone is insufficient for period-specific occurrence evidence access.

### Category reference reads

Occurrence categories are operational reference data required by reporters.

Authenticated users with effective `submit_teacher_duty_reports` may list the school occurrence categories.

Category listing does not establish period responsibility and does not expose period-specific report evidence.

### Category administration

Creating and deactivating Teacher Duty occurrence categories requires:

`manage_teacher_duty_roster`

Category administration is school-wide configuration and does not use reporter responsibility.

No new permission is introduced by this phase.

## Routes

The phase introduces:

- `GET /teacher-duty/occurrence-categories`
- `POST /teacher-duty/occurrence-categories`
- `PATCH /teacher-duty/occurrence-categories/{category}/deactivate`
- `GET /teacher-duty/periods/{period}/occurrences`
- `POST /teacher-duty/periods/{period}/occurrences`
- `GET /teacher-duty/occurrences/{occurrence}`

No route is introduced for:

- updating an occurrence;
- deleting an occurrence;
- correcting or amending an occurrence;
- reactivating a category;
- deleting a category;
- generic category update.

## Operational Middleware

Mutation endpoints require `school.operational`.

This applies to:

- creating a custom occurrence category;
- deactivating an occurrence category;
- recording an occurrence.

Read endpoints do not require `school.operational`.

## Tenant Boundary

The authenticated user school remains authoritative.

`TenantMiddleware` supplies and verifies tenant context.

Controllers must fail closed if:

- no authenticated user exists;
- the authenticated user has no school;
- `tenant_school_id` is absent;
- `tenant_school_id` differs from the authenticated user school;
- a requested Teacher Duty resource does not belong to the authenticated school.

Cross-tenant and unavailable Teacher Duty resources must use the same generic unavailable-resource pattern already used by `TeacherDutyRosterController`.

## Server Ownership

Clients must not control:

- `school_id`;
- `recorded_by`;
- `created_by`;
- `deactivated_by`;
- `deactivated_at`;
- `is_canonical`;
- `active`;
- resource identifiers;
- timestamps;
- duty-period identity where the period is supplied by the route.

Authoritative actor fields are resolved from the authenticated user.

## Form Request Boundary

FormRequests use `authorize(): true`.

Route middleware and domain authorization own access control.

Mutation handlers use `$request->validated()` only.

Client-supplied `school_id` must be rejected using the same TenantMiddleware ownership convention already used by Teacher Duty roster requests.

### Record occurrence request

Allowed client inputs:

- `occurrence_category_id`
- `occurrence_date`
- `occurrence_time`
- `description`

Validation:

- `occurrence_category_id`: required UUID.
- `occurrence_date`: required `Y-m-d`.
- `occurrence_time`: nullable valid wall-clock time.
- `description`: required string, maximum 5000 characters.

The service remains authoritative for:

- same-school period validation;
- current-period eligibility;
- same-school active category validation;
- inclusive period date-range validation;
- whitespace-only description rejection;
- normalization of optional time;
- actor eligibility.

### Create custom category request

Allowed client inputs:

- `code`
- `name`
- `description`
- `display_order`

Validation:

- `code`: required string, maximum 100 characters.
- `name`: required string, maximum 150 characters.
- `description`: nullable string.
- `display_order`: required integer, minimum 0.

The service remains authoritative for:

- category-code format;
- reserved canonical codes;
- school-scoped duplicate detection;
- trimming and normalization;
- actor eligibility.

### Deactivate category request

No client-owned lifecycle field is accepted.

Category identity comes from the route.

The authenticated actor is server-owned.

## Service Boundary

Controllers remain thin.

`TeacherDutyOccurrenceService` remains the domain boundary.

This phase may add only the minimum tenant-scoped read operations required by the HTTP surface, such as:

- category listing;
- occurrence listing for a period;
- occurrence lookup.

Read operations must not alter existing lifecycle semantics.

Controllers must not become an alternate domain-query layer.

## Response Contract

Read endpoints return a JSON object containing a `data` member.

Collection reads return `data` as an array.

Creation endpoints return HTTP 201 with a `message` member and a `data` member.

Lifecycle mutations return a `message` member and a `data` member.

Response shaping follows the existing `TeacherDutyRosterController` convention.

Dedicated Laravel Resource classes are not required by this phase.

## Occurrence Representation

Occurrence responses expose only school-safe application fields required by the client, including:

- `id`
- `duty_period_id`
- `occurrence_category_id`
- `occurrence_date`
- `occurrence_time`
- `description`
- `recorded_by`
- `created_at`
- `updated_at`

No tenant identifier is exposed solely because it exists in persistence.

## Category Representation

Category responses may expose:

- `id`
- `code`
- `name`
- `description`
- `is_canonical`
- `display_order`
- `active`
- `created_by`
- `deactivated_by`
- `deactivated_at`
- `created_at`
- `updated_at`

## Auditing

Occurrence recording must be audited.

Custom category creation must be audited.

Category deactivation must be audited.

Audit data must use server-owned actor and tenant context.

## Historical Preservation

HTTP exposure must not create mutation paths that can rewrite or delete preserved occurrence history.

No occurrence update or delete operation is introduced.

No category reactivation operation is introduced.

Historical occurrences remain readable after:

- period closure;
- assignment closure;
- category deactivation,

subject to the authorization contract for the endpoint.

## Error Boundary

Existing domain `ValidationException` behavior remains authoritative.

Model-not-found conditions at the Teacher Duty HTTP boundary must be converted to the established generic unavailable-resource validation response.

The HTTP layer must not leak cross-tenant existence through different not-found behavior.

## Required HTTP Regression Coverage

Tests must prove at minimum:

1. occurrence routes require authentication;
2. occurrence recording requires `submit_teacher_duty_reports`;
3. occurrence recording requires reporter responsibility for the concrete period;
4. permission without period responsibility fails;
5. period responsibility without required permission fails;
6. preserved historical responsibility remains sufficient where 6A.9H defines it as sufficient;
7. cross-tenant occurrence recording fails closed;
8. non-operational school cannot mutate occurrence data;
9. valid occurrence recording returns HTTP 201;
10. client cannot control `school_id` or actor fields;
11. occurrence description exceeding 5000 characters fails HTTP validation;
12. whitespace-only description still fails through the existing domain rule;
13. inactive category cannot receive new occurrences;
14. occurrence date outside the period fails;
15. occurrence listing requires reporter responsibility;
16. single occurrence read requires reporter responsibility for the occurrence period;
17. category listing requires `submit_teacher_duty_reports`;
18. category creation requires `manage_teacher_duty_roster`;
19. category deactivation requires `manage_teacher_duty_roster`;
20. category mutations require `school.operational`;
21. category actor and tenant fields remain server-owned;
22. category deactivation preserves historical occurrences;
23. no occurrence update route exists;
24. no occurrence delete route exists;
25. no category reactivation route exists;
26. tenant mismatches do not leak resource existence;
27. HTTP responses follow the Teacher Duty roster response envelope convention.

## Frozen Invariants

1. Existing 6A.9G-A persistence and lifecycle rules remain unchanged.
2. Occurrences remain duty-period-owned, not assignment-owned.
3. Recording authorization is responsibility-scoped.
4. Period-specific occurrence reads are responsibility-scoped.
5. Category reference reads are not period evidence.
6. Category administration remains roster-administrative.
7. No new permission is introduced.
8. `school.operational` applies to occurrence and category mutations.
9. Tenant and actor fields remain server-owned.
10. Controllers use validated client input only.
11. Controllers remain thin.
12. The existing occurrence service remains authoritative.
13. HTTP may add minimal read methods but no new lifecycle semantics.
14. Occurrence description maximum is 5000 characters at the HTTP boundary.
15. Generic occurrence deletion remains prohibited.
16. No occurrence correction or amendment workflow is introduced.
17. Category deactivation remains terminal.
18. Cross-tenant access fails closed.
19. Period-specific authorization must resolve the concrete duty period.
20. Existing 6A.9H responsibility semantics remain authoritative.

## Freeze Rule

Implementation must conform to this frozen contract.

Verified conflict with an earlier frozen Teacher Duty contract requires stop, documentation amendment, re-freeze, then implementation.
