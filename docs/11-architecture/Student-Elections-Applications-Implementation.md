# Versioned applications, guardian consent and board review

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Status: locally verified; real-school release remains blocked.
Elections remains blocked for real-school use. Classroom rollout stays disabled.

New service-created elections use versioned_v1. Existing elections retain their
legacy marker and are not silently reinterpreted. A manager can adopt a draft
with no existing candidacies by publishing a policy; existing evidence requires
a separately reviewed adoption. Legacy elections are not production-approved.

A policy is an immutable version containing the exact terms, SHA-256 checksum,
explicit-offset UTC deadlines, the school photo requirement, designated primary
reviewer and different appeal reviewer. Publication and replacement are allowed
only while draft and before applications open. Each deadline increases strictly:
opening, application closure, consent closure, review closure, appeal closure,
then voting opening. There are no inferred default deadlines.

Learner submissions require their own active portal identity, exact terms
acceptance, published policy UUID and an eligible active school/grade/stream
position. Unsupported grade ranges, rules and multi-seat policies fail closed.
One application projection per learner/election points to immutable versions.
Each revision freezes office name/duties/scope, manifesto, slogan and terms
version. Position replacement before closure uses the same application and
preserves the earlier candidate as withdrawn. Edits require the current version
UUID and invalidate consent/approval. Old versions and decisions remain intact.

Guardian action requires the exact application UUID/version and current explicit
guardian designation. Only the guardian's own active account and relationship
can act. Staff cannot impersonate acceptance. A new acceptance or refusal after
an earlier decision requires a fresh application version; withdrawal can append
at any time. Consent-event sequence numbers determine ordering, including two
actions in the same timestamp precision. No choice is correlated with a ballot.

Review requires the configured officer, current manage_election_candidates
permission, exact version, valid current guardian acceptance, eligibility and
unchanged position snapshot. Known self/guardian relationships force recusal, and reviewers must explicitly
attest that no other conflict exists.
A rejected version can be appealed by the learner before the appeal deadline;
a different configured officer decides. Reasons remain private evidence. One
primary decision and one appeal decision per version are enforced in PostgreSQL.
The legacy candidate-review service refuses managed elections. Opening voting
rechecks every approved candidate's application, consent, review and projection.

Pre-opening withdrawal invalidates candidate approval. Post-opening learner or
guardian withdrawal appends incident evidence and sets integrity_hold. Original
and runoff ballot inserts, station actions, lifecycle operations and result
publication reject the hold. Existing ballots and frozen candidates are retained.
There is deliberately no hold-clear/annul/reopen endpoint: controlled resolution
policy and workflow are still pending. Published history is not silently erased.

All workflow writes take the root election UPDATE lock before their subordinate
locks. This serializes edits, consent, review, withdrawal and voting closure.
Strict audit shares the transaction. Evidence tables reject update/delete;
checksums are consistency evidence, not cryptographic signatures or protection
against a privileged database owner. Actual new workflow contention verification
remains a release gate; passing earlier worker suites does not establish it.

HTTP routes are under /api/student-election-applications/{election}; JWT and
tenant middleware apply. Mixed learner/guardian/officer access is enforced by
the domain service rather than the generic module permission map. Policy and
guardian designation require manage_student_elections, review requires assigned
officer plus manage_election_candidates, and learner/guardian actions require
concrete ownership. Successful private responses carry Cache-Control no-store.
No public candidate response includes guardian contacts, reasons or consent data.

Photo integration is deliberately fail-closed: arbitrary paths/version IDs are
rejected, optional-photo submissions contain no image, and photo-mandatory
submissions cannot proceed until verified immutable learner media is connected.
Local/test NullVirusScanner results are not production malware-scan evidence.

Remaining: verified media, richer private history/deadline discovery UI, guardian
access recovery and limited Elections portal entitlement, new workflow PostgreSQL
contention tests, incident resolution, cancellation policy, yearly entitlement,
observability/privacy review, scoped static analysis and full regression.
No school activation, staging, commit, push or deployment is performed here.
