<?php

namespace Tests\Feature;

use App\Services\Teaching\LessonNoteService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LessonNoteTest extends TestCase
{
    private array $id;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['lesson_notes', 'lesson_plans'] as $t) {
            Schema::dropIfExists($t);
        }Schema::create('lesson_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->boolean('is_deleted');
        });
        Schema::create('lesson_notes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('lesson_plan_id')->unique();
            $t->text('note_content');
            $t->uuid('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->boolean('is_deleted');
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
        });
        $this->id = ['school' => (string) Str::uuid(), 'plan' => (string) Str::uuid()];
        DB::table('lesson_plans')->insert(['id' => $this->id['plan'], 'school_id' => $this->id['school'], 'is_deleted' => false]);
    }

    public function test_it_creates_one_note_for_a_tenant_plan(): void
    {
        $n = app(LessonNoteService::class)->create(['lesson_plan_id' => $this->id['plan'], 'note_content' => 'Key teaching notes.'], $this->id['school'], null);
        $this->assertSame($this->id['school'], $n->school_id);
        $this->assertDatabaseHas('lesson_notes', ['lesson_plan_id' => $this->id['plan']]);
    }

    public function test_it_rejects_a_duplicate_note(): void
    {
        $s = app(LessonNoteService::class);
        $d = ['lesson_plan_id' => $this->id['plan'], 'note_content' => 'Notes'];
        $s->create($d, $this->id['school'], null);
        $this->expectException(ValidationException::class);
        $s->create($d, $this->id['school'], null);
    }

    public function test_it_rejects_cross_school_access(): void
    {
        $this->expectException(ValidationException::class);
        app(LessonNoteService::class)->create(['lesson_plan_id' => $this->id['plan'], 'note_content' => 'Notes'], (string) Str::uuid(), null);
    }
}
