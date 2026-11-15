# Application, consent and withdrawal contention checks

## Current verification — 15 November 2026

Local Elections and full backend checks passed. See
[Student-Elections-Verification-and-Release-Gates.md](Student-Elections-Verification-and-Release-Gates.md) for exact results, current delivery limits and release gates.
Dated slice notes below describe earlier implementation stages; their pending
verification statements are historical and do not override the current record.
Classroom rollout remains disabled. CI success and production readiness are not claimed.

Status: locally verified; real-school release remains blocked.
No production PHP, route, configuration or migration is changed by this slice.
Elections remains blocked for real-school use; classroom rollout stays disabled.

Fifteen cases use committed synthetic fixtures, separate PHP processes and
PostgreSQL sessions. The controller connection verifies the actual blocker via
pg_blocking_pids before releasing the first worker. A scheduling delay alone
cannot satisfy the tests. Worker input, including database configuration, travels
through stdin and is not placed on the process command line or printed.

Coverage: competing initial submissions and competing edits; both orders of
edit versus consent and edit versus review; rolled-back edit versus consent;
consent deadline after an observed root-lock wait; both orders of withdrawal
versus internal voting and classroom device submission; rolled-back withdrawal versus voting; both orders of withdrawal
versus publication. The deadline test deliberately advances the injected worker
clock after its blocked query returns, rather than waiting twenty-five minutes.

Expected outcomes preserve exact-version consent and review evidence. An edit
that commits first makes the old version stale. Earlier consent or review can
commit, but cannot approve the subsequent edit. Withdrawal that commits first
holds voting/publication. A vote accepted before withdrawal remains recorded.
Publication that commits before withdrawal remains historical evidence, followed
by an incident hold; the system does not silently erase appointments or ballots.

All tests require APP_ENV=testing, the exact database shuleos_assessment_fk_test,
no connection URL, and no cached configuration. Fixtures carry an owned school
marker. Cleanup runs only after the runner has stopped every worker. To remove
committed immutable synthetic evidence, cleanup takes ACCESS EXCLUSIVE table
locks, temporarily disables only four named application/guardian user triggers,
deletes only that school's fixtures, re-enables every trigger, and commits the
cleanup transaction. Foreign-key triggers remain enabled. Failed cleanup rolls
back both its row changes and PostgreSQL trigger DDL. This is isolated test-only
cleanup, never a production evidence-deletion workflow. Run this suite without
parallel processes sharing the database. The test database role must own the
evidence tables, as it does when applying the migrations.

The prior verified baseline was 260 Elections tests and 1,854 assertions. These
new tests do not establish throughput or production readiness. Verified photos,
incident resolution, yearly entitlement, privacy/observability deployment review,
scoped static analysis and full backend regression remain pending.
