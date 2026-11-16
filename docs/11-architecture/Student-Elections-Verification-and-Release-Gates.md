# Student Elections verification and release gates

> Current Elections follow-up — 16 November 2026: HTTP boundaries, shared photo-read locks, shared commercial evaluation and unsupported-setting validation are locally verified. Full backend: 2,537 tests/35,422 assertions; both scoped analyses and remaining checks passed. Final source review completed. This follow-up is uncommitted and has no CI result; real-school Elections use remains blocked. See [current verification](Elections-Transport-Audit-Repairs.md) and [photo/commercial/settings contract](Student-Elections-Photo-Locks-and-Settings.md).

> Audit follow-up — 16 November 2026: [verified repairs and current delivery limits](Elections-Transport-Audit-Repairs.md). Local full regression passed 2,521 tests/35,273 assertions; both scoped analyses and remaining checks passed. PR #89 merged at 7a718e3dde1317af5250b2c8566399188daa3b27; all ten PR checks passed. Not deployed. Real-school Elections use remains blocked. Voting extension and certificates/PDFs remain undelivered requirements.

Current follow-up verified locally on 16 November 2026: Elections features 390 tests/1,841 assertions, units 28 tests/756 assertions, full backend 2,537 tests/35,422 assertions; 20 minutes 42.378 seconds and 654 MB. Both scoped analyses, formatting, Composer checks and diff checks passed. Exact targeted results and test limitations are in the linked current verification record. Release remains blocked for real-school use.

## Historical local evidence — 15 November 2026

All tests ran against shuleos_assessment_fk_test with process environment settings
restored afterward. The full run used PHP 8.2.12 and Laravel 12.71.0.

| Check | Verified result |
| --- | --- |
| Elections suites | 385 tests, 2,363 assertions |
| Transport feature suite | 223 tests, 2,765 assertions |
| Transport subscription suite | 41 tests, 362 assertions |
| Transport unit suite | 9 tests, 182 assertions |
| Full backend regression | 2,450 tests, 34,824 assertions; exit code 0 |
| Full-run elapsed time / peak memory | 17 minutes 20.943 seconds / 636 MB |
| Elections scoped PHPStan level 5 | No errors; no baseline or suppressions |
| Composer validation and security audit | Passed; no advisories reported |
| Diff whitespace check | Passed |

The table above is historical evidence from the earlier implementation, before its final CI/documentation/autoload cleanup. It is superseded for this follow-up by the 16 November verification record linked above. Local execution does not establish GitHub CI success for the current uncommitted changes.

phpstan-elections.neon covers the two Elections service directories, the listed
HTTP controllers, Elections Resources, the station boundary middleware and station
configuration. It does not claim whole-repository, migration or test analysis.

The PostgreSQL suites exercise actual blocking with independent sessions for
voting/closure, tally/declaration, linked rounds, classroom stations, application
changes/consent/review/withdrawal, cancellation and commercial lock-wait checks.
A passing test count is not a throughput benchmark or cryptographic anonymity proof.

## Implemented scope

- Explicit privacy-safe API fields, tenant and election/position scoping.
- Duplicate-vote safeguards, concurrent voting and closure, aggregate reconciliation.
- Single-seat classroom ballots, explicit abstention and unopposed confirmation.
- Immutable tally evidence, seat limits, unresolved-tie declaration blocks and linked runoffs.
- Teacher approval, classroom attendance checks and one-use device-bound sessions.
- Versioned applications and terms, designated guardian consent and independent reviews.
- Verified private photo versions, strict mutation audit and controlled integrity holds.
- Independent cancellation review and a fresh linked election with fresh evidence.
- Optional 12-calendar-month Elections periods without changing the main package.
- Entitlement checks for new work and recorded-window completion of paid openings.

No package name implicitly grants Elections access. New elections, new rounds and
configuration need effective commercial access. Expiry does not silently rewrite
accepted evidence; bounded completion and authorized historical access follow the
commercial contract. General learner/parent portals are not globally enabled.

## CI and dependency changes prepared for this branch

Elections Static Analysis is a separate job using phpstan-elections.neon. Backend
Tests depends on it as well as the existing formatting, dependency/security,
Assessment and Transport checks. The new job checks optimized PSR-4 autoloading.
Backend CI explicitly installs GD for photo tests and requests a 1 GB PHP limit.
Schedulers and classroom rollout are explicitly disabled in the relevant jobs.
The workflow does not activate a school, collect payment or deploy the application.

The Laravel requirement is raised to ^12.71.0. Updating the lock content hash must
preserve the installed package versions; it is not another broad dependency upgrade.
The two worker rollback exceptions use matching Tests\Support files and imports;
their rollback, failure reporting and PostgreSQL transaction protocol are preserved.

A repository administrator must include Elections Static Analysis in any required
branch-protection checks before relying on it as a merge gate. Adding a workflow
job does not by itself change repository protection settings.

## Transport test-clock correction

PostgreSQL uses its real clock for captured and recorded evidence. Frozen handover
dates must not precede that capture. Transport fixtures now use a shared future
service week derived from the database clock, preserving weekday alignment,
relative expiry/grace/renewal boundaries, SQL date assertions and worker-session
consistency. Only test/support files changed. Production clocks, triggers,
authorizations and custody/evidence constraints were not relaxed.

## Remaining delivery and release gates

- Review actual CI results for the final commit; local green checks are not CI evidence.
- Review fresh-install and existing-schema migration adoption, irreversible changes,
  existing Elections data and privacy metadata removal before any production migration.
- Review logging, SQL bindings, request tracing, proxy/IP trust, TLS, origin controls,
  shared-network throttling and operational privacy on the actual deployment stack.
- Validate production scanning, private encrypted-photo storage, key handling,
  retention, revocation/takedown and orphan reconciliation before real learner media.
- Complete remaining delivery items such as certificates/PDF reports and frontend
  integration before claiming the full product contract delivered. Multi-seat
  voting is disabled; no unapproved multi-seat election method is introduced.
- Purchase/payment-provider verification, entitlement revocation/cancellation and
  administrator commercial UI integration remain separate work.
- No real-school grants or commercial activation, classroom rollout, production
  adoption, deployment or scheduler activation is established by these results.

Privacy controls minimize stored correlations and prohibit voter/session/device
identifiers in anonymous ballots. They do not protect against every privileged
observer, database transaction/log correlation or compromised voting device. The
privacy threat model and deployment review remain authoritative release constraints.

Finance foreign-key verification and direct FinancePaymentService test coverage
remain separate open verification items; this work does not close them.
