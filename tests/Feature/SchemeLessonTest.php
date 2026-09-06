<?php

namespace Tests\Feature;

use App\Services\Teaching\SchemeLessonService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\EducationLevelBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearningAreaAllocationBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\TestCase;

class SchemeLessonTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $academicYear;

    private object $term;

    private object $scheme;

    private object $week;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();

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

        $learningArea = LearningAreaBuilder::create([
            'learning_area_name' => 'Kiswahili',
            'short_name' => 'KIS',
        ]);

        LearningAreaAllocationBuilder::create(
            $this->school,
            $grade,
            $learningArea
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

        $this->scheme = (object) [
            'id' => (string) Str::uuid(),
        ];

        DB::table('schemes_of_work')->insert([
            'id' => $this->scheme->id,
            'school_id' => $this->school->id,
            'learning_area_id' => $learningArea->id,
            'grade_id' => $grade->id,
            'academic_year_id' => $this->academicYear->id,
            'term_id' => $this->term->id,
            'title' => 'Azimio la Kazi ya Kiswahili Gredi ya Tisa Muhula wa Tatu',
            'active' => true,
            'is_deleted' => false,
        ]);

        $this->week = (object) [
            'id' => (string) Str::uuid(),
        ];

        DB::table('academic_weeks')->insert([
            'id' => $this->week->id,
            'school_id' => $this->school->id,
            'academic_year_id' => $this->academicYear->id,
            'term_id' => $this->term->id,
            'week_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-05',
            'active' => true,
        ]);
    }

    public function test_it_creates_a_lesson_in_the_scheme_period(): void
    {
        $lesson = app(SchemeLessonService::class)->create(
            $this->data(),
            $this->school->id
        );

        $this->assertSame(1, $lesson->lesson_number);
        $this->assertSame('Sarufi', $lesson->strand);
        $this->assertSame('Nomino', $lesson->sub_strand);

        $this->assertDatabaseHas('scheme_lessons', [
            'scheme_id' => $this->scheme->id,
            'lesson_number' => 1,
            'strand' => 'Sarufi',
            'sub_strand' => 'Nomino',
        ]);
    }

    public function test_it_rejects_a_week_outside_the_scheme_period(): void
    {
        $otherTerm = TermBuilder::create(
            $this->school,
            $this->academicYear,
            [
                'term_name' => 'Term 2',
                'start_date' => '2026-05-01',
                'end_date' => '2026-08-01',
            ]
        );

        $badWeek = (string) Str::uuid();

        DB::table('academic_weeks')->insert([
            'id' => $badWeek,
            'school_id' => $this->school->id,
            'academic_year_id' => $this->academicYear->id,
            'term_id' => $otherTerm->id,
            'week_number' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-05',
            'active' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(SchemeLessonService::class)->create(
            $this->data([
                'week_id' => $badWeek,
            ]),
            $this->school->id
        );
    }

    private function data(array $overrides = []): array
    {
        return [
            ...[
                'scheme_id' => $this->scheme->id,
                'week_id' => $this->week->id,
                'lesson_number' => 1,
                'strand' => 'Sarufi',
                'sub_strand' => 'Nomino',
                'specific_learning_outcome' => 'Mwanafunzi aweze kutambua na kutumia nomino kwa usahihi katika sentensi.',
                'learning_experience' => 'Wanafunzi watatambua nomino katika sentensi, watajadili mifano kwa vikundi na kuunda sentensi zao wenyewe.',
                'resources' => 'Kitabu cha Kiswahili, ubao, kadi za maneno na picha.',
                'assessment_method' => 'Maswali ya mdomo, zoezi la kuandika na uchunguzi wa ushiriki wa mwanafunzi.',
            ],
            ...$overrides,
        ];
    }
}
