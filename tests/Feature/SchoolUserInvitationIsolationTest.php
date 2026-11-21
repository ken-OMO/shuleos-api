<?php

namespace Tests\Feature;

use App\Mail\SchoolAdminActivationOtpMail;
use App\Mail\SchoolUserInvitationMail;
use App\Models\AuthenticationChallenge;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use App\Services\Administrator\AdministratorUserService;
use App\Services\Administrator\SchoolUserInvitationService;
use App\Services\Auth\AuthContextService;
use App\Services\Auth\SchoolAdminActivationService;
use App\Services\Auth\SchoolLoginIdentityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Database\MainSubscriptionBuilder;
use Tests\TestCase;

class SchoolUserInvitationIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private School $alpha;

    private School $beta;

    private User $admin;

    private User $otherAdmin;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('i', 64)]);
        Mail::fake();
        $this->alpha = $this->school();
        $this->beta = $this->school();
        $this->admin = $this->administrator($this->alpha);
        $this->otherAdmin = $this->administrator($this->beta);
        $this->role = Role::create(['id' => (string) Str::uuid(), 'role_name' => 'Invitation test reader '.Str::random(8),
            'school_id' => null, 'system_role' => true, 'active' => true]);
    }

    private function school(): School
    {
        $school = School::create(['id' => (string) Str::uuid(), 'school_name' => 'Synthetic school',
            'school_code' => 'INV'.Str::upper(Str::random(10)), 'active' => true,
            'lifecycle_state' => 'active', 'timezone' => 'Africa/Nairobi']);
        MainSubscriptionBuilder::activeFor($school->id);

        return $school;
    }

    private function administrator(School $school): User
    {
        $role = Role::whereNull('school_id')->where('role_name', 'School Admin')->where('active', true)->firstOrFail();

        return User::create(['id' => (string) Str::uuid(), 'school_id' => $school->id, 'role_id' => $role->id,
            'username' => 'invite-admin-'.Str::random(12), 'email' => Str::random(12).'@example.test',
            'first_name' => 'Test', 'last_name' => 'Admin', 'active' => true, 'first_login' => false,
            'temporary_password' => false, 'activated_at' => now(), 'email_verified_at' => now(),
            'password_hash' => Hash::make('SyntheticAdminPass123!'), 'auth_generation' => 1]);
    }

    private function payload(?string $email = null): array
    {
        return ['first_name' => 'Synthetic', 'last_name' => 'Teacher', 'email' => $email ?? strtolower(Str::random(12)).'@example.test', 'role_id' => $this->role->id];
    }

    private function invite(?User $actor = null, ?string $email = null): array
    {
        return app(SchoolUserInvitationService::class)->invite($actor ?? $this->admin, $this->payload($email));
    }

    private function invitation(string $id): SchoolUserInvitationMail
    {
        $user = User::findOrFail($id);

        return Mail::sent(SchoolUserInvitationMail::class, fn ($mail) => $mail->hasTo($user->email))->last();
    }

    private function login(User $user, string $password, ?string $code = null)
    {
        return $this->postJson('/api/auth/login', ['username' => $user->email, 'password' => $password,
            'school_code' => $code ?? $user->school->school_code]);
    }

    public function test_invitation_derives_tenant_and_never_returns_credentials(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->admin))
            ->postJson('/api/admin/school/user-invitations', $this->payload())->assertCreated();
        $user = User::findOrFail($response->json('data.id'));
        $this->assertSame($this->alpha->id, $user->school_id);
        $this->assertSame($user->email, $user->username);
        $this->assertTrue($user->temporary_password);
        $this->assertNull($user->activated_at);
        $this->assertNotSame($this->invitation($user->id)->temporaryPassword, $user->password_hash);
        foreach (['password', 'token', 'username'] as $field) {
            $this->assertStringNotContainsString($field, $response->getContent());
        }
        Mail::assertSentCount(1);
    }

    public function test_client_tenant_override_and_platform_roles_are_rejected(): void
    {
        $platformRole = Role::where('role_name', 'Platform Owner')->whereNull('school_id')->firstOrFail();
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->admin))
            ->postJson('/api/admin/school/user-invitations', $this->payload() + ['school_id' => $this->beta->id])->assertUnprocessable();
        $data = $this->payload();
        $data['role_id'] = $platformRole->id;
        $this->postJson('/api/admin/school/user-invitations', $data)->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_same_email_in_two_schools_has_distinct_identities_and_school_bound_login(): void
    {
        $email = strtolower(Str::random(12)).'@example.test';
        $a = User::findOrFail($this->invite($this->admin, $email)['id']);
        $passwordA = $this->invitation($a->id)->temporaryPassword;
        $b = User::findOrFail($this->invite($this->otherAdmin, $email)['id']);
        $passwordB = $this->invitation($b->id)->temporaryPassword;
        $this->assertNotSame($a->id, $b->id);
        $this->login($a, $passwordA)->assertStatus(202)->assertJsonPath('data.school_id', $this->alpha->id);
        $this->login($b, $passwordB)->assertStatus(202)->assertJsonPath('data.school_id', $this->beta->id);
        $this->login($a, $passwordA, $this->beta->school_code)->assertUnauthorized();
        $this->postJson('/api/auth/login', ['username' => $email, 'password' => $passwordA])->assertUnauthorized();
    }

    public function test_case_normalized_duplicate_is_rejected_only_within_same_school(): void
    {
        $email = strtolower(Str::random(12)).'@example.test';
        $this->invite($this->admin, $email);
        $this->expectException(ValidationException::class);
        $this->invite($this->admin, strtoupper($email));
    }

    public function test_other_school_cannot_resend_or_list_this_invitation(): void
    {
        $result = $this->invite();
        $token = JWTAuth::fromUser($this->otherAdmin);
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/admin/school/user-invitations')
            ->assertOk()->assertJsonPath('data.school_id', $this->beta->id)->assertJsonCount(0, 'data.pending');
        $this->postJson('/api/admin/school/user-invitations/'.$result['id'].'/resend')->assertNotFound();
    }

    public function test_missing_or_expired_subscription_blocks_new_invitations(): void
    {
        DB::table('school_subscriptions')->where('school_id', $this->alpha->id)->update(['expiry_date' => now()->subDay()->toDateString()]);
        $this->expectException(AuthorizationException::class);
        $this->invite();
    }

    public function test_resend_invalidates_old_password_and_all_prior_challenges(): void
    {
        $user = User::findOrFail($this->invite()['id']);
        $old = $this->invitation($user->id)->temporaryPassword;
        $login = $this->login($user, $old)->assertStatus(202);
        $challenge = $login->json('data.challenge_id');
        $this->travel(61)->seconds();
        app(SchoolUserInvitationService::class)->resend($this->admin, $user->id);
        $new = $this->invitation($user->id)->temporaryPassword;
        $this->assertNotSame($old, $new);
        $this->assertNotNull(AuthenticationChallenge::findOrFail($challenge)->consumed_at);
        $this->login($user, $old)->assertUnauthorized();
        $this->login($user, $new)->assertStatus(202);
    }

    public function test_subscription_expiry_between_otp_and_activation_blocks_activation(): void
    {
        [$user, $activation] = $this->verifiedInvitation();
        DB::table('school_subscriptions')->where('school_id', $user->school_id)->update(['expiry_date' => now()->subDay()->toDateString()]);
        $this->activate($activation)->assertForbidden();
        $this->assertNull($user->fresh()->activated_at);
    }

    private function verifiedInvitation(): array
    {
        $user = User::findOrFail($this->invite()['id']);
        $login = $this->login($user, $this->invitation($user->id)->temporaryPassword)->assertStatus(202);
        $otp = Mail::sent(SchoolAdminActivationOtpMail::class)->last()->otp;
        $response = $this->postJson('/api/auth/first-login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'), 'challenge_token' => $login->json('data.challenge_token'), 'otp' => $otp,
        ])->assertStatus(202);

        return [$user, $response->json('data')];
    }

    private function activate(array $data, array $extra = [])
    {
        return $this->postJson('/api/auth/first-login/activate', array_merge([
            'challenge_id' => $data['challenge_id'], 'activation_token' => $data['activation_token'],
            'password' => 'PermanentSyntheticPass123!', 'password_confirmation' => 'PermanentSyntheticPass123!',
        ], $extra));
    }

    public function test_activation_is_school_bound_and_cannot_be_replayed(): void
    {
        [$user, $data] = $this->verifiedInvitation();
        $this->activate($data, ['school_id' => $this->beta->id])->assertUnauthorized();
        $this->activate($data)->assertOk()->assertJsonPath('data.user.school_id', $this->alpha->id);
        $this->activate($data)->assertUnauthorized();
        $this->assertTrue(Hash::check('PermanentSyntheticPass123!', $user->fresh()->password_hash));
        $this->assertFalse($user->fresh()->temporary_password);
    }

    public function test_database_rejects_case_only_duplicate_email_within_one_school(): void
    {
        $user = User::findOrFail($this->invite()['id']);
        $this->expectException(QueryException::class);
        DB::table('users')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->alpha->id, 'role_id' => $this->role->id,
            'username' => strtoupper($user->email), 'email' => strtoupper($user->email), 'password_hash' => 'synthetic']);
    }

    public function test_audit_failure_rolls_back_invitation_and_sends_no_mail(): void
    {
        $before = DB::table('users')->count();
        $this->partialMock(AdministratorAuditService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('Synthetic audit failure'));
        });
        try {
            $this->invite();
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        }
        $this->assertSame($before, DB::table('users')->count());
        Mail::assertNothingSent();
    }

    public function test_legacy_generated_username_still_resolves_but_email_requires_school_code(): void
    {
        $resolver = app(SchoolLoginIdentityService::class);
        $this->assertSame($this->admin->id, $resolver->find($this->admin->username, null)?->id);
        $this->assertNull($resolver->find($this->admin->email, null));
        $this->assertNull($resolver->find($this->admin->username, $this->beta->school_code));
    }

    public function test_resend_cooldown_preserves_existing_credential(): void
    {
        $this->travelTo(now()->startOfSecond());
        DB::statement("SET LOCAL TIME ZONE 'Africa/Nairobi'");
        $user = User::findOrFail($this->invite()['id']);
        $this->assertLessThan(2, abs(now()->getTimestamp() - $user->invitation_last_sent_at->getTimestamp()));
        $hash = $user->password_hash;
        foreach ([0, 59] as $seconds) {
            if ($seconds) {
                $this->travel($seconds)->seconds();
            }
            try {
                app(SchoolUserInvitationService::class)->resend($this->admin, $user->id);
                $this->fail('Cooldown must block resend before 60 seconds.');
            } catch (HttpException $error) {
                $this->assertSame(429, $error->getStatusCode());
            }
            $this->assertSame($hash, $user->fresh()->password_hash);
            Mail::assertSentCount(1);
        }
        $this->travel(1)->seconds();
        app(SchoolUserInvitationService::class)->resend($this->admin, $user->id);
        $this->assertNotSame($hash, $user->fresh()->password_hash);
        Mail::assertSentCount(2);
    }

    public function test_expired_temporary_password_cannot_advance_a_verified_activation(): void
    {
        [$user, $data] = $this->verifiedInvitation();
        $user->forceFill(['temporary_password_expires_at' => now()->subSecond()])->save();
        $this->activate($data)->assertUnauthorized();
        $this->assertNull($user->fresh()->activated_at);
    }

    public function test_jwt_tenant_claim_must_match_current_user_membership(): void
    {
        $token = JWTAuth::fromUser($this->admin);
        $this->admin->forceFill(['school_id' => $this->beta->id])->save();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_other_school_role_cannot_be_assigned(): void
    {
        $role = Role::create(['id' => (string) Str::uuid(), 'role_name' => 'Foreign reader', 'school_id' => $this->beta->id, 'system_role' => false, 'active' => true]);
        $data = $this->payload();
        $data['role_id'] = $role->id;
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->admin))
            ->postJson('/api/admin/school/user-invitations', $data)->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_new_user_cannot_call_invitation_endpoints_without_administrator_authority(): void
    {
        $user = User::findOrFail($this->invite()['id']);
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user))
            ->postJson('/api/admin/school/user-invitations', $this->payload())->assertForbidden();
        Mail::assertSentCount(1);
    }

    public function test_platform_owner_cannot_use_school_invitation_service(): void
    {
        $role = Role::whereNull('school_id')->where('role_name', 'Platform Owner')->where('active', true)->firstOrFail();
        $owner = $this->administrator($this->alpha);
        $owner->forceFill(['school_id' => null, 'role_id' => $role->id])->save();
        $this->expectException(AuthorizationException::class);
        $this->invite($owner);
    }

    public function test_role_permissions_beyond_inviter_authority_are_rejected(): void
    {
        $permissionId = (string) Str::uuid();
        DB::table('permissions')->insert(['id' => $permissionId, 'permission_name' => 'synthetic_unowned_'.Str::random(8)]);
        DB::table('role_permissions')->insert(['role_id' => $this->role->id, 'permission_id' => $permissionId]);
        $this->expectException(AuthorizationException::class);
        $this->invite();
    }

    public function test_school_admin_can_invite_standard_teacher_without_personal_teacher_portal_permission(): void
    {
        $teacher = Role::whereNull('school_id')->where('system_role', true)->where('active', true)->where('role_name', 'Teacher')->firstOrFail();
        $permission = DB::table('permissions')->where('permission_name', 'access_teacher_portal')->value('id');
        $this->assertNotNull($permission);
        DB::table('role_permissions')->where('role_id', $this->admin->role_id)->where('permission_id', $permission)->delete();
        DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $teacher->id, 'permission_id' => $permission, 'created_at' => now()]);
        $data = $this->payload();
        $data['role_id'] = $teacher->id;
        $token = JWTAuth::fromUser($this->admin);
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/admin/school/user-invitations/roles')->assertOk()
            ->assertJsonFragment(['id' => $teacher->id, 'name' => 'Teacher']);
        $result = $this->postJson('/api/admin/school/user-invitations', $data)->assertCreated();
        $this->assertDatabaseHas('users', ['id' => $result->json('data.id'), 'school_id' => $this->alpha->id, 'role_id' => $teacher->id]);
        $this->assertFalse(app(AuthContextService::class)->hasPermission($this->admin, 'access_teacher_portal'));
    }

    public function test_standard_teacher_preset_with_an_unreviewed_permission_is_rejected(): void
    {
        $teacher = Role::whereNull('school_id')->where('system_role', true)->where('active', true)->where('role_name', 'Teacher')->firstOrFail();
        $id = (string) Str::uuid();
        DB::table('permissions')->insert(['id' => $id, 'permission_name' => 'unreviewed_teacher_'.Str::random(8)]);
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $teacher->id, 'permission_id' => $id, 'created_at' => now()]);
        $this->expectException(AuthorizationException::class);
        app(AdministratorUserService::class)->invitationRole($this->admin, $teacher->id);
    }

    public function test_teacher_role_without_system_flag_does_not_inherit_standard_preset_authority(): void
    {
        $teacher = Role::whereNull('school_id')->where('system_role', true)->where('active', true)->where('role_name', 'Teacher')->firstOrFail();
        $teacher->forceFill(['system_role' => false])->save();
        $id = (string) Str::uuid();
        DB::table('permissions')->insert(['id' => $id, 'permission_name' => 'custom_teacher_'.Str::random(8)]);
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $teacher->id, 'permission_id' => $id, 'created_at' => now()]);
        $this->expectException(AuthorizationException::class);
        app(AdministratorUserService::class)->invitationRole($this->admin, $teacher->id);
    }

    public function test_login_snapshot_from_before_resend_cannot_create_a_new_otp_challenge(): void
    {
        $stale = User::findOrFail($this->invite()['id']);
        $oldPassword = $this->invitation($stale->id)->temporaryPassword;
        $this->travel(61)->seconds();
        app(SchoolUserInvitationService::class)->resend($this->admin, $stale->id);
        $request = Request::create('/api/auth/login', 'POST', ['password' => $oldPassword]);
        try {
            app(SchoolAdminActivationService::class)->begin($stale, $request);
            $this->fail('A stale pre-resend login snapshot must not create an OTP challenge.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Activation failed.', $error->getMessage());
        }
        $this->assertSame(0, AuthenticationChallenge::where('user_id', $stale->id)->count());
        Mail::assertNotSent(SchoolAdminActivationOtpMail::class);
        $fresh = $stale->fresh();
        $this->login($fresh, $this->invitation($fresh->id)->temporaryPassword)->assertStatus(202);
        Mail::assertSent(SchoolAdminActivationOtpMail::class);
    }
}
