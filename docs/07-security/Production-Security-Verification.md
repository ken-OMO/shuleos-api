# Production security verification

Record the release SHA, environment, date and owner alongside each item. Keep secrets and learner records out of evidence. Mark controls pending until the evidence below has been collected.

| Control | Evidence to collect |
| --- | --- |
| Firewall and edge protection | Review the deployed firewall and access rules. Confirm that the web and API origins accept traffic only from approved paths and that PostgreSQL and administrative interfaces are private. Record the WAF and DDoS configuration if provided by the host. |
| TLS and response headers | Check the public web and API hostnames over HTTPS, including HTTP redirects and certificate renewal. Verify HSTS at the edge or set `SHULEOS_ENABLE_HSTS=true` in the web build environment after HTTPS works and rebuild. Verify browser headers and a successful sign-in using the deployed API origin. |
| Data encryption | Obtain hosting evidence for encryption of database storage, files and backups. Verify database TLS and access to encryption keys under the actual application identity. |
| Secrets | Check that runtime credentials come from the deployment secret store and are scoped per environment. Record who can read, rotate and revoke them. Verify production debug output is disabled. |
| Monitoring | Confirm that authorization failures, API errors and backup failures reach the assigned responders. Use synthetic events for the verification and check that tokens and learner data are filtered. |
| Backup restoration | Restore an encrypted backup into an isolated environment. Verify integrity and application access with test identities and record recovery time and recoverable data age. |
| PostgreSQL tenant isolation | Run `php artisan tenant:verify-schema --strict --require-rls` against the migrated database using the runtime application role, and retain the result. Exercise the expected tenant boundaries with synthetic data under the same role, including reads, writes and background jobs. |

The API CI schema step runs `tenant:verify-schema --strict` after migrations. It enforces UUID ownership columns and the expected core tenant columns, and reports RLS coverage for the CI database role. The separate `--require-rls` option fails when the current role bypasses RLS or lacks an applicable permissive policy on discovered tables. The command inventories tables with `school_id` in the current schema. Review indirect tenant relationships and policy predicates separately. The CI service role and production runtime role can produce different results.

The web CSP permits the inline hydration and theme scripts used by the current static build. Verify the policy against the deployed app. Strict script CSP with nonces or hashes requires a separate implementation and browser verification.
