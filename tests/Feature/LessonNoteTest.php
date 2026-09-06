<?php

namespace Tests\Feature;

use App\Services\Teaching\LessonNoteService;
use App\Services\Teaching\LessonPlanService;
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

class LessonNoteTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $user;

    private object $lessonPlan;

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

        $grade = GradeBuilder::create(
            $this->school,
            $educationLevel,
            [
                'grade_name' => 'Grade 9',
                'grade_order' => 9,
            ]
        );

        $stream = StreamBuilder::create(
            $this->school,
            $grade
        );

        $learningArea = LearningAreaBuilder::create([
            'learning_area_name' => 'Kiswahili',
            'short_name' => 'KIS',
        ]);

        LearningAreaAllocationBuilder::create(
            $this->school,
            $grade,
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

        $assignment = app(
            TeacherAssignmentService::class
        )->create(
            [
                'teacher_id' => $teacher->id,
                'learning_area_id' => $learningArea->id,
                'grade_id' => $grade->id,
                'stream_id' => $stream->id,
                'academic_year_id' => $academicYear->id,
                'term_id' => $term->id,
                'lessons_per_week' => 5,
            ],
            $this->school->id
        );

        $scheme = app(
            SchemeOfWorkService::class
        )->create(
            [
                'learning_area_id' => $learningArea->id,
                'grade_id' => $grade->id,
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
            'week_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-05',
            'active' => true,
        ]);

        $schemeLesson = app(
            SchemeLessonService::class
        )->create(
            [
                'scheme_id' => $scheme->id,
                'week_id' => $weekId,
                'lesson_number' => 1,
                'strand' => 'Sarufi',
                'sub_strand' => 'Nomino',
                'specific_learning_outcome' => 'Mwanafunzi aweze kutambua na kutumia nomino kwa usahihi katika sentensi.',
                'learning_experience' => 'Wanafunzi watatambua nomino katika sentensi na kuunda sentensi zao wenyewe.',
                'resources' => 'Kitabu cha Kiswahili, ubao na kadi za maneno.',
                'assessment_method' => 'Maswali ya mdomo na zoezi la kuandika.',
            ],
            $this->school->id
        );

        $this->lessonPlan = app(
            LessonPlanService::class
        )->create(
            [
                'teacher_assignment_id' => $assignment->id,
                'scheme_lesson_id' => $schemeLesson->id,
                'lesson_date' => '2026-09-03',
                'introduction' => 'Mwalimu atawauliza wanafunzi maswali kuhusu nomino.',
                'lesson_development' => 'Mwalimu atawaongoza wanafunzi kutambua na kutumia nomino katika sentensi.',
                'conclusion' => 'Mwalimu atafanya muhtasari wa matumizi ya nomino.',
                'reflection' => 'Wanafunzi wengi waliweza kutumia nomino kwa usahihi.',
            ],
            $this->school->id,
            $this->user->id
        );
    }

    public function test_it_creates_one_note_for_a_tenant_plan(): void
    {
        $note = app(LessonNoteService::class)->create(
            [
                'lesson_plan_id' => $this->lessonPlan->id,
                'note_content' => 'Nomino hutaja watu, vitu, mahali na dhana. Wanafunzi wapewe mifano ya kutosha na washirikishwe katika kutunga sentensi.',
            ],
            $this->school->id,
            $this->user->id
        );

        $this->assertSame(
            $this->school->id,
            $note->school_id
        );

        $this->assertDatabaseHas('lesson_notes', [
            'lesson_plan_id' => $this->lessonPlan->id,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_it_rejects_a_duplicate_note(): void
    {
        $service = app(LessonNoteService::class);

        $data = [
            'lesson_plan_id' => $this->lessonPlan->id,
            'note_content' => 'Maelezo ya somo kuhusu matumizi ya nomino.',
        ];

        $service->create(
            $data,
            $this->school->id,
            $this->user->id
        );

        $this->expectException(
            ValidationException::class
        );

        $service->create(
            $data,
            $this->school->id,
            $this->user->id
        );
    }

    public function test_it_rejects_cross_school_access(): void
    {
        $otherSchool = SchoolBuilder::create();

        $this->expectException(
            ValidationException::class
        );

        app(LessonNoteService::class)->create(
            [
                'lesson_plan_id' => $this->lessonPlan->id,
                'note_content' => 'Maelezo ya somo la Kiswahili.',
            ],
            $otherSchool->id,
            $this->user->id
        );
    }
}
