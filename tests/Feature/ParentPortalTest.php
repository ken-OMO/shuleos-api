<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ParentPortal\ParentPortalAccessService;
use App\Services\ParentPortal\ParentPortalService;
use App\Services\ParentPortal\ParentReportCardAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class ParentPortalTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();

        $school = SchoolBuilder::create();
        $otherSchool = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Parent',
        ]);

        $user = UserBuilder::create($school, $role);

        $grade = GradeBuilder::create($school);
        $stream = StreamBuilder::create($school, $grade);

        $learner1 = LearnerBuilder::create($school, $grade, $stream);
        $learner2 = LearnerBuilder::create($school, $grade, $stream);
        $unlinked = LearnerBuilder::create($school, $grade, $stream);

        $year = AcademicYearBuilder::create($school);
        $term = TermBuilder::create($school, $year);

        $parentId = (string) Str::uuid();

        DB::table('parents')->insert([
            'id' => $parentId,
            'school_id' => $school->id,
            'user_id' => $user->id,
            'first_name' => 'Parent',
            'last_name' => 'One',
            'phone' => '+254700000001',
            'active' => true,
            'is_deleted' => false,
        ]);

        foreach ([$learner1->id, $learner2->id] as $learnerId) {
            DB::table('learner_parents')->insert([
                'id' => (string) Str::uuid(),
                'learner_id' => $learnerId,
                'parent_id' => $parentId,
                'relationship' => 'Parent',
                'is_primary_contact' => true,
                'active' => true,
                'portal_enabled' => true,
                'is_deleted' => false,
            ]);
        }

        DB::table('school_settings')
            ->where('school_id', $school->id)
            ->update([
                'parent_portal_enabled' => true,
                'report_card_fee_policy' => 'open',
                'report_card_balance_threshold' => 0,
                'report_card_allow_admin_override' => true,
                'parent_portal_show_fees' => true,
                'parent_portal_show_attendance' => true,
                'parent_portal_show_announcements' => true,
                'parent_portal_show_pathway' => true,
                'pathway_enabled' => true,
            ]);

        if (! DB::table('school_settings')
            ->where('school_id', $school->id)
            ->exists()) {
            DB::table('school_settings')->insert([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'parent_portal_enabled' => true,
                'report_card_fee_policy' => 'open',
                'report_card_balance_threshold' => 0,
                'report_card_allow_admin_override' => true,
                'parent_portal_show_fees' => true,
                'parent_portal_show_attendance' => true,
                'parent_portal_show_announcements' => true,
                'parent_portal_show_pathway' => true,
                'pathway_enabled' => true,
            ]);
        }

        $assessmentType = (string) Str::uuid();

        DB::table('assessment_types')->insert([
            'id' => $assessmentType,
            'school_id' => $school->id,
            'assessment_type_name' => 'Parent Portal Test',
            'active' => true,
            'is_deleted' => false,
        ]);

        $exam = (string) Str::uuid();
        $draftExam = (string) Str::uuid();

        DB::table('exams')->insert([
            [
                'id' => $exam,
                'school_id' => $school->id,
                'exam_name' => 'Published Parent Portal Exam',
                'assessment_type_id' => $assessmentType,
                'academic_year_id' => $year->id,
                'term_id' => $term->id,
                'active' => true,
                'status' => 'published',
                'is_deleted' => false,
            ],
            [
                'id' => $draftExam,
                'school_id' => $school->id,
                'exam_name' => 'Generated Parent Portal Exam',
                'assessment_type_id' => $assessmentType,
                'academic_year_id' => $year->id,
                'term_id' => $term->id,
                'active' => true,
                'status' => 'draft',
                'is_deleted' => false,
            ],
        ]);

        $card = (string) Str::uuid();
        $draft = (string) Str::uuid();

        DB::table('report_cards')->insert([
            [
                'id' => $card,
                'school_id' => $school->id,
                'learner_id' => $learner1->id,
                'exam_id' => $exam,
                'academic_year_id' => $year->id,
                'term_id' => $term->id,
                'status' => 'published',
                'published_at' => now(),
                'is_deleted' => false,
            ],
            [
                'id' => $draft,
                'school_id' => $school->id,
                'learner_id' => $learner1->id,
                'exam_id' => $draftExam,
                'academic_year_id' => $year->id,
                'term_id' => $term->id,
                'status' => 'generated',
                'published_at' => null,
                'is_deleted' => false,
            ],
        ]);

        $this->id = [
            'school' => $school->id,
            'other' => $otherSchool->id,
            'role' => $role->id,
            'user' => $user->id,
            'parent' => $parentId,
            'grade' => $grade->id,
            'stream' => $stream->id,
            'learner1' => $learner1->id,
            'learner2' => $learner2->id,
            'unlinked' => $unlinked->id,
            'exam' => $exam,
            'draft_exam' => $draftExam,
            'year' => $year->id,
            'term' => $term->id,
            'card' => $card,
            'draft' => $draft,
        ];
    }

    private function user(): User
    {
        return User::with('role')->findOrFail($this->id['user']);
    }

    private function invoice(float $balance, ?string $term = null): void
    {
        $id = (string) Str::uuid();

        DB::table('fee_invoices')->insert([
            'id' => $id,
            'school_id' => $this->id['school'],
            'learner_id' => $this->id['learner1'],
            'academic_year_id' => $this->id['year'],
            'term_id' => $term ?? $this->id['term'],
            'invoice_number' => 'INV-'.strtoupper(substr(str_replace('-', '', $id), 0, 12)),
            'total_amount' => $balance,
            'amount_paid' => 0,
            'balance' => $balance,
            'status' => 'UNPAID',
            'invoice_date' => now()->toDateString(),
        ]);
    }

    public function test_parent_resolves_and_sees_multiple_active_links(): void
    {
        $access = app(ParentPortalAccessService::class);

        $this->assertSame(
            $this->id['parent'],
            $access->parent($this->user())->id
        );

        $this->assertCount(2, $access->links($this->user()));
    }

    public function test_unlinked_inactive_disabled_and_cross_school_are_rejected(): void
    {
        $access = app(ParentPortalAccessService::class);

        foreach (['unlinked', 'learner1', 'learner2'] as $i => $key) {
            if ($i === 1) {
                DB::table('learner_parents')
                    ->where('learner_id', $this->id[$key])
                    ->update(['active' => false]);
            }

            if ($i === 2) {
                DB::table('learner_parents')
                    ->where('learner_id', $this->id[$key])
                    ->update(['portal_enabled' => false]);
            }

            try {
                $access->requireLinkedLearner(
                    $this->user(),
                    $this->id[$key]
                );

                $this->fail('Unauthorized learner access succeeded.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_only_published_cards_are_listed(): void
    {
        $rows = app(ParentPortalService::class)->reportCards(
            $this->user(),
            $this->id['learner1']
        );

        $this->assertCount(1, $rows);
        $this->assertSame(
            $this->id['card'],
            $rows->first()['report_card']->id
        );
    }

    public function test_fee_policies_and_term_specific_balance(): void
    {
        $this->invoice(100);

        $otherTerm = TermBuilder::create(
            (object) ['id' => $this->id['school']],
            (object) ['id' => $this->id['year']],
            [
                'term_name' => 'Other Test Term',
                'start_date' => '2026-08-02',
                'end_date' => '2026-10-31',
            ]
        );

        $this->invoice(999, $otherTerm->id);

        $service = app(ParentReportCardAccessService::class);

        $open = $service->decision(
            $this->user(),
            $this->id['learner1'],
            $this->id['card']
        );

        $this->assertSame(100.0, $open['outstanding_balance']);

        DB::table('school_settings')
            ->where('school_id', $this->id['school'])
            ->update([
                'report_card_fee_policy' => 'download_only_when_cleared',
            ]);

        $decision = $service->decision(
            $this->user(),
            $this->id['learner1'],
            $this->id['card']
        );

        $this->assertTrue($decision['can_view']);
        $this->assertFalse($decision['can_download']);

        DB::table('school_settings')
            ->where('school_id', $this->id['school'])
            ->update([
                'report_card_fee_policy' => 'fully_restricted_when_balance',
            ]);

        $decision = $service->decision(
            $this->user(),
            $this->id['learner1'],
            $this->id['card']
        );

        $this->assertFalse($decision['can_view']);
    }

    public function test_threshold_and_override_expiry(): void
    {
        $this->invoice(50);

        DB::table('school_settings')
            ->where('school_id', $this->id['school'])
            ->update([
                'report_card_fee_policy' => 'balance_threshold',
                'report_card_balance_threshold' => 40,
            ]);

        $service = app(ParentReportCardAccessService::class);

        $this->assertFalse(
            $service->decision(
                $this->user(),
                $this->id['learner1'],
                $this->id['card']
            )['can_view']
        );

        $override = (string) Str::uuid();

        DB::table('report_card_access_overrides')->insert([
            'id' => $override,
            'school_id' => $this->id['school'],
            'learner_id' => $this->id['learner1'],
            'report_card_id' => $this->id['card'],
            'access_scope' => 'both',
            'access_allowed' => true,
            'approved_by' => $this->id['user'],
            'expires_at' => now()->addDay(),
            'is_deleted' => false,
        ]);

        $this->assertTrue(
            $service->decision(
                $this->user(),
                $this->id['learner1'],
                $this->id['card']
            )['can_download']
        );

        DB::table('report_card_access_overrides')
            ->where('id', $override)
            ->update([
                'expires_at' => now()->subDay(),
            ]);

        $this->assertFalse(
            $service->decision(
                $this->user(),
                $this->id['learner1'],
                $this->id['card']
            )['can_download']
        );
    }

    public function test_notifications_and_announcements_are_tenant_and_user_scoped(): void
    {
        DB::table('notifications')->insert([
            [
                'id' => (string) Str::uuid(),
                'school_id' => $this->id['school'],
                'user_id' => $this->id['user'],
                'title' => 'Mine',
                'message' => 'x',
                'is_read' => false,
                'created_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'school_id' => $this->id['school'],
                'user_id' => UserBuilder::create(
                    (object) ['id' => $this->id['school']],
                    (object) ['id' => $this->id['role']]
                )->id,
                'title' => 'Other',
                'message' => 'x',
                'is_read' => false,
                'created_at' => now(),
            ],
        ]);

        $communication = (string) Str::uuid();

        DB::table('communications')->insert([
            'id' => $communication,
            'school_id' => $this->id['school'],
            'sender_user_id' => $this->id['user'],
            'communication_type' => 'announcement',
            'category' => 'general',
            'priority' => 'normal',
            'subject' => 'Sent',
            'body' => 'x',
            'status' => 'sent',
            'requires_approval' => false,
            'risk_level' => 'low',
            'recipient_count' => 1,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('communication_recipient_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $this->id['school'],
            'communication_id' => $communication,
            'user_id' => $this->id['user'],
            'audience_type' => 'parent',
            'resolved_at' => now(),
        ]);

        $portal = app(ParentPortalService::class);

        $this->assertSame(
            1,
            $portal->notifications($this->user())->total()
        );

        $this->assertCount(
            1,
            $portal->announcements($this->user())
        );
    }
}
