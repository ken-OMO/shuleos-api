# Verified learner profile photo versions

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Status: locally verified; real-school release remains blocked.
Elections remains blocked for real-school use; classroom rollout stays disabled.
Prior verified baseline: 275 Elections tests, 2,007 assertions.

A learner uploads their own profile image through their authenticated learner
account, or a school user with manage_learners records it for that school's
learner. The actor explicitly attests the image identity. This establishes
account/learner ownership, not biometric identification. A guardian's candidacy
consent authority does not grant permission to replace the learner profile.

The upload takes the learner lock and requires the current profile version UUID.
The server copies the input to private quarantine before scanning. JPEG, PNG and
WebP are permitted up to 5 MB, 4096 pixels per side and four million pixels in
total. Required extension, MIME, magic and malware checks must all complete.
A NullVirusScanner is refused. Production requires ClamAV; scanner unavailability,
execution error and timeout fail closed. The shared ClamAV adapter now discovers
and executes the binary using Symfony Process, without a shell or a clean-on-error
fallback. Definitions freshness and operational health remain deployment checks.

The image is decoded and re-encoded into at most 1024-by-1024 PNG pixels. Original
filenames, EXIF/GPS and source metadata are not retained in the permanent asset.
The normalized output is inspected again, hashed and stored with the existing
private authenticated-encryption storage. Raw quarantine and temporary normalized
files are removed. Metadata and strict audit share an outermost transaction.
A body/audit failure removes the uncommitted encrypted file. If COMMIT itself
fails ambiguously, retain the private encrypted file for reconciliation rather
than deleting an asset that may have committed. Private orphan-file reconciliation,
backup/key recovery and retention policy remain release gates.

Each immutable version records server ownership, normalized hash, encrypted hash,
dimensions, scanner provenance and attesting actor. The current profile pointer
can advance; earlier versions remain unchanged. PostgreSQL constraints prevent
assigning a photo owned by another learner or school to a candidate. Test fakes
are marked test_only and cannot be used outside APP_ENV=testing. Passing fake scan
tests does not prove that production malware scanning is deployed or up to date.

Application versions freeze a verified photo UUID and its normalized hash. New
submissions select the current profile version. Changing the profile alone does
not change an existing submitted application image. A deliberate application
photo change creates a fresh version and requires fresh consent and review.
Required-photo policies now reject missing selection and accept only a verified
owned photo. Arbitrary paths remain prohibited. Consent, review and opening gates
recheck ownership, provenance, stored-file integrity and candidate projection.

Authorized owners/profile managers can read the current profile. The exact image
bound to an application is available through the application ownership/designated
guardian/assigned-officer checks. Successful image responses are private no-store
PNG, nosniff, no-referrer, with temporary plaintext deleted after sending. No
storage identifiers, keys, hashes, filenames or private paths are in public DTOs.
Campaign image delivery additionally requires the learner's eligible election
position, approved current application, campaigning state, no hold, no prior vote
and a date before voting day. Device ballots retain names and choices only.
The existing candidate DTO adds photo_version only when a verified reference is
present; legacy/no-photo responses retain their five-field contract.

Routes: /api/student-election-photos/learners/{learner} (GET/POST),
/learners/{learner}/image, /applications/{election}/{application}/image,
/campaign/{election}/{position}/{candidate}/image. JWT and tenant middleware apply;
the service enforces concrete ownership and domain permissions on every path.
No real-school images are uploaded or activated by the installer or tests.

The isolated committed-fixture cleanup is extended for the photo evidence table:
exclusive table locks, only the named user immutability trigger temporarily
disabled, owned synthetic rows removed, trigger restored before commit. FK
triggers remain enabled. It is not a production evidence-deletion endpoint.

Remaining: incident resolution, photo takedown/revocation and retention, classroom
teacher profile-image presentation, frontend integration, annual entitlement,
privacy/observability deployment review, scoped static analysis and full backend
regression. The installer records the already approved next-stage policies below.
