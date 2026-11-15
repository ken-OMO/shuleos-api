# Elections guardian authority foundation

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Status: locally verified; real-school release remains blocked.
Real-school Elections use remains blocked. Classroom stations remain disabled.

An authorised school election manager explicitly designates one current guardian
for a learner in an election, recording a private verification reason. This is
not consent: no application, terms or photo is approved by designation. Primary
contact, pickup, SMS, email and generic portal flags never confer this authority.

Each change appends a revision with the guardian account and relationship IDs.
The latest event UUID is the optimistic version; initial designation requires
null, later changes require the current UUID. Revocation appends evidence;
re-designation does not restore the earlier version. No automatic designations
or role grants are made. The service rechecks school, actor permissions, active
learner, guardian account and current portal relationship. Shared relationship
and account locks are retained with the election/learner locks until commit.

The database refuses updates and deletes and rejects inserts whose election,
learner, guardian account, relationship or actor belongs to another tenant.
Privileged database owners can still disable triggers or truncate tables; this
is administrative evidence protection, not protection against database owners.
Strict audit and designation commit in one transaction. The service response
contains only version, revision, action, learner_id and parent_id. Verification
reasons and guardian contact details are not public campaign data.

The internal guardianForConsent hook requires an already-open transaction.
Future consent persistence MUST retain that transaction and these locks, validate
application/terms version and deadlines, and recheck designated guardian access.
It accepts only the designated guardian's own authenticated account; staff cannot
impersonate consent. Existing school-level parent-portal disablement is respected.
The limited Elections-only portal entitlement remains a later integration task.

This foundation exposes no HTTP endpoints and does not change existing candidate
approval, voting, results, declaration or parent-link workflows. Those paths are
not yet consent-enforced. Designation and revocation are deliberately refused
once voting has opened or an electorate has been frozen. The approved later
post-opening withdrawal path must append an incident and pause voting/declaration
without rewriting ballots; it is not implemented by this foundation.

Remaining: versioned applications and terms; published deadlines; officer
assignments/conflict checks; guardian decisions; review/appeal APIs; withdrawal
incidents; verified private photo versions; cross-workflow PostgreSQL contention;
full regression, static analysis and release review.

Production image readiness must not be inferred from local/test uploads:
AppServiceProvider uses NullVirusScanner in local/testing and ClamAV otherwise.
The former reports files clean without malware scanning. Photo ownership,
immutable versions and authorised delivery require explicit implementation.
