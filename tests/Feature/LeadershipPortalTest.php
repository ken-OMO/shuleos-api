<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use App\Services\LeadershipPortal\LeadershipPortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeadershipPortalTest extends TestCase
{
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['leadership_dashboard_preferences', 'approvals', 'hod_assignments', 'teacher_assignments', 'teachers', 'notifications', 'broadcasts', 'payments', 'fee_invoices', 'role_permissions', 'permissions', 'users', 'roles', 'schools'] as $t) {
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
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->boolean('active');
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('permission_name');
        });
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('role_id');
            $t->uuid('permission_id');
        });
        Schema::create('leadership_dashboard_preferences', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('user_id');
            foreach (['attendance', 'teacher_attendance', 'curriculum_coverage', 'pending_approvals', 'lesson_plans', 'records_of_work', 'exams', 'report_cards', 'academic_performance', 'discipline', 'finance', 'announcements', 'notifications', 'teacher_workload', 'learner_enrolment'] as $f) {
                $t->boolean('show_'.$f)->default(1);
            }$t->timestamps();
        });
        Schema::create('teachers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('user_id');
            $t->boolean('active');
            $t->boolean('is_deleted');
        });
        Schema::create('hod_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('teacher_id');
            $t->uuid('learning_area_id');
            $t->boolean('active');
        });
        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('teacher_id');
            $t->uuid('learning_area_id');
            $t->integer('lessons_per_week');
            $t->boolean('active');
            $t->boolean('is_deleted');
        });
        Schema::create('approvals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('entity_type');
            $t->uuid('entity_id');
            $t->uuid('approver_id');
            $t->string('approval_status');
            $t->text('comments')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('fee_invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->decimal('total_amount', 12, 2);
            $t->decimal('amount_paid', 12, 2);
            $t->decimal('balance', 12, 2);
            $t->string('status');
            $t->timestamp('cancelled_at')->nullable();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->decimal('amount', 12, 2);
            $t->timestamp('payment_date');
            $t->boolean('reversed');
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('user_id');
            $t->string('title');
            $t->timestamp('created_at');
        });
        Schema::create('broadcasts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('title');
            $t->string('status');
            $t->timestamp('sent_at')->nullable();
        });
        foreach (['school', 'other', 'principal_role', 'hod_role', 'teacher_role', 'principal', 'hod', 'ordinary', 'teacher', 'area', 'other_area', 'approval'] as $k) {
            $this->id[$k] = (string) Str::uuid();
        }DB::table('schools')->insert([['id' => $this->id['school']], ['id' => $this->id['other']]]);
        DB::table('roles')->insert([['id' => $this->id['principal_role'], 'role_name' => 'Principal'], ['id' => $this->id['hod_role'], 'role_name' => 'HOD'], ['id' => $this->id['teacher_role'], 'role_name' => 'Teacher']]);
        foreach ([['principal', 'principal_role'], ['hod', 'hod_role'], ['ordinary', 'teacher_role']] as [$u,$r]) {
            DB::table('users')->insert(['id' => $this->id[$u], 'school_id' => $this->id['school'], 'role_id' => $this->id[$r], 'first_name' => $u, 'active' => 1]);
        }$permissions = ['access_school_leadership_portal', 'view_school_finance_summary', 'view_school_academic_summary', 'view_school_discipline_summary', 'view_leadership_approvals', 'manage_leadership_approvals'];
        foreach ($permissions as $p) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert(['id' => $id, 'permission_name' => $p]);
            DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $this->id['principal_role'], 'permission_id' => $id]);
            if (in_array($p, ['access_school_leadership_portal', 'view_school_academic_summary', 'view_leadership_approvals', 'manage_leadership_approvals'])) {
                DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $this->id['hod_role'], 'permission_id' => $id]);
            }
        }DB::table('teachers')->insert(['id' => $this->id['teacher'], 'school_id' => $this->id['school'], 'user_id' => $this->id['hod'], 'active' => 1, 'is_deleted' => 0]);
        DB::table('hod_assignments')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'teacher_id' => $this->id['teacher'], 'learning_area_id' => $this->id['area'], 'active' => 1]);
        DB::table('teacher_assignments')->insert([['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'teacher_id' => $this->id['teacher'], 'learning_area_id' => $this->id['area'], 'lessons_per_week' => 5, 'active' => 1, 'is_deleted' => 0], ['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'teacher_id' => (string) Str::uuid(), 'learning_area_id' => $this->id['other_area'], 'lessons_per_week' => 5, 'active' => 1, 'is_deleted' => 0]]);
        DB::table('approvals')->insert(['id' => $this->id['approval'], 'school_id' => $this->id['school'], 'entity_type' => 'lesson_plan', 'entity_id' => (string) Str::uuid(), 'approver_id' => $this->id['hod'], 'approval_status' => 'Pending', 'created_at' => now()]);
    }

    private function u(string $k): User
    {
        return User::with('role')->find($this->id[$k]);
    }

    public function test_principal_has_whole_school_and_hod_is_scoped(): void
    {
        $a = app(LeadershipPortalAccessService::class);
        $this->assertTrue($a->scope($this->u('principal'))['whole_school']);
        $h = $a->scope($this->u('hod'));
        $this->assertSame([$this->id['area']], $h['learning_area_ids']);
        $this->assertFalse($h['finance']);
    }

    public function test_ordinary_teacher_is_rejected(): void
    {
        $this->expectException(AuthorizationException::class);
        app(LeadershipPortalAccessService::class)->scope($this->u('ordinary'));
    }

    public function test_preferences_cannot_enable_unauthorized_finance(): void
    {
        $this->expectException(AuthorizationException::class);
        app(LeadershipPortalService::class)->updatePreferences($this->u('hod'), ['show_finance' => true]);
    }

    public function test_approval_is_scoped_finalized_and_records_identity(): void
    {
        $s = app(LeadershipPortalService::class);
        $x = $s->decide($this->u('hod'), $this->id['approval'], 'Approved', 'ok');
        $this->assertSame('Approved', $x->approval_status);
        $this->assertSame($this->id['hod'], $x->approver_id);
        $this->expectException(ValidationException::class);
        $s->decide($this->u('hod'), $this->id['approval'], 'Rejected', 'no');
    }

    public function test_finance_excludes_cancelled_and_reversed(): void
    {
        DB::table('fee_invoices')->insert([['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'total_amount' => 100, 'amount_paid' => 20, 'balance' => 80, 'status' => 'POSTED', 'cancelled_at' => null], ['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'total_amount' => 999, 'amount_paid' => 0, 'balance' => 999, 'status' => 'CANCELLED', 'cancelled_at' => now()]]);
        DB::table('payments')->insert([['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'amount' => 20, 'payment_date' => now(), 'reversed' => 0], ['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'amount' => 999, 'payment_date' => now(), 'reversed' => 1]]);
        $f = app(LeadershipPortalService::class)->finance($this->u('principal'));
        $this->assertSame(100.0, $f['invoiced']);
        $this->assertSame(20.0, $f['collection_today']);
    }
}
