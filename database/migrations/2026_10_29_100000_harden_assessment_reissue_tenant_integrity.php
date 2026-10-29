<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Assessment integrity requires PostgreSQL.');
        }

        // Check every relationship before making schema changes.
        // Optional references may be null; supplied references must match.
        foreach ($this->relationships() as [$name, $child, $columns, $parent, $references]) {
            $present = implode(' AND ', array_map(
                fn (string $column): string => 'c.'.$column.' IS NOT NULL',
                $columns
            ));
            $matching = [];

            foreach ($columns as $index => $column) {
                $matching[] = 'p.'.$references[$index].' = c.'.$column;
            }

            $invalid = DB::selectOne(
                'SELECT count(*) AS total FROM public.'.$child.' c '
                .'WHERE '.$present.' AND NOT EXISTS ('
                .'SELECT 1 FROM public.'.$parent.' p WHERE '
                .implode(' AND ', $matching).')'
            );

            if ((int) $invalid->total > 0) {
                throw new RuntimeException(
                    $name.': '.(int) $invalid->total
                    .' conflicting records. No automatic repair is permitted.'
                );
            }
        }

        // Learners and users already have full (school_id, id) unique keys.
        // These additional keys support the exam and request references.
        DB::statement(
            'ALTER TABLE public.exams '
            .'ADD CONSTRAINT assessment_exams_school_id_uq UNIQUE (school_id, id)'
        );
        DB::statement(
            'ALTER TABLE public.assessment_reissue_requests '
            .'ADD CONSTRAINT assessment_requests_school_id_uq UNIQUE (school_id, id)'
        );
        DB::statement(
            'ALTER TABLE public.assessment_reissue_requests '
            .'ADD CONSTRAINT assessment_requests_school_exam_id_uq '
            .'UNIQUE (school_id, exam_id, id)'
        );

        foreach ($this->relationships() as [$name, $child, $columns, $parent, $references]) {
            DB::statement(
                'ALTER TABLE public.'.$child
                .' ADD CONSTRAINT '.$name
                .' FOREIGN KEY ('.implode(', ', $columns).')'
                .' REFERENCES public.'.$parent
                .' ('.implode(', ', $references).')'
                .' ON UPDATE RESTRICT ON DELETE RESTRICT'
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

        DB::statement(
            'ALTER TABLE public.assessment_reissue_requests '
            .'DROP CONSTRAINT assessment_requests_school_exam_id_uq'
        );
        DB::statement(
            'ALTER TABLE public.assessment_reissue_requests '
            .'DROP CONSTRAINT assessment_requests_school_id_uq'
        );
        DB::statement(
            'ALTER TABLE public.exams '
            .'DROP CONSTRAINT assessment_exams_school_id_uq'
        );
    }

    private function relationships(): array
    {
        $relationships = [
            [
                'assessment_request_exam_tenant_fk',
                'assessment_reissue_requests',
                ['school_id', 'exam_id'],
                'exams',
                ['school_id', 'id'],
            ],
            [
                'assessment_event_request_tenant_fk',
                'assessment_reissue_events',
                ['school_id', 'request_id'],
                'assessment_reissue_requests',
                ['school_id', 'id'],
            ],
            [
                'assessment_draft_request_exam_fk',
                'assessment_reissue_drafts',
                ['school_id', 'exam_id', 'request_id'],
                'assessment_reissue_requests',
                ['school_id', 'exam_id', 'id'],
            ],
            [
                'assessment_version_request_exam_fk',
                'assessment_publication_versions',
                ['school_id', 'exam_id', 'reissue_request_id'],
                'assessment_reissue_requests',
                ['school_id', 'exam_id', 'id'],
            ],
        ];

        foreach ([
            'assessment_reissue_drafts' => 'draft',
            'assessment_publication_versions' => 'version',
        ] as $table => $label) {
            $relationships[] = [
                'assessment_'.$label.'_exam_tenant_fk',
                $table,
                ['school_id', 'exam_id'],
                'exams',
                ['school_id', 'id'],
            ];
            $relationships[] = [
                'assessment_'.$label.'_learner_tenant_fk',
                $table,
                ['school_id', 'learner_id'],
                'learners',
                ['school_id', 'id'],
            ];
        }

        foreach ([
            ['assessment_reissue_requests', 'requested_by', 'request_requester'],
            ['assessment_reissue_requests', 'decided_by', 'request_decider'],
            ['assessment_reissue_requests', 'reviewed_by', 'request_reviewer'],
            ['assessment_reissue_requests', 'published_by', 'request_publisher'],
            ['assessment_reissue_events', 'actor_id', 'event_actor'],
            ['assessment_reissue_drafts', 'prepared_by', 'draft_preparer'],
            ['assessment_publication_versions', 'published_by', 'version_publisher'],
        ] as [$table, $column, $label]) {
            $relationships[] = [
                'assessment_'.$label.'_tenant_fk',
                $table,
                ['school_id', $column],
                'users',
                ['school_id', 'id'],
            ];
        }

        return $relationships;
    }
};
