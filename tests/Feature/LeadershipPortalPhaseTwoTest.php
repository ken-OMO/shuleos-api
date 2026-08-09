<?php

namespace Tests\Feature;

use App\Http\Resources\LeadershipSafeResource;
use App\Models\User;
use App\Services\LeadershipPortal\LeadershipApprovalCentreService;
use App\Services\LeadershipPortal\LeadershipDeviceService;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use App\Services\LeadershipPortal\LeadershipPortalPhaseTwoService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeadershipPortalPhaseTwoTest extends TestCase
{
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['school', 'other_school', 'principal_role', 'hod_role', 'teacher_role', 'principal', 'hod', 'ordinary', 'other_principal', 'hod_teacher', 'department_teacher', 'other_teacher', 'year', 'term', 'grade', 'stream', 'area', 'assignment', 'attendance_session_two'] as $key) {
            $this->ids[$key] = (string) Str::uuid();
        }

        DB::table('schools')->insert([
            ['id' => $this->ids['school'], 'school_name' => 'Phase Two School', 'school_code' => 'L2-'.Str::lower(Str::random(6)), 'active' => true],
            ['id' => $this->ids['other_school'], 'school_name' => 'Other School', 'school_code' => 'O2-'.Str::lower(Str::random(6)), 'active' => true],
        ]);
        foreach (['principal_role' => 'Principal', 'hod_role' => 'HOD', 'teacher_role' => 'Teacher'] as $key => $name) {
            $existing = DB::table('roles')->where('role_name', $name)->value('id');
            if ($existing) {
                $this->ids[$key] = $existing;
            } else {
                DB::table('roles')->insert(['id' => $this->ids[$key], 'role_name' => $name]);
            }
        }
        $this->user('principal', 'principal_role', 'school');
        $this->user('hod', 'hod_role', 'school');
        $this->user('ordinary', 'teacher_role', 'school');
        $this->user('other_principal', 'principal_role', 'other_school');

        DB::table('academic_years')->insert(['id' => $this->ids['year'], 'school_id' => $this->ids['school'], 'year_name' => 'Phase Two Year', 'active' => true]);
        DB::table('terms')->insert(['id' => $this->ids['term'], 'school_id' => $this->ids['school'], 'academic_year_id' => $this->ids['year'], 'term_name' => 'Term 1', 'active' => true]);
        DB::table('grades')->insert(['id' => $this->ids['grade'], 'school_id' => $this->ids['school'], 'grade_name' => 'Grade L2', 'grade_order' => 99, 'active' => true]);
        DB::table('streams')->insert(['id' => $this->ids['stream'], 'school_id' => $this->ids['school'], 'grade_id' => $this->ids['grade'], 'stream_name' => 'L2 Stream', 'active' => true]);
        DB::table('learning_areas')->insert(['id' => $this->ids['area'], 'learning_area_name' => 'Phase Two Area '.Str::random(6), 'active' => true]);

        DB::table('teachers')->insert([
            ['id' => $this->ids['hod_teacher'], 'school_id' => $this->ids['school'], 'user_id' => $this->ids['hod'], 'active' => true, 'is_deleted' => false],
            ['id' => $this->ids['department_teacher'], 'school_id' => $this->ids['school'], 'user_id' => $this->ids['ordinary'], 'active' => true, 'is_deleted' => false],
            ['id' => $this->ids['other_teacher'], 'school_id' => $this->ids['other_school'], 'user_id' => $this->ids['other_principal'], 'active' => true, 'is_deleted' => false],
        ]);
        DB::table('hod_assignments')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->ids['school'], 'teacher_id' => $this->ids['hod_teacher'], 'learning_area_id' => $this->ids['area'], 'academic_year_id' => $this->ids['year'], 'active' => true]);
        DB::table('teacher_assignments')->insert(['id' => $this->ids['assignment'], 'school_id' => $this->ids['school'], 'teacher_id' => $this->ids['department_teacher'], 'learning_area_id' => $this->ids['area'], 'grade_id' => $this->ids['grade'], 'stream_id' => $this->ids['stream'], 'academic_year_id' => $this->ids['year'], 'term_id' => $this->ids['term'], 'lessons_per_week' => 5, 'active' => true, 'is_deleted' => false]);

        $this->grant('principal_role', [
            'access_school_leadership_portal', 'access_leadership_portal_phase_two', 'view_principal_dashboard',
            'view_school_kpis', 'view_teacher_compliance', 'view_teacher_workload', 'view_academic_insights',
            'view_attendance_intelligence', 'view_behaviour_oversight', 'view_finance_oversight',
            'view_communication_monitoring', 'view_timetable_oversight', 'view_leadership_action_queue',
            'view_leadership_alerts', 'acknowledge_leadership_alerts', 'manage_leadership_preferences',
            'manage_leadership_devices', 'view_leadership_reports', 'generate_leadership_reports',
            'review_cross_module_approvals',
        ]);
        $this->grant('hod_role', [
            'access_school_leadership_portal', 'access_leadership_portal_phase_two', 'view_hod_dashboard',
            'view_teacher_compliance', 'view_teacher_workload', 'view_academic_insights',
            'view_attendance_intelligence', 'view_communication_monitoring', 'view_timetable_oversight',
            'view_leadership_action_queue', 'view_leadership_alerts', 'acknowledge_leadership_alerts',
            'manage_leadership_preferences', 'manage_leadership_devices', 'view_hod_department_analytics',
            'view_leadership_reports', 'generate_leadership_reports', 'review_cross_module_approvals',
        ]);
    }

    public function test_phase_two_routes_are_explicitly_permissioned(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        foreach (['api/leadership/dashboard/principal', 'api/leadership/approvals', 'api/leadership/teachers', 'api/leadership/kpis', 'api/leadership/finance/summary', 'api/leadership/devices'] as $uri) {
            $route = $routes->first(fn ($item) => $item->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertNotEmpty(collect($route->gatherMiddleware())->first(fn ($item) => str_starts_with($item, 'permission:')), $uri);
        }
    }

    public function test_principal_is_whole_school_and_hod_is_department_scoped_without_finance(): void
    {
        $access = app(LeadershipPortalAccessService::class);
        $this->assertTrue($access->scope($this->userModel('principal'))['whole_school']);
        $hod = $access->scope($this->userModel('hod'));
        $this->assertFalse($hod['whole_school']);
        $this->assertFalse($hod['finance']);
        $this->assertSame([$this->ids['area']], $hod['learning_area_ids']);
        $this->assertSame([$this->ids['department_teacher']], $hod['teacher_ids']);
    }

    public function test_ordinary_teacher_and_cross_school_teacher_are_denied(): void
    {
        $access = app(LeadershipPortalAccessService::class);
        try {
            $access->scope($this->userModel('ordinary'));
            $this->fail('Ordinary teacher was allowed into leadership scope.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->expectException(AuthorizationException::class);
        $access->assertTeacher($this->userModel('principal'), $this->ids['other_teacher']);
    }

    public function test_hod_teacher_listing_is_department_scoped_and_finance_is_denied(): void
    {
        $portal = app(LeadershipPortalPhaseTwoService::class);
        $rows = $portal->teachers($this->userModel('hod'));
        $this->assertSame([$this->ids['department_teacher']], collect($rows->items())->pluck('id')->all());

        $this->expectException(AuthorizationException::class);
        $portal->finance($this->userModel('hod'), 'summary');
    }

    public function test_inactive_hod_assignment_is_denied(): void
    {
        DB::table('hod_assignments')->where('teacher_id', $this->ids['hod_teacher'])->update(['active' => false]);

        $this->expectException(AuthorizationException::class);
        app(LeadershipPortalAccessService::class)->scope($this->userModel('hod'));
    }

    public function test_attendance_summary_uses_finalized_records_only(): void
    {
        $status = DB::table('attendance_statuses')->whereIn('status_code', ['P', 'PRESENT'])->value('id') ?: (string) Str::uuid();
        $learner = (string) Str::uuid();
        $session = (string) Str::uuid();
        if (! DB::table('attendance_statuses')->where('id', $status)->exists()) {
            DB::table('attendance_statuses')->insert(['id' => $status, 'status_name' => 'Present L2', 'status_code' => 'P']);
        }
        DB::table('attendance_sessions')->insert([
            ['id' => $session, 'school_id' => $this->ids['school'], 'session_name' => 'Morning L2', 'active' => true],
            ['id' => $this->ids['attendance_session_two'], 'school_id' => $this->ids['school'], 'session_name' => 'Afternoon L2', 'active' => true],
        ]);
        DB::table('learners')->insert(['id' => $learner, 'school_id' => $this->ids['school'], 'admission_no' => 'L2-'.Str::random(6), 'first_name' => 'Safe', 'last_name' => 'Learner', 'grade_id' => $this->ids['grade'], 'stream_id' => $this->ids['stream'], 'active' => true, 'is_deleted' => false]);
        DB::table('learner_attendance')->insert([
            ['id' => (string) Str::uuid(), 'school_id' => $this->ids['school'], 'learner_id' => $learner, 'grade_id' => $this->ids['grade'], 'stream_id' => $this->ids['stream'], 'attendance_session_id' => $session, 'attendance_status_id' => $status, 'attendance_date' => today(), 'finalized' => true],
            ['id' => (string) Str::uuid(), 'school_id' => $this->ids['school'], 'learner_id' => $learner, 'grade_id' => $this->ids['grade'], 'stream_id' => $this->ids['stream'], 'attendance_session_id' => $this->ids['attendance_session_two'], 'attendance_status_id' => $status, 'attendance_date' => today(), 'finalized' => false],
        ]);

        $summary = app(LeadershipPortalPhaseTwoService::class)->attendance($this->userModel('principal'), 'today');
        $this->assertSame(1, $summary['finalized_denominator']);
        $this->assertSame(100.0, $summary['rate']);
    }

    public function test_device_identifier_and_token_are_not_exposed_and_registration_is_idempotent(): void
    {
        $service = app(LeadershipDeviceService::class);
        $user = $this->userModel('principal');
        $first = $service->register($user, ['device_identifier' => 'phase-two-device', 'platform' => 'android', 'push_token' => 'secret-token']);
        $second = $service->register($user, ['device_identifier' => 'phase-two-device', 'platform' => 'android']);
        $this->assertSame($first->id, $second->id);
        $payload = (new LeadershipSafeResource($second))->toArray(Request::create('/'));
        $this->assertArrayNotHasKey('device_identifier_hash', $payload);
        $this->assertArrayNotHasKey('push_token_encrypted', $payload);
        $this->assertFalse($second->push_enabled);
    }

    public function test_device_revocation_is_owner_only(): void
    {
        $service = app(LeadershipDeviceService::class);
        $device = $service->register($this->userModel('principal'), ['device_identifier' => 'phase-two-owner-device', 'platform' => 'ios']);

        $this->expectException(ModelNotFoundException::class);
        $service->revoke($this->userModel('other_principal'), $device->id);
    }

    public function test_report_allowlist_and_approval_reason_are_enforced(): void
    {
        $this->expectException(ValidationException::class);
        app(LeadershipPortalPhaseTwoService::class)->report($this->userModel('principal'), ['report' => 'select * from users'], false);
    }

    public function test_rejection_without_reason_is_denied_before_any_module_mutation(): void
    {
        $this->expectException(ValidationException::class);
        app(LeadershipApprovalCentreService::class)->decide($this->userModel('principal'), 'teacher_workflow:'.Str::uuid(), 'rejected', null);
    }

    public function test_safe_resource_redacts_private_and_provider_fields_recursively(): void
    {
        $payload = (new LeadershipSafeResource(['id' => 'safe', 'private_notes' => 'hidden', 'nested' => ['push_token' => 'hidden', 'name' => 'safe']]))->toArray(Request::create('/'));
        $this->assertArrayNotHasKey('private_notes', $payload);
        $this->assertArrayNotHasKey('push_token', $payload['nested']);
        $this->assertSame('safe', $payload['nested']['name']);
    }

    private function user(string $userKey, string $roleKey, string $schoolKey): void
    {
        DB::table('users')->insert([
            'id' => $this->ids[$userKey],
            'school_id' => $this->ids[$schoolKey],
            'role_id' => $this->ids[$roleKey],
            'username' => $userKey.'-'.Str::lower(Str::random(6)),
            'password_hash' => bcrypt('password'),
            'first_name' => Str::headline($userKey),
            'active' => true,
            'is_deleted' => false,
        ]);
    }

    private function grant(string $roleKey, array $permissions): void
    {
        foreach ($permissions as $name) {
            $permissionId = DB::table('permissions')->where('permission_name', $name)->value('id');
            if (! $permissionId) {
                $permissionId = (string) Str::uuid();
                DB::table('permissions')->insert(['id' => $permissionId, 'permission_name' => $name]);
            }
            if (! DB::table('role_permissions')->where('role_id', $this->ids[$roleKey])->where('permission_id', $permissionId)->exists()) {
                DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $this->ids[$roleKey], 'permission_id' => $permissionId]);
            }
        }
    }

    private function userModel(string $key): User
    {
        return User::with('role')->findOrFail($this->ids[$key]);
    }
}
