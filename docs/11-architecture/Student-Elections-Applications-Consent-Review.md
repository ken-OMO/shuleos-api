# Student Elections applications, guardian consent and board review

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Approved: 12 November 2026
Status: locally verified; real-school release remains blocked.
Release: blocked for real-school use

## Approved policy

1. School staff verify and designate one consenting guardian for the learner.
   Existing primary-contact, pickup and general portal-access flags do not confer
   this authority. No guardian is automatically designated by this change.
2. The designated guardian accepts or refuses the exact application and terms
   version through their own authenticated account. Staff cannot impersonate the
   guardian or attest consent on their behalf. Portal access recovery restores
   the verified guardian's own account; it never bypasses consent.
3. Material edits invalidate existing consent and candidate approval. A revised
   application requires fresh consent and review. Previously accepted versions,
   refusals, withdrawals and administrative decisions remain traceable.
4. Withdrawal before voting opens invalidates candidate approval. After opening,
   record an incident and pause voting/declaration for controlled review. Never
   automatically remove or rewrite accepted ballots. The later incident-resolution
   process must not silently introduce an annulment or reopening policy.
5. One designated, non-conflicted election officer records a reasoned decision.
   Appeals require a different authorised officer. School Administrator authority
   does not bypass conflict, consent, eligibility or immutable-evidence checks.
6. The school publishes application, consent, review and appeal deadlines before
   applications open. Position replacement is allowed only before application
   closure. Preserve the replaced record and obtain fresh consent and review.
   Exact dates are school-configured; no arbitrary default deadlines are inferred.
7. The school decides whether a profile photo is mandatory before applications
   open. Missing required photos block submission/approval. Any included photo
   must have verified school/learner ownership and an approved immutable version.
   No unverified path is accepted or published as an election photograph.

## Evidence and access requirements

Application versions freeze the selected office and its duties, manifesto, slogan,
terms version and approved photo version when present. Guardian decisions and board
reviews refer to that exact version. One active application across the whole
school election is enforced under transaction and PostgreSQL contention tests.

The guardian's designation and active link to the learner are checked at consent
and review. A revoked link, changed designation, stale version, refusal, missing
consent or withdrawn consent cannot support candidate approval. Competing edits,
consent and reviews must serialize or reject stale expected versions without
silently approving old material. Administrative evidence and strict audit share
one transaction; audit failure rolls back the mutation.

Learner drafts and submitted applications are private. Guardian views are limited
to their authorised child. Board views require the appropriate school/election
assignment and permission. Public campaign responses remain allowlisted. Guardian
contact details, review notes, private storage paths and consent evidence are not
public candidate fields. Candidacy consent never authorises disclosure of a vote.

Use the existing file-security/private-storage framework for profile-media work;
verify current policies, scanners, storage wiring and ownership before exposing
an image. A file-validation policy alone is not a learner-photo ownership record.
Do not manufacture a trusted photo reference from a client-supplied filesystem path.

## Implementation boundaries

This policy record does not implement, test or activate the workflow. Elections
retains its disabled classroom rollout and its real-school release block. There
are no real-school guardian designations, consent actions, board assignments,
payments, deployments, staged files or commits created by recording this policy.

The last verified Elections run passed 203 tests and 1,744 assertions, including
28 PostgreSQL concurrency scenarios. Those results precede this next implementation.
New application, consent, board and media tests plus full regression remain pending.
