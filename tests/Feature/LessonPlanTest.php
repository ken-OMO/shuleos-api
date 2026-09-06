<?php

namespace Tests\Feature;

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

class LessonPlanTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $user;

    private object $grade;

    private object $otherGrade;

    private object $academicYear;

    private object $term;

    private object $teacherAssignment;

    private object $scheme;

    private object $schemeLesson;

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
            $learningArea,
            [
                'lessons_per_week' => 5,
            ]
        );

        $this->academicYear = AcademicYearBuilder::create(
            $this->school
        );

        $this->term = TermBuilder::create(
            $this->school,
            $this->academicYear,
            [
                'term_name' => 'Term 3',
                'start_date' => '2026-09-01',
                'end_date' => '2026-11-30',
            ]
        );

        $this->teacherAssignment = app(
            TeacherAssignmentService::class
        )->create(
            [
                'teacher_id' => $teacher->id,
                'learning_area_id' => $learningArea->id,
                'grade_id' => $this->grade->id,
                'stream_id' => $stream->id,
                'academic_year_id' => $this->academicYear->id,
                'term_id' => $this->term->id,
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
                'academic_year_id' => $this->academicYear->id,
                'term_id' => $this->term->id,
                'title' => 'Azimio la Kazi ya Kiswahili Gredi ya Tisa Muhula wa Tatu',
            ],
            $this->school->id,
            $this->user->id
        );

        $weekId = (string) Str::uuid();

        DB::table('academic_weeks')->insert([
            'id' => $weekId,
            'school_id' => $this->school->id,
            'academic_year_id' => $this->academicYear->id,
            'term_id' => $this->term->id,
            'week_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-05',
            'active' => true,
        ]);

        $this->schemeLesson = app(
            SchemeLessonService::class
        )->create(
            [
                'scheme_id' => $this->scheme->id,
                'week_id' => $weekId,
                'lesson_number' => 1,
                'strand' => 'Sarufi',
                'sub_strand' => 'Nomino',
                'specific_learning_outcome' => 'Mwanafunzi aweze kutambua na kutumia nomino kwa usahihi katika sentensi.',
                'learning_experience' => 'Wanafunzi watatambua nomino katika sentensi, watajadili mifano kwa vikundi na kuunda sentensi zao wenyewe.',
                'resources' => 'Kitabu cha Kiswahili, ubao, kadi za maneno na picha.',
                'assessment_method' => 'Maswali ya mdomo, zoezi la kuandika na uchunguzi wa ushiriki wa mwanafunzi.',
            ],
            $this->school->id
        );
    }

    public function test_it_creates_a_compatible_lesson_plan(): void
    {
        $plan = app(LessonPlanService::class)->create(
            $this->data(),
            $this->school->id,
            $this->user->id
        );

        $this->assertSame('draft', $plan->status);

        $this->assertSame(
            'Mwalimu atawauliza wanafunzi maswali kuhusu nomino walizowahi kukutana nazo katika mawasiliano ya kila siku.',
            $plan->introduction
        );

        $this->assertSame(
            'Mwalimu atawaongoza wanafunzi kutambua aina mbalimbali za nomino, kuzitumia katika sentensi na kufanya shughuli za vikundi.',
            $plan->lesson_development
        );

        $this->assertDatabaseHas('lesson_plans', [
            'school_id' => $this->school->id,
            'teacher_assignment_id' => $this->teacherAssignment->id,
            'scheme_lesson_id' => $this->schemeLesson->id,
            'created_by' => $this->user->id,
            'status' => 'draft',
            'is_deleted' => false,
        ]);
    }

    public function test_it_rejects_mismatched_assignment_and_scheme(): void
    {
        DB::table('schemes_of_work')
            ->where('id', $this->scheme->id)
            ->update([
                'grade_id' => $this->otherGrade->id,
            ]);

        $this->expectException(
            ValidationException::class
        );

        app(LessonPlanService::class)->create(
            $this->data(),
            $this->school->id,
            $this->user->id
        );
    }

    public function test_it_rejects_duplicate_plans(): void
    {
        $service = app(LessonPlanService::class);

        $service->create(
            $this->data(),
            $this->school->id,
            $this->user->id
        );

        $this->expectException(
            ValidationException::class
        );

        $service->create(
            $this->data(),
            $this->school->id,
            $this->user->id
        );
    }

    public function test_it_enforces_status_transitions(): void
    {
        $service = app(LessonPlanService::class);

        $plan = $service->create(
            $this->data(),
            $this->school->id,
            $this->user->id
        );

        $service->transition(
            $plan,
            'submitted'
        );

        $this->assertSame(
            'submitted',
            $plan->fresh()->status
        );

        $this->expectException(
            ValidationException::class
        );

        $service->transition(
            $plan->fresh(),
            'draft'
        );
    }

    private function data(): array
    {
        return [
            'teacher_assignment_id' => $this->teacherAssignment->id,
            'scheme_lesson_id' => $this->schemeLesson->id,
            'lesson_date' => '2026-09-03',
            'introduction' => 'Mwalimu atawauliza wanafunzi maswali kuhusu nomino walizowahi kukutana nazo katika mawasiliano ya kila siku.',
            'lesson_development' => 'Mwalimu atawaongoza wanafunzi kutambua aina mbalimbali za nomino, kuzitumia katika sentensi na kufanya shughuli za vikundi.',
            'conclusion' => 'Mwalimu atafanya muhtasari wa matumizi ya nomino na kuwapa wanafunzi nafasi ya kutoa mifano yao.',
            'reflection' => 'Wanafunzi wengi waliweza kutambua na kutumia nomino kwa usahihi; wanaohitaji msaada zaidi watapewa zoezi la ziada.',
        ];
    }
}
