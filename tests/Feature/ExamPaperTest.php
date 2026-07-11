<?php

namespace Tests\Feature;

use App\Services\Assessment\ExamPaperService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExamPaperTest extends TestCase
{
    private array $id;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['exam_papers', 'exam_learning_areas', 'exams'] as $t) {
            Schema::dropIfExists($t);
        }Schema::create('exams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('status');
            $t->boolean('is_deleted');
        });
        Schema::create('exam_learning_areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('exam_id');
            $t->integer('number_of_papers');
            $t->integer('total_marks');
            $t->boolean('is_deleted');
        });
        Schema::create('exam_papers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('exam_learning_area_id');
            $t->string('paper_name');
            $t->integer('paper_number');
            $t->integer('max_marks');
            $t->boolean('is_deleted');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
        });
        foreach (['school', 'exam', 'area'] as $n) {
            $this->id[$n] = (string) Str::uuid();
        }DB::table('exams')->insert(['id' => $this->id['exam'], 'school_id' => $this->id['school'], 'status' => 'draft', 'is_deleted' => false]);
        DB::table('exam_learning_areas')->insert(['id' => $this->id['area'], 'exam_id' => $this->id['exam'], 'number_of_papers' => 2, 'total_marks' => 100, 'is_deleted' => false]);
    }

    public function test_it_creates_a_paper_within_declared_limits(): void
    {
        $x = app(ExamPaperService::class)->create($this->data(), $this->id['school']);
        $this->assertSame(1, $x->paper_number);
        $this->assertSame(50, $x->max_marks);
    }

    public function test_it_rejects_duplicate_paper_numbers(): void
    {
        $s = app(ExamPaperService::class);
        $s->create($this->data(), $this->id['school']);
        $this->expectException(ValidationException::class);
        $s->create($this->data(), $this->id['school']);
    }

    public function test_it_rejects_marks_above_subject_total(): void
    {
        $s = app(ExamPaperService::class);
        $s->create($this->data(), $this->id['school']);
        $this->expectException(ValidationException::class);
        $s->create([...$this->data(), 'paper_number' => 2, 'max_marks' => 60], $this->id['school']);
    }

    private function data(): array
    {
        return ['exam_learning_area_id' => $this->id['area'], 'paper_name' => 'Paper 1', 'paper_number' => 1, 'max_marks' => 50];
    }
}
