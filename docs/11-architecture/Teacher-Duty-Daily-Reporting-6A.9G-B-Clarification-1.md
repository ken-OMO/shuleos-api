# Teacher Duty Daily Reporting — Phase 6A.9G-B Clarification 1

Status: FROZEN once committed

Parent contract: Teacher-Duty-Daily-Reporting-6A.9G-B-Database-Domain-Contract.md

## Purpose

This clarification resolves the existing-school and future-school provisioning semantics for the Teacher Duty reporting settings stored in `school_settings`.

The frozen 6A.9G-B contract requires:

- `teacher_duty_report_deadline_time`
- `teacher_duty_report_grace_minutes`

to be school-level settings and requires existing schools to receive the product defaults.

Repository discovery established that a `School` may exist without a corresponding `school_settings` row. The current production Platform school-onboarding workflow does not create that row, and no automatic SchoolSettings provisioning hook was found.

Therefore adding columns to `school_settings` alone is insufficient to guarantee the frozen reporting-settings invariant.

## Clarified invariant

Every School must have exactly one `school_settings` row for Teacher Duty daily reporting configuration to be authoritative.

The existing database uniqueness rule on `school_settings.school_id` remains the final database guard against more than one settings row per School.

## Existing-school migration behaviour

The 6A.9G-B migration must ensure that every School existing when the migration runs has a corresponding `school_settings` row.

For each School without a settings row, the migration must create one using the School identity and existing database defaults for all unrelated settings.

The migration must not infer, copy, synthesize, or overwrite unrelated school-setting values.

Existing `school_settings` rows must be preserved.

After missing rows have been provisioned, the migration must add the Teacher Duty reporting settings required by the frozen parent contract:

- `teacher_duty_report_deadline_time` with product default `17:00:00`
- `teacher_duty_report_grace_minutes` with product default `120`

Existing and newly provisioned settings rows therefore receive the same Teacher Duty product defaults when the columns are introduced.

Provisioning must be idempotent with respect to the one-row-per-school invariant.

## Future-school provisioning behaviour

The current production school-creation workflow is:

`PlatformSchoolOnboardingService::onboard(...)`

Future schools created through this workflow must receive a `school_settings` row in the same database transaction as School creation.

The settings row must be provisioned explicitly after the School exists and before the onboarding transaction commits.

Failure to provision the settings row must fail closed and roll back the onboarding transaction.

The initial School Admin is not the creator or owner of the settings row. No actor provenance field is introduced.

The provisioning workflow must rely on database defaults for unrelated settings rather than duplicating those defaults in application code.

## Reusable provisioning boundary

A dedicated reusable SchoolSettings provisioning service may be introduced so that:

- the production Platform onboarding workflow can invoke it explicitly;
- future production School creation workflows can invoke the same canonical provisioner;
- provisioning remains idempotent;
- existing settings are preserved rather than reset.

No School observer, `created` event, model boot hook, or other hidden lifecycle hook may be introduced for this purpose.

## Reporting-domain failure behaviour

Teacher Duty daily reporting must not silently manufacture an in-memory fallback settings object when the persistent `school_settings` invariant is broken.

After migration and onboarding provisioning establish the invariant, absence of the required persistent settings row is an invalid server state and must fail closed.

This does not change the frozen timezone rule:

- `schools.timezone` remains authoritative;
- `config('app.timezone')` remains its fallback;
- timezone is not duplicated into `school_settings`.

## Database constraints

Existing `school_settings` constraints remain authoritative, including:

- primary key on `id`;
- foreign key from `school_id` to `schools.id`;
- uniqueness of `school_id`.

The 6A.9G-B implementation must preserve these constraints.

The existing database UUID default for `school_settings.id` may be used when migration-level provisioning inserts missing rows.

## Down migration

Rolling back 6A.9G-B must remove the Teacher Duty reporting columns as required by the parent contract.

Rollback must not delete `school_settings` rows merely because they were provisioned while 6A.9G-B was applied.

Deleting such rows could destroy unrelated School settings subsequently stored in them and is therefore prohibited.

## Tests added by this clarification

The 6A.9G-B implementation must additionally verify:

- an existing School without `school_settings` receives a settings row during migration;
- an existing settings row is preserved;
- unrelated settings values are not reset;
- exactly one settings row exists per School after provisioning;
- production Platform onboarding creates the settings row;
- onboarding-created settings use the Teacher Duty defaults;
- onboarding settings provisioning failure rolls back School creation and initial-admin creation;
- repeated reusable provisioning preserves an existing settings row and its customized values;
- reporting fails closed if its required persistent settings row is absent after the invariant should have been established.

## Frozen boundary

This clarification changes only SchoolSettings provisioning semantics necessary to make the frozen 6A.9G-B reporting-settings contract implementable.

It does not change:

- the daily-report aggregate;
- report lifecycle;
- submission semantics;
- deadline snapshot semantics;
- overdue or late derivation;
- occurrence semantics;
- actor eligibility;
- weekly reporting;
- authorization;
- notifications;
- HTTP scope.

Once committed, this clarification is immutable.

Any further normative gap requires another separate clarification commit. The original frozen contract and this clarification must not be amended or rewritten.
