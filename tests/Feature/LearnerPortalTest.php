<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LearnerPortal\LearnerAccountService;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\LearnerPortal\LearnerPortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LearnerPortalTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['school', 'other', 'role', 'grade', 'stream', 'learner', 'learner2'] as $k) {
            $this->id[$k] = (string) Str::uuid();
        }DB::table('schools')->insert([
            [
                'id' => $this->id['school'],
                'school_name' => 'Learner Portal Test School',
                'school_code' => 'LPTS-'.strtoupper(Str::random(8)),
                'active' => true,
                'is_deleted' => false,
            ],
            [
                'id' => $this->id['other'],
                'school_name' => 'Learner Portal Other School',
                'school_code' => 'LPTO-'.strtoupper(Str::random(8)),
                'active' => true,
                'is_deleted' => false,
            ],
        ]);
        DB::table('roles')->insert(['id' => $this->id['role'], 'role_name' => 'Learner']);
        DB::table('school_settings')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'learner_portal_enabled' => 1, 'learner_portal_show_fees' => 0, 'learner_portal_show_positions' => 0, 'learner_portal_show_pathway' => 1, 'learner_portal_show_report_cards' => 1, 'learner_portal_show_attendance' => 1, 'learner_portal_show_results' => 1]);
        DB::table('grades')->insert([
            'id' => $this->id['grade'],
            'school_id' => $this->id['school'],
            'grade_name' => 'Learner Portal Grade',
            'grade_order' => 99,
            'active' => true,
        ]);
        DB::table('streams')->insert([
            'id' => $this->id['stream'],
            'school_id' => $this->id['school'],
            'grade_id' => $this->id['grade'],
            'stream_name' => 'Learner Portal Stream',
            'active' => true,
        ]);
        foreach (['learner', 'learner2'] as $l) {
            DB::table('learners')->insert(['id' => $this->id[$l], 'school_id' => $this->id['school'], 'admission_no' => strtoupper($l), 'first_name' => 'Test', 'last_name' => $l, 'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream'], 'active' => 1, 'portal_enabled' => 1, 'is_deleted' => 0]);
        }
    }

    public function test_admin_provisions_transactionally_and_hashes_generated_password(): void
    {
        $r = app(LearnerAccountService::class)->create($this->id['school'], $this->id['learner'], []);
        $this->assertNotNull($r['temporary_password']);
        $u = User::find($r['user']['id']);
        $this->assertTrue(Hash::check($r['temporary_password'], $u->password_hash));
        $this->assertSame($u->id, DB::table('learners')->where('id', $this->id['learner'])->value('user_id'));
    }

    public function test_duplicate_and_cross_school_provisioning_are_rejected(): void
    {
        $s = app(LearnerAccountService::class);
        $s->create($this->id['school'], $this->id['learner'], ['password' => 'LongPassword1!']);
        try {
            $s->create($this->id['school'], $this->id['learner'], ['password' => 'LongPassword1!']);
            $this->fail();
        } catch (ValidationException) {
            $this->assertTrue(true);
        }$this->expectException(ValidationException::class);
        $s->create($this->id['other'], $this->id['learner2'], []);
    }

    public function test_learner_resolves_and_disabled_portals_are_rejected(): void
    {
        $r = app(LearnerAccountService::class)->create($this->id['school'], $this->id['learner'], []);
        $u = User::with('role')->find($r['user']['id']);
        $this->assertSame($this->id['learner'], app(LearnerPortalAccessService::class)->learner($u)->id);
        DB::table('learners')->where('id', $this->id['learner'])->update(['portal_enabled' => 0]);
        $this->expectException(AuthorizationException::class);
        app(LearnerPortalAccessService::class)->learner($u);
    }

    public function test_preferences_cannot_enable_school_prohibited_fees(): void
    {
        $r = app(LearnerAccountService::class)->create($this->id['school'], $this->id['learner'], []);
        $u = User::with('role')->find($r['user']['id']);
        $this->expectException(AuthorizationException::class);
        app(LearnerPortalService::class)->updatePreferences($u, ['show_fees' => true]);
    }

    public function test_non_learner_cross_school_and_other_learner_idor_are_denied(): void
    {
        $result = app(LearnerAccountService::class)->create($this->id['school'], $this->id['learner'], []);
        $user = User::with('role')->find($result['user']['id']);
        $access = app(LearnerPortalAccessService::class);

        try {
            $access->assertLearner($user, $this->id['learner2']);
            $this->fail('Another learner ID must be denied.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        DB::table('learners')->where('id', $this->id['learner'])->update(['school_id' => $this->id['other']]);
        try {
            $access->learner($user);
            $this->fail('Cross-school learner links must be denied.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        DB::table('learners')->where('id', $this->id['learner'])->update(['school_id' => $this->id['school']]);
        $teacherRoleId = DB::table('roles')
            ->where('role_name', 'Teacher')
            ->value('id');

        if (! $teacherRoleId) {
            throw new \RuntimeException('Required migrated Teacher role was not found.');
        }

        DB::table('users')
            ->where('id', $user->id)
            ->update(['role_id' => $teacherRoleId]);

        $user->role_id = $teacherRoleId;
        $user->unsetRelation('role')->load('role');
        $this->expectException(AuthorizationException::class);
        $access->learner($user);
    }

    public function test_inactive_user_and_disabled_school_policy_are_denied(): void
    {
        $result = app(LearnerAccountService::class)->create($this->id['school'], $this->id['learner'], []);
        $user = User::with('role')->find($result['user']['id']);
        $access = app(LearnerPortalAccessService::class);

        $user->active = false;
        try {
            $access->learner($user);
            $this->fail('Inactive users must be denied.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $user->active = true;
        DB::table('school_settings')->where('school_id', $this->id['school'])->update(['learner_portal_enabled' => false]);
        $this->expectException(AuthorizationException::class);
        $access->learner($user);
    }
}
