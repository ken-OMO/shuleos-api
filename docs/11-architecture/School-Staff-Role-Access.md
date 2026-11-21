# School staff roles and workspaces

Status: focused checks and manual local Laravel role verification passed; prepared for draft PR CI review. Nothing deployed.

## Approved boundaries

- Director / School Admin: administration workspace. Financial actions still require their existing explicit permissions.
- Headteacher / Principal: academic leadership workspace. Their role grants cannot confer finance, payment, subscription or administrator portal access. A separately assigned administrator or finance role can confer its own permissions.
- School Admin + Headteacher: both workspaces; changing the displayed workspace does not mutate identity, school or permissions. The leadership workspace excludes financial data even for a combined holder.
- School Secretary: only `access_school_office` and `view_school_office_summary` from that role. No learner mutation, bulk export, finance, marks, role management or confidential records. Office business forms remain on hold.

The Secretary office page intentionally shows the authenticated school and responsibilities, not admissions or contact forms. Those workflows need separately reviewed fine-grained permissions, approvals and tests before release. Do not grant broad `manage_learners` to enable office data entry.

## Assignment

`GET /api/admin/school/staff-access?page=1` lists up to 25 users from the authenticated school. `PUT /api/admin/school/staff-access/{user}` takes only a named `Headteacher` or `School Secretary` role and boolean `assigned`. Client-selected tenants and arbitrary role IDs/permissions are rejected. Director / School Admin, current update/delegation/invitation permissions and an effective subscription are required for changes. This flow never grants School Admin or platform authority.

Assignments use active global system presets, validated against reviewed extra permission sets and existing actor-held delegatable authority. Creation remains the existing school invitation/OTP/password flow. Secondary role changes lock the school, actor and target, recheck actor authority after waiting, and audit mutation atomically. Repeat changes are idempotent. Removal cannot alter a primary role and increments auth_generation so prior sessions fail on their next request. Adding a role requires a fresh sign-in to refresh frontend claims; the backend always resolves current assignments.

`GET /api/school/office` requires a school-bound Secretary identity and both office permissions. No client school ID selects the tenant.

## Migration

Adds the Secretary preset and two office permissions, ensures reviewed Headteacher portal grants, and copies only an explicit reviewed subset of existing School Admin grants to protected global Director/School Owner presets. Does not assign roles to existing users, change subscriptions, create accounts, or send mail. Existing Secretary name conflicts with non-system global roles stop migration for review. Rollback is deliberately refused; use a reviewed forward migration.

Per-role filtering is applied before unioning permissions. A Secretary/Headteacher restriction does not remove authority explicitly obtained from another role. Both /auth/me and permission middleware use the same resolver. Administrator/leadership access resolves active tenant-valid secondary roles rather than trusting only role_id. HOD-only department scoping remains intact.

## Validation

Frontend: 57 unit/contract tests, ESLint and production build passed locally. Includes routing, switch eligibility, denied callers, scoped responses and late response denial. Two synthetic browser tests passed on the user’s Windows environment.

Backend: 94 focused PostgreSQL tests / 456 assertions passed on PHP 8.2.12 against `shuleos_assessment_fk_test`, including the final administrator-count and Director-migration regressions. PHP syntax, Pint and diff checks passed locally. The Director migration test creates its own optional global role fixtures and rolls back its changes.

Manual local Laravel verification: Mansoor academy’s School Admin + Headteacher account loaded both workspaces through the switcher, with no finance widget in leadership. A separately invited Secretary-only account completed activation, loaded School office, and was denied direct administration and leadership URLs. The local development migration was guarded and applied; test accounts, assignments and trial data are not part of these commits.

Full regression and remote CI remain pending. Existing frontend live CI pins an earlier backend and does not independently exercise the new staff-role endpoints. The included focused HTTP/backend tests and two synthetic browser tests cover this delivery; deployment is not requested. Other business forms remain on hold.
