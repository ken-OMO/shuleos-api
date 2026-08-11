<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\EducationLevelBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearningAreaAllocationBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\TestCase;

class SchemeOfWorkTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $grade;

    private object $learningArea;

    private object $academicYear;

    private object $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->school = SchoolBuilder::create();

        $educationLevel = EducationLevelBuilder::create([
            'level_name' => 'Junior School',
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

        $this->learningArea = LearningAreaBuilder::create([
            'learning_area_name' => 'Kiswahili',
            'short_name' => 'KIS',
        ]);

        LearningAreaAllocationBuilder::create(
            $this->school,
            $this->grade,
            $this->learningArea
        );
    }

    public function test_it_creates_a_scheme_for_an_allocated_learning_area(): void
    {
        $this->postJson(
            '/api/schemes-of-work',
            $this->payload()
        )
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.grade_id',
                $this->grade->id
            )
            ->assertJsonPath(
                'data.learning_area_id',
                $this->learningArea->id
            )
            ->assertJsonPath(
                'data.title',
                'Azimio la Kazi ya Kiswahili Gredi ya Tisa Muhula wa Tatu'
            );

        $this->assertDatabaseHas('schemes_of_work', [
            'school_id' => $this->school->id,
            'learning_area_id' => $this->learningArea->id,
            'grade_id' => $this->grade->id,
            'academic_year_id' => $this->academicYear->id,
            'term_id' => $this->term->id,
            'title' => 'Azimio la Kazi ya Kiswahili Gredi ya Tisa Muhula wa Tatu',
            'is_deleted' => false,
        ]);
    }

    public function test_it_rejects_a_duplicate_scheme_for_the_same_period(): void
    {
        $this->postJson(
            '/api/schemes-of-work',
            $this->payload()
        )->assertCreated();

        $this->postJson(
            '/api/schemes-of-work',
            $this->payload([
                'title' => 'Azimio Lingine la Kiswahili',
            ])
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheme');
    }

    private function payload(array $overrides = []): array
    {
        return [
            ...[
                'school_id' => $this->school->id,
                'learning_area_id' => $this->learningArea->id,
                'grade_id' => $this->grade->id,
                'academic_year_id' => $this->academicYear->id,
                'term_id' => $this->term->id,
                'title' => 'Azimio la Kazi ya Kiswahili Gredi ya Tisa Muhula wa Tatu',
            ],
            ...$overrides,
        ];
    }
}
