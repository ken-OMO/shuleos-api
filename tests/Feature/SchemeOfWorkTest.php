<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchemeOfWorkTest extends TestCase
{
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->schema();
        $this->seedContext();
    }

    public function test_it_creates_a_scheme_for_an_allocated_learning_area(): void
    {
        $this->postJson('/api/schemes-of-work', $this->payload())
            ->assertCreated()->assertJsonPath('success', true)
            ->assertJsonPath('data.grade_id', $this->ids['grade']);

        $this->assertDatabaseHas('schemes_of_work', ['title' => 'Mathematics Term One', 'is_deleted' => false]);
    }

    public function test_it_rejects_a_duplicate_scheme_for_the_same_period(): void
    {
        $this->postJson('/api/schemes-of-work', $this->payload())->assertCreated();
        $this->postJson('/api/schemes-of-work', $this->payload(['title' => 'Duplicate']))
            ->assertUnprocessable()->assertJsonValidationErrors('scheme');
    }

    private function payload(array $overrides = []): array
    {
        return [...[
            'school_id' => $this->ids['school'], 'learning_area_id' => $this->ids['area'],
            'grade_id' => $this->ids['grade'], 'academic_year_id' => $this->ids['year'],
            'term_id' => $this->ids['term'], 'title' => 'Mathematics Term One',
        ], ...$overrides];
    }

    private function seedContext(): void
    {
        foreach (['school', 'area', 'grade', 'year', 'term'] as $name) {
            $this->ids[$name] = (string) Str::uuid();
        }
        DB::table('schools')->insert(['id' => $this->ids['school']]);
        DB::table('learning_areas')->insert(['id' => $this->ids['area']]);
        DB::table('grades')->insert(['id' => $this->ids['grade'], 'school_id' => $this->ids['school'], 'active' => true]);
        DB::table('academic_years')->insert(['id' => $this->ids['year'], 'school_id' => $this->ids['school'], 'active' => true]);
        DB::table('terms')->insert(['id' => $this->ids['term'], 'school_id' => $this->ids['school'], 'academic_year_id' => $this->ids['year'], 'active' => true]);
        DB::table('learning_area_allocations')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->ids['school'], 'grade_id' => $this->ids['grade'], 'learning_area_id' => $this->ids['area'], 'active' => true]);
    }

    private function schema(): void
    {
        foreach (['scheme_lessons', 'schemes_of_work', 'learning_area_allocations', 'terms', 'academic_years', 'grades', 'learning_areas', 'users', 'schools'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('schools', fn (Blueprint $t) => $t->uuid('id')->primary());
        Schema::create('users', fn (Blueprint $t) => $t->uuid('id')->primary());
        Schema::create('learning_areas', fn (Blueprint $t) => $t->uuid('id')->primary());
        Schema::create('grades', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
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
        Schema::create('schemes_of_work', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('learning_area_id');
            $t->uuid('grade_id');
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->string('title');
            $t->boolean('active');
            $t->uuid('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
        });
        Schema::create('scheme_lessons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('scheme_id');
        });
    }
}
