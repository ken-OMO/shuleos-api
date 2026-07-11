<?php

namespace Tests\Feature;

use App\Services\Assessment\ExamResultService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExamResultTest extends TestCase
{
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['exam_results', 'learners', 'exam_papers', 'exam_learning_areas', 'exams'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('exams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->string('status');
            $table->boolean('is_deleted');
        });

        Schema::create('exam_learning_areas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('exam_id');
            $table->uuid('learning_area_id');
            $table->boolean('is_deleted');
        });

        Schema::create('exam_papers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('exam_learning_area_id');
            $table->integer('max_marks');
            $table->boolean('is_deleted');
        });

        Schema::create('learners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->boolean('active');
            $table->boolean('is_deleted');
        });

        Schema::create('exam_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('exam_id');
            $table->uuid('learner_id');
            $table->uuid('learning_area_id');
            $table->uuid('paper_id');
            $table->decimal('marks', 8, 2);
            $table->uuid('entered_by')->nullable();
            $table->boolean('is_deleted');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        foreach (['school', 'exam', 'area', 'exam_area', 'paper', 'learner'] as $name) {
            $this->ids[$name] = (string) Str::uuid();
        }

        DB::table('exams')->insert([
            'id' => $this->ids['exam'],
            'school_id' => $this->ids['school'],
            'status' => 'published',
            'is_deleted' => false,
        ]);

        DB::table('exam_learning_areas')->insert([
            'id' => $this->ids['exam_area'],
            'exam_id' => $this->ids['exam'],
            'learning_area_id' => $this->ids['area'],
            'is_deleted' => false,
        ]);

        DB::table('exam_papers')->insert([
            'id' => $this->ids['paper'],
            'exam_learning_area_id' => $this->ids['exam_area'],
            'max_marks' => 100,
            'is_deleted' => false,
        ]);

        DB::table('learners')->insert([
            'id' => $this->ids['learner'],
            'school_id' => $this->ids['school'],
            'active' => true,
            'is_deleted' => false,
        ]);
    }

    public function test_it_derives_exam_and_learning_area_from_the_paper(): void
    {
        $result = app(ExamResultService::class)->create([
            'learner_id' => $this->ids['learner'],
            'paper_id' => $this->ids['paper'],
            'marks' => 78,
        ], $this->ids['school'], null);

        $this->assertSame($this->ids['exam'], $result->exam_id);
        $this->assertSame($this->ids['area'], $result->learning_area_id);
        $this->assertSame('78.00', $result->marks);
    }

    public function test_it_rejects_marks_above_the_paper_maximum(): void
    {
        $this->expectException(ValidationException::class);

        app(ExamResultService::class)->create([
            'learner_id' => $this->ids['learner'],
            'paper_id' => $this->ids['paper'],
            'marks' => 101,
        ], $this->ids['school'], null);
    }

    public function test_it_rejects_duplicate_learner_paper_results(): void
    {
        $service = app(ExamResultService::class);
        $data = [
            'learner_id' => $this->ids['learner'],
            'paper_id' => $this->ids['paper'],
            'marks' => 70,
        ];

        $service->create($data, $this->ids['school'], null);

        $this->expectException(ValidationException::class);
        $service->create($data, $this->ids['school'], null);
    }

    public function test_it_rejects_cross_school_learners(): void
    {
        DB::table('learners')->where('id', $this->ids['learner'])->update([
            'school_id' => (string) Str::uuid(),
        ]);

        $this->expectException(ValidationException::class);

        app(ExamResultService::class)->create([
            'learner_id' => $this->ids['learner'],
            'paper_id' => $this->ids['paper'],
            'marks' => 65,
        ], $this->ids['school'], null);
    }
}
