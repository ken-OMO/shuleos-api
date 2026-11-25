<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyTenantSchema extends Command
{
    protected $signature = 'tenant:verify-schema
        {--strict : Fail on missing core tenant columns or invalid ownership column types}
        {--require-rls : Fail unless every discovered tenant table has RLS and a policy applicable to this role}';

    protected $description = 'Validate PostgreSQL tenant ownership columns and inspect row-level security for the connected role';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('Tenant schema verification requires PostgreSQL.');

            return self::FAILURE;
        }

        $tables = collect(DB::select(<<<'SQL'
            SELECT c.relname AS name, a.atttypid = 'uuid'::regtype AS uuid_column,
                   a.attnotnull AS required, row_security_active(c.oid) AS rls_active,
                   EXISTS (
                       SELECT 1 FROM pg_policy AS p
                       WHERE p.polrelid = c.oid AND p.polpermissive
                         AND EXISTS (
                             SELECT 1 FROM unnest(p.polroles) AS policy_role(role_oid)
                             WHERE CASE WHEN policy_role.role_oid = 0 THEN true
                                        ELSE pg_has_role(policy_role.role_oid, 'USAGE') END
                         )
                   ) AS has_policy
            FROM pg_class AS c
            JOIN pg_namespace AS n ON n.oid = c.relnamespace
            JOIN pg_attribute AS a ON a.attrelid = c.oid
                AND a.attname = 'school_id' AND a.attnum > 0 AND NOT a.attisdropped
            WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p')
            ORDER BY c.relname
            SQL))->keyBy('name');

        $errors = [];
        foreach (['learners', 'teachers', 'teacher_assignments', 'grades', 'streams', 'academic_years', 'terms', 'users'] as $name) {
            $table = $tables->get($name);
            if ($table === null || ($name !== 'users' && ! $table->required)) {
                $errors[] = "$name is missing its expected school_id column or nullability.";
            }
        }

        foreach ($tables as $table) {
            if (! $table->uuid_column) {
                $errors[] = "$table->name.school_id must use UUID.";
            }
        }

        $uncovered = $tables->filter(fn ($table) => ! $table->rls_active || ! $table->has_policy)->keys();
        $this->info("Inspected {$tables->count()} tables with school_id in the current schema.");
        foreach ($errors as $error) {
            $this->warn($error);
        }
        if ($uncovered->isNotEmpty()) {
            $this->warn('RLS inactive for the connected role or no applicable permissive policy: '.$uncovered->implode(', '));
        }

        if (($this->option('strict') && $errors !== []) ||
            ($this->option('require-rls') && ($errors !== [] || $uncovered->isNotEmpty()))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
