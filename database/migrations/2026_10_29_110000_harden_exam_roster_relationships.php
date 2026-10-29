<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Roster integrity requires PostgreSQL.');
        }

        foreach ($this->existingRelationships() as [$name, $child, $columns, $parent, $references]) {
            $present = [];
            $matching = [];

            foreach ($columns as $index => $column) {
                $present[] = 'c.'.$column.' IS NOT NULL';
                $matching[] = 'p.'.$references[$index].' = c.'.$column;
            }

            $this->assertNoConflicts($name,
                'SELECT count(*) AS total FROM public.'.$child.' c WHERE '
                .implode(' AND ', $present).' AND NOT EXISTS ('
                .'SELECT 1 FROM public.'.$parent.' p WHERE '
                .implode(' AND ', $matching).')'
            );
        }

        // Validate the entire paper chain before deriving its exam identity.
        $this->assertNoConflicts('assessment_roster_paper_chain', <<<'SQL'
            SELECT count(*) AS total
            FROM public.exam_roster_papers rp
            LEFT JOIN public.exam_roster_learners rl
                ON rl.id = rp.roster_learner_id
            LEFT JOIN public.exam_learning_areas a
                ON a.id = rp.exam_learning_area_id
            LEFT JOIN public.exam_papers p ON p.id = rp.paper_id
            WHERE rl.id IS NULL OR a.id IS NULL OR p.id IS NULL
               OR rl.exam_id IS DISTINCT FROM a.exam_id
               OR rp.learning_area_id IS DISTINCT FROM a.learning_area_id
               OR p.exam_learning_area_id IS DISTINCT FROM a.id
            SQL
        );

        foreach ($this->uniqueKeys() as [$table, $name, $columns]) {
            DB::statement(
                'ALTER TABLE public.'.$table.' ADD CONSTRAINT '.$name
                .' UNIQUE ('.implode(', ', $columns).')'
            );
        }

        DB::statement('ALTER TABLE public.exam_roster_papers ADD COLUMN exam_id uuid');

        DB::statement(<<<'SQL'
            UPDATE public.exam_roster_papers rp
            SET exam_id = rl.exam_id
            FROM public.exam_roster_learners rl
            WHERE rl.id = rp.roster_learner_id
            SQL
        );

        DB::statement(
            'ALTER TABLE public.exam_roster_papers ALTER COLUMN exam_id SET NOT NULL'
        );

        foreach ($this->relationships() as [$name, $child, $columns, $parent, $references, $delete]) {
            DB::statement(
                'ALTER TABLE public.'.$child.' ADD CONSTRAINT '.$name
                .' FOREIGN KEY ('.implode(', ', $columns).')'
                .' REFERENCES public.'.$parent
                .' ('.implode(', ', $references).')'
                .' ON UPDATE RESTRICT ON DELETE '.$delete
            );
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->relationships()) as [$name, $child]) {
            DB::statement(
                'ALTER TABLE public.'.$child.' DROP CONSTRAINT '.$name
            );
        }

        DB::statement('ALTER TABLE public.exam_roster_papers DROP COLUMN exam_id');

        foreach (array_reverse($this->uniqueKeys()) as [$table, $name]) {
            DB::statement(
                'ALTER TABLE public.'.$table.' DROP CONSTRAINT '.$name
            );
        }
    }

    private function assertNoConflicts(string $name, string $sql): void
    {
        $count = (int) DB::selectOne($sql)->total;

        if ($count > 0) {
            throw new RuntimeException(
                $name.': '.$count.' conflicting records. No automatic repair is permitted.'
            );
        }
    }

    private function uniqueKeys(): array
    {
        return [
            ['grades', 'assessment_roster_grades_school_id_uq', ['school_id', 'id']],
            ['streams', 'assessment_roster_streams_school_grade_id_uq', ['school_id', 'grade_id', 'id']],
            ['exam_roster_learners', 'assessment_roster_participant_exam_id_uq', ['exam_id', 'id']],
            ['exam_learning_areas', 'assessment_roster_area_exam_identity_uq', ['exam_id', 'id', 'learning_area_id']],
            ['exam_papers', 'assessment_roster_paper_area_identity_uq', ['exam_learning_area_id', 'id']],
        ];
    }

    private function existingRelationships(): array
    {
        return [
            [
                'assessment_roster_exam_tenant_fk', 'exam_rosters',
                ['school_id', 'exam_id'], 'exams', ['school_id', 'id'], 'RESTRICT',
            ],
            [
                'assessment_roster_creator_tenant_fk', 'exam_rosters',
                ['school_id', 'created_by'], 'users', ['school_id', 'id'], 'RESTRICT',
            ],
            [
                'assessment_roster_updater_tenant_fk', 'exam_rosters',
                ['school_id', 'updated_by'], 'users', ['school_id', 'id'], 'RESTRICT',
            ],
            [
                'assessment_roster_learner_tenant_fk', 'exam_roster_learners',
                ['school_id', 'learner_id'], 'learners', ['school_id', 'id'], 'RESTRICT',
            ],
            [
                'assessment_roster_grade_tenant_fk', 'exam_roster_learners',
                ['school_id', 'grade_id'], 'grades', ['school_id', 'id'], 'RESTRICT',
            ],
            [
                'assessment_roster_stream_grade_fk', 'exam_roster_learners',
                ['school_id', 'grade_id', 'stream_id'], 'streams',
                ['school_id', 'grade_id', 'id'], 'RESTRICT',
            ],
        ];
    }

    private function relationships(): array
    {
        return array_merge($this->existingRelationships(), [
            [
                'assessment_roster_paper_participant_exam_fk', 'exam_roster_papers',
                ['exam_id', 'roster_learner_id'], 'exam_roster_learners',
                ['exam_id', 'id'], 'CASCADE',
            ],
            [
                'assessment_roster_paper_area_exam_fk', 'exam_roster_papers',
                ['exam_id', 'exam_learning_area_id', 'learning_area_id'],
                'exam_learning_areas', ['exam_id', 'id', 'learning_area_id'], 'RESTRICT',
            ],
            [
                'assessment_roster_paper_area_fk', 'exam_roster_papers',
                ['exam_learning_area_id', 'paper_id'], 'exam_papers',
                ['exam_learning_area_id', 'id'], 'RESTRICT',
            ],
        ]);
    }
};
