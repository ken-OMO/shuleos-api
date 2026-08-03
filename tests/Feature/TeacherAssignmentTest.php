<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherAssignmentTest extends TestCase
{
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->createSchema();
        $this->seedContext();
    }

    public function test_it_creates_a_valid_teacher_assignment(): void
    {
        $response = $this->postJson('/api/teacher-assignments', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.school_id', $this->ids['school'])
            ->assertJsonPath('data.teacher_id', $this->ids['teacher']);

        $this->assertDatabaseHas('teacher_assignments', [
            'teacher_id' => $this->ids['teacher'],
            'is_deleted' => false,
        ]);
    }

    public function test_it_rejects_a_duplicate_assignment(): void
    {
        $this->postJson('/api/teacher-assignments', $this->payload())->assertCreated();

        $this->postJson('/api/teacher-assignments', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignment');
    }

    public function test_it_enforces_one_class_teacher_per_stream_and_term(): void
    {
        $this->postJson('/api/teacher-assignments', $this->payload(['is_class_teacher' => true]))->assertCreated();

        $secondTeacher = (string) Str::uuid();
        DB::table('teachers')->insert([
            'id' => $secondTeacher, 'school_id' => $this->ids['school'], 'user_id' => (string) Str::uuid(),
            'active' => true, 'is_deleted' => false,
        ]);

        $this->postJson('/api/teacher-assignments', $this->payload([
            'teacher_id' => $secondTeacher,
            'is_class_teacher' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors('is_class_teacher');
    }

    private function payload(array $overrides = []): array
    {
        return [...[
            'school_id' => $this->ids['school'],
            'teacher_id' => $this->ids['teacher'],
            'learning_area_id' => $this->ids['area'],
            'grade_id' => $this->ids['grade'],
            'stream_id' => $this->ids['stream'],
            'academic_year_id' => $this->ids['year'],
            'term_id' => $this->ids['term'],
            'lessons_per_week' => 5,
        ], ...$overrides];
    }

    private function seedContext(): void
    {
        $this->ids = array_map(fn () => (string) Str::uuid(), array_flip(['school', 'teacher', 'area', 'grade', 'stream', 'year', 'term']));
        DB::table('schools')->insert(['id' => $this->ids['school']]);
        DB::table('teachers')->insert(['id' => $this->ids['teacher'], 'school_id' => $this->ids['school'], 'user_id' => (string) Str::uuid(), 'active' => true, 'is_deleted' => false]);
        DB::table('learning_areas')->insert(['id' => $this->ids['area'], 'learning_area_name' => 'Mathematics']);
        DB::table('grades')->insert(['id' => $this->ids['grade'], 'school_id' => $this->ids['school'], 'active' => true]);
        DB::table('streams')->insert(['id' => $this->ids['stream'], 'school_id' => $this->ids['school'], 'grade_id' => $this->ids['grade'], 'active' => true]);
        DB::table('academic_years')->insert(['id' => $this->ids['year'], 'school_id' => $this->ids['school'], 'active' => true]);
        DB::table('terms')->insert(['id' => $this->ids['term'], 'school_id' => $this->ids['school'], 'academic_year_id' => $this->ids['year'], 'active' => true]);
        DB::table('learning_area_allocations')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->ids['school'], 'grade_id' => $this->ids['grade'], 'learning_area_id' => $this->ids['area'], 'active' => true]);
    }

    private function createSchema(): void
    {
        foreach (['teacher_assignments', 'learning_area_allocations', 'terms', 'academic_years', 'streams', 'grades', 'learning_areas', 'teachers', 'schools'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('schools', fn (Blueprint $t) => $t->uuid('id')->primary());
        Schema::create('teachers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('user_id');
            $t->boolean('active');
            $t->boolean('is_deleted');
        });
        Schema::create('learning_areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('learning_area_name');
        });
        Schema::create('grades', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->boolean('active');
        });
        Schema::create('streams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('grade_id');
            $t->boolean('active');
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->boolean('active');
        });
        Schema::create('terms', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('academic_year_id');
            $t->boolean('active');
        });
        Schema::create('learning_area_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('grade_id');
            $t->uuid('learning_area_id');
            $t->boolean('active');
        });
        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('teacher_id');
            $t->uuid('learning_area_id');
            $t->uuid('grade_id');
            $t->uuid('stream_id')->nullable();
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->boolean('is_class_teacher')->default(false);
            $t->integer('lessons_per_week');
            $t->boolean('active')->default(true);
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
        });
    }
}
