<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $constraint = DB::table('information_schema.table_constraints as tc')
            ->join('information_schema.key_column_usage as kcu', function ($join) {
                $join->on('tc.constraint_name', '=', 'kcu.constraint_name')
                    ->on('tc.constraint_schema', '=', 'kcu.constraint_schema');
            })
            ->where('tc.constraint_type', 'FOREIGN KEY')
            ->where('tc.table_schema', 'public')
            ->where('tc.table_name', 'parents')
            ->where('kcu.column_name', 'deleted_by')
            ->value('tc.constraint_name');

        if ($constraint) {
            DB::statement(
                'ALTER TABLE parents DROP CONSTRAINT '.
                $this->quoteIdentifier($constraint)
            );
        }

        Schema::table('parents', function ($table) {
            $table->foreign('deleted_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $constraint = DB::table('information_schema.table_constraints as tc')
            ->join('information_schema.key_column_usage as kcu', function ($join) {
                $join->on('tc.constraint_name', '=', 'kcu.constraint_name')
                    ->on('tc.constraint_schema', '=', 'kcu.constraint_schema');
            })
            ->where('tc.constraint_type', 'FOREIGN KEY')
            ->where('tc.table_schema', 'public')
            ->where('tc.table_name', 'parents')
            ->where('kcu.column_name', 'deleted_by')
            ->value('tc.constraint_name');

        if ($constraint) {
            DB::statement(
                'ALTER TABLE parents DROP CONSTRAINT '.
                $this->quoteIdentifier($constraint)
            );
        }

        Schema::table('parents', function ($table) {
            $table->foreign('deleted_by')
                ->references('id')
                ->on('parents')
                ->nullOnDelete();
        });
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
