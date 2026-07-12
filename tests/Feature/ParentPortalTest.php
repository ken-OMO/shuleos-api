<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ParentPortal\ParentPortalAccessService;
use App\Services\ParentPortal\ParentPortalService;
use App\Services\ParentPortal\ParentReportCardAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParentPortalTest extends TestCase
{
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['report_card_access_overrides', 'fee_invoices', 'report_cards', 'notifications', 'broadcasts', 'learner_parents', 'parents', 'learners', 'streams', 'grades', 'school_settings', 'user_roles', 'users', 'roles', 'schools'] as $t) {
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
            $t->boolean('active');
            $t->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $t) {
            $t->uuid('user_id');
            $t->uuid('role_id');
        });
        Schema::create('school_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->boolean('parent_portal_enabled');
            $t->string('report_card_fee_policy');
            $t->decimal('report_card_balance_threshold', 12, 2);
            $t->text('report_card_restriction_message')->nullable();
            $t->boolean('report_card_allow_admin_override');
            $t->boolean('parent_portal_show_fees');
            $t->boolean('parent_portal_show_attendance');
            $t->boolean('parent_portal_show_announcements');
            $t->boolean('parent_portal_show_pathway');
            $t->boolean('pathway_enabled');
            $t->timestamps();
        });
        Schema::create('grades', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
        });
        Schema::create('streams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('grade_id');
        });
        Schema::create('learners', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('grade_id');
            $t->uuid('stream_id');
            $t->boolean('active');
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('parents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('user_id');
            $t->string('first_name');
            $t->string('last_name');
            $t->boolean('active');
            $t->boolean('is_deleted');
        });
        Schema::create('learner_parents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('learner_id');
            $t->uuid('parent_id');
            $t->string('relationship')->nullable();
            $t->boolean('is_primary_contact');
            $t->boolean('active');
            $t->boolean('portal_enabled');
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('report_cards', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('learner_id');
            $t->uuid('exam_id');
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->string('status');
            $t->timestamp('published_at')->nullable();
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('fee_invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('learner_id');
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->decimal('total_amount', 12, 2);
            $t->decimal('amount_paid', 12, 2);
            $t->decimal('balance', 12, 2);
            $t->string('status');
            $t->timestamp('cancelled_at')->nullable();
            $t->date('invoice_date');
        });
        Schema::create('report_card_access_overrides', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('learner_id');
            $t->uuid('exam_id')->nullable();
            $t->uuid('report_card_id')->nullable();
            $t->string('access_scope');
            $t->boolean('access_allowed');
            $t->uuid('approved_by');
            $t->timestamp('expires_at')->nullable();
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('user_id');
            $t->string('title');
            $t->text('message');
            $t->boolean('is_read');
            $t->timestamp('created_at');
        });
        Schema::create('broadcasts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('title');
            $t->text('message_body');
            $t->string('target_group')->nullable();
            $t->string('status');
            $t->timestamp('sent_at')->nullable();
        });
        foreach (['school', 'other', 'role', 'user', 'parent', 'grade', 'stream', 'learner1', 'learner2', 'unlinked', 'exam', 'year', 'term', 'card', 'draft'] as $k) {
            $this->id[$k] = (string) Str::uuid();
        }DB::table('schools')->insert([['id' => $this->id['school']], ['id' => $this->id['other']]]);
        DB::table('roles')->insert(['id' => $this->id['role'], 'role_name' => 'Parent']);
        DB::table('users')->insert(['id' => $this->id['user'], 'school_id' => $this->id['school'], 'role_id' => $this->id['role'], 'active' => 1]);
        DB::table('school_settings')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'parent_portal_enabled' => 1, 'report_card_fee_policy' => 'open', 'report_card_balance_threshold' => 0, 'report_card_allow_admin_override' => 1, 'parent_portal_show_fees' => 1, 'parent_portal_show_attendance' => 1, 'parent_portal_show_announcements' => 1, 'parent_portal_show_pathway' => 1, 'pathway_enabled' => 1]);
        DB::table('grades')->insert(['id' => $this->id['grade'], 'school_id' => $this->id['school']]);
        DB::table('streams')->insert(['id' => $this->id['stream'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['grade']]);
        foreach (['learner1', 'learner2', 'unlinked'] as $l) {
            DB::table('learners')->insert(['id' => $this->id[$l], 'school_id' => $this->id['school'], 'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream'], 'active' => 1, 'is_deleted' => 0]);
        }DB::table('parents')->insert(['id' => $this->id['parent'], 'school_id' => $this->id['school'], 'user_id' => $this->id['user'], 'first_name' => 'Parent', 'last_name' => 'One', 'active' => 1, 'is_deleted' => 0]);
        foreach (['learner1', 'learner2'] as $l) {
            DB::table('learner_parents')->insert(['id' => (string) Str::uuid(), 'learner_id' => $this->id[$l], 'parent_id' => $this->id['parent'], 'relationship' => 'Parent', 'is_primary_contact' => 1, 'active' => 1, 'portal_enabled' => 1, 'is_deleted' => 0]);
        }DB::table('report_cards')->insert([['id' => $this->id['card'], 'school_id' => $this->id['school'], 'learner_id' => $this->id['learner1'], 'exam_id' => $this->id['exam'], 'academic_year_id' => $this->id['year'], 'term_id' => $this->id['term'], 'status' => 'published', 'published_at' => now(), 'is_deleted' => 0], ['id' => $this->id['draft'], 'school_id' => $this->id['school'], 'learner_id' => $this->id['learner1'], 'exam_id' => $this->id['exam'], 'academic_year_id' => $this->id['year'], 'term_id' => $this->id['term'], 'status' => 'generated', 'published_at' => null, 'is_deleted' => 0]]);
    }

    private function user(): User
    {
        return User::with('role')->find($this->id['user']);
    }

    private function invoice(float $b, ?string $term = null): void
    {
        DB::table('fee_invoices')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'learner_id' => $this->id['learner1'], 'academic_year_id' => $this->id['year'], 'term_id' => $term ?? $this->id['term'], 'total_amount' => $b, 'amount_paid' => 0, 'balance' => $b, 'status' => 'UNPAID', 'invoice_date' => now()]);
    }

    public function test_parent_resolves_and_sees_multiple_active_links(): void
    {
        $a = app(ParentPortalAccessService::class);
        $this->assertSame($this->id['parent'], $a->parent($this->user())->id);
        $this->assertCount(2, $a->links($this->user()));
    }

    public function test_unlinked_inactive_disabled_and_cross_school_are_rejected(): void
    {
        $a = app(ParentPortalAccessService::class);
        foreach (['unlinked', 'learner1', 'learner2'] as $i => $key) {
            if ($i === 1) {
                DB::table('learner_parents')->where('learner_id', $this->id[$key])->update(['active' => 0]);
            }if ($i === 2) {
                DB::table('learner_parents')->where('learner_id', $this->id[$key])->update(['portal_enabled' => 0]);
            }try {
                $a->requireLinkedLearner($this->user(), $this->id[$key]);
                $this->fail();
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_only_published_cards_are_listed(): void
    {
        $rows = app(ParentPortalService::class)->reportCards($this->user(), $this->id['learner1']);
        $this->assertCount(1, $rows);
        $this->assertSame($this->id['card'], $rows->first()['report_card']->id);
    }

    public function test_fee_policies_and_term_specific_balance(): void
    {
        $this->invoice(100);
        $this->invoice(999, (string) Str::uuid());
        $s = app(ParentReportCardAccessService::class);
        $open = $s->decision($this->user(), $this->id['learner1'], $this->id['card']);
        $this->assertSame(100.0, $open['outstanding_balance']);
        DB::table('school_settings')->update(['report_card_fee_policy' => 'download_only_when_cleared']);
        $d = $s->decision($this->user(), $this->id['learner1'], $this->id['card']);
        $this->assertTrue($d['can_view']);
        $this->assertFalse($d['can_download']);
        DB::table('school_settings')->update(['report_card_fee_policy' => 'fully_restricted_when_balance']);
        $d = $s->decision($this->user(), $this->id['learner1'], $this->id['card']);
        $this->assertFalse($d['can_view']);
    }

    public function test_threshold_and_override_expiry(): void
    {
        $this->invoice(50);
        DB::table('school_settings')->update(['report_card_fee_policy' => 'balance_threshold', 'report_card_balance_threshold' => 40]);
        $s = app(ParentReportCardAccessService::class);
        $this->assertFalse($s->decision($this->user(), $this->id['learner1'], $this->id['card'])['can_view']);
        DB::table('report_card_access_overrides')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'learner_id' => $this->id['learner1'], 'report_card_id' => $this->id['card'], 'access_scope' => 'both', 'access_allowed' => 1, 'approved_by' => $this->id['user'], 'expires_at' => now()->addDay(), 'is_deleted' => 0]);
        $this->assertTrue($s->decision($this->user(), $this->id['learner1'], $this->id['card'])['can_download']);
        DB::table('report_card_access_overrides')->update(['expires_at' => now()->subDay()]);
        $this->assertFalse($s->decision($this->user(), $this->id['learner1'], $this->id['card'])['can_download']);
    }

    public function test_notifications_and_announcements_are_tenant_and_user_scoped(): void
    {
        DB::table('notifications')->insert([['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'user_id' => $this->id['user'], 'title' => 'Mine', 'message' => 'x', 'is_read' => 0, 'created_at' => now()], ['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'user_id' => (string) Str::uuid(), 'title' => 'Other', 'message' => 'x', 'is_read' => 0, 'created_at' => now()]]);
        DB::table('broadcasts')->insert([['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'title' => 'Sent', 'message_body' => 'x', 'target_group' => 'parents', 'status' => 'SENT', 'sent_at' => now()], ['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'title' => 'Draft', 'message_body' => 'x', 'target_group' => 'parents', 'status' => 'DRAFT', 'sent_at' => null]]);
        $p = app(ParentPortalService::class);
        $this->assertSame(1, $p->notifications($this->user())->total());
        $this->assertCount(1, $p->announcements($this->user()));
    }
}
