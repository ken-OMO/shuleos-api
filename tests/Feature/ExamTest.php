<?php

namespace Tests\Feature;

use App\Services\Assessment\ExamService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExamTest extends TestCase
{
    private array $id;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['exams', 'terms', 'academic_years', 'assessment_types'] as $t) {
            Schema::dropIfExists($t);
        }Schema::create('assessment_types', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->boolean('active');
            $t->boolean('is_deleted');
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
            $t->date('start_date');
            $t->date('end_date');
            $t->boolean('active');
        });
        Schema::create('exams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('exam_name');
            $t->uuid('assessment_type_id');
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->date('start_date');
            $t->date('end_date');
            $t->boolean('active');
            $t->string('status');
            $t->uuid('created_by')->nullable();
            $t->boolean('is_deleted');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
        });
        foreach (['school', 'type', 'year', 'term'] as $n) {
            $this->id[$n] = (string) Str::uuid();
        }DB::table('assessment_types')->insert(['id' => $this->id['type'], 'school_id' => $this->id['school'], 'active' => true, 'is_deleted' => false]);
        DB::table('academic_years')->insert(['id' => $this->id['year'], 'school_id' => $this->id['school'], 'active' => true]);
        DB::table('terms')->insert(['id' => $this->id['term'], 'school_id' => $this->id['school'], 'academic_year_id' => $this->id['year'], 'start_date' => '2026-05-01', 'end_date' => '2026-08-01', 'active' => true]);
    }

    public function test_it_creates_a_draft_exam_in_the_term(): void
    {
        $e = app(ExamService::class)->create($this->data(), $this->id['school'], null);
        $this->assertSame('draft', $e->status);
        $this->assertSame($this->id['term'], $e->term_id);
    }

    public function test_it_rejects_dates_outside_the_term(): void
    {
        $this->expectException(ValidationException::class);
        app(ExamService::class)->create([...$this->data(), 'start_date' => '2026-08-02', 'end_date' => '2026-08-03'], $this->id['school'], null);
    }

    public function test_it_enforces_exam_lifecycle(): void
    {
        $s = app(ExamService::class);
        $e = $s->create($this->data(), $this->id['school'], null);
        $s->transition($e, 'published');
        $this->assertSame('published', $e->fresh()->status);
        $this->expectException(ValidationException::class);
        $s->transition($e->fresh(), 'draft');
    }

    private function data(): array
    {
        return ['exam_name' => 'Term Two Exam', 'assessment_type_id' => $this->id['type'], 'academic_year_id' => $this->id['year'], 'term_id' => $this->id['term'], 'start_date' => '2026-07-20', 'end_date' => '2026-07-25'];
    }
}
