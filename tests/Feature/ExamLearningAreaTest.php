<?php

namespace Tests\Feature;

use App\Services\Assessment\ExamLearningAreaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExamLearningAreaTest extends TestCase
{
    private array $id;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['exam_learning_areas', 'learning_areas', 'exams'] as $t) {
            Schema::dropIfExists($t);
        }Schema::create('exams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('status');
            $t->boolean('active');
            $t->boolean('is_deleted');
        });
        Schema::create('learning_areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->boolean('active');
        });
        Schema::create('exam_learning_areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('exam_id');
            $t->uuid('learning_area_id');
            $t->integer('number_of_papers');
            $t->integer('total_marks');
            $t->boolean('is_deleted');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
        });
        foreach (['school', 'exam', 'area'] as $n) {
            $this->id[$n] = (string) Str::uuid();
        }DB::table('exams')->insert(['id' => $this->id['exam'], 'school_id' => $this->id['school'], 'status' => 'draft', 'active' => true, 'is_deleted' => false]);
        DB::table('learning_areas')->insert(['id' => $this->id['area'], 'active' => true]);
    }

    public function test_it_attaches_an_active_area_to_a_draft_exam(): void
    {
        $x = app(ExamLearningAreaService::class)->create($this->data(), $this->id['school']);
        $this->assertSame(2, $x->number_of_papers);
        $this->assertSame(100, $x->total_marks);
    }

    public function test_it_rejects_duplicate_exam_areas(): void
    {
        $s = app(ExamLearningAreaService::class);
        $s->create($this->data(), $this->id['school']);
        $this->expectException(ValidationException::class);
        $s->create($this->data(), $this->id['school']);
    }

    public function test_it_rejects_cross_school_exams(): void
    {
        $this->expectException(ValidationException::class);
        app(ExamLearningAreaService::class)->create($this->data(), (string) Str::uuid());
    }

    private function data(): array
    {
        return ['exam_id' => $this->id['exam'], 'learning_area_id' => $this->id['area'], 'number_of_papers' => 2, 'total_marks' => 100];
    }
}
