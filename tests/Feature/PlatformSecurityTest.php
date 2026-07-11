<?php

namespace Tests\Feature;

use App\Http\Middleware\ModulePermissionMiddleware;
use App\Models\Grade;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformSecurityTest extends TestCase
{
    private string $schoolId;

    private string $otherSchoolId;

    private string $roleId;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        foreach (['role_permissions', 'permissions', 'roles', 'grades'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('role_name');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('permission_name');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->uuid('permission_id');
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->string('grade_name');
        });

        $this->schoolId = (string) Str::uuid();
        $this->otherSchoolId = (string) Str::uuid();
        $this->roleId = (string) Str::uuid();

        DB::table('roles')->insert(['id' => $this->roleId, 'role_name' => 'Teacher']);

        Auth::setUser(new User([
            'id' => (string) Str::uuid(),
            'school_id' => $this->schoolId,
            'role_id' => $this->roleId,
        ]));
    }

    protected function tearDown(): void
    {
        Auth::forgetUser();
        parent::tearDown();
    }

    public function test_tenant_models_hide_other_school_records(): void
    {
        DB::table('grades')->insert([
            ['id' => (string) Str::uuid(), 'school_id' => $this->schoolId, 'grade_name' => 'Own grade'],
            ['id' => (string) Str::uuid(), 'school_id' => $this->otherSchoolId, 'grade_name' => 'Foreign grade'],
        ]);

        $this->assertSame(['Own grade'], Grade::pluck('grade_name')->all());
    }

    public function test_module_permission_allows_a_granted_capability(): void
    {
        $permissionId = (string) Str::uuid();
        DB::table('permissions')->insert(['id' => $permissionId, 'permission_name' => 'create_exam']);
        DB::table('role_permissions')->insert(['role_id' => $this->roleId, 'permission_id' => $permissionId]);

        $response = app(ModulePermissionMiddleware::class)->handle(
            Request::create('/api/exams', 'GET'),
            fn () => response('allowed')
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_module_permission_denies_a_missing_capability(): void
    {
        $response = app(ModulePermissionMiddleware::class)->handle(
            Request::create('/api/exams', 'GET'),
            fn () => response('allowed')
        );

        $this->assertSame(403, $response->getStatusCode());
    }
}
