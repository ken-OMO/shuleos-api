<?php

namespace Tests\Feature;

use App\Services\Teaching\CurriculumCoverageService;
use App\Services\Teaching\LessonPlanService;
use App\Services\Teaching\RecordOfWorkService;
use App\Services\Teaching\SchemeLessonService;
use App\Services\Teaching\SchemeOfWorkService;
use App\Services\Teaching\TeacherAssignmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\EducationLevelBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearningAreaAllocationBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TeacherBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class CurriculumCoverageTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $user;

    private object $grade;

    private object $otherGrade;

    private object $scheme;

    private object $assignment;

    private object $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Teacher',
        ]);

        $this->user = UserBuilder::create(
            $this->school,
            $role
        );

        $teacher = TeacherBuilder::create(
            $this->school,
            $this->user
        );

        $educationLevel = EducationLevelBuilder::create([
            'level_name' => 'Junior School '.Str::random(8),
            'level_order' => 3,
        ]);

        $this->grade = GradeBuilder::create(
            $this->school,
            $educationLevel,
            [
                'grade_name' => 'Grade 9',
                'grade_order' => 9,
            ]
        );

        $this->otherGrade = GradeBuilder::create(
            $this->school,
            $educationLevel,
            [
                'grade_name' => 'Grade 8',
                'grade_order' => 8,
            ]
        );

        $stream = StreamBuilder::create(
            $this->school,
            $this->grade
        );

        $learningArea = LearningAreaBuilder::create([
            'learning_area_name' => 'Kiswahili',
            'short_name' => 'KIS',
        ]);

        LearningAreaAllocationBuilder::create(
            $this->school,
            $this->grade,
            $learningArea
        );

        $academicYear = AcademicYearBuilder::create(
            $this->school
        );

        $term = TermBuilder::create(
            $this->school,
            $academicYear,
            [
                'term_name' => 'Term 3',
                'start_date' => '2026-09-01',
                'end_date' => '2026-11-30',
            ]
        );

        $this->assignment = app(
            TeacherAssignmentService::class
        )->create(
            [
                'teacher_id' => $teacher->id,
                'learning_area_id' => $learningArea->id,
                'grade_id' => $this->grade->id,
                'stream_id' => $stream->id,
                'academic_year_id' => $academicYear->id,
                'term_id' => $term->id,
                'lessons_per_week' => 5,
            ],
            $this->school->id
        );

        $this->scheme = app(
            SchemeOfWorkService::class
        )->create(
            [
                'learning_area_id' => $learningArea->id,
                'grade_id' => $this->grade->id,
                'academic_year_id' => $academicYear->id,
                'term_id' => $term->id,
                'title' => 'Azimio la Kazi ya Kiswahili Gredi ya Tisa Muhula wa Tatu',
            ],
            $this->school->id,
            $this->user->id
        );

        $weekId = (string) Str::uuid();

        DB::table('academic_weeks')->insert([
            'id' => $weekId,
            'school_id' => $this->school->id,
            'academic_year_id' => $academicYear->id,
            'term_id' => $term->id,
            'week_number' => 3,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'active' => true,
        ]);

        $schemeLesson = app(
            SchemeLessonService::class
        )->create(
            [
                'scheme_id' => $this->scheme->id,
                'week_id' => $weekId,
                'lesson_number' => 1,
                'strand' => 'Sarufi',
                'sub_strand' => 'Nomino',
                'specific_learning_outcome' => 'Mwanafunzi aweze kutambua na kutumia nomino kwa usahihi katika sentensi.',
                'learning_experience' => 'Wanafunzi watatambua nomino, watajadili mifano na kutunga sentensi.',
                'resources' => 'Kitabu cha Kiswahili, ubao na kadi za maneno.',
                'assessment_method' => 'Maswali ya mdomo na zoezi la kuandika.',
            ],
            $this->school->id
        );

        $lessonPlan = app(
            LessonPlanService::class
        )->create(
            [
                'teacher_assignment_id' => $this->assignment->id,
                'scheme_lesson_id' => $schemeLesson->id,
                'lesson_date' => now()->subDay()->toDateString(),
                'introduction' => 'Mwalimu atawauliza wanafunzi maswali kuhusu nomino.',
                'lesson_development' => 'Mwalimu atawaongoza wanafunzi kutambua na kutumia nomino katika sentensi.',
                'conclusion' => 'Mwalimu atafanya muhtasari wa matumizi ya nomino.',
                'reflection' => 'Wanafunzi wengi waliweza kutumia nomino kwa usahihi.',
            ],
            $this->school->id,
            $this->user->id
        );

        $lessonPlanService = app(LessonPlanService::class);

        $lessonPlanService->transition(
            $lessonPlan,
            'submitted'
        );

        $lessonPlanService->transition(
            $lessonPlan->fresh(),
            'approved'
        );

        $this->record = app(
            RecordOfWorkService::class
        )->create(
            [
                'lesson_plan_id' => $lessonPlan->id,
                'date_taught' => now()->toDateString(),
                'content_covered' => 'Wanafunzi walijifunza kutambua na kutumia nomino katika sentensi.',
                'learner_response' => 'Wanafunzi walishiriki kikamilifu na kutoa mifano sahihi ya nomino.',
                'teacher_reflection' => 'Somo lilieleweka vizuri na wanafunzi wachache watahitaji mazoezi zaidi.',
            ],
            $this->school->id,
            $this->user->id
        );
    }

    public function test_it_derives_coverage_from_the_record_chain(): void
    {
        $coverage = app(
            CurriculumCoverageService::class
        )->create(
            $this->record->id,
            $this->school->id
        );

        $this->assertSame(
            $this->assignment->id,
            $coverage->teacher_assignment_id
        );

        $this->assertSame(
            3,
            $coverage->week_number
        );

        $this->assertSame(
            'Sarufi',
            $coverage->strand
        );

        $this->assertSame(
            'Nomino',
            $coverage->sub_strand
        );

        $this->assertTrue(
            $coverage->completed
        );
    }

    public function test_it_rejects_an_inconsistent_chain(): void
    {
        DB::table('schemes_of_work')
            ->where('id', $this->scheme->id)
            ->update([
                'grade_id' => $this->otherGrade->id,
            ]);

        $this->expectException(
            ValidationException::class
        );

        app(CurriculumCoverageService::class)->create(
            $this->record->id,
            $this->school->id
        );
    }

    public function test_it_rejects_duplicate_coverage(): void
    {
        $service = app(
            CurriculumCoverageService::class
        );

        $service->create(
            $this->record->id,
            $this->school->id
        );

        $this->expectException(
            ValidationException::class
        );

        $service->create(
            $this->record->id,
            $this->school->id
        );
    }
}
