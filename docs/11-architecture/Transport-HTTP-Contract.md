# Transport HTTP contract

All paths below have the `/api/transport` prefix. Routes require the existing JWT
and tenant middleware. Services independently recheck current actors, ownership,
permissions, lifecycle, school mode, setup and concrete dated responsibility.

The caller cannot provide `school_id`, even if it matches their own school.
Only the middleware-injected tenant field is removed before service validation.
Other unknown fields remain present and are rejected rather than silently dropped.
Actor identities, audit fields, seat slots, snapshots and custody states are
server controlled.

## Routes

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/{resource}` | `view_transport_operations` |
| GET | `/{resource}/{id}` | `view_transport_operations` |
| POST | `/{resource}` | `manage_transport` |
| PATCH | `/{resource}/{id}` | `manage_transport` |
| POST | `/schedules` | `manage_transport` |
| GET | `/schedules/{id}` | `view_transport_operations` |
| PATCH | `/schedules/{id}/state` | `manage_transport` |
| POST | `/assignments` | `manage_transport` |
| GET | `/assignments/{id}` | `view_transport_operations` |
| POST | `/assignments/{id}/end` | `manage_transport` |
| POST | `/reservations` | `manage_transport_enrolments` |
| GET | `/reservations/{id}` | `view_transport_operations` |
| POST | `/reservations/{id}/end` | `manage_transport_enrolments` |
| POST | `/enrolments` | `manage_transport_enrolments` |
| GET | `/enrolments/{id}` | `view_transport_operations` |
| POST | `/enrolments/{id}/transfer` | `manage_transport_enrolments` |
| POST | `/trips` | `record_transport_journeys` |
| GET | `/trips/{id}` | `view_transport_operations or record_transport_journeys with captured crew responsibility` |
| GET | `/trips/{id}/attendance` | `view_transport_operations` |
| POST | `/trips/{id}/complete` | `record_transport_journeys` |
| POST | `/rosters/{id}/outcome` | `record_transport_journeys` |
| POST | `/authorizations` | `authorize_transport_handover` for guardian grants; `manage_transport` for school staff grants |
| GET | `/authorizations/{id}` | `view_transport_operations` |
| POST | `/authorizations/{id}/revoke` | Same permission split as authorization creation |
| GET | `/rosters/{id}/journey` | `view_transport_operations` |
| POST | `/rosters/{id}/events` | `record_transport_journeys` |
| GET | `/exceptions/{id}` | `view_transport_operations` |
| POST | `/exceptions/{id}/actions` | Resolution/reassignment: `authorize_transport_handover`; follow-up: that permission or `record_transport_journeys` with concrete responsibility |
| POST | `/reporting/policies` | `manage_transport` |
| POST | `/reporting/policies/{id}/materialize-today` | `manage_transport` |
| GET | `/reporting/obligations` | `view_transport_operations` |
| GET | `/reporting/obligations/{id}` | `view_transport_operations` |
| GET | `/reporting/obligations/{id}/report` | `view_transport_operations` |
| POST | `/reporting/obligations/{id}/submissions` | `submit_transport_daily_reports` |
| POST | `/reporting/submissions/{id}/review` | `review_transport_daily_reports` |

`{resource}` on foundation show/create/update routes is restricted to `vehicles`,
`routes` and `stops`. The catalogue route additionally accepts `schedules`,
`assignments`, `reservations`, `enrolments`, `trips` and `authorizations`.

## Payloads and responses

Resource/action responses use `{data: ...}`. Collection responses use
`{data: [...], meta: ...}`; reporting visibility also returns `summary`.
Catalogue queries accept only `limit` (1–100, default 50) and `offset`
(0–100000, default 0), ordered by identity. Detailed version tokens are obtained
from show endpoints before mutation. Catalogues omit authorization identity and
handover snapshots; sensitive details remain in explicitly authorized detail reads.

New resources and append-only events, outcomes, follow-ups, submissions and
reviews return HTTP 201. Capture, materialization, completion and update actions
return HTTP 200, including idempotent retries where the shared service permits them.

Writes accept the existing service payloads. Schedule creation additionally
exposes the existing guarded timetable definition: route_id, service_code,
direction, timezone, weekday_mask, effective_from, optional effective_to,
departure_time and completion_time (`HH:mm`), optional completion_day_offset
(0 or 1) and turnaround_minutes (0–1440). The state endpoint accepts only
expected_version, active and a nonblank reason. A timetable change requires a new
schedule identity. A true state no-op retains its version and creates no audit.

Reporting policy materialization accepts no client date or other payload. It
uses the current service-local date. Reporting visibility accepts its existing
bounded date/status/responsibility filters and does not create obligations.

Errors: unauthenticated 401; unavailable permission/responsibility/write access
403; foreign or unavailable scoped identity 404; stale/duplicate/capacity
conflict 409; invalid or prohibited payload 422. Strict audit failures roll back
mutations and surface as server failures. There are no HTTP endpoints for running
or configuring the scheduler, deleting evidence, replacing submitted snapshots
or editing journey history.

## Security and evidence boundaries

Guardian linkage alone does not grant handover authority. Actual parties,
verification and receipt rules remain enforced by the event service and database.
A failed handover retains continuing custody. Later success does not implicitly
resolve an earlier exception. Explicit resolution still requires appropriate
permission and later successful evidence at the same boundary.

Submission freezes server-derived totals and evidence. An independent reviewer
reviews that submission, even if live evidence later changes. Review acceptance
is not a declaration that current custody exceptions are resolved. Corrections
require requested changes and create a new immutable revision.

Transport attendance remains separate from classroom attendance. This HTTP slice
does not implement external recipient substitution, historical occupancy inference,
reminders, escalation or deployment activation.

## Verification

Local HTTP/service/database verification passed on 7 November 2026: 217 Transport
feature tests with 2,705 assertions and 9 unit tests with 182 assertions. The full
application regression subsequently passed with 2,021 tests and 32,047 assertions
on shuleos_assessment_fk_test after the migration function rebuild fix.

The operations contract section 15 records the delivery limits and evidence.
The final persistent Transport static-analysis configuration and remote CI remain
separate checks; these local results do not claim deployment or scheduler activation.


## Transport add-on enforcement

All existing Transport mutation endpoints enforce commercial access through their
shared services. No new paid activation HTTP endpoint is introduced in this slice.
Normal writes require an effective add-on and eligible main subscription plus all
existing security, permission, mode, setup and responsibility rules. A missing or
expired entitlement returns 403 without a mutation or audit. An invalid input can
still return validation errors before access is evaluated; denial grants no access.

Historical authorized GET operations remain available after commercial expiry.
Only concrete captured-work closure can continue: already-onboard arrival or
drop-off, scoped replacement recipient authorization/revocation, custody follow-up,
non-travel attendance, trip reconciliation and eligible preexisting report closure.
Pickup, school departure, capture, new configuration and obligation scheduling
cannot use continuation. Existing stale tokens, recipient checks, service windows,
independent review and atomic audit rules remain in effect.

Commercial time is evaluated after acquiring the school write lock and latest
main-subscription shared lock. Waiting for a commercial row cannot reuse a
pre-wait timestamp to admit a new operation after Transport expiry.
