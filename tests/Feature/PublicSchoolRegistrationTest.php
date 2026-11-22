<?php

namespace Tests\Feature;

use App\Mail\SchoolAdminInvitationMail;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use App\Services\Platform\SchoolRegistrationRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class PublicSchoolRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('r', 64)]);
        Mail::fake();
    }

    private function payload(): array
    {
        return ['school_name' => 'Mansoor Academy', 'operating_mode' => 'day',
            'admin' => ['first_name' => 'Amina', 'last_name' => 'Hassan', 'email' => 'registration-'.Str::random(12).'@example.test']];
    }

    private function owner(): User
    {
        $role = Role::where('role_name', 'Platform Owner')->whereNull('school_id')->where('system_role', true)->where('active', true)->firstOrFail();

        return User::create(['id' => (string) Str::uuid(), 'school_id' => null, 'role_id' => $role->id,
            'username' => 'registration-owner-'.Str::random(12), 'email' => Str::random(12).'@example.test',
            'first_name' => 'Platform', 'last_name' => 'Owner', 'password_hash' => Hash::make('SyntheticPassword123!'),
            'active' => true, 'is_deleted' => false, 'first_login' => false, 'temporary_password' => false,
            'activated_at' => now(), 'email_verified_at' => now(), 'auth_generation' => 1]);
    }

    public function test_public_submission_saves_only_a_pending_request_and_never_creates_accounts_or_mail(): void
    {
        $schools = DB::table('schools')->count();
        $users = DB::table('users')->count();
        $payload = $this->payload();
        $response = $this->postJson('/api/school-registration-requests', $payload)->assertStatus(202);
        $this->assertDatabaseHas('school_registration_requests', ['school_name' => 'Mansoor Academy', 'status' => 'pending', 'admin_email' => strtolower($payload['admin']['email'])]);
        $this->assertSame($schools, DB::table('schools')->count());
        $this->assertSame($users, DB::table('users')->count());
        foreach (['password', 'username', 'school_id', 'admin_email', 'token'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json());
        }
        Mail::assertNothingSent();
    }

    public function test_duplicate_pending_email_is_normalized_without_replacing_the_original_request(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/school-registration-requests', $payload)->assertStatus(202);
        $payload['school_name'] = 'Replacement name';
        $payload['admin']['email'] = strtoupper($payload['admin']['email']);
        $this->postJson('/api/school-registration-requests', $payload)->assertStatus(202);
        $rows = DB::table('school_registration_requests')->where('admin_email', strtolower($payload['admin']['email']))->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Mansoor Academy', $rows[0]->school_name);
    }

    public function test_public_overposting_and_empty_sanitized_names_are_rejected(): void
    {
        $this->postJson('/api/school-registration-requests', $this->payload() + ['status' => 'approved', 'school_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'school_id']);
        $payload = $this->payload();
        $payload['admin']['role_id'] = (string) Str::uuid();
        $this->postJson('/api/school-registration-requests', $payload)->assertUnprocessable()->assertJsonValidationErrors('admin');
        $payload = $this->payload();
        $payload['school_name'] = '<br>';
        $this->postJson('/api/school-registration-requests', $payload)->assertUnprocessable()->assertJsonValidationErrors('school_name');
    }

    public function test_review_requires_platform_identity_and_anonymous_users_cannot_read_requests(): void
    {
        $this->getJson('/api/admin/platform/school-registration-requests')->assertUnauthorized();
        $owner = $this->owner();
        $school = School::create(['id' => (string) Str::uuid(), 'school_name' => 'Not a platform', 'school_code' => 'REG-'.Str::random(8), 'active' => true, 'is_deleted' => false]);
        $owner->forceFill(['school_id' => $school->id])->save();
        $this->expectException(AuthorizationException::class);
        app(SchoolRegistrationRequestService::class)->index($owner, 'pending');
    }

    public function test_owner_approval_creates_one_school_and_repeat_approval_is_idempotent_without_exposing_credentials(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/school-registration-requests', $payload)->assertStatus(202);
        $id = DB::table('school_registration_requests')->where('admin_email', strtolower($payload['admin']['email']))->value('id');
        $token = JWTAuth::fromUser($this->owner());
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/admin/platform/school-registration-requests')->assertOk();
        $response = $this->postJson('/api/admin/platform/school-registration-requests/'.$id.'/approve')->assertOk();
        $schoolId = $response->json('data.school_id');
        $this->assertNotEmpty($schoolId);
        $this->assertDatabaseHas('schools', ['id' => $schoolId, 'school_name' => 'Mansoor Academy', 'lifecycle_state' => 'onboarding']);
        $this->postJson('/api/admin/platform/school-registration-requests/'.$id.'/approve')->assertOk()->assertJsonPath('data.school_id', $schoolId);
        $this->assertSame(1, DB::table('users')->where('email', strtolower($payload['admin']['email']))->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'school_registration_approved')->where('record_id', $id)->count());
        foreach (['temporary_password', 'password_hash', 'username', 'token'] as $field) {
            $this->assertStringNotContainsString($field, $response->getContent());
        }
        // Laravel's test transaction manager executes application afterCommit callbacks
        // while retaining its outer rollback wrapper. Repeat approval must not resend.
        $schoolCode = School::findOrFail($schoolId)->school_code;
        $this->assertNotEmpty($schoolCode);
        Mail::assertSent(SchoolAdminInvitationMail::class, function ($mail) use ($payload, $schoolCode) {
            if (! $mail->hasTo(strtolower($payload['admin']['email']))) {
                return false;
            }

            $mail->assertSeeInText('School code: '.$schoolCode);

            return true;
        });
        Mail::assertSentCount(1);
    }

    public function test_rejection_creates_no_school_and_cannot_later_be_approved(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/school-registration-requests', $payload)->assertStatus(202);
        $id = DB::table('school_registration_requests')->where('admin_email', strtolower($payload['admin']['email']))->value('id');
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->owner()))
            ->postJson('/api/admin/platform/school-registration-requests/'.$id.'/reject', ['reason' => 'Please verify school details.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson('/api/admin/platform/school-registration-requests/'.$id.'/approve')->assertConflict();
        $this->assertDatabaseHas('school_registration_requests', ['id' => $id, 'status' => 'rejected', 'school_id' => null]);
        Mail::assertNothingSent();
    }

    public function test_public_submission_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/school-registration-requests', $this->payload())->assertStatus(202);
        }
        $this->postJson('/api/school-registration-requests', $this->payload())->assertStatus(429);
        // Registration exhaustion must not consume the independent school login budget.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [])->assertUnprocessable();
        }
        $this->postJson('/api/auth/login', [])->assertStatus(429);
    }

    public function test_failed_approval_audit_rolls_back_school_account_and_request_state(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/school-registration-requests', $payload)->assertStatus(202);
        $id = DB::table('school_registration_requests')->where('admin_email', strtolower($payload['admin']['email']))->value('id');
        $owner = $this->owner();
        $schools = DB::table('schools')->count();
        $this->partialMock(AdministratorAuditService::class, function ($mock) {
            $mock->shouldReceive('record')->andReturnUsing(function ($actor, $action) {
                if ($action === 'school_registration_approved') {
                    throw new \RuntimeException('Synthetic audit failure');
                }
            });
        });
        try {
            app(SchoolRegistrationRequestService::class)->review($owner, $id, 'approve', null);
            $this->fail('Approval should fail when its audit fails.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        }
        $this->assertSame($schools, DB::table('schools')->count());
        $this->assertSame(0, DB::table('users')->where('email', strtolower($payload['admin']['email']))->count());
        $this->assertDatabaseHas('school_registration_requests', ['id' => $id, 'status' => 'pending', 'school_id' => null]);
        Mail::assertNothingSent();
    }
}
