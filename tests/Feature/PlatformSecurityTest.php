<?php

namespace Tests\Feature;

use App\Http\Middleware\ModulePermissionMiddleware;
use App\Models\Grade;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private string $schoolId;

    private string $otherSchoolId;

    private string $roleId;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->schoolId = (string) Str::uuid();
        $this->otherSchoolId = (string) Str::uuid();
        DB::table('schools')->insert([
            [
                'id' => $this->schoolId,
                'school_name' => 'Platform Security School',
                'school_code' => 'PSS-'.strtoupper(Str::random(8)),
                'active' => true,
                'is_deleted' => false,
            ],
            [
                'id' => $this->otherSchoolId,
                'school_name' => 'Platform Security Other School',
                'school_code' => 'PSO-'.strtoupper(Str::random(8)),
                'active' => true,
                'is_deleted' => false,
            ],
        ]);

        $this->roleId = DB::table('roles')
            ->where('role_name', 'Teacher')
            ->value('id');

        if (! $this->roleId) {
            throw new \RuntimeException('Required migrated Teacher role was not found.');
        }

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
            ['id' => (string) Str::uuid(), 'school_id' => $this->schoolId, 'grade_name' => 'Own grade', 'grade_order' => 1],
            ['id' => (string) Str::uuid(), 'school_id' => $this->otherSchoolId, 'grade_name' => 'Foreign grade', 'grade_order' => 2],
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
