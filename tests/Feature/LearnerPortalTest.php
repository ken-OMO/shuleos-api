<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LearnerPortal\LearnerAccountService;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\LearnerPortal\LearnerPortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LearnerPortalTest extends TestCase
{
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['learner_dashboard_preferences', 'learners', 'school_settings', 'user_roles', 'users', 'roles', 'schools'] as $t) {
            Schema::dropIfExists($t);
        }Schema::create('schools', fn (Blueprint $t) => $t->uuid('id')->primary());
        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('role_name');
        });
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('role_id');
            $t->string('username')->unique();
            $t->string('password_hash');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('first_name')->nullable();
            $t->string('middle_name')->nullable();
            $t->string('last_name')->nullable();
            $t->boolean('active');
            $t->boolean('first_login');
            $t->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $t) {
            $t->uuid('user_id');
            $t->uuid('role_id');
        });
        Schema::create('school_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            foreach (['learner_portal_enabled', 'learner_portal_show_fees', 'learner_portal_show_positions', 'learner_portal_show_pathway', 'learner_portal_show_report_cards', 'learner_portal_show_attendance', 'learner_portal_show_results'] as $f) {
                $t->boolean($f);
            }$t->timestamps();
        });
        Schema::create('learners', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('user_id')->nullable()->unique();
            $t->string('admission_no');
            $t->string('first_name');
            $t->string('middle_name')->nullable();
            $t->string('last_name');
            $t->uuid('grade_id')->nullable();
            $t->uuid('stream_id')->nullable();
            $t->boolean('active');
            $t->boolean('portal_enabled');
            $t->timestamp('portal_activated_at')->nullable();
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('learner_dashboard_preferences', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('learner_id')->unique();
            foreach (['timetable', 'attendance', 'results', 'report_cards', 'fees', 'announcements', 'notifications', 'upcoming_exams', 'learning_resources'] as $f) {
                $t->boolean('show_'.$f)->default($f !== 'fees');
            }$t->timestamps();
        });
        foreach (['school', 'other', 'role', 'learner', 'learner2'] as $k) {
            $this->id[$k] = (string) Str::uuid();
        }DB::table('schools')->insert([['id' => $this->id['school']], ['id' => $this->id['other']]]);
        DB::table('roles')->insert(['id' => $this->id['role'], 'role_name' => 'Learner']);
        DB::table('school_settings')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'learner_portal_enabled' => 1, 'learner_portal_show_fees' => 0, 'learner_portal_show_positions' => 0, 'learner_portal_show_pathway' => 1, 'learner_portal_show_report_cards' => 1, 'learner_portal_show_attendance' => 1, 'learner_portal_show_results' => 1]);
        foreach (['learner', 'learner2'] as $l) {
            DB::table('learners')->insert(['id' => $this->id[$l], 'school_id' => $this->id['school'], 'admission_no' => strtoupper($l), 'first_name' => 'Test', 'last_name' => $l, 'active' => 1, 'portal_enabled' => 1, 'is_deleted' => 0]);
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
}
