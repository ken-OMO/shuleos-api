<?php

namespace Tests\Feature;

use App\Services\Teaching\SchemeLessonService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SchemeLessonTest extends TestCase
{
    private array $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSchema();
        foreach (['school', 'year', 'term', 'scheme', 'week'] as $n) {
            $this->id[$n] = (string) Str::uuid();
        }DB::table('schemes_of_work')->insert(['id' => $this->id['scheme'], 'school_id' => $this->id['school'], 'academic_year_id' => $this->id['year'], 'term_id' => $this->id['term'], 'active' => true, 'is_deleted' => false]);
        DB::table('academic_weeks')->insert(['id' => $this->id['week'], 'school_id' => $this->id['school'], 'academic_year_id' => $this->id['year'], 'term_id' => $this->id['term'], 'active' => true]);
    }

    public function test_it_creates_a_lesson_in_the_scheme_period(): void
    {
        $lesson = app(SchemeLessonService::class)->create($this->data(), $this->id['school']);
        $this->assertSame(1, $lesson->lesson_number);
        $this->assertDatabaseHas('scheme_lessons', ['scheme_id' => $this->id['scheme'], 'lesson_number' => 1]);
    }

    public function test_it_rejects_a_week_outside_the_scheme_period(): void
    {
        $bad = (string) Str::uuid();
        DB::table('academic_weeks')->insert(['id' => $bad, 'school_id' => $this->id['school'], 'academic_year_id' => $this->id['year'], 'term_id' => (string) Str::uuid(), 'active' => true]);
        $this->expectException(ValidationException::class);
        app(SchemeLessonService::class)->create($this->data(['week_id' => $bad]), $this->id['school']);
    }

    private function data(array $x = []): array
    {
        return [...['scheme_id' => $this->id['scheme'], 'week_id' => $this->id['week'], 'lesson_number' => 1, 'strand' => 'Numbers', 'sub_strand' => 'Whole numbers', 'specific_learning_outcome' => 'Count objects', 'learning_experience' => 'Guided counting', 'resources' => null, 'assessment_method' => 'Observation'], ...$x];
    }

    private function makeSchema(): void
    {
        foreach (['scheme_lessons', 'academic_weeks', 'schemes_of_work'] as $t) {
            Schema::dropIfExists($t);
        }Schema::create('schemes_of_work', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->boolean('active');
            $t->boolean('is_deleted');
        });
        Schema::create('academic_weeks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->boolean('active');
        });
        Schema::create('scheme_lessons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('scheme_id');
            $t->uuid('week_id');
            $t->integer('lesson_number');
            $t->string('strand');
            $t->string('sub_strand');
            $t->text('specific_learning_outcome');
            $t->text('learning_experience');
            $t->text('resources')->nullable();
            $t->text('assessment_method')->nullable();
            $t->boolean('is_deleted');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
            $t->unique(['scheme_id', 'lesson_number']);
        });
    }
}
