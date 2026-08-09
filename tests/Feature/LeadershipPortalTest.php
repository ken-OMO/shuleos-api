<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use App\Services\LeadershipPortal\LeadershipPortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeadershipPortalTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['school', 'other', 'principal_role', 'hod_role', 'teacher_role', 'principal', 'hod', 'ordinary', 'teacher', 'area', 'other_area', 'grade', 'academic_year', 'term', 'learner', 'approval'] as $k) {
            $this->id[$k] = (string) Str::uuid();
        }
        DB::table('schools')->insert([
            [
                'id' => $this->id['school'],
                'school_name' => 'Leadership Test School',
                'school_code' => 'LEADERSHIP-TEST',
                'active' => true,
                'is_deleted' => false,
            ],
            [
                'id' => $this->id['other'],
                'school_name' => 'Other Leadership Test School',
                'school_code' => 'LEADERSHIP-OTHER',
                'active' => true,
                'is_deleted' => false,
            ],
        ]);
        DB::table('roles')->insert([['id' => $this->id['principal_role'], 'role_name' => 'Principal'], ['id' => $this->id['hod_role'], 'role_name' => 'HOD'], ['id' => $this->id['teacher_role'], 'role_name' => 'Teacher']]);
        foreach ([['principal', 'principal_role'], ['hod', 'hod_role'], ['ordinary', 'teacher_role']] as [$u,$r]) {
            DB::table('users')->insert(['id' => $this->id[$u], 'school_id' => $this->id['school'], 'role_id' => $this->id[$r], 'username' => 'leadership-'.$u.'-'.substr($this->id[$u], 0, 8), 'password_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'first_name' => $u, 'active' => 1]);
        }$permissions = ['access_school_leadership_portal', 'view_school_finance_summary', 'view_school_academic_summary', 'view_school_discipline_summary', 'view_leadership_approvals', 'manage_leadership_approvals'];
        foreach ($permissions as $p) {
            $id = DB::table('permissions')->where('permission_name', $p)->value('id');

            if (! $id) {
                throw new \RuntimeException("Required migrated permission [$p] was not found.");
            }

            DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $this->id['principal_role'], 'permission_id' => $id]);

            if (in_array($p, ['access_school_leadership_portal', 'view_school_academic_summary', 'view_leadership_approvals', 'manage_leadership_approvals'])) {
                DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $this->id['hod_role'], 'permission_id' => $id]);
            }
        }
        DB::table('teachers')->insert(['id' => $this->id['teacher'], 'school_id' => $this->id['school'], 'user_id' => $this->id['hod'], 'active' => 1, 'is_deleted' => 0]);
        DB::table('learning_areas')->insert([
            [
                'id' => $this->id['area'],
                'learning_area_name' => 'Leadership Test Area '.substr($this->id['area'], 0, 8),
                'short_name' => 'LTA-'.substr($this->id['area'], 0, 4),
                'active' => true,
            ],
            [
                'id' => $this->id['other_area'],
                'learning_area_name' => 'Leadership Other Area '.substr($this->id['other_area'], 0, 8),
                'short_name' => 'LOA-'.substr($this->id['other_area'], 0, 4),
                'active' => true,
            ],
        ]);

        DB::table('academic_years')->insert([
            'id' => $this->id['academic_year'],
            'school_id' => $this->id['school'],
            'year_name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('grades')->insert([
            'id' => $this->id['grade'],
            'school_id' => $this->id['school'],
            'grade_name' => 'Grade 7',
            'grade_order' => 7,
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('terms')->insert([
            'id' => $this->id['term'],
            'school_id' => $this->id['school'],
            'academic_year_id' => $this->id['academic_year'],
            'term_name' => 'Term 1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-04-30',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('hod_assignments')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'teacher_id' => $this->id['teacher'], 'learning_area_id' => $this->id['area'], 'academic_year_id' => $this->id['academic_year'], 'active' => 1]);
        DB::table('teacher_assignments')->insert([['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'teacher_id' => $this->id['teacher'], 'learning_area_id' => $this->id['area'], 'grade_id' => $this->id['grade'], 'academic_year_id' => $this->id['academic_year'], 'term_id' => $this->id['term'], 'lessons_per_week' => 5, 'active' => 1, 'is_deleted' => 0], ['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'teacher_id' => $this->id['teacher'], 'learning_area_id' => $this->id['other_area'], 'grade_id' => $this->id['grade'], 'academic_year_id' => $this->id['academic_year'], 'term_id' => $this->id['term'], 'lessons_per_week' => 5, 'active' => 1, 'is_deleted' => 0]]);
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
        DB::table('fee_invoices')->insert([
            [
                'id' => (string) Str::uuid(),
                'school_id' => $this->id['school'],
                'learner_id' => $this->id['learner'],
                'academic_year_id' => $this->id['academic_year'],
                'term_id' => $this->id['term'],
                'invoice_number' => 'LEAD-INV-001',
                'total_amount' => 100,
                'amount_paid' => 20,
                'balance' => 80,
                'status' => 'POSTED',
                'invoice_date' => '2026-01-15',
                'cancelled_at' => null,
            ],
            [
                'id' => (string) Str::uuid(),
                'school_id' => $this->id['school'],
                'learner_id' => $this->id['learner'],
                'academic_year_id' => $this->id['academic_year'],
                'term_id' => $this->id['term'],
                'invoice_number' => 'LEAD-INV-002',
                'total_amount' => 999,
                'amount_paid' => 0,
                'balance' => 999,
                'status' => 'CANCELLED',
                'invoice_date' => '2026-01-15',
                'cancelled_at' => now(),
            ],
        ]);
        DB::table('payments')->insert([
            [
                'id' => (string) Str::uuid(),
                'school_id' => $this->id['school'],
                'learner_id' => $this->id['learner'],
                'payment_method_id' => (string) Str::uuid(),
                'receipt_number' => 'LEAD-PAY-001',
                'amount' => 20,
                'payment_date' => now(),
                'reversed' => false,
            ],
            [
                'id' => (string) Str::uuid(),
                'school_id' => $this->id['school'],
                'learner_id' => $this->id['learner'],
                'payment_method_id' => (string) Str::uuid(),
                'receipt_number' => 'LEAD-PAY-002',
                'amount' => 999,
                'payment_date' => now(),
                'reversed' => true,
            ],
        ]);
        $f = app(LeadershipPortalService::class)->finance($this->u('principal'));
        $this->assertSame(100.0, $f['invoiced']);
        $this->assertSame(20.0, $f['collection_today']);
    }
}
