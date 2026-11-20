# School readiness summary

`GET /api/admin/school/readiness` requires the existing school tenant middleware,
`view_academic_setup_status`, and administrator portal access. School identity
comes from the authenticated administrator; query parameters and headers cannot
select another school. Platform identities cannot use this school endpoint.

The response contains three independent results, each with `complete` and `missing`:

- `initial_setup`: preserves the five existing setup checks verbatim. This remains
  the only readiness service used by the admission gate.
- `academic_configuration`: active years, connected active terms, active grades,
  streams and learning-area allocations per grade, active linked learning areas,
  and valid year/term date ranges, including term containment in its year.
- `teaching_coverage`: active, non-deleted teachers and assignments covering every
  active stream / active allocated learning area / active term in an active year,
  plus a class teacher per stream and term. Missing academic configuration also
  prevents this diagnostic from being complete. Teaching coverage is never an
  admission requirement.

Coverage uses all active terms in active years, rather than choosing an arbitrary
first term. Active means configured; a future term may be fully configured before
its dates begin. This endpoint does not define today's teaching period. A
stream-null subject assignment covers its grade's streams, but cannot supply a
class teacher. Inactive/deleted assignments and inactive/deleted teachers do not
satisfy coverage. Class teachers must also reference an active allocation.

Missing records use stable codes with counts and school-owned record identifiers.
Each issue includes at most 50 details, ordered deterministically, with `truncated`
and an unbounded total count. No teacher/user personal fields are returned.

The existing `/school/setup`, `/academic-setup/status`, profile percentage,
module readiness flags and operational middleware are unchanged. Dashboard wiring
and business forms are outside this backend change.

## Validation

Run `SchoolReadinessSummaryTest`, `SchoolAdminInitialSetupTest`,
`SchoolOperationalSetupGateTest`, `SchoolAdminLearnerAdmissionTest`,
`SchoolAdminAcademicYearSetupTest`, `SchoolAdminCurrentTermSetupTest`,
`SchoolAdminGradeSetupTest`, `SchoolAdminStreamSetupTest` and
`TeacherAssignmentTest` against the isolated PostgreSQL test database.
