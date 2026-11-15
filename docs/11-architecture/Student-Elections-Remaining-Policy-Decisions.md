# Approved next-stage policy decisions

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Approved in this session on 14 November 2026.
Implementation and tests remain pending unless separately recorded as passed.

## Withdrawal after voting opens

Independently reviewed cancellation followed by a fresh linked election is the
selected resolution. Preserve original ballots, submissions, consent decisions,
tallies and any existing publication as historical evidence. Never rewrite the
accepted vote history. A new election requires fresh applications, guardian
consent, review, eligibility capture and voting. A linked election is not a reset
of the original ballot tables. No automatic resumption/annulment shortcut is
approved. Precise authorization, conflict, appointment-status and audit gates
must be implemented and tested before a hold can lead to cancellation.

## Annual commercial period

The Elections add-on period runs for 12 months from activation, rather than a
fixed school academic year. It remains a separately optional module; package names
do not grant access. No price, payment-provider integration or real-school
activation is set by this decision. The contract's limited learner/guardian
access and safe completion of already-open work must be enforced explicitly;
new elections and linked fresh reruns must require an effective entitlement.

Verified photo work precedes these stages. Scoped static analysis and full backend
regression follow the remaining implementation. Elections remains blocked for
real-school use throughout this preparation, and classroom rollout remains off.
