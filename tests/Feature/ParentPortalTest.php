<?php

namespace Tests\Feature;

use App\Http\Resources\ParentPortalArrayResource;
use App\Models\ReportCard;
use App\Models\User;
use App\Services\LearnerPortal\LearnerAccountService;
use App\Services\LearnerPortal\LearnerPortalService;
use App\Services\LearnerPortal\LearnerReportCardAccessService;
use App\Services\ParentPortal\ParentPortalAccessService;
use App\Services\ParentPortal\ParentPortalService;
use App\Services\ParentPortal\ParentReportCardAccessService;
use App\Services\Pdf\ReportCardPdfService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;
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

    public function test_learner_report_access_ignores_expired_and_deleted_overrides(): void
    {
        $user = $this->reportLearnerUser();
        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update(['report_card_fee_policy' => 'admin_approval']);

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

        $service = app(LearnerReportCardAccessService::class);
        $allowed = $service->decision($user, $this->id['card']);
        $this->assertTrue($allowed['view']);
        $this->assertTrue($allowed['download']);

        foreach ([
            ['expires_at' => now()->subDay(), 'is_deleted' => false],
            ['expires_at' => now()->addDay(), 'is_deleted' => true],
        ] as $state) {
            DB::table('report_card_access_overrides')->where('id', $override)
                ->update($state);

            $decision = $service->decision($user, $this->id['card']);
            $this->assertFalse($decision['view']);
            $this->assertFalse($decision['download']);

            foreach (['requireView', 'requireDownload'] as $method) {
                try {
                    $service->$method($user, $this->id['card']);
                    $this->fail('Invalid overrides must not authorize access.');
                } catch (AuthorizationException) {
                    $this->assertTrue(true);
                }
            }
        }
    }

    public function test_hidden_learner_report_cards_reject_view_and_download(): void
    {
        $user = $this->reportLearnerUser();
        $service = app(LearnerReportCardAccessService::class);

        $this->assertSame(
            $this->id['card'],
            $service->requireView($user, $this->id['card'])->id
        );

        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update(['learner_portal_show_report_cards' => false]);

        foreach (['requireView', 'requireDownload'] as $method) {
            try {
                $service->$method($user, $this->id['card']);
                $this->fail('Hidden report cards must not be accessible.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Report cards are disabled.', $exception->getMessage());
            }
        }
    }

    public function test_authenticated_learner_pdf_routes_enforce_report_access_before_rendering(): void
    {
        config(['jwt.secret' => str_repeat('assessment-http-test-', 4)]);
        $user = $this->reportLearnerUser();

        foreach ([
            'access_learner_portal',
            'view_own_report_cards',
            'download_own_report_cards',
        ] as $name) {
            $permission = DB::table('permissions')
                ->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission, 'Missing permission: '.$name);

            if (! DB::table('role_permissions')
                ->where('role_id', $user->role_id)
                ->where('permission_id', $permission)->exists()) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $user->role_id,
                    'permission_id' => $permission,
                    'created_at' => now(),
                ]);
            }
        }

        $token = JWTAuth::fromUser($user);
        $cardId = $this->id['card'];

        // Only the two authorized requests may reach PDF generation.
        $document = new class
        {
            public function stream(string $filename): Response
            {
                return new Response(
                    '%PDF-test-authorized', 200,
                    ['Content-Type' => 'application/pdf']
                );
            }
        };

        $this->mock(ReportCardPdfService::class)
            ->shouldReceive('make')->twice()
            ->with($this->id['school'], $cardId)
            ->andReturn(['pdf' => $document, 'filename' => 'report.pdf']);

        $base = '/api/learner/report-cards/'.$cardId;
        foreach (['pdf', 'download'] as $suffix) {
            $this->withToken($token)->get($base.'/'.$suffix, [
                'Accept' => 'application/json',
            ])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }

        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update(['learner_portal_show_report_cards' => false]);

        foreach (['pdf', 'download'] as $suffix) {
            $this->withToken($token)->getJson($base.'/'.$suffix)->assertForbidden();
        }
        $this->withToken($token)->getJson($base)->assertForbidden();

        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update([
                'learner_portal_show_report_cards' => true,
                'report_card_fee_policy' => 'admin_approval',
            ]);

        $override = (string) Str::uuid();
        DB::table('report_card_access_overrides')->insert([
            'id' => $override,
            'school_id' => $this->id['school'],
            'learner_id' => $this->id['learner1'],
            'report_card_id' => $cardId,
            'access_scope' => 'both',
            'access_allowed' => true,
            'approved_by' => $this->id['user'],
            'expires_at' => now()->subDay(),
            'is_deleted' => false,
        ]);

        foreach ([
            ['expires_at' => now()->subDay(), 'is_deleted' => false],
            ['expires_at' => now()->addDay(), 'is_deleted' => true],
        ] as $state) {
            DB::table('report_card_access_overrides')->where('id', $override)
                ->update($state);

            foreach (['pdf', 'download'] as $suffix) {
                $this->withToken($token)->getJson($base.'/'.$suffix)->assertForbidden();
            }
            $this->withToken($token)->getJson($base)->assertForbidden();
        }
    }

    public function test_learner_report_list_hides_restricted_scores_and_honours_override_scope(): void
    {
        $user = $this->reportLearnerUser();
        $portal = app(LearnerPortalService::class);

        $visible = $portal->reportCards($user)->firstWhere('id', $this->id['card']);
        $this->assertInstanceOf(ReportCard::class, $visible);

        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update(['report_card_fee_policy' => 'admin_approval']);

        $locked = [
            'id' => $this->id['card'],
            'access' => ['can_view' => false, 'can_download' => false],
        ];
        $this->assertSame(
            $locked,
            $portal->reportCards($user)->firstWhere('id', $this->id['card'])
        );

        $override = (string) Str::uuid();
        DB::table('report_card_access_overrides')->insert([
            'id' => $override,
            'school_id' => $this->id['school'],
            'learner_id' => $this->id['learner1'],
            'report_card_id' => $this->id['card'],
            'access_scope' => 'download',
            'access_allowed' => true,
            'approved_by' => $this->id['user'],
            'expires_at' => now()->addDay(),
            'is_deleted' => false,
        ]);

        $downloadOnly = $locked;
        $downloadOnly['access']['can_download'] = true;
        $this->assertSame(
            $downloadOnly,
            $portal->reportCards($user)->firstWhere('id', $this->id['card'])
        );

        DB::table('report_card_access_overrides')->where('id', $override)
            ->update(['access_scope' => 'view']);

        $allowed = $portal->reportCards($user)->firstWhere('id', $this->id['card']);
        $this->assertInstanceOf(ReportCard::class, $allowed);
        $this->assertSame($visible->overall_score, $allowed->overall_score);

        DB::table('report_card_access_overrides')->where('id', $override)
            ->update(['expires_at' => now()->subDay()]);

        $this->assertSame(
            $locked,
            $portal->reportCards($user)->firstWhere('id', $this->id['card'])
        );
    }

    public function test_nested_parent_report_cards_respect_view_restrictions(): void
    {
        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update(['report_card_fee_policy' => 'admin_approval']);

        $portal = app(ParentPortalService::class);
        $rows = $portal->reportCards($this->user(), $this->id['learner1']);
        $entry = $rows->first(fn ($row) => $row['report_card']->id === $this->id['card']);
        $this->assertNotNull($entry);

        $payload = (new ParentPortalArrayResource([
            'latest_report_card' => $entry,
            'report_cards' => collect([$entry]),
        ]))->resolve(request());

        foreach ([
            $payload['latest_report_card'],
            $payload['report_cards'][0],
        ] as $card) {
            $this->assertSame($this->id['card'], $card['id']);
            $this->assertFalse($card['access']['can_view']);
            $this->assertFalse($card['access']['can_download']);
            $this->assertSame(['id', 'access'], array_keys($card));
        }

        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update(['report_card_fee_policy' => 'open']);

        $entry = $portal->reportCards($this->user(), $this->id['learner1'])
            ->first(fn ($row) => $row['report_card']->id === $this->id['card']);

        $visible = (new ParentPortalArrayResource([
            'latest_report_card' => $entry,
        ]))->resolve(request())['latest_report_card'];

        $this->assertTrue($visible['access']['can_view']);
        $this->assertArrayHasKey('overall_score', $visible);
        $this->assertEquals($entry['report_card']->overall_score, $visible['overall_score']);
        $this->assertArrayNotHasKey('report_card', $visible);
        $this->assertArrayNotHasKey('school_id', $visible);
    }

    public function test_authenticated_parent_dashboards_hide_restricted_report_scores(): void
    {
        config(['jwt.secret' => str_repeat('assessment-http-test-', 4)]);
        $user = $this->user();

        $permission = DB::table('permissions')
            ->where('permission_name', 'access_parent_portal_phase_two')->value('id');
        $this->assertNotNull($permission);

        if (! DB::table('role_permissions')
            ->where('role_id', $user->role_id)
            ->where('permission_id', $permission)->exists()) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $user->role_id,
                'permission_id' => $permission,
                'created_at' => now(),
            ]);
        }

        $token = JWTAuth::fromUser($user);
        $urls = [
            '/api/parent/dashboard',
            '/api/parent/phase-two/dashboard',
        ];

        foreach (['admin_approval', 'open'] as $policy) {
            DB::table('school_settings')->where('school_id', $this->id['school'])
                ->update(['report_card_fee_policy' => $policy]);

            foreach ($urls as $url) {
                $response = $this->withToken($token)->getJson(
                    $url.'?learner_id='.$this->id['learner1']
                )->assertOk();

                $card = $response->json('data.latest_published_report_card');
                $this->assertIsArray($card);
                $this->assertSame($this->id['card'], $card['id']);
                $this->assertArrayNotHasKey('report_card', $card);
                $this->assertArrayNotHasKey('school_id', $card);

                if ($policy === 'admin_approval') {
                    $this->assertSame(['id', 'access'], array_keys($card));
                    $this->assertFalse($card['access']['can_view']);
                    $this->assertFalse($card['access']['can_download']);
                } else {
                    $this->assertTrue($card['access']['can_view']);
                    $this->assertArrayHasKey('overall_score', $card);
                    $this->assertEquals(
                        DB::table('report_cards')->where('id', $this->id['card'])
                            ->value('overall_score'),
                        $card['overall_score']
                    );
                }
            }
        }
    }

    public function test_authenticated_learner_dashboard_respects_report_visibility_and_override_scope(): void
    {
        config(['jwt.secret' => str_repeat('assessment-http-test-', 4)]);
        $user = $this->reportLearnerUser();

        foreach (['access_learner_portal', 'access_learner_portal_phase_two'] as $name) {
            $permission = DB::table('permissions')
                ->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission, 'Missing permission: '.$name);

            if (! DB::table('role_permissions')
                ->where('role_id', $user->role_id)
                ->where('permission_id', $permission)->exists()) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $user->role_id,
                    'permission_id' => $permission,
                    'created_at' => now(),
                ]);
            }
        }

        $token = JWTAuth::fromUser($user);
        $dashboard = fn () => $this->withToken($token)
            ->getJson('/api/learner/dashboard')->assertOk();

        $visible = $dashboard()->json('data.latest_report_card');
        $this->assertSame($this->id['card'], $visible['id']);
        $this->assertArrayHasKey('average_percentage', $visible);

        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update(['report_card_fee_policy' => 'admin_approval']);

        $locked = [
            'id' => $this->id['card'],
            'access' => ['can_view' => false, 'can_download' => false],
        ];
        $this->assertSame($locked, $dashboard()->json('data.latest_report_card'));

        $override = (string) Str::uuid();
        DB::table('report_card_access_overrides')->insert([
            'id' => $override,
            'school_id' => $this->id['school'],
            'learner_id' => $this->id['learner1'],
            'report_card_id' => $this->id['card'],
            'access_scope' => 'download',
            'access_allowed' => true,
            'approved_by' => $this->id['user'],
            'expires_at' => now()->addDay(),
            'is_deleted' => false,
        ]);

        $downloadOnly = $locked;
        $downloadOnly['access']['can_download'] = true;
        $this->assertSame(
            $downloadOnly, $dashboard()->json('data.latest_report_card')
        );

        DB::table('report_card_access_overrides')->where('id', $override)
            ->update(['access_scope' => 'view']);
        $this->assertSame($visible, $dashboard()->json('data.latest_report_card'));

        foreach ([
            ['expires_at' => now()->subDay(), 'is_deleted' => false],
            ['expires_at' => now()->addDay(), 'is_deleted' => true],
        ] as $state) {
            DB::table('report_card_access_overrides')->where('id', $override)
                ->update($state);
            $this->assertSame($locked, $dashboard()->json('data.latest_report_card'));
        }

        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update(['learner_portal_show_report_cards' => false]);

        $this->assertNull($dashboard()->json('data.latest_report_card'));
    }

    public function test_authenticated_parent_progress_projects_nested_report_card_collections(): void
    {
        config(['jwt.secret' => str_repeat('assessment-http-test-', 4)]);
        $user = $this->user();

        $permission = DB::table('permissions')
            ->where('permission_name', 'view_linked_learner_progress')->value('id');
        $this->assertNotNull($permission);

        if (! DB::table('role_permissions')
            ->where('role_id', $user->role_id)
            ->where('permission_id', $permission)->exists()) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $user->role_id,
                'permission_id' => $permission,
                'created_at' => now(),
            ]);
        }

        $token = JWTAuth::fromUser($user);
        $base = '/api/parent/children/'.$this->id['learner1'].'/progress';

        foreach (['admin_approval', 'open'] as $policy) {
            DB::table('school_settings')->where('school_id', $this->id['school'])
                ->update(['report_card_fee_policy' => $policy]);

            foreach ([$base, $base.'/academics'] as $url) {
                $response = $this->withToken($token)->getJson($url)->assertOk();
                $cards = $response->json('data.academics.published_report_cards');

                $this->assertIsArray($cards);
                $this->assertCount(1, $cards);
                $card = $cards[0];

                $this->assertSame($this->id['card'], $card['id']);
                $this->assertArrayNotHasKey('report_card', $card);
                $this->assertArrayNotHasKey('school_id', $card);

                if ($policy === 'admin_approval') {
                    $this->assertSame(['id', 'access'], array_keys($card));
                    $this->assertFalse($card['access']['can_view']);
                    $this->assertFalse($card['access']['can_download']);
                } else {
                    $this->assertTrue($card['access']['can_view']);
                    $this->assertArrayHasKey('overall_score', $card);
                    $this->assertEquals(
                        DB::table('report_cards')->where('id', $this->id['card'])
                            ->value('overall_score'),
                        $card['overall_score']
                    );
                }
            }
        }
    }

    public function test_authenticated_learner_progress_protects_report_card_history(): void
    {
        config(['jwt.secret' => str_repeat('assessment-http-test-', 4)]);
        $user = $this->reportLearnerUser();

        foreach (['access_learner_portal', 'view_own_academic_progress'] as $name) {
            $permission = DB::table('permissions')
                ->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission, 'Missing permission: '.$name);

            if (! DB::table('role_permissions')
                ->where('role_id', $user->role_id)
                ->where('permission_id', $permission)->exists()) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $user->role_id,
                    'permission_id' => $permission,
                    'created_at' => now(),
                ]);
            }
        }

        $token = JWTAuth::fromUser($user);
        $history = fn () => $this->withToken($token)
            ->getJson('/api/learner/progress')->assertOk()
            ->json('data.report_card_history');

        $visible = $history();
        $this->assertCount(1, $visible);
        $this->assertSame($this->id['card'], $visible[0]['id']);
        $this->assertArrayHasKey('average_percentage', $visible[0]);

        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update(['report_card_fee_policy' => 'admin_approval']);

        $locked = [[
            'id' => $this->id['card'],
            'access' => ['can_view' => false, 'can_download' => false],
        ]];
        $this->assertSame($locked, $history());

        $override = (string) Str::uuid();
        DB::table('report_card_access_overrides')->insert([
            'id' => $override,
            'school_id' => $this->id['school'],
            'learner_id' => $this->id['learner1'],
            'report_card_id' => $this->id['card'],
            'access_scope' => 'download',
            'access_allowed' => true,
            'approved_by' => $this->id['user'],
            'expires_at' => now()->addDay(),
            'is_deleted' => false,
        ]);

        $downloadOnly = $locked;
        $downloadOnly[0]['access']['can_download'] = true;
        $this->assertSame($downloadOnly, $history());

        DB::table('report_card_access_overrides')->where('id', $override)
            ->update(['access_scope' => 'view']);
        $this->assertSame($visible, $history());

        foreach ([
            ['expires_at' => now()->subDay(), 'is_deleted' => false],
            ['expires_at' => now()->addDay(), 'is_deleted' => true],
        ] as $state) {
            DB::table('report_card_access_overrides')->where('id', $override)
                ->update($state);
            $this->assertSame($locked, $history());
        }

        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update(['learner_portal_show_report_cards' => false]);

        $this->assertSame([], $history());
    }

    private function reportLearnerUser(): User
    {
        DB::table('school_settings')->where('school_id', $this->id['school'])
            ->update([
                'learner_portal_enabled' => true,
                'learner_portal_show_report_cards' => true,
            ]);
        DB::table('learners')->where('id', $this->id['learner1'])
            ->update(['portal_enabled' => true]);

        $account = app(LearnerAccountService::class)
            ->create($this->id['school'], $this->id['learner1'], []);

        return User::withoutGlobalScopes()->with('role')
            ->findOrFail($account['user']['id']);
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
