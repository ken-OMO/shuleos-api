<?php

namespace Tests\Feature;

use App\Services\Teaching\RecordOfWorkService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecordOfWorkTest extends TestCase
{
    private array $id;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['records_of_work', 'lesson_plans'] as $t) {
            Schema::dropIfExists($t);
        }Schema::create('lesson_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('status');
            $t->boolean('is_deleted');
        });
        Schema::create('records_of_work', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('lesson_plan_id')->unique();
            $t->date('date_taught');
            $t->text('content_covered');
            $t->text('learner_response')->nullable();
            $t->text('teacher_reflection')->nullable();
            $t->string('status');
            $t->uuid('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->boolean('is_deleted');
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
        });
        $this->id = ['school' => (string) Str::uuid(), 'plan' => (string) Str::uuid()];
        DB::table('lesson_plans')->insert(['id' => $this->id['plan'], 'school_id' => $this->id['school'], 'status' => 'approved', 'is_deleted' => false]);
    }

    public function test_it_records_work_for_an_approved_plan(): void
    {
        $x = app(RecordOfWorkService::class)->create($this->data(), $this->id['school'], null);
        $this->assertSame('completed', $x->status);
        $this->assertDatabaseHas('records_of_work', ['lesson_plan_id' => $this->id['plan']]);
    }

    public function test_it_rejects_duplicate_records(): void
    {
        $s = app(RecordOfWorkService::class);
        $s->create($this->data(), $this->id['school'], null);
        $this->expectException(ValidationException::class);
        $s->create($this->data(), $this->id['school'], null);
    }

    public function test_it_rejects_cross_school_plans(): void
    {
        $this->expectException(ValidationException::class);
        app(RecordOfWorkService::class)->create($this->data(), (string) Str::uuid(), null);
    }

    public function test_it_rejects_future_taught_dates(): void
    {
        $this->expectException(ValidationException::class);
        app(RecordOfWorkService::class)->create([...$this->data(), 'date_taught' => now()->addDay()->toDateString()], $this->id['school'], null);
    }

    private function data(): array
    {
        return ['lesson_plan_id' => $this->id['plan'], 'date_taught' => now()->toDateString(), 'content_covered' => 'Whole numbers and place value.'];
    }
}
