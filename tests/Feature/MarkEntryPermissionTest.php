<?php

namespace Tests\Feature;

use App\Services\Assessment\MarkEntryPermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarkEntryPermissionTest extends TestCase
{
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('mark_entry_permissions');
        Schema::dropIfExists('exams');

        Schema::create('exams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->string('status');
            $table->boolean('active');
            $table->boolean('is_deleted');
        });

        Schema::create('mark_entry_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('exam_id');
            $table->string('role_name');
            $table->boolean('active');
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->boolean('is_deleted');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        $this->ids = [
            'school' => (string) Str::uuid(),
            'exam' => (string) Str::uuid(),
        ];

        DB::table('exams')->insert([
            'id' => $this->ids['exam'],
            'school_id' => $this->ids['school'],
            'status' => 'published',
            'active' => true,
            'is_deleted' => false,
        ]);
    }

    public function test_it_grants_a_role_permission_for_a_published_exam(): void
    {
        $permission = app(MarkEntryPermissionService::class)->create([
            'exam_id' => $this->ids['exam'],
            'role_name' => 'Teacher',
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addHour(),
        ], $this->ids['school']);

        $this->assertSame('teacher', $permission->role_name);
        $this->assertTrue($permission->isOpen());
    }

    public function test_it_rejects_duplicate_role_permissions(): void
    {
        $service = app(MarkEntryPermissionService::class);
        $data = ['exam_id' => $this->ids['exam'], 'role_name' => 'teacher'];

        $service->create($data, $this->ids['school']);

        $this->expectException(ValidationException::class);
        $service->create(['exam_id' => $this->ids['exam'], 'role_name' => 'TEACHER'], $this->ids['school']);
    }

    public function test_it_rejects_permissions_for_draft_exams(): void
    {
        DB::table('exams')->where('id', $this->ids['exam'])->update(['status' => 'draft']);

        $this->expectException(ValidationException::class);

        app(MarkEntryPermissionService::class)->create([
            'exam_id' => $this->ids['exam'],
            'role_name' => 'teacher',
        ], $this->ids['school']);
    }
}
