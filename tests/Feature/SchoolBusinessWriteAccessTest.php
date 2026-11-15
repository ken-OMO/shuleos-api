<?php

namespace Tests\Feature;

use App\Services\School\SchoolBusinessWriteAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\MainSubscriptionBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class SchoolBusinessWriteAccessTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;
    private object $grade;
    private object $stream;
    private object $subscription;
    private string $token;
    private string $roleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 11, 15)->setTime(12, 0));
        config(['jwt.secret' => str_repeat('business-access-test-', 4), 'jwt.ttl' => 1440]);
        $this->school = SchoolBuilder::create([
            'short_name' => 'BUS', 'registration_number' => 'REG-'.Str::uuid(),
            'school_type' => 'Primary', 'operating_mode' => 'day', 'county' => 'Nairobi',
            'phone' => '0700000000', 'email' => Str::uuid().'@example.test',
            'timezone' => 'Africa/Nairobi', 'locale' => 'en', 'lifecycle_state' => 'active',
        ]);
        $year = AcademicYearBuilder::create($this->school);
        TermBuilder::create($this->school, $year);
        $this->grade = GradeBuilder::create($this->school, null, ['grade_number' => 2]);
        $this->stream = StreamBuilder::create($this->school, $this->grade);
        $role = RoleBuilder::create();
        $this->roleId = $role->id;
        foreach (['manage_learners', 'manage_academics', 'manage_guardians'] as $name) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(), 'role_id' => $role->id,
                'permission_id' => DB::table('permissions')->where('permission_name', $name)->soleValue('id'),
            ]);
        }
        $user = UserBuilder::create($this->school, $role);
        $login = $this->postJson('/api/auth/login', [
            'username' => $user->username, 'password' => 'Password123!',
        ])->assertOk();
        $this->token = $login->json('token');
        $this->subscription = MainSubscriptionBuilder::activeFor($this->school->id);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public static function lifecycleStates(): array
    {
        return ['active' => ['active', 'ACTIVE', 201], 'grace' => ['grace', 'GRACE_PERIOD', 201],
            'read-only' => ['read_only', 'ACTIVE', 403], 'locked' => ['locked', 'ACTIVE', 403]];
    }

    #[DataProvider('lifecycleStates')]
    public function test_real_login_then_admission_obeys_school_and_subscription_state(string $state, string $status, int $expected): void
    {
        DB::table('schools')->where('id', $this->school->id)->update(['lifecycle_state' => $state]);
        DB::table('school_subscriptions')->where('id', $this->subscription->id)->update([
            'status' => $status, 'grace_end_date' => '2027-12-31',
        ]);
        $before = $this->snapshot();
        $this->withToken($this->token)->postJson('/api/learners', $this->payload())->assertStatus($expected);
        if ($expected === 403) {
            $this->assertSame($before, $this->snapshot());
        } else {
            $this->assertDatabaseHas('learners', ['school_id' => $this->school->id, 'admission_no' => 'BUS-001']);
            $this->assertSame(1, DB::table('learners')->where('school_id', $this->school->id)->count());
        }
    }

    public static function invalidSubscriptions(): array
    {
        return [
            'missing' => [null], 'pending' => [['status' => 'PENDING_PAYMENT']],
            'locked subscription' => [['status' => 'LOCKED']], 'suspended subscription' => [['status' => 'SUSPENDED']],
            'expired active' => [['expiry_date' => '2026-11-14', 'grace_end_date' => '2026-12-01']],
            'future start' => [['start_date' => '2026-11-16']],
            'missing start' => [['start_date' => null]], 'missing expiry' => [['expiry_date' => null]],
            'missing package' => [['package_id' => null]],
            'expired grace' => [['status' => 'GRACE_PERIOD', 'expiry_date' => '2026-11-01', 'grace_end_date' => '2026-11-14']],
            'missing grace date' => [['status' => 'GRACE_PERIOD', 'grace_end_date' => null]],
            'invalid grace order' => [['status' => 'GRACE_PERIOD', 'grace_end_date' => '2026-11-15']],
            'unrecorded trial' => [['status' => 'TRIAL', 'is_trial' => false]],
        ];
    }

    #[DataProvider('invalidSubscriptions')]
    public function test_unentitled_admission_is_denied_without_business_or_audit_changes(?array $attributes): void
    {
        $query = DB::table('school_subscriptions')->where('id', $this->subscription->id);
        if ($attributes === null) {
            $query->delete();
        } else {
            $query->update($attributes);
        }
        $before = $this->snapshot();
        $this->withToken($this->token)->postJson('/api/learners', $this->payload())->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_recorded_trial_and_inclusive_school_local_expiry_are_supported_without_inventing_grace(): void
    {
        DB::table('school_subscriptions')->where('id', $this->subscription->id)->update([
            'status' => 'TRIAL', 'is_trial' => true, 'package_id' => null, 'expiry_date' => '2026-11-15',
        ]);
        $this->withToken($this->token)->postJson('/api/learners', $this->payload())->assertCreated();
        // Nairobi has crossed midnight even though UTC is still the previous day.
        $this->travelTo(now()->setTimezone('UTC')->setTime(21, 0, 0));
        $before = $this->snapshot();
        $this->withToken($this->token)->postJson('/api/learners', $this->payload('BUS-002'))->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_latest_subscription_cannot_fall_back_to_an_older_active_record(): void
    {
        MainSubscriptionBuilder::activeFor($this->school->id, ['status' => 'PENDING_PAYMENT', 'created_at' => now()->addSecond()]);
        $before = $this->snapshot();
        $this->withToken($this->token)->postJson('/api/learners', $this->payload())->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_read_only_keeps_authorized_reads_but_blocks_update_delete_and_another_module_write(): void
    {
        $id = $this->withToken($this->token)->postJson('/api/learners', $this->payload())->assertCreated()->json('data.id');
        DB::table('schools')->where('id', $this->school->id)->update(['lifecycle_state' => 'read_only']);
        $before = $this->snapshot();
        $this->withToken($this->token)->getJson('/api/learners/'.$id)->assertOk();
        $this->withToken($this->token)->putJson('/api/learners/'.$id, ['first_name' => 'Changed'])->assertForbidden();
        $this->withToken($this->token)->deleteJson('/api/learners/'.$id)->assertForbidden();
        $this->withToken($this->token)->postJson('/api/academic-years', $this->yearPayload())->assertForbidden();
        $this->assertSame($before, $this->snapshot());
        DB::table('schools')->where('id', $this->school->id)->update(['lifecycle_state' => 'locked']);
        $this->withToken($this->token)->getJson('/api/learners/'.$id)->assertForbidden();
    }

    public function test_active_subscription_does_not_supply_a_missing_permission(): void
    {
        DB::table('role_permissions')->where('role_id', $this->roleId)->delete();
        $before = $this->snapshot();
        $this->withToken($this->token)->postJson('/api/learners', $this->payload())->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_school_cannot_borrow_foreign_subscription_or_placement_and_reactivation_uses_current_state(): void
    {
        $foreign = SchoolBuilder::create(['lifecycle_state' => 'active']);
        MainSubscriptionBuilder::activeFor($foreign->id);
        $foreignGrade = GradeBuilder::create($foreign, null, ['grade_number' => 2]);
        $foreignStream = StreamBuilder::create($foreign, $foreignGrade);
        DB::table('school_subscriptions')->where('school_id', $this->school->id)->delete();
        $before = $this->snapshot();
        $this->withToken($this->token)->withHeader('X-School-Id', $foreign->id)
            ->postJson('/api/learners', $this->payload() + ['school_id' => $foreign->id])->assertForbidden();
        $this->assertSame($before, $this->snapshot());
        MainSubscriptionBuilder::activeFor($this->school->id);
        $payload = $this->payload();
        $payload['grade_id'] = $foreignGrade->id;
        $payload['stream_id'] = $foreignStream->id;
        $this->withToken($this->token)->postJson('/api/learners', $payload)->assertUnprocessable();
        $this->withToken($this->token)->postJson('/api/learners', $this->payload())->assertCreated();
        $this->assertSame(0, DB::table('learners')->where('school_id', $foreign->id)->count());
    }

    public function test_valid_non_assessment_academic_write_and_controller_validation_remain_intact(): void
    {
        $this->withToken($this->token)->postJson('/api/academic-years', $this->yearPayload())->assertCreated();
        $this->withToken($this->token)->postJson('/api/learners', [])->assertUnprocessable();
        $this->assertSame(0, DB::table('learners')->where('school_id', $this->school->id)->count());
    }

    public function test_policy_scope_preserves_auth_assessment_and_specialized_commercial_boundaries(): void
    {
        $policy = app(SchoolBusinessWriteAccess::class);
        foreach (['auth/logout', 'auth/refresh', 'exam-results', 'teacher/marks-entry', 'transport/trips', 'student-elections', 'parent/payments'] as $path) {
            $this->assertFalse($policy->covers(Request::create('/api/'.$path, 'POST')));
        }
        foreach (['learners', 'guardians', 'teachers', 'teacher-assignments', 'learning-area-allocations', 'academic-years', 'terms', 'academic-weeks', 'grades', 'streams', 'admin/school'] as $path) {
            $this->assertTrue($policy->covers(Request::create('/api/'.$path, 'POST')));
            $this->assertFalse($policy->covers(Request::create('/api/'.$path, 'GET')));
        }
    }

    private function payload(string $number = 'BUS-001'): array
    {
        return ['admission_no' => $number, 'first_name' => 'Synthetic', 'last_name' => 'Learner',
            'mode_of_study' => 'day_scholar', 'grade_id' => $this->grade->id, 'stream_id' => $this->stream->id];
    }

    private function yearPayload(): array
    {
        return ['year_name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'active' => true];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['schools', 'school_subscriptions', 'learners', 'academic_years', 'grades', 'streams', 'terms', 'audit_logs'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $snapshot;
    }
}
