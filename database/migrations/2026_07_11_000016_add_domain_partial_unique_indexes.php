<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE UNIQUE INDEX teacher_assignments_current_unique ON teacher_assignments (school_id, teacher_id, learning_area_id, grade_id, COALESCE(stream_id, '00000000-0000-0000-0000-000000000000'::uuid), academic_year_id, term_id) WHERE is_deleted = false");
        DB::statement('CREATE UNIQUE INDEX schemes_of_work_current_unique ON schemes_of_work (school_id, learning_area_id, grade_id, academic_year_id, term_id) WHERE is_deleted = false');
        DB::statement('CREATE UNIQUE INDEX assessment_types_current_name_unique ON assessment_types (school_id, LOWER(assessment_type_name)) WHERE is_deleted = false');
        DB::statement('CREATE UNIQUE INDEX assessment_registrations_current_learner_unique ON assessment_registrations (school_id, learner_id, assessment_year, LOWER(assessment_type)) WHERE is_deleted = false');
        DB::statement('CREATE UNIQUE INDEX assessment_registrations_candidate_unique ON assessment_registrations (school_id, assessment_year, candidate_number) WHERE is_deleted = false');
        DB::statement('CREATE UNIQUE INDEX assessment_registrations_number_unique ON assessment_registrations (school_id, assessment_year, registration_number) WHERE is_deleted = false');
        DB::statement('CREATE UNIQUE INDEX exams_current_name_unique ON exams (school_id, academic_year_id, term_id, LOWER(exam_name)) WHERE is_deleted = false');
        DB::statement('CREATE UNIQUE INDEX exam_learning_areas_current_unique ON exam_learning_areas (exam_id, learning_area_id) WHERE is_deleted = false');
        DB::statement('CREATE UNIQUE INDEX exam_papers_current_number_unique ON exam_papers (exam_learning_area_id, paper_number) WHERE is_deleted = false');
        DB::statement('CREATE UNIQUE INDEX exam_results_current_learner_paper_unique ON exam_results (learner_id, paper_id) WHERE is_deleted = false');
        DB::statement('CREATE UNIQUE INDEX mark_entry_permissions_current_role_unique ON mark_entry_permissions (exam_id, LOWER(role_name)) WHERE is_deleted = false');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'teacher_assignments_current_unique',
            'schemes_of_work_current_unique',
            'assessment_types_current_name_unique',
            'assessment_registrations_current_learner_unique',
            'assessment_registrations_candidate_unique',
            'assessment_registrations_number_unique',
            'exams_current_name_unique',
            'exam_learning_areas_current_unique',
            'exam_papers_current_number_unique',
            'exam_results_current_learner_paper_unique',
            'mark_entry_permissions_current_role_unique',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
