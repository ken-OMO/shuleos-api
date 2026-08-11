<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\Database\AcademicYearBuilder;
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

class TeacherAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $role;

    private object $teacher;

    private object $learningArea;

    private object $grade;

    private object $stream;

    private object $academicYear;

    private object $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->school = SchoolBuilder::create();

        $this->role = RoleBuilder::create();

        $user = UserBuilder::create(
            $this->school,
            $this->role
        );

        $this->teacher = TeacherBuilder::create(
            $this->school,
            $user
        );

        $this->learningArea = LearningAreaBuilder::create([
            'learning_area_name' => 'Mathematics',
        ]);

        $this->grade = GradeBuilder::create(
            $this->school
        );

        $this->stream = StreamBuilder::create(
            $this->school,
            $this->grade
        );

        $this->academicYear = AcademicYearBuilder::create(
            $this->school
        );

        $this->term = TermBuilder::create(
            $this->school,
            $this->academicYear
        );

        LearningAreaAllocationBuilder::create(
            $this->school,
            $this->grade,
            $this->learningArea,
            [
                'lessons_per_week' => 5,
            ]
        );
    }

    public function test_it_creates_a_valid_teacher_assignment(): void
    {
        $response = $this->postJson(
            '/api/teacher-assignments',
            $this->payload()
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.school_id',
                $this->school->id
            )
            ->assertJsonPath(
                'data.teacher_id',
                $this->teacher->id
            );

        $this->assertDatabaseHas(
            'teacher_assignments',
            [
                'teacher_id' => $this->teacher->id,
                'is_deleted' => false,
            ]
        );
    }

    public function test_it_rejects_a_duplicate_assignment(): void
    {
        $this->postJson(
            '/api/teacher-assignments',
            $this->payload()
        )->assertCreated();

        $this->postJson(
            '/api/teacher-assignments',
            $this->payload()
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'assignment'
            );
    }

    public function test_it_enforces_one_class_teacher_per_stream_and_term(): void
    {
        $this->postJson(
            '/api/teacher-assignments',
            $this->payload([
                'is_class_teacher' => true,
            ])
        )->assertCreated();

        $secondUser = UserBuilder::create(
            $this->school,
            $this->role
        );

        $secondTeacher = TeacherBuilder::create(
            $this->school,
            $secondUser
        );

        $this->postJson(
            '/api/teacher-assignments',
            $this->payload([
                'teacher_id' => $secondTeacher->id,
                'is_class_teacher' => true,
            ])
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'is_class_teacher'
            );
    }

    private function payload(
        array $overrides = []
    ): array {
        return [
            ...[
                'school_id' => $this->school->id,
                'teacher_id' => $this->teacher->id,
                'learning_area_id' => $this->learningArea->id,
                'grade_id' => $this->grade->id,
                'stream_id' => $this->stream->id,
                'academic_year_id' => $this->academicYear->id,
                'term_id' => $this->term->id,
                'lessons_per_week' => 5,
            ],
            ...$overrides,
        ];
    }
}
