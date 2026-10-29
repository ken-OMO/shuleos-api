<?php

namespace Tests\Feature;

use App\Core\Security\File\SecureFileStorage;
use App\Http\Resources\ParentReportCardResource;
use App\Http\Resources\ReportCardLearningAreaResource;
use App\Http\Resources\ReportCardResource;
use App\Models\Learner;
use App\Models\ReportCard;
use App\Models\User;
use App\Services\Administrator\AdministratorPortalAccessService;
use App\Services\Administrator\SchoolBrandingAdministrationService;
use App\Services\Assessment\AssessmentCorrectionProposalService;
use App\Services\Assessment\AssessmentPublicationArchiveService;
use App\Services\Assessment\AssessmentPublicationHistoryService;
use App\Services\Assessment\AssessmentReissueCalculationService;
use App\Services\Assessment\AssessmentReissuePreparationService;
use App\Services\Assessment\AssessmentReissuePublicationService;
use App\Services\Assessment\AssessmentReissueReportAssembler;
use App\Services\Assessment\AssessmentReissueRequestService;
use App\Services\Assessment\AssessmentReissueReviewService;
use App\Services\Assessment\ExamResultLifecycleService;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ReportBrandingAssetService;
use App\Services\Assessment\ReportCardPresentationSnapshotService;
use App\Services\Assessment\ReportCardService;
use App\Services\LearnerPortal\LearnerAccountService;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\LearnerPortal\LearnerPortalService;
use App\Services\Pdf\ReportCardPdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\Database\EducationLevelBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\UserBuilder;

class ReportCardPdfTest extends ReportCardTest
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('school_settings')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => DB::table('exams')->where('exam_name', 'Report Card Exam')->value('school_id'),
            'school_motto' => 'Knowledge and Integrity',
            'principal_name' => 'Test Principal',
            'principal_signature_url' => null,
            'school_logo_url' => null,
            'report_header' => 'Academic Report',
            'report_footer' => 'End of Report',
            'pathway_enabled' => true,
        ]);
    }

    public function test_captured_card_grades_survive_live_edits_before_and_after_publication(): void
    {
        $context = $this->context();
        $original = $context['card']->fresh()->load('learningAreas');
        $award = $original->grading_snapshot;
        $detailAwards = $original->learningAreas->pluck('grading_snapshot', 'id')->all();

        $this->assertNotNull($award);
        foreach ($detailAwards as $detailAward) {
            $this->assertNotNull($detailAward);
        }

        $systemIds = DB::table('learning_area_results')
            ->where('exam_id', $context['exam']->id)
            ->pluck('grading_system_id')->filter()->unique();

        DB::table('grading_scales')->whereIn('grading_system_id', $systemIds)->update([
            'grade_code' => 'LIVE_CHANGED',
            'grade_description' => 'LIVE DESCRIPTION CHANGED',
            'points' => 1,
        ]);
        DB::table('grading_systems')->whereIn('id', $systemIds)
            ->update(['active' => false]);

        $card = app(ReportCardService::class)->generate(
            $context['exam']->school_id, $context['exam']->id,
            $context['learner']->id, null, null, $context['user']->id
        )->first();

        $this->assertSame($award, $card->grading_snapshot);
        $this->assertSame($award['grade_code'], $card->overall_grade);
        $this->assertSame(
            $detailAwards, $card->learningAreas->pluck('grading_snapshot', 'id')->all()
        );

        app(ReportCardService::class)->publish(
            $context['exam']->school_id, $context['exam']->id,
            $context['learner']->id, null, null, $context['user']->id
        );

        DB::table('grading_scales')->whereIn('grading_system_id', $systemIds)->update([
            'grade_code' => 'LATER_CHANGED',
            'grade_description' => 'LATER DESCRIPTION CHANGED',
            'points' => 2,
        ]);

        $card = $card->fresh()->load([
            'school.settings', 'learner', 'exam', 'academicYear', 'term',
            'grade', 'stream', 'overallGradingScale', 'pathwayRecommendation',
            'learningAreas.learningArea', 'learningAreas.gradingScale',
        ]);

        $data = (new ReportCardResource($card))->resolve(request());
        $this->assertSame('published', $card->status);
        $this->assertSame($award['grade_code'], $data['overall_grade']);
        $this->assertSame($award['grade_description'], $data['grade_description']);
        $this->assertSame($award['points'], $data['overall_points']);

        foreach ($card->learningAreas as $detail) {
            $detailData = (new ReportCardLearningAreaResource($detail))
                ->resolve(request());
            $this->assertSame($detailAwards[$detail->id], $detail->grading_snapshot);
            $this->assertSame(
                $detail->grading_snapshot['grade_description'],
                $detailData['grade_description']
            );
            $this->assertSame($detail->grading_snapshot['points'], $detailData['points']);
        }

        $html = view('pdf.report-card', [
            'card' => $card, 'settings' => null, 'logo' => null, 'signature' => null,
        ])->render();

        $this->assertStringContainsString($award['grade_description'], $html);
        $this->assertStringNotContainsString('LIVE_CHANGED', $html);
        $this->assertStringNotContainsString('LATER_CHANGED', $html);
        $this->assertStringNotContainsString('LATER DESCRIPTION CHANGED', $html);

        $document = app(ReportCardPdfService::class)->make(
            $context['exam']->school_id, $card->id
        );
        $this->assertStringStartsWith('%PDF', $document['pdf']->output());
    }

    public function test_card_generation_rejects_legacy_merit_without_writing_cards(): void
    {
        $exam = DB::table('exams')->where('exam_name', 'Report Card Exam')->first();
        $userId = DB::table('users')->where('school_id', $exam->school_id)->value('id');

        DB::table('merit_lists')->where('exam_id', $exam->id)
            ->update(['grading_snapshot' => null]);

        try {
            app(ReportCardService::class)->generate(
                $exam->school_id, $exam->id, null, null, null, $userId
            );
            $this->fail('Expected legacy merit grading to block card generation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('grading_snapshot', $exception->errors());
        }

        $this->assertSame(0, DB::table('report_cards')->where('exam_id', $exam->id)->count());
    }

    public function test_publication_captures_presentation_without_rewriting_legacy_cards(): void
    {
        $context = $this->context();
        $card = $context['card']->fresh();

        $this->assertNull($card->presentation_snapshot);

        app(ReportCardService::class)->publish(
            $context['exam']->school_id, $context['exam']->id,
            $context['learner']->id, null, null, $context['user']->id
        );

        $card = $card->fresh();
        $snapshot = $card->presentation_snapshot;

        $this->assertSame('published', $card->status);
        $this->assertSame(1, $snapshot['version']);
        $this->assertSame($card->learner_id, $snapshot['learner']['id']);
        $this->assertSame($context['learner']->admission_no, $snapshot['learner']['admission_no']);
        $this->assertSame($context['exam']->exam_name, $snapshot['exam']['exam_name']);
        $this->assertSame('Knowledge and Integrity', $snapshot['settings']['school_motto']);
        $this->assertCount($card->learningAreas()->count(), $snapshot['learning_areas']);

        DB::table('learners')->where('id', $card->learner_id)
            ->update(['first_name' => 'Changed after publication']);
        DB::table('exams')->where('id', $card->exam_id)
            ->update(['exam_name' => 'Changed after publication']);
        DB::table('school_settings')->where('school_id', $card->school_id)
            ->update(['school_motto' => 'Changed after publication']);

        $this->assertSame($snapshot, $card->fresh()->presentation_snapshot);

        // Simulate a report published before presentation capture existed.
        DB::table('report_cards')->where('id', $card->id)
            ->update(['presentation_snapshot' => null]);

        $legacy = $card->fresh();
        $this->assertSame('published', $legacy->status);
        $this->assertNull($legacy->presentation_snapshot);
    }

    public function test_published_pdf_presentation_survives_live_identity_and_branding_edits(): void
    {
        $context = $this->context();
        app(ReportCardService::class)->publish(
            $context['exam']->school_id, $context['exam']->id,
            $context['learner']->id, null, null, $context['user']->id
        );

        $relations = [
            'school.settings', 'learner', 'exam', 'academicYear', 'term',
            'grade', 'stream', 'overallGradingScale', 'pathwayRecommendation',
            'learningAreas.learningArea', 'learningAreas.gradingScale',
        ];
        $render = function () use ($context, $relations) {
            return view('pdf.report-card', [
                'card' => $context['card']->fresh()->load($relations),
                'settings' => null, 'logo' => null, 'signature' => null,
            ])->render();
        };

        $before = $render();
        $documentBefore = app(ReportCardPdfService::class)->make(
            $context['exam']->school_id, $context['card']->id
        );

        DB::table('learners')->where('id', $context['learner']->id)->update([
            'first_name' => 'CHANGED_LIVE_LEARNER',
            'admission_no' => 'CHANGED-LIVE-ADMISSION',
        ]);
        DB::table('schools')->where('id', $context['exam']->school_id)
            ->update(['school_name' => 'CHANGED_LIVE_SCHOOL']);
        DB::table('exams')->where('id', $context['exam']->id)
            ->update(['exam_name' => 'CHANGED_LIVE_EXAM']);
        DB::table('school_settings')->where('school_id', $context['exam']->school_id)
            ->update([
                'school_motto' => 'CHANGED_LIVE_MOTTO',
                'principal_name' => 'CHANGED_LIVE_PRINCIPAL',
            ]);

        $this->assertSame($before, $render());

        $documentAfter = app(ReportCardPdfService::class)->make(
            $context['exam']->school_id, $context['card']->id
        );
        $this->assertSame($documentBefore['filename'], $documentAfter['filename']);
        $this->assertStringStartsWith('%PDF', $documentAfter['pdf']->output());

        DB::table('report_cards')->where('id', $context['card']->id)
            ->update(['presentation_snapshot' => null]);

        $legacy = $render();
        $this->assertStringContainsString('Historical presentation details were not captured', $legacy);
        foreach ([
            'CHANGED_LIVE_LEARNER', 'CHANGED_LIVE_SCHOOL',
            'CHANGED_LIVE_EXAM', 'CHANGED_LIVE_MOTTO',
        ] as $value) {
            $this->assertStringNotContainsString($value, $legacy);
        }
    }

    public function test_publication_preserves_image_bytes_after_source_replacement(): void
    {
        $context = $this->context();
        $relative = 'snapshot-test-'.Str::uuid().'.png';
        $path = public_path($relative);
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=',
            true
        );

        try {
            file_put_contents($path, $bytes);
            DB::table('school_settings')->where('school_id', $context['exam']->school_id)
                ->update([
                    'school_logo_url' => $relative,
                    'principal_signature_url' => $relative,
                ]);

            app(ReportCardService::class)->publish(
                $context['exam']->school_id, $context['exam']->id,
                $context['learner']->id, null, null, $context['user']->id
            );

            $card = $context['card']->fresh();
            $presentations = app(ReportCardPresentationSnapshotService::class);
            $expected = 'data:image/png;base64,'.base64_encode($bytes);

            foreach (['logo', 'principal_signature'] as $name) {
                $this->assertSame('captured', $card->presentation_snapshot['assets'][$name]['status']);
                $this->assertSame(hash('sha256', $bytes), $card->presentation_snapshot['assets'][$name]['sha256']);
                $this->assertSame($expected, $presentations->asset($card, $name));
            }

            file_put_contents($path, 'Replaced after publication');
            $this->assertSame($expected, $presentations->asset($card->fresh(), 'logo'));

            unlink($path);
            $this->assertSame($expected, $presentations->asset($card->fresh(), 'principal_signature'));

            $document = app(ReportCardPdfService::class)->make(
                $context['exam']->school_id, $card->id
            );
            $this->assertStringStartsWith('%PDF', $document['pdf']->output());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_presentation_failure_rolls_back_the_entire_publication_batch(): void
    {
        $context = $this->context();
        $service = app(ReportCardService::class);
        $service->generate(
            $context['exam']->school_id, $context['exam']->id,
            null, null, null, $context['user']->id
        );

        $query = fn () => DB::table('report_cards')
            ->where('school_id', $context['exam']->school_id)
            ->where('exam_id', $context['exam']->id)->orderBy('id');

        $before = $query()->get()->map(fn ($row) => (array) $row)->all();
        $this->assertGreaterThanOrEqual(2, count($before));

        $real = new ReportCardPresentationSnapshotService;
        $seen = 0;
        $this->mock(ReportCardPresentationSnapshotService::class)
            ->shouldReceive('capture')->andReturnUsing(
                function ($card) use ($real, &$seen) {
                    $seen++;
                    if ($seen === 2) {
                        throw new \RuntimeException('Injected presentation capture failure.');
                    }

                    return $real->capture($card);
                }
            );

        try {
            $service->publish(
                $context['exam']->school_id, $context['exam']->id,
                null, null, null, $context['user']->id
            );
            $this->fail('The injected failure must abort publication.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Injected presentation capture failure.', $exception->getMessage()
            );
        }

        $this->assertSame(2, $seen);
        $this->assertSame(
            $before, $query()->get()->map(fn ($row) => (array) $row)->all()
        );

        $this->app->instance(
            ReportCardPresentationSnapshotService::class, $real
        );

        $published = $service->publish(
            $context['exam']->school_id, $context['exam']->id,
            null, null, null, $context['user']->id
        );

        $this->assertCount(count($before), $published);
        foreach ($published as $card) {
            $this->assertSame('published', $card->status);
            $this->assertSame(1, $card->presentation_snapshot['version']);
        }
    }

    public function test_published_api_resources_use_captured_presentation_without_image_payloads(): void
    {
        $context = $this->context();
        app(ReportCardService::class)->publish(
            $context['exam']->school_id, $context['exam']->id,
            $context['learner']->id, null, null, $context['user']->id
        );

        $payloads = function () use ($context): array {
            $card = $context['card']->fresh()->load([
                'school.settings', 'learner', 'exam', 'academicYear', 'term',
                'grade', 'stream', 'overallGradingScale', 'pathwayRecommendation',
                'learningAreas.learningArea', 'learningAreas.gradingScale',
            ]);

            return json_decode(json_encode([
                'staff' => (new ReportCardResource($card))->resolve(request()),
                'parent' => (new ParentReportCardResource([
                    'report_card' => $card,
                    'access' => [
                        'can_view' => true, 'can_download' => true,
                        'restriction_message' => null,
                    ],
                ]))->resolve(request()),
            ], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        };

        $before = $payloads();
        $this->assertSame('captured', $before['staff']['presentation_history']['status']);
        $this->assertSame('captured', $before['parent']['presentation_history']['status']);

        DB::table('learners')->where('id', $context['learner']->id)
            ->update(['first_name' => 'LIVE_API_NAME_CHANGED']);
        DB::table('exams')->where('id', $context['exam']->id)
            ->update(['exam_name' => 'LIVE_API_EXAM_CHANGED']);

        $this->assertSame($before, $payloads());
        $serialized = json_encode($payloads(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('presentation_snapshot', $serialized);
        $this->assertStringNotContainsString('"base64"', $serialized);

        $this->assertArrayNotHasKey(
            'presentation_snapshot', $context['card']->fresh()->toArray()
        );

        DB::table('report_cards')->where('id', $context['card']->id)
            ->update(['presentation_snapshot' => null]);

        $legacy = $payloads();
        $this->assertSame('legacy_unavailable', $legacy['staff']['presentation_history']['status']);
        $this->assertSame('legacy_unavailable', $legacy['parent']['presentation_history']['status']);
        $this->assertStringNotContainsString(
            'LIVE_API_EXAM_CHANGED', json_encode($legacy, JSON_THROW_ON_ERROR)
        );
    }

    public function test_learner_report_models_use_historical_labels_and_hide_snapshot_bytes(): void
    {
        $context = $this->context();
        app(ReportCardService::class)->publish(
            $context['exam']->school_id, $context['exam']->id,
            $context['learner']->id, null, null, $context['user']->id
        );

        $user = User::withoutGlobalScopes()->findOrFail($context['user']->id);
        $learner = Learner::withoutGlobalScopes()
            ->findOrFail($context['learner']->id);

        DB::table('school_settings')->where('school_id', $user->school_id)->update([
            'learner_portal_show_report_cards' => true,
            'report_card_fee_policy' => 'open',
        ]);

        $access = $this->mock(LearnerPortalAccessService::class);
        $access->shouldReceive('learner')->with($user)->andReturn($learner);
        $access->shouldReceive('requireReportCard')->with($user, $context['card']->id)
            ->andReturnUsing(fn () => $context['card']->fresh());

        $portal = app(LearnerPortalService::class);
        $read = function () use ($portal, $user, $context): array {
            return [
                'list' => $portal->reportCards($user)
                    ->firstWhere('id', $context['card']->id)->toArray(),
                'detail' => $portal->reportCard($user, $context['card']->id)->toArray(),
            ];
        };

        $before = $read();
        $this->assertSame(
            $context['exam']->exam_name, $before['list']['exam']['exam_name']
        );

        DB::table('exams')->where('id', $context['exam']->id)
            ->update(['exam_name' => 'LIVE_LEARNER_EXAM_CHANGED']);

        $this->assertSame($before, $read());

        foreach ($read() as $payload) {
            $this->assertArrayNotHasKey('presentation_snapshot', $payload);
            $this->assertStringNotContainsString(
                'LIVE_LEARNER_EXAM_CHANGED', json_encode($payload, JSON_THROW_ON_ERROR)
            );
        }

        DB::table('report_cards')->where('id', $context['card']->id)
            ->update(['presentation_snapshot' => null]);

        foreach ($read() as $payload) {
            $this->assertStringNotContainsString(
                'LIVE_LEARNER_EXAM_CHANGED', json_encode($payload, JSON_THROW_ON_ERROR)
            );
        }
    }

    public function test_authenticated_learner_reports_preserve_history_and_identify_legacy_data(): void
    {
        $context = $this->context();
        app(ReportCardService::class)->publish(
            $context['exam']->school_id, $context['exam']->id,
            $context['learner']->id, null, null, $context['user']->id
        );

        DB::table('school_settings')->where('school_id', $context['exam']->school_id)
            ->update([
                'learner_portal_enabled' => true,
                'learner_portal_show_report_cards' => true,
                'report_card_fee_policy' => 'open',
            ]);
        DB::table('learners')->where('id', $context['learner']->id)
            ->update(['portal_enabled' => true]);

        $account = app(LearnerAccountService::class)
            ->create($context['exam']->school_id, $context['learner']->id, []);
        $user = User::withoutGlobalScopes()->with('role')
            ->findOrFail($account['user']['id']);

        config(['jwt.secret' => str_repeat('assessment-http-test-', 4)]);
        foreach (['access_learner_portal', 'view_own_report_cards'] as $name) {
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
        auth()->guard()->forgetUser();

        $read = function () use ($token, $context): array {
            $list = $this->withToken($token)->getJson('/api/learner/report-cards')
                ->assertOk()->json('data');
            $detail = $this->withToken($token)
                ->getJson('/api/learner/report-cards/'.$context['card']->id)
                ->assertOk()->json('data');

            return [
                collect($list)->firstWhere('id', $context['card']->id),
                $detail,
            ];
        };

        $before = $read();
        foreach ($before as $payload) {
            $this->assertSame('captured', $payload['presentation_history']['status']);
            $this->assertSame(1, $payload['presentation_history']['version']);
            $this->assertSame($context['exam']->exam_name, $payload['exam']['exam_name']);
            $this->assertArrayNotHasKey('presentation_snapshot', $payload);
        }

        DB::table('exams')->where('id', $context['exam']->id)
            ->update(['exam_name' => 'CHANGED_AUTHENTICATED_EXAM']);
        DB::table('learners')->where('id', $context['learner']->id)
            ->update(['first_name' => 'CHANGED_AUTHENTICATED_LEARNER']);

        $this->assertSame($before, $read());

        DB::table('report_cards')->where('id', $context['card']->id)
            ->update(['presentation_snapshot' => null]);

        foreach ($read() as $payload) {
            $this->assertSame('legacy_unavailable', $payload['presentation_history']['status']);
            $this->assertNull($payload['presentation_history']['version']);
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('CHANGED_AUTHENTICATED_EXAM', $json);
            $this->assertStringNotContainsString('CHANGED_AUTHENTICATED_LEARNER', $json);
        }
    }

    public function test_pdf_identifiers_preserve_leading_zeros_and_publication_history(): void
    {
        $context = $this->context();
        $learnerId = $context['learner']->id;
        $cardId = $context['card']->id;

        DB::table('learners')->where('id', $learnerId)->update([
            'upi' => '0000123401',
            'kemis_no' => '0000567802',
        ]);

        $html = function () use ($context): string {
            $card = $context['card']->fresh()->load([
                'school.settings', 'learner', 'exam', 'academicYear', 'term',
                'grade', 'stream', 'overallGradingScale', 'pathwayRecommendation',
                'learningAreas.learningArea', 'learningAreas.gradingScale',
            ]);

            return view('pdf.report-card', [
                'card' => $card,
                'settings' => $card->school->settings,
                'logo' => null,
                'signature' => null,
            ])->render();
        };

        $preview = $html();
        $this->assertStringContainsString('KEMIS No:', $preview);
        $this->assertStringContainsString('UPI:', $preview);
        $this->assertStringContainsString('0000123401', $preview);
        $this->assertStringContainsString('0000567802', $preview);

        app(ReportCardService::class)->publish(
            $context['exam']->school_id, $context['exam']->id,
            $learnerId, null, null, $context['user']->id
        );

        $snapshot = $context['card']->fresh()->presentation_snapshot;
        $this->assertSame('0000123401', $snapshot['learner']['upi']);
        $this->assertSame('0000567802', $snapshot['learner']['kemis_no']);

        DB::table('learners')->where('id', $learnerId)->update([
            'upi' => 'CURRENT-UPI-CHANGED',
            'kemis_no' => 'CURRENT-KEMIS-CHANGED',
        ]);

        $published = $html();
        $this->assertStringContainsString('0000123401', $published);
        $this->assertStringContainsString('0000567802', $published);
        $this->assertStringNotContainsString('CURRENT-UPI-CHANGED', $published);
        $this->assertStringNotContainsString('CURRENT-KEMIS-CHANGED', $published);

        $document = app(ReportCardPdfService::class)->make(
            $context['exam']->school_id, $cardId
        );
        $this->assertStringStartsWith('%PDF', $document['pdf']->output());

        // Older captured presentations have no identifier keys.
        unset($snapshot['learner']['upi'], $snapshot['learner']['kemis_no']);
        DB::table('report_cards')->where('id', $cardId)->update([
            'presentation_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ]);

        $older = $html();
        $this->assertStringContainsString('KEMIS No:</span> —', $older);
        $this->assertStringContainsString('UPI:</span> —', $older);
        $this->assertStringNotContainsString('CURRENT-UPI-CHANGED', $older);
        $this->assertStringNotContainsString('CURRENT-KEMIS-CHANGED', $older);

        // Reports predating presentation capture must also avoid live values.
        DB::table('report_cards')->where('id', $cardId)
            ->update(['presentation_snapshot' => null]);

        $legacy = $html();
        $this->assertStringContainsString('KEMIS No:</span> —', $legacy);
        $this->assertStringContainsString('UPI:</span> —', $legacy);
        $this->assertStringNotContainsString('CURRENT-UPI-CHANGED', $legacy);
        $this->assertStringNotContainsString('CURRENT-KEMIS-CHANGED', $legacy);
    }

    public function test_publication_archive_preserves_originals_and_is_repeatable(): void
    {
        $context = $this->context();
        $schoolId = $context['exam']->school_id;
        $examId = $context['exam']->id;

        app(MeritListService::class)
            ->publish($schoolId, $examId, null, null);
        app(ReportCardService::class)->publish(
            $schoolId, $examId, $context['learner']->id,
            null, null, $context['user']->id
        );

        $service = app(AssessmentPublicationArchiveService::class);
        $query = fn () => DB::table('assessment_publication_versions')
            ->where('school_id', $schoolId)->where('exam_id', $examId)
            ->orderBy('output_type')->orderBy('output_id');

        $beforeCard = (array) DB::table('report_cards')
            ->where('id', $context['card']->id)->first();
        $beforeDetails = DB::table('report_card_learning_areas')
            ->where('report_card_id', $context['card']->id)->orderBy('id')
            ->get()->map(fn ($row) => (array) $row)->all();

        $counts = $service->preserveOriginals($schoolId, $examId);
        $this->assertSame(1, $counts['report_card']);
        $this->assertGreaterThanOrEqual(1, $counts['merit_list']);

        $version = $query()->where('output_type', 'report_card')
            ->where('output_id', $context['card']->id)->first();
        $this->assertNotNull($version);
        $payload = json_decode($version->payload, true, 512, JSON_THROW_ON_ERROR);

        $this->assertEquals($beforeCard, $payload['output']);
        $this->assertEquals($beforeDetails, $payload['learning_areas']);
        $this->assertSame(1, (int) $version->revision_number);
        $this->assertNull($version->reissue_request_id);
        $this->assertSame(
            $service->fingerprint($payload), $version->payload_sha256
        );

        $beforeArchive = $query()->get()->map(fn ($row) => (array) $row)->all();

        DB::table('learners')->where('id', $context['learner']->id)
            ->update(['first_name' => 'Changed after archive']);

        $this->assertSame(
            ['report_card' => 0, 'merit_list' => 0],
            $service->preserveOriginals($schoolId, $examId)
        );
        $this->assertSame(
            $beforeArchive,
            $query()->get()->map(fn ($row) => (array) $row)->all()
        );
        $this->assertSame(
            $beforeCard,
            (array) DB::table('report_cards')
                ->where('id', $context['card']->id)->first()
        );
        $this->assertSame(
            $beforeDetails,
            DB::table('report_card_learning_areas')
                ->where('report_card_id', $context['card']->id)->orderBy('id')
                ->get()->map(fn ($row) => (array) $row)->all()
        );

        // Detect an unexpected change to an already archived original.
        DB::table('report_cards')->where('id', $context['card']->id)
            ->update(['principal_comment' => 'Unexpected stored edit']);

        try {
            $service->preserveOriginals($schoolId, $examId);
            $this->fail('Conflicting original data must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'publication_history', $exception->errors()
            );
        }

        $this->assertSame(
            $beforeArchive,
            $query()->get()->map(fn ($row) => (array) $row)->all()
        );
    }

    public function test_publication_archive_failure_rolls_back_all_versions(): void
    {
        $context = $this->context();
        $schoolId = $context['exam']->school_id;
        $examId = $context['exam']->id;

        app(MeritListService::class)
            ->publish($schoolId, $examId, null, null);
        app(ReportCardService::class)->publish(
            $schoolId, $examId, $context['learner']->id,
            null, null, $context['user']->id
        );

        $service = new class(app(ExamResultLifecycleService::class)) extends AssessmentPublicationArchiveService
        {
            public int $seen = 0;

            public function fingerprint(array $payload): string
            {
                $this->seen++;

                if ($this->seen === 2) {
                    throw new \RuntimeException('Injected archive failure.');
                }

                return parent::fingerprint($payload);
            }
        };

        try {
            $service->preserveOriginals($schoolId, $examId);
            $this->fail('The injected failure must abort archival.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Injected archive failure.', $exception->getMessage()
            );
        }

        $this->assertSame(2, $service->seen);
        $this->assertSame(
            0,
            DB::table('assessment_publication_versions')
                ->where('school_id', $schoolId)->where('exam_id', $examId)
                ->count()
        );
        $this->assertSame(
            'published',
            DB::table('report_cards')->where('id', $context['card']->id)
                ->value('status')
        );
    }

    public function test_correction_proposal_captures_marks_and_absence_without_writes(): void
    {
        $context = $this->context();
        $schoolId = $context['exam']->school_id;
        $examId = $context['exam']->id;

        $result = DB::table('exam_results')
            ->where('exam_id', $examId)
            ->where('learner_id', $context['learner']->id)
            ->where('is_deleted', false)
            ->where('is_absent', false)
            ->whereNotNull('marks')->orderBy('id')->first();
        $this->assertNotNull($result);

        $service = app(AssessmentCorrectionProposalService::class);
        $before = (array) $result;
        $marks = (float) $result->marks === 0.0 ? 1 : 0;

        $proposal = $service->normalize($schoolId, $examId, [[
            'exam_result_id' => $result->id,
            'marks' => $marks,
            'is_absent' => false,
        ]]);

        $this->assertCount(1, $proposal);
        $this->assertSame($result->id, $proposal[0]['exam_result_id']);
        $this->assertSame($result->learner_id, $proposal[0]['learner_id']);
        $this->assertSame($result->paper_id, $proposal[0]['paper_id']);
        $this->assertSame($result->marks, $proposal[0]['before']['marks']);
        $this->assertFalse($proposal[0]['before']['is_absent']);
        $this->assertEquals($marks, $proposal[0]['after']['marks']);
        $this->assertFalse($proposal[0]['after']['is_absent']);

        $absence = $service->normalize($schoolId, $examId, [[
            'exam_result_id' => $result->id,
            'marks' => null,
            'is_absent' => true,
        ]]);

        $this->assertNull($absence[0]['after']['marks']);
        $this->assertTrue($absence[0]['after']['is_absent']);
        $this->assertSame(
            $before,
            (array) DB::table('exam_results')->where('id', $result->id)->first()
        );
        $this->assertSame(
            'generated',
            DB::table('report_cards')->where('id', $context['card']->id)
                ->value('status')
        );
    }

    public function test_correction_proposal_rejects_invalid_inputs_without_writes(): void
    {
        $context = $this->context();
        $schoolId = $context['exam']->school_id;
        $examId = $context['exam']->id;

        $result = DB::table('exam_results')
            ->where('exam_id', $examId)
            ->where('learner_id', $context['learner']->id)
            ->where('is_deleted', false)
            ->where('is_absent', false)
            ->whereNotNull('marks')->orderBy('id')->first();
        $this->assertNotNull($result);

        $maximum = DB::table('exam_papers')->where('id', $result->paper_id)
            ->value('max_marks');
        $this->assertNotNull($maximum);

        $service = app(AssessmentCorrectionProposalService::class);
        $before = (array) $result;
        $valid = [
            'exam_result_id' => $result->id,
            'marks' => (float) $result->marks === 0.0 ? 1 : 0,
            'is_absent' => false,
        ];

        $cases = [
            'empty proposal' => [],
            'duplicate result' => [$valid, $valid],
            'unchanged result' => [[
                'exam_result_id' => $result->id,
                'marks' => $result->marks,
                'is_absent' => false,
            ]],
            'unknown result' => [[
                'exam_result_id' => (string) Str::uuid(),
                'marks' => 0,
                'is_absent' => false,
            ]],
            'negative marks' => [array_replace($valid, ['marks' => -1])],
            'above maximum' => [array_replace($valid, [
                'marks' => (float) $maximum + 1,
            ])],
            'absent with marks' => [array_replace($valid, [
                'marks' => 0, 'is_absent' => true,
            ])],
            'scored without marks' => [array_replace($valid, ['marks' => null])],
            'unexpected field' => [$valid + ['learner_id' => $result->learner_id]],
            'missing absence flag' => [[
                'exam_result_id' => $result->id,
                'marks' => 0,
            ]],
        ];

        foreach ($cases as $label => $changes) {
            try {
                $service->normalize($schoolId, $examId, $changes);
                $this->fail('Expected rejection: '.$label);
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors(), $label);
            }

            $this->assertSame(
                $before,
                (array) DB::table('exam_results')->where('id', $result->id)->first(),
                $label
            );
        }

        // A valid result ID must not be usable under a different school.
        try {
            $service->normalize((string) Str::uuid(), $examId, [$valid]);
            $this->fail('A foreign school context must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('school_id', $exception->errors());
        }

        // School lifecycle restrictions also apply to direct proposal calls.
        DB::table('schools')->where('id', $schoolId)
            ->update(['lifecycle_state' => 'read_only']);

        try {
            $service->normalize($schoolId, $examId, [$valid]);
            $this->fail('A read-only school must be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertSame(
            $before,
            (array) DB::table('exam_results')->where('id', $result->id)->first()
        );
    }

    public function test_reissue_request_requires_separate_approval_and_preserves_outputs(): void
    {
        [$context, $actor, $reviewer, $changes] = $this->reissueRequestContext();
        $service = app(AssessmentReissueRequestService::class);
        $schoolId = $context['exam']->school_id;
        $examId = $context['exam']->id;

        $beforeResult = (array) DB::table('exam_results')
            ->where('id', $changes[0]['exam_result_id'])->first();
        $beforeCard = (array) DB::table('report_cards')
            ->where('id', $context['card']->id)->first();

        $id = $service->submit($actor, $examId, 'Correct a transcribed paper mark.', $changes);
        $request = DB::table('assessment_reissue_requests')->where('id', $id)->first();

        $this->assertSame('pending', $request->status);
        $this->assertSame($actor->id, $request->requested_by);
        $this->assertSame(
            1, DB::table('assessment_reissue_events')->where('request_id', $id)->count()
        );

        try {
            $service->decide($actor, $id, 'approved', 'Self approval attempt.');
            $this->fail('Self approval must be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        try {
            $service->submit($actor, $examId, 'Duplicate correction.', $changes);
            $this->fail('A second active request must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }

        $service->decide($reviewer, $id, 'approved', 'Checked against the original mark sheet.');

        $request = DB::table('assessment_reissue_requests')->where('id', $id)->first();
        $this->assertSame('approved', $request->status);
        $this->assertSame($reviewer->id, $request->decided_by);
        $this->assertNotNull($request->decided_at);
        $this->assertSame(
            ['submitted', 'approved'],
            DB::table('assessment_reissue_events')->where('request_id', $id)
                ->orderBy('created_at')->pluck('action')->all()
        );

        $this->assertSame(
            $beforeResult,
            (array) DB::table('exam_results')
                ->where('id', $changes[0]['exam_result_id'])->first()
        );
        $this->assertSame(
            $beforeCard,
            (array) DB::table('report_cards')->where('id', $context['card']->id)->first()
        );
        $this->assertGreaterThanOrEqual(
            1, DB::table('assessment_publication_versions')
                ->where('school_id', $schoolId)->where('exam_id', $examId)->count()
        );

        try {
            $service->decide($reviewer, $id, 'rejected', 'Repeated decision.');
            $this->fail('An already decided request must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }

        $this->assertSame(
            2, DB::table('assessment_reissue_events')->where('request_id', $id)->count()
        );
    }

    public function test_reissue_request_rejects_changed_source_but_allows_rejection(): void
    {
        [$context, $actor, $reviewer, $changes] = $this->reissueRequestContext();
        $service = app(AssessmentReissueRequestService::class);
        $examId = $context['exam']->id;

        try {
            $service->submit($actor, $examId, '   ', $changes);
            $this->fail('A blank reason must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $this->assertSame(
            0, DB::table('assessment_reissue_requests')->where('exam_id', $examId)->count()
        );

        $id = $service->submit($actor, $examId, 'Correct the recorded mark.', $changes);

        DB::table('report_cards')->where('id', $context['card']->id)
            ->update(['principal_comment' => 'Unexpected source change']);

        try {
            $service->decide($reviewer, $id, 'approved', 'Attempt approval after source change.');
            $this->fail('Changed source data must invalidate approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }

        $this->assertSame(
            'pending',
            DB::table('assessment_reissue_requests')->where('id', $id)->value('status')
        );
        $this->assertSame(
            1, DB::table('assessment_reissue_events')->where('request_id', $id)->count()
        );

        $service->decide($reviewer, $id, 'rejected', 'Source changed; a fresh proposal is required.');

        $this->assertSame(
            'rejected',
            DB::table('assessment_reissue_requests')->where('id', $id)->value('status')
        );
        $this->assertSame(
            2, DB::table('assessment_reissue_events')->where('request_id', $id)->count()
        );
    }

    private function reissueRequestContext(): array
    {
        $context = $this->context();
        $actor = User::withoutGlobalScopes()->findOrFail($context['user']->id);
        $actor->forceFill([
            'active' => true,
            'is_deleted' => false,
            'suspended_at' => null,
            'account_locked_until' => null,
        ])->save();

        DB::table('roles')->where('id', $actor->role_id)->update(['active' => true]);

        foreach (['request_assessment_reissues', 'approve_assessment_reissues'] as $name) {
            $permissionId = DB::table('permissions')
                ->where('permission_name', $name)->value('id');
            $this->assertNotNull($permissionId, 'Missing permission: '.$name);

            if (! DB::table('role_permissions')
                ->where('role_id', $actor->role_id)
                ->where('permission_id', $permissionId)->exists()) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $actor->role_id,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                ]);
            }
        }

        $reviewer = $actor->replicate();
        $reviewer->id = (string) Str::uuid();
        $reviewer->username = 'reissue-review-'.Str::random(12);
        $reviewer->email = Str::random(16).'@example.test';
        $reviewer->phone = null;
        $reviewer->save();

        app(ReportCardService::class)->publish(
            $context['exam']->school_id, $context['exam']->id,
            $context['learner']->id, null, null, $actor->id
        );

        $result = DB::table('exam_results')
            ->where('exam_id', $context['exam']->id)
            ->where('learner_id', $context['learner']->id)
            ->where('is_deleted', false)->where('is_absent', false)
            ->whereNotNull('marks')->orderBy('id')->first();
        $this->assertNotNull($result);

        return [$context, $actor->fresh(), $reviewer->fresh(), [[
            'exam_result_id' => $result->id,
            'marks' => (float) $result->marks === 0.0 ? 1 : 0,
            'is_absent' => false,
        ]]];
    }

    public function test_publication_fingerprint_survives_jsonb_numeric_normalization(): void
    {
        $service = app(AssessmentPublicationArchiveService::class);
        $payload = [
            'changes' => [
                ['marks' => 0.0, 'is_absent' => false],
                ['marks' => 80.0, 'is_absent' => false],
                ['marks' => 75.25, 'is_absent' => false],
                ['marks' => null, 'is_absent' => true],
            ],
            'version' => 1,
        ];

        foreach ([JSON_THROW_ON_ERROR, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION] as $flags) {
            $stored = DB::selectOne(
                'select cast(? as jsonb)::text as payload',
                [json_encode($payload, $flags)]
            );
            $decoded = json_decode(
                $stored->payload, true, 512, JSON_THROW_ON_ERROR
            );

            $this->assertSame(
                $service->fingerprint($payload),
                $service->fingerprint($decoded)
            );
        }

        $reordered = ['version' => 1, 'changes' => $payload['changes']];
        $this->assertSame(
            $service->fingerprint($payload),
            $service->fingerprint($reordered)
        );

        $changed = $payload;
        $changed['changes'][0]['marks'] = 1;
        $this->assertNotSame(
            $service->fingerprint($payload),
            $service->fingerprint($changed)
        );

        $changed = $payload;
        $changed['changes'][0]['marks'] = '0';
        $this->assertNotSame(
            $service->fingerprint($payload),
            $service->fingerprint($changed)
        );
    }

    public function test_reissue_request_permissions_are_required_for_each_action(): void
    {
        [$context, $actor, $reviewer, $changes] = $this->reissueRequestContext();
        $service = app(AssessmentReissueRequestService::class);
        $examId = $context['exam']->id;

        $requestPermission = DB::table('permissions')
            ->where('permission_name', 'request_assessment_reissues')->value('id');
        $grant = (array) DB::table('role_permissions')
            ->where('role_id', $actor->role_id)
            ->where('permission_id', $requestPermission)->first();
        $this->assertNotEmpty($grant);

        DB::table('role_permissions')->where('id', $grant['id'])->delete();

        try {
            $service->submit($actor, $examId, 'Unauthorized request.', $changes);
            $this->fail('Submission without permission must be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertSame(
            0, DB::table('assessment_reissue_requests')->where('exam_id', $examId)->count()
        );
        $this->assertSame(
            0, DB::table('assessment_publication_versions')->where('exam_id', $examId)->count()
        );

        DB::table('role_permissions')->insert($grant);
        $id = $service->submit($actor, $examId, 'Authorized request.', $changes);

        $approvePermission = DB::table('permissions')
            ->where('permission_name', 'approve_assessment_reissues')->value('id');
        DB::table('role_permissions')
            ->where('role_id', $reviewer->role_id)
            ->where('permission_id', $approvePermission)->delete();

        foreach (['approved', 'rejected'] as $decision) {
            try {
                $service->decide($reviewer, $id, $decision, 'Unauthorized decision.');
                $this->fail('A decision without permission must be rejected.');
            } catch (AuthorizationException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }

        $this->assertSame(
            'pending',
            DB::table('assessment_reissue_requests')->where('id', $id)->value('status')
        );
        $this->assertSame(
            1, DB::table('assessment_reissue_events')->where('request_id', $id)->count()
        );
    }

    public function test_reissue_request_audit_failure_rolls_back_submission_and_decision(): void
    {
        [$context, $actor, $reviewer, $changes] = $this->reissueRequestContext();
        $service = app(AssessmentReissueRequestService::class);
        $examId = $context['exam']->id;
        $armed = true;

        DB::listen(function ($query) use (&$armed): void {
            $sql = strtolower(ltrim($query->sql));

            if ($armed && str_starts_with($sql, 'insert')
                && str_contains($sql, 'assessment_reissue_events')) {
                $armed = false;
                throw new \RuntimeException('Injected reissue audit failure.');
            }
        });

        try {
            try {
                $service->submit($actor, $examId, 'Submission rollback test.', $changes);
                $this->fail('Audit failure must abort submission.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Injected reissue audit failure.', $exception->getMessage()
                );
            }

            $this->assertFalse($armed);
            $this->assertSame(
                0, DB::table('assessment_reissue_requests')->where('exam_id', $examId)->count()
            );
            $this->assertSame(
                0, DB::table('assessment_publication_versions')->where('exam_id', $examId)->count()
            );
            $this->assertSame(
                0, DB::table('assessment_reissue_events')
                    ->where('school_id', $actor->school_id)->count()
            );

            $id = $service->submit($actor, $examId, 'Decision rollback test.', $changes);
            $before = (array) DB::table('assessment_reissue_requests')
                ->where('id', $id)->first();
            $archive = DB::table('assessment_publication_versions')
                ->where('exam_id', $examId)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
            $this->assertNotEmpty($archive);

            $armed = true;

            try {
                $service->decide($reviewer, $id, 'approved', 'Approval rollback test.');
                $this->fail('Audit failure must abort approval.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Injected reissue audit failure.', $exception->getMessage()
                );
            }

            $this->assertFalse($armed);
            $this->assertSame(
                $before,
                (array) DB::table('assessment_reissue_requests')->where('id', $id)->first()
            );
            $this->assertSame(
                ['submitted'],
                DB::table('assessment_reissue_events')->where('request_id', $id)
                    ->pluck('action')->all()
            );
            $this->assertSame(
                $archive,
                DB::table('assessment_publication_versions')
                    ->where('exam_id', $examId)->orderBy('id')->get()
                    ->map(fn ($row) => (array) $row)->all()
            );

            $service->decide($reviewer, $id, 'approved', 'Successful approval after rollback.');
            $this->assertSame(
                'approved',
                DB::table('assessment_reissue_requests')->where('id', $id)->value('status')
            );
        } finally {
            $armed = false;
        }
    }

    public function test_reissue_calculation_changes_candidates_without_live_writes(): void
    {
        [$context, $actor, , $changes] = $this->reissueRequestContext();
        $examId = $context['exam']->id;
        $schoolId = $context['exam']->school_id;

        $before = $this->reissueCalculationStoredState($examId);
        $paper = DB::table('exam_results')
            ->where('id', $changes[0]['exam_result_id'])->first();
        $oldMerit = DB::table('merit_lists')
            ->where('school_id', $schoolId)->where('exam_id', $examId)
            ->where('learner_id', $paper->learner_id)->first();
        $this->assertNotNull($oldMerit);

        $candidate = DB::transaction(fn () => app(
            AssessmentReissueCalculationService::class
        )->calculate($schoolId, $examId, $changes, $actor->id));

        $this->assertCount(1, $candidate['paper_results']);
        $this->assertCount(1, $candidate['learning_area_results']);
        $this->assertEquals(
            $changes[0]['marks'], $candidate['paper_results'][0]['marks']
        );

        $merit = collect($candidate['merit_lists'])
            ->firstWhere('learner_id', $paper->learner_id);
        $this->assertNotNull($merit);
        $this->assertSame($oldMerit->id, $merit['id']);
        $this->assertEquals(
            round(
                (float) $oldMerit->total_score
                - (float) $paper->marks
                + (float) $changes[0]['marks'],
                2
            ),
            $merit['total_score']
        );
        $this->assertNotEquals(
            (float) $oldMerit->average_percentage,
            (float) $merit['average_percentage']
        );

        $this->assertSame(
            count($before['merit_lists']), count($candidate['merit_lists'])
        );
        $this->assertSame(
            $before, $this->reissueCalculationStoredState($examId)
        );
    }

    public function test_reissue_calculation_absence_recalculates_remaining_positions(): void
    {
        [$context, $actor, , $changes] = $this->reissueRequestContext();
        $examId = $context['exam']->id;
        $schoolId = $context['exam']->school_id;
        $before = $this->reissueCalculationStoredState($examId);

        $changes[0]['marks'] = null;
        $changes[0]['is_absent'] = true;

        $candidate = DB::transaction(fn () => app(
            AssessmentReissueCalculationService::class
        )->calculate($schoolId, $examId, $changes, $actor->id));

        $area = $candidate['learning_area_results'][0];
        $this->assertTrue((bool) $area['is_absent']);
        $this->assertNull($area['marks_obtained']);
        $this->assertNull($area['percentage']);

        $merits = collect($candidate['merit_lists']);
        $absent = $merits->firstWhere('learner_id', $context['learner']->id);
        $this->assertNotNull($absent);
        $this->assertSame('not_ranked_absent', $absent['ranking_method']);
        $this->assertNull($absent['total_score']);
        $this->assertNull($absent['average_percentage']);
        $this->assertNull($absent['total_points']);
        $this->assertNull($absent['school_position']);
        $this->assertNull($absent['grade_position']);
        $this->assertNull($absent['stream_position']);

        $remaining = $merits->whereNotNull('school_position');
        $this->assertNotEmpty($remaining->all());
        $this->assertSame(1, (int) $remaining->min('school_position'));

        foreach ($remaining as $merit) {
            $this->assertGreaterThanOrEqual(1, (int) $merit['school_position']);
        }

        $this->assertSame(
            $before, $this->reissueCalculationStoredState($examId)
        );
    }

    private function reissueCalculationStoredState(string $examId): array
    {
        $state = [];

        foreach ([
            'exam_results',
            'learning_area_results',
            'merit_lists',
            'report_cards',
            'exam_grading_snapshots',
        ] as $table) {
            $state[$table] = DB::table($table)->where('exam_id', $examId)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        $state['report_card_learning_areas'] = DB::table('report_card_learning_areas')
            ->whereIn('report_card_id', function ($query) use ($examId): void {
                $query->select('id')->from('report_cards')->where('exam_id', $examId);
            })->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        return $state;
    }

    public function test_reissue_report_assembly_preserves_history_without_live_writes(): void
    {
        [$context, $actor, , $changes] = $this->reissueRequestContext();
        $schoolId = $context['exam']->school_id;
        $examId = $context['exam']->id;
        $cardId = $context['card']->id;

        // Live labels must not replace the published presentation.
        DB::table('learners')->where('id', $context['learner']->id)
            ->update(['first_name' => 'Changed after original publication']);

        $before = $this->reissueCalculationStoredState($examId);
        $storedCard = collect($before['report_cards'])->firstWhere('id', $cardId);
        $storedDetails = collect($before['report_card_learning_areas'])
            ->where('report_card_id', $cardId)->values();

        [$calculated, $reports] = DB::transaction(function () use ($schoolId, $examId, $changes, $actor): array {
            $calculated = app(
                AssessmentReissueCalculationService::class
            )->calculate($schoolId, $examId, $changes, $actor->id);

            return [$calculated, app(
                AssessmentReissueReportAssembler::class
            )->assemble($schoolId, $examId, $calculated)];
        });

        $report = collect($reports)->first(
            fn ($payload) => $payload['output']['id'] === $cardId
        );
        $this->assertNotNull($report);
        $candidate = $report['output'];
        $merit = collect($calculated['merit_lists'])
            ->firstWhere('learner_id', $context['learner']->id);

        $this->assertEquals($merit['total_score'], $candidate['overall_score']);
        $this->assertEquals($merit['average_percentage'], $candidate['average_percentage']);
        $this->assertSame($merit['school_position'], $candidate['school_position']);
        $rankedCount = collect($calculated['merit_lists'])
            ->whereNotNull('school_position')->count();
        $this->assertGreaterThan(1, $rankedCount);
        $this->assertSame($rankedCount, $candidate['total_learners']);
        $this->assertLessThanOrEqual(
            $candidate['total_learners'], $candidate['school_position']
        );
        $this->assertNotEquals(
            (float) $storedCard['overall_score'], (float) $candidate['overall_score']
        );

        foreach ([
            'presentation_snapshot', 'class_teacher_comment', 'principal_comment',
            'attendance_percentage', 'academic_year_id', 'term_id',
            'grade_id', 'stream_id', 'published_at', 'published_by',
        ] as $field) {
            $this->assertSame($storedCard[$field], $candidate[$field], $field);
        }

        $this->assertSame(
            $storedDetails->pluck('id')->all(),
            collect($report['learning_areas'])->pluck('id')->all()
        );
        foreach ($report['learning_areas'] as $detail) {
            $original = $storedDetails->firstWhere('id', $detail['id']);
            $this->assertSame($original['teacher_comment'], $detail['teacher_comment']);
        }

        $this->assertSame(
            $before, $this->reissueCalculationStoredState($examId)
        );
    }

    public function test_reissue_preparation_requires_approval_and_preserves_live_outputs(): void
    {
        [$context, $actor, $reviewer, $changes] = $this->reissueRequestContext();
        $examId = $context['exam']->id;
        $schoolId = $context['exam']->school_id;
        $requests = app(AssessmentReissueRequestService::class);
        $preparation = app(AssessmentReissuePreparationService::class);
        $id = $requests->submit($actor, $examId, 'Correct the recorded mark.', $changes);

        $permissionId = DB::table('permissions')
            ->where('permission_name', 'prepare_assessment_reissues')->value('id');
        $this->assertNotNull($permissionId);
        DB::table('role_permissions')->where('role_id', $actor->role_id)
            ->where('permission_id', $permissionId)->delete();

        try {
            $preparation->prepare($actor, $id, 'Unauthorized preparation.');
            $this->fail('Preparation without permission must be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->grantReissueTestPermission($actor, 'prepare_assessment_reissues');

        try {
            $preparation->prepare($actor, $id, 'Preparation before approval.');
            $this->fail('Pending requests cannot be prepared.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }

        $requests->decide($reviewer, $id, 'approved', 'Verified against the mark sheet.');
        $before = $this->reissueCalculationStoredState($examId);
        $versions = DB::table('assessment_publication_versions')
            ->where('exam_id', $examId)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();

        $result = $preparation->prepare($actor, $id, 'Prepared for review.');
        $drafts = DB::table('assessment_reissue_drafts')
            ->where('request_id', $id)->get();

        $this->assertSame($drafts->count(), $result['draft_count']);
        $this->assertGreaterThanOrEqual(4, $result['draft_count']);
        $this->assertSame(
            'prepared',
            DB::table('assessment_reissue_requests')->where('id', $id)->value('status')
        );
        $this->assertSame(
            $result['prepared_sha256'],
            DB::transaction(fn () => $preparation->draftFingerprint($schoolId, $id))
        );

        $cardDraft = $drafts->firstWhere('output_type', 'report_card');
        $this->assertNotNull($cardDraft);
        $this->assertSame(2, (int) $cardDraft->revision_number);

        $payload = json_decode($cardDraft->payload, true, 512, JSON_THROW_ON_ERROR);
        $storedCard = collect($before['report_cards'])
            ->firstWhere('id', $context['card']->id);
        $this->assertSame(
            $storedCard['presentation_snapshot'],
            $payload['output']['presentation_snapshot']
        );
        $this->assertNotEquals(
            (float) $storedCard['overall_score'],
            (float) $payload['output']['overall_score']
        );
        $this->assertSame($before, $this->reissueCalculationStoredState($examId));
        $this->assertSame(
            $versions,
            DB::table('assessment_publication_versions')
                ->where('exam_id', $examId)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all()
        );

        try {
            $preparation->prepare($actor, $id, 'Repeated preparation.');
            $this->fail('Prepared requests cannot be prepared again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }

        $payload['output']['principal_comment'] = 'Tampered draft';
        DB::table('assessment_reissue_drafts')->where('id', $cardDraft->id)
            ->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        try {
            DB::transaction(fn () => $preparation->draftFingerprint($schoolId, $id));
            $this->fail('Changed draft content must be detected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }
    }

    public function test_reissue_preparation_audit_failure_rolls_back_drafts(): void
    {
        [$context, $actor, $reviewer, $changes] = $this->reissueRequestContext();
        $this->grantReissueTestPermission($actor, 'prepare_assessment_reissues');
        $examId = $context['exam']->id;
        $requests = app(AssessmentReissueRequestService::class);
        $preparation = app(AssessmentReissuePreparationService::class);

        $id = $requests->submit($actor, $examId, 'Correct the paper mark.', $changes);
        $requests->decide($reviewer, $id, 'approved', 'Verified correction.');
        $beforeRequest = (array) DB::table('assessment_reissue_requests')
            ->where('id', $id)->first();
        $before = $this->reissueCalculationStoredState($examId);
        $armed = true;

        DB::listen(function ($query) use (&$armed): void {
            $sql = strtolower(ltrim($query->sql));
            if ($armed && str_starts_with($sql, 'insert')
                && str_contains($sql, 'assessment_reissue_events')) {
                $armed = false;
                throw new \RuntimeException('Injected preparation audit failure.');
            }
        });

        try {
            try {
                $preparation->prepare($actor, $id, 'Preparation rollback test.');
                $this->fail('Audit failure must abort preparation.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Injected preparation audit failure.', $exception->getMessage()
                );
            }

            $this->assertFalse($armed);
            $this->assertSame(
                0, DB::table('assessment_reissue_drafts')->where('request_id', $id)->count()
            );
            $this->assertSame(
                $beforeRequest,
                (array) DB::table('assessment_reissue_requests')->where('id', $id)->first()
            );
            $this->assertSame(
                2, DB::table('assessment_reissue_events')->where('request_id', $id)->count()
            );
            $this->assertSame($before, $this->reissueCalculationStoredState($examId));

            $result = $preparation->prepare($actor, $id, 'Successful retry.');
            $this->assertGreaterThanOrEqual(4, $result['draft_count']);
        } finally {
            $armed = false;
        }
    }

    private function grantReissueTestPermission(User $actor, string $name): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', $name)->value('id');
        $this->assertNotNull($permissionId, 'Missing permission: '.$name);

        if (! DB::table('role_permissions')->where('role_id', $actor->role_id)
            ->where('permission_id', $permissionId)->exists()) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $actor->role_id,
                'permission_id' => $permissionId,
                'created_at' => now(),
            ]);
        }
    }

    public function test_reissue_review_requires_separation_and_the_exact_draft_fingerprint(): void
    {
        [$context, $actor, $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $review = app(AssessmentReissueReviewService::class);
        $before = $this->reissueCalculationStoredState($context['exam']->id);

        try {
            $review->approve($actor, $id, $prepared['prepared_sha256'], 'Self review.');
            $this->fail('The requester and preparer cannot approve revisions.');
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $wrong = $prepared['prepared_sha256'] === str_repeat('0', 64)
            ? str_repeat('1', 64) : str_repeat('0', 64);

        try {
            $review->approve($reviewer, $id, $wrong, 'Review with a different fingerprint.');
            $this->fail('A different reviewed fingerprint must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }

        $review->approve(
            $reviewer, $id, $prepared['prepared_sha256'],
            'Checked corrected scores and recalculated positions.'
        );

        $request = DB::table('assessment_reissue_requests')->where('id', $id)->first();
        $this->assertSame('ready', $request->status);
        $this->assertSame($reviewer->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertSame($prepared['prepared_sha256'], $request->prepared_sha256);
        $this->assertSame(
            4, DB::table('assessment_reissue_events')->where('request_id', $id)->count()
        );
        $this->assertSame(
            $before, $this->reissueCalculationStoredState($context['exam']->id)
        );

        try {
            $review->approve($reviewer, $id, $prepared['prepared_sha256'], 'Repeated review.');
            $this->fail('A ready request cannot be reviewed again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }
    }

    public function test_reissue_review_detects_source_changes_and_rehashed_draft_changes(): void
    {
        [$context, , $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $review = app(AssessmentReissueReviewService::class);
        $examId = $context['exam']->id;
        $paper = DB::table('exam_papers')
            ->whereIn('exam_learning_area_id', function ($query) use ($examId): void {
                $query->select('id')->from('exam_learning_areas')->where('exam_id', $examId);
            })->orderBy('id')->first();
        $this->assertNotNull($paper);

        DB::table('exam_papers')->where('id', $paper->id)
            ->update(['max_marks' => (float) $paper->max_marks + 1]);

        try {
            $review->approve($reviewer, $id, $prepared['prepared_sha256'], 'Changed source.');
            $this->fail('Source changes must invalidate review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }

        DB::table('exam_papers')->where('id', $paper->id)
            ->update(['max_marks' => $paper->max_marks]);

        $draft = DB::table('assessment_reissue_drafts')
            ->where('request_id', $id)->where('output_type', 'report_card')->first();
        $this->assertNotNull($draft);
        $payload = json_decode($draft->payload, true, 512, JSON_THROW_ON_ERROR);
        $payload['output']['principal_comment'] = 'Changed after preparation';

        DB::table('assessment_reissue_drafts')->where('id', $draft->id)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_sha256' => app(
                AssessmentPublicationArchiveService::class
            )->fingerprint($payload),
        ]);

        try {
            $review->approve($reviewer, $id, $prepared['prepared_sha256'], 'Changed draft.');
            $this->fail('Rehashing a changed draft must not preserve approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }

        $this->assertSame(
            'prepared',
            DB::table('assessment_reissue_requests')->where('id', $id)->value('status')
        );
        $this->assertSame(
            3, DB::table('assessment_reissue_events')->where('request_id', $id)->count()
        );
    }

    private function reissueReviewContext(): array
    {
        [$context, $actor, $reviewer, $changes] = $this->reissueRequestContext();
        $this->grantReissueTestPermission($actor, 'prepare_assessment_reissues');
        $this->grantReissueTestPermission($reviewer, 'review_assessment_reissues');

        $requests = app(AssessmentReissueRequestService::class);
        $id = $requests->submit(
            $actor, $context['exam']->id, 'Correct the transcribed mark.', $changes
        );
        $requests->decide($reviewer, $id, 'approved', 'Verified original mark sheet.');
        $prepared = app(
            AssessmentReissuePreparationService::class
        )->prepare($actor, $id, 'Prepared corrected outputs.');

        return [$context, $actor, $reviewer, $id, $prepared];
    }

    public function test_reissue_publication_preserves_original_and_publishes_revision_once(): void
    {
        [$context, $actor, $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $this->grantReissueTestPermission($actor, 'publish_assessment_reissues');
        $publisher = app(AssessmentReissuePublicationService::class);
        $cardId = $context['card']->id;
        $examId = $context['exam']->id;

        try {
            $publisher->publish($actor, $id, $prepared['prepared_sha256'], 'Before review.');
            $this->fail('Publication before review must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }

        app(AssessmentReissueReviewService::class)->approve(
            $reviewer, $id, $prepared['prepared_sha256'], 'Verified revised outputs.'
        );

        $original = (array) DB::table('assessment_publication_versions')
            ->where('output_type', 'report_card')->where('output_id', $cardId)
            ->where('revision_number', 1)->first();
        $this->assertNotEmpty($original);

        $draft = DB::table('assessment_reissue_drafts')->where('request_id', $id)
            ->where('output_type', 'report_card')->where('output_id', $cardId)->first();
        $payload = json_decode($draft->payload, true, 512, JSON_THROW_ON_ERROR);

        $unpublishedMerits = DB::table('merit_lists')->where('exam_id', $examId)
            ->where('status', '!=', 'published')->pluck('id')->all();

        $result = $publisher->publish(
            $actor, $id, $prepared['prepared_sha256'], 'Release the approved correction.'
        );

        $this->assertGreaterThanOrEqual(4, $result['applied_count']);
        $this->assertSame(1, $result['publication_version_count']);
        $stored = DB::table('report_cards')->where('id', $cardId)->first();
        $this->assertSame('published', $stored->status);
        $rankedCount = DB::table('merit_lists')
            ->where('school_id', $context['exam']->school_id)
            ->where('exam_id', $examId)->where('is_deleted', false)
            ->whereNotNull('school_position')->distinct()->count('learner_id');
        $this->assertGreaterThan(1, $rankedCount);
        $this->assertSame($rankedCount, (int) $stored->total_learners);
        $this->assertSame($rankedCount, $payload['output']['total_learners']);
        $this->assertLessThanOrEqual(
            (int) $stored->total_learners, (int) $stored->school_position
        );
        $this->assertEquals($payload['output']['overall_score'], $stored->overall_score);
        $this->assertEquals(
            $payload['output']['average_percentage'], $stored->average_percentage
        );
        $this->assertSame(
            $payload['output']['presentation_snapshot'], $stored->presentation_snapshot
        );
        $this->assertSame($actor->id, $stored->published_by);

        $this->assertSame(
            $original,
            (array) DB::table('assessment_publication_versions')
                ->where('id', $original['id'])->first()
        );

        $revision = DB::table('assessment_publication_versions')
            ->where('output_type', 'report_card')->where('output_id', $cardId)
            ->where('revision_number', 2)->first();
        $this->assertNotNull($revision);
        $this->assertSame($id, $revision->reissue_request_id);
        $revisedPayload = json_decode($revision->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals((array) $stored, $revisedPayload['output']);
        $this->assertSame(
            $revision->payload_sha256,
            app(AssessmentPublicationArchiveService::class)
                ->fingerprint($revisedPayload)
        );

        $this->assertSame(
            count($unpublishedMerits),
            DB::table('merit_lists')->whereIn('id', $unpublishedMerits)
                ->where('status', '!=', 'published')->count()
        );
        $this->assertSame(
            'published',
            DB::table('assessment_reissue_requests')->where('id', $id)->value('status')
        );
        $this->assertSame(
            5, DB::table('assessment_reissue_events')->where('request_id', $id)->count()
        );

        try {
            $publisher->publish($actor, $id, $prepared['prepared_sha256'], 'Repeated release.');
            $this->fail('A published request cannot be published again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }

        $this->assertSame(
            2, DB::table('assessment_publication_versions')
                ->where('output_type', 'report_card')->where('output_id', $cardId)->count()
        );
    }

    public function test_reissue_publication_failures_roll_back_outputs_versions_and_audit(): void
    {
        [$context, $actor, $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $this->grantReissueTestPermission($actor, 'publish_assessment_reissues');
        app(AssessmentReissueReviewService::class)->approve(
            $reviewer, $id, $prepared['prepared_sha256'], 'Verified revised outputs.'
        );

        $publisher = app(AssessmentReissuePublicationService::class);
        $examId = $context['exam']->id;
        $before = $this->reissueCalculationStoredState($examId);
        $request = (array) DB::table('assessment_reissue_requests')->where('id', $id)->first();
        $versions = DB::table('assessment_publication_versions')->where('exam_id', $examId)
            ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $drafts = DB::table('assessment_reissue_drafts')->where('request_id', $id)
            ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $target = null;
        DB::listen(function ($query) use (&$target): void {
            $sql = strtolower(ltrim($query->sql));
            if ($target !== null && str_starts_with($sql, 'insert')
                && str_contains($sql, $target)) {
                $target = null;
                throw new \RuntimeException('Injected publication failure.');
            }
        });

        try {
            foreach ([
                'assessment_publication_versions',
                'assessment_reissue_events',
            ] as $failurePoint) {
                $target = $failurePoint;

                try {
                    $publisher->publish(
                        $actor, $id, $prepared['prepared_sha256'], 'Rollback regression.'
                    );
                    $this->fail('The injected publication failure must abort release.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame(
                        'Injected publication failure.', $exception->getMessage()
                    );
                }

                $this->assertNull($target);
                $this->assertSame($before, $this->reissueCalculationStoredState($examId));
                $this->assertSame(
                    $request,
                    (array) DB::table('assessment_reissue_requests')->where('id', $id)->first()
                );
                $this->assertSame(
                    $versions,
                    DB::table('assessment_publication_versions')->where('exam_id', $examId)
                        ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
                );
                $this->assertSame(
                    $drafts,
                    DB::table('assessment_reissue_drafts')->where('request_id', $id)
                        ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
                );
                $this->assertSame(
                    4, DB::table('assessment_reissue_events')->where('request_id', $id)->count()
                );
            }

            $result = $publisher->publish(
                $actor, $id, $prepared['prepared_sha256'], 'Successful retry.'
            );
            $this->assertSame(1, $result['publication_version_count']);
        } finally {
            $target = null;
        }
    }

    public function test_reissue_history_renders_original_and_revision_with_captured_labels(): void
    {
        [$context, $actor, $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $this->grantReissueTestPermission($actor, 'publish_assessment_reissues');
        $this->grantReissueTestPermission($actor, 'view_assessment_publication_history');

        app(AssessmentReissueReviewService::class)->approve(
            $reviewer, $id, $prepared['prepared_sha256'], 'Verified revision.'
        );
        app(AssessmentReissuePublicationService::class)->publish(
            $actor, $id, $prepared['prepared_sha256'], 'Release revision.'
        );

        $versions = DB::table('assessment_publication_versions')
            ->where('output_type', 'report_card')->where('output_id', $context['card']->id)
            ->orderBy('revision_number')->get();
        $this->assertCount(2, $versions);

        DB::table('learners')->where('id', $context['learner']->id)
            ->update(['first_name' => 'LIVE HISTORY NAME CHANGED']);
        DB::table('exams')->where('id', $context['exam']->id)
            ->update(['exam_name' => 'LIVE HISTORY EXAM CHANGED']);

        $history = app(AssessmentPublicationHistoryService::class);
        $scores = [];

        foreach ($versions as $version) {
            $record = $history->reportCard($actor, $version->id);
            $card = $record['card'];
            $scores[] = (float) $card->overall_score;
            $this->assertSame((int) $version->revision_number, $record['revision_number']);
            $this->assertSame($context['exam']->exam_name, $card->exam->exam_name);
            $this->assertSame($context['learner']->first_name, $card->learner->first_name);
            $this->assertArrayNotHasKey('presentation_snapshot', $card->toArray());

            $html = view('pdf.report-card', [
                'card' => $card,
                'settings' => $card->school->settings,
                'logo' => null,
                'signature' => null,
                'revisionNumber' => $record['revision_number'],
            ])->render();

            $this->assertStringContainsString(
                'Publication revision '.$record['revision_number'], $html
            );
            $this->assertStringContainsString(
                $record['revision_number'] === 1 ? 'Original publication' : 'Corrected reissue',
                $html
            );
            $this->assertStringNotContainsString('LIVE HISTORY NAME CHANGED', $html);
            $this->assertStringNotContainsString('LIVE HISTORY EXAM CHANGED', $html);

            $document = app(ReportCardPdfService::class)->makeVersion($actor, $version->id);
            $this->assertStringStartsWith('%PDF', $document['pdf']->output());
            $this->assertStringEndsWith(
                '-revision-'.$record['revision_number'].'.pdf', $document['filename']
            );
        }

        $this->assertNotSame($scores[0], $scores[1]);

        DB::table('schools')->where('id', $actor->school_id)
            ->update(['lifecycle_state' => 'read_only']);
        $this->assertSame(
            1, $history->reportCard($actor, $versions[0]->id)['revision_number']
        );

        $permissionId = DB::table('permissions')
            ->where('permission_name', 'view_assessment_publication_history')->value('id');
        DB::table('role_permissions')->where('role_id', $actor->role_id)
            ->where('permission_id', $permissionId)->delete();

        try {
            $history->reportCard($actor, $versions[0]->id);
            $this->fail('History access without permission must be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
    }

    public function test_reissue_history_second_correction_creates_revision_three(): void
    {
        [$context, $actor, $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $this->grantReissueTestPermission($actor, 'publish_assessment_reissues');
        $this->grantReissueTestPermission($actor, 'view_assessment_publication_history');

        $review = app(AssessmentReissueReviewService::class);
        $publisher = app(AssessmentReissuePublicationService::class);
        $review->approve($reviewer, $id, $prepared['prepared_sha256'], 'First revision verified.');
        $publisher->publish($actor, $id, $prepared['prepared_sha256'], 'First revision released.');

        $beforeVersions = DB::table('assessment_publication_versions')
            ->where('output_type', 'report_card')->where('output_id', $context['card']->id)
            ->orderBy('revision_number')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertCount(2, $beforeVersions);

        $paperDraft = DB::table('assessment_reissue_drafts')->where('request_id', $id)
            ->where('output_type', 'paper_result')->first();
        $this->assertNotNull($paperDraft);
        $paper = DB::table('exam_results')->where('id', $paperDraft->output_id)->first();

        $requests = app(AssessmentReissueRequestService::class);
        $secondId = $requests->submit(
            $actor, $context['exam']->id, 'A second verified correction.',
            [[
                'exam_result_id' => $paper->id,
                'marks' => (float) $paper->marks === 0.0 ? 1 : 0,
                'is_absent' => false,
            ]]
        );
        $requests->decide($reviewer, $secondId, 'approved', 'Second correction verified.');
        $secondPrepared = app(
            AssessmentReissuePreparationService::class
        )->prepare($actor, $secondId, 'Prepared second revision.');
        $review->approve(
            $reviewer, $secondId, $secondPrepared['prepared_sha256'],
            'Second revision outputs verified.'
        );
        $publisher->publish(
            $actor, $secondId, $secondPrepared['prepared_sha256'],
            'Second revision released.'
        );

        $versions = DB::table('assessment_publication_versions')
            ->where('output_type', 'report_card')->where('output_id', $context['card']->id)
            ->orderBy('revision_number')->get();
        $this->assertSame([1, 2, 3], $versions->map(
            fn ($row) => (int) $row->revision_number
        )->all());

        $this->assertSame(
            $beforeVersions,
            $versions->take(2)->map(fn ($row) => (array) $row)->all()
        );
        $this->assertSame($secondId, $versions[2]->reissue_request_id);

        $history = app(AssessmentPublicationHistoryService::class);
        $second = $history->reportCard($actor, $versions[1]->id);
        $third = $history->reportCard($actor, $versions[2]->id);
        $this->assertNotEquals(
            (float) $second['card']->overall_score,
            (float) $third['card']->overall_score
        );
        $this->assertEquals(
            DB::table('report_cards')->where('id', $context['card']->id)->value('overall_score'),
            $third['card']->overall_score
        );
    }

    public function test_authenticated_reissue_workflow_and_read_only_restrictions(): void
    {
        [$context, $actor, $reviewer, $changes] = $this->reissueRequestContext();
        foreach ([
            'request_assessment_reissues',
            'approve_assessment_reissues',
            'prepare_assessment_reissues',
            'review_assessment_reissues',
            'publish_assessment_reissues',
            'view_assessment_publication_history',
        ] as $permission) {
            $this->grantReissueTestPermission($actor, $permission);
        }

        config(['jwt.secret' => str_repeat('assessment-http-test-', 4)]);
        $http = function (User $user, string $method, string $url, array $data = []) {
            auth()->guard()->forgetUser();
            $token = JWTAuth::fromUser($user);

            return $this->withToken($token)->json($method, $url, $data);
        };

        $base = '/api/assessment-reissues';
        $created = $http($actor, 'POST', $base, [
            'exam_id' => $context['exam']->id,
            'reason' => 'Correct a transcribed paper mark.',
            'changes' => $changes,
        ])->assertOk();
        $id = $created->json('data.id');
        $this->assertNotEmpty($id);

        $http($actor, 'GET', $base.'/'.$id)
            ->assertOk()->assertJsonPath('data.status', 'pending');

        $http($actor, 'POST', $base.'/'.$id.'/decision', [
            'decision' => 'approved', 'reason' => 'Self approval attempt.',
        ])->assertForbidden();

        $http($reviewer, 'POST', $base.'/'.$id.'/decision', [
            'decision' => 'approved', 'reason' => 'Verified the original mark sheet.',
        ])->assertOk();

        $prepared = $http($actor, 'POST', $base.'/'.$id.'/prepare', [
            'reason' => 'Prepare corrected scores and positions.',
        ])->assertOk();
        $fingerprint = $prepared->json('data.prepared_sha256');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);

        $drafts = $http($reviewer, 'GET', $base.'/'.$id.'/drafts')->assertOk();
        $this->assertStringNotContainsString('presentation_snapshot', $drafts->getContent());
        $this->assertStringNotContainsString('"base64"', $drafts->getContent());

        $http($reviewer, 'POST', $base.'/'.$id.'/review', [
            'prepared_sha256' => $fingerprint,
            'reason' => 'Verified the prepared scores and ranking changes.',
        ])->assertOk();

        $http($actor, 'POST', $base.'/'.$id.'/publish', [
            'prepared_sha256' => $fingerprint,
            'reason' => 'Release the approved revision.',
        ])->assertOk();

        $http($actor, 'GET', $base.'/'.$id)
            ->assertOk()->assertJsonPath('data.status', 'published');

        $versions = $http($actor, 'GET', $base.'/publications?'.http_build_query([
            'exam_id' => $context['exam']->id,
            'output_type' => 'report_card',
            'output_id' => $context['card']->id,
        ]))->assertOk();
        $this->assertStringNotContainsString('presentation_snapshot', $versions->getContent());
        $this->assertStringNotContainsString('"base64"', $versions->getContent());

        $version = DB::table('assessment_publication_versions')
            ->where('output_type', 'report_card')->where('output_id', $context['card']->id)
            ->where('revision_number', 1)->first();
        $this->assertNotNull($version);

        $report = $http($actor, 'GET', $base.'/publications/'.$version->id.'/report')
            ->assertOk()->assertJsonPath('data.revision_number', 1);
        $this->assertStringNotContainsString('presentation_snapshot', $report->getContent());
        $this->assertStringNotContainsString('"base64"', $report->getContent());

        DB::table('schools')->where('id', $actor->school_id)
            ->update(['lifecycle_state' => 'read_only']);

        $before = $this->reissueCalculationStoredState($context['exam']->id);
        foreach ([
            [$base, [
                'exam_id' => $context['exam']->id,
                'reason' => 'Read-only request attempt.',
                'changes' => $changes,
            ]],
            [$base.'/'.$id.'/decision', [
                'decision' => 'approved', 'reason' => 'Read-only decision.',
            ]],
            [$base.'/'.$id.'/prepare', ['reason' => 'Read-only preparation.']],
            [$base.'/'.$id.'/review', [
                'prepared_sha256' => $fingerprint, 'reason' => 'Read-only review.',
            ]],
            [$base.'/'.$id.'/publish', [
                'prepared_sha256' => $fingerprint, 'reason' => 'Read-only publication.',
            ]],
        ] as [$url, $data]) {
            $http($actor, 'POST', $url, $data)->assertForbidden();
        }

        $http($actor, 'GET', $base.'/'.$id)->assertOk();
        $http($actor, 'GET', $base.'/publications/'.$version->id.'/report')->assertOk();
        $pdf = $http($actor, 'GET', $base.'/publications/'.$version->id.'/pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(
            $before, $this->reissueCalculationStoredState($context['exam']->id)
        );
    }

    public function test_authenticated_reissue_tenant_isolation_and_ownership_input(): void
    {
        [$context, $actor, , $id, $prepared] = $this->reissueReviewContext();

        $school = SchoolBuilder::create();
        $role = RoleBuilder::create([
            'role_name' => 'Reissue isolation '.Str::random(10),
            'school_id' => $school->id,
            'active' => true,
        ]);
        $account = UserBuilder::create($school, $role);
        $foreign = User::withoutGlobalScopes()->findOrFail($account->id);

        foreach ([
            'request_assessment_reissues', 'approve_assessment_reissues',
            'prepare_assessment_reissues', 'review_assessment_reissues',
            'publish_assessment_reissues', 'view_assessment_publication_history',
        ] as $permission) {
            $this->grantReissueTestPermission($foreign, $permission);
            $this->grantReissueTestPermission($actor, $permission);
        }

        $http = $this->reissueAuthenticatedHttp();
        $base = '/api/assessment-reissues';
        $before = $this->reissueCalculationStoredState($context['exam']->id);
        $requestBefore = (array) DB::table('assessment_reissue_requests')
            ->where('id', $id)->first();
        $eventCount = DB::table('assessment_reissue_events')->where('request_id', $id)->count();

        $http($foreign, 'GET', $base)->assertOk()->assertJsonPath('data.data', []);
        $http($foreign, 'GET', $base.'/'.$id)->assertNotFound();
        $http($foreign, 'GET', $base.'/'.$id.'/drafts')->assertNotFound();

        foreach ([
            ['decision', ['decision' => 'approved', 'reason' => 'Foreign decision.']],
            ['prepare', ['reason' => 'Foreign preparation.']],
            ['review', [
                'prepared_sha256' => $prepared['prepared_sha256'],
                'reason' => 'Foreign review.',
            ]],
            ['publish', [
                'prepared_sha256' => $prepared['prepared_sha256'],
                'reason' => 'Foreign publication.',
            ]],
        ] as [$action, $data]) {
            $http($foreign, 'POST', $base.'/'.$id.'/'.$action, $data)
                ->assertUnprocessable()->assertJsonValidationErrors('reissue_request');
        }

        $paper = DB::table('exam_results')->where('exam_id', $context['exam']->id)
            ->where('learner_id', $context['learner']->id)
            ->where('is_deleted', false)->orderBy('id')->first();
        $this->assertNotNull($paper);
        $changes = [[
            'exam_result_id' => $paper->id,
            'marks' => (float) $paper->marks === 0.0 ? 1 : 0,
            'is_absent' => false,
        ]];

        $http($foreign, 'POST', $base, [
            'exam_id' => $context['exam']->id,
            'reason' => 'Foreign exam request.',
            'changes' => $changes,
        ])->assertUnprocessable()->assertJsonValidationErrors('exam_id');

        $http($actor, 'POST', $base, [
            'school_id' => $school->id,
            'exam_id' => $context['exam']->id,
            'reason' => 'Client ownership attempt.',
            'changes' => $changes,
        ])->assertUnprocessable()->assertJsonValidationErrors('school_id');

        $version = DB::table('assessment_publication_versions')
            ->where('output_type', 'report_card')
            ->where('output_id', $context['card']->id)->first();
        $this->assertNotNull($version);

        $http($foreign, 'GET', $base.'/publications?'.http_build_query([
            'exam_id' => $context['exam']->id,
        ]))->assertOk()->assertJsonPath('data.data', []);

        foreach (['report', 'pdf'] as $suffix) {
            $http($foreign, 'GET', $base.'/publications/'.$version->id.'/'.$suffix)
                ->assertUnprocessable()->assertJsonValidationErrors('publication_version');
        }

        $this->assertSame($before, $this->reissueCalculationStoredState($context['exam']->id));
        $this->assertSame(
            $requestBefore,
            (array) DB::table('assessment_reissue_requests')->where('id', $id)->first()
        );
        $this->assertSame(
            $eventCount,
            DB::table('assessment_reissue_events')->where('request_id', $id)->count()
        );
        $this->assertSame(
            0, DB::table('assessment_reissue_requests')->where('school_id', $school->id)->count()
        );
    }

    public function test_authenticated_reissue_action_permissions_are_enforced(): void
    {
        [$context, $actor, , $id, $prepared] = $this->reissueReviewContext();
        $permissions = [
            'request_assessment_reissues', 'approve_assessment_reissues',
            'prepare_assessment_reissues', 'review_assessment_reissues',
            'publish_assessment_reissues', 'view_assessment_publication_history',
        ];
        foreach ($permissions as $permission) {
            $this->grantReissueTestPermission($actor, $permission);
        }

        $http = $this->reissueAuthenticatedHttp();
        $base = '/api/assessment-reissues';
        $before = $this->reissueCalculationStoredState($context['exam']->id);

        foreach ([
            ['request_assessment_reissues', $base, [
                'exam_id' => $context['exam']->id,
                'reason' => 'Missing request permission.',
                'changes' => [],
            ]],
            ['approve_assessment_reissues', $base.'/'.$id.'/decision', [
                'decision' => 'approved', 'reason' => 'Missing decision permission.',
            ]],
            ['prepare_assessment_reissues', $base.'/'.$id.'/prepare', [
                'reason' => 'Missing preparation permission.',
            ]],
            ['review_assessment_reissues', $base.'/'.$id.'/review', [
                'prepared_sha256' => $prepared['prepared_sha256'],
                'reason' => 'Missing review permission.',
            ]],
            ['publish_assessment_reissues', $base.'/'.$id.'/publish', [
                'prepared_sha256' => $prepared['prepared_sha256'],
                'reason' => 'Missing publication permission.',
            ]],
        ] as [$permission, $url, $data]) {
            $permissionId = DB::table('permissions')
                ->where('permission_name', $permission)->value('id');
            DB::table('role_permissions')->where('role_id', $actor->role_id)
                ->where('permission_id', $permissionId)->delete();

            $http($actor, 'POST', $url, $data)->assertForbidden();
            $this->grantReissueTestPermission($actor, $permission);
        }

        $permissionId = DB::table('permissions')
            ->where('permission_name', 'view_assessment_publication_history')->value('id');
        DB::table('role_permissions')->where('role_id', $actor->role_id)
            ->where('permission_id', $permissionId)->delete();
        $http($actor, 'GET', $base)->assertForbidden();
        $http($actor, 'GET', $base.'/'.$id)->assertForbidden();

        auth()->guard()->forgetUser();
        $this->withHeader('Authorization', '')->getJson($base)->assertUnauthorized();

        $this->assertSame(
            'prepared',
            DB::table('assessment_reissue_requests')->where('id', $id)->value('status')
        );
        $this->assertSame(
            $before, $this->reissueCalculationStoredState($context['exam']->id)
        );
    }

    private function reissueAuthenticatedHttp(): \Closure
    {
        config(['jwt.secret' => str_repeat('assessment-http-test-', 4)]);

        return function (User $user, string $method, string $url, array $data = []) {
            auth()->guard()->forgetUser();
            $token = JWTAuth::fromUser($user);

            return $this->withToken($token)->json($method, $url, $data);
        };
    }

    public function test_authenticated_reissue_cancellation_preserves_history_and_allows_fresh_request(): void
    {
        [$context, $actor, $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $this->grantReissueTestPermission($actor, 'view_assessment_publication_history');
        app(AssessmentReissueReviewService::class)->approve(
            $reviewer, $id, $prepared['prepared_sha256'], 'Verified prepared revision.'
        );

        $http = $this->reissueAuthenticatedHttp();
        $base = '/api/assessment-reissues';
        $examId = $context['exam']->id;
        $before = $this->reissueCalculationStoredState($examId);
        $drafts = DB::table('assessment_reissue_drafts')->where('request_id', $id)
            ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $versions = DB::table('assessment_publication_versions')->where('exam_id', $examId)
            ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $permissionId = DB::table('permissions')
            ->where('permission_name', 'approve_assessment_reissues')->value('id');
        DB::table('role_permissions')->where('role_id', $actor->role_id)
            ->where('permission_id', $permissionId)->delete();
        $http($actor, 'POST', $base.'/'.$id.'/cancel', [
            'reason' => 'Unauthorized cancellation.',
        ])->assertForbidden();
        $this->grantReissueTestPermission($actor, 'approve_assessment_reissues');

        $http($actor, 'POST', $base.'/'.$id.'/cancel', [
            'reason' => '   ',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $http($actor, 'POST', $base.'/'.$id.'/cancel', [
            'reason' => 'Withdraw this revision pending a fresh mark-sheet check.',
        ])->assertOk();
        $http($actor, 'GET', $base.'/'.$id)
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame($before, $this->reissueCalculationStoredState($examId));
        $this->assertSame(
            $drafts,
            DB::table('assessment_reissue_drafts')->where('request_id', $id)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
        );
        $this->assertSame(
            $versions,
            DB::table('assessment_publication_versions')->where('exam_id', $examId)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
        );
        $this->assertSame(
            1, DB::table('assessment_reissue_events')->where('request_id', $id)
                ->where('action', 'cancelled')->count()
        );

        $http($actor, 'POST', $base.'/'.$id.'/cancel', [
            'reason' => 'Repeated cancellation.',
        ])->assertUnprocessable()->assertJsonValidationErrors('reissue_request');

        $proposal = json_decode(
            DB::table('assessment_reissue_requests')->where('id', $id)
                ->value('proposed_changes'),
            true, 512, JSON_THROW_ON_ERROR
        );
        $changes = array_map(fn ($item): array => [
            'exam_result_id' => $item['exam_result_id'],
            'marks' => $item['after']['marks'],
            'is_absent' => $item['after']['is_absent'],
        ], $proposal);

        $created = $http($actor, 'POST', $base, [
            'exam_id' => $examId,
            'reason' => 'Fresh correction after cancellation.',
            'changes' => $changes,
        ])->assertOk();
        $this->assertNotSame($id, $created->json('data.id'));
        $this->assertSame(
            'pending',
            DB::table('assessment_reissue_requests')
                ->where('id', $created->json('data.id'))->value('status')
        );
    }

    public function test_current_pdf_identifies_reissue_while_archived_original_keeps_revision_one(): void
    {
        [$context, $actor, $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $this->grantReissueTestPermission($actor, 'publish_assessment_reissues');
        $this->grantReissueTestPermission($actor, 'view_assessment_publication_history');

        $seen = [];
        $document = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $document->shouldReceive('setPaper')->with('a4', 'portrait')
            ->times(3)->andReturnSelf();

        Pdf::shouldReceive('loadView')
            ->times(3)->andReturnUsing(
                function ($view, $data) use (&$seen, $document) {
                    $this->assertSame('pdf.report-card', $view);
                    $seen[] = $data['revisionNumber'];

                    return $document;
                }
            );

        $pdfs = app(ReportCardPdfService::class);
        $original = $pdfs->make($actor->school_id, $context['card']->id);
        $this->assertStringEndsWith('-revision-1.pdf', $original['filename']);

        app(AssessmentReissueReviewService::class)->approve(
            $reviewer, $id, $prepared['prepared_sha256'], 'Verified revision display.'
        );
        app(AssessmentReissuePublicationService::class)->publish(
            $actor, $id, $prepared['prepared_sha256'], 'Publish verified correction.'
        );

        $current = $pdfs->make($actor->school_id, $context['card']->id);
        $this->assertStringEndsWith('-revision-2.pdf', $current['filename']);

        $versionId = DB::table('assessment_publication_versions')
            ->where('school_id', $actor->school_id)
            ->where('output_type', 'report_card')
            ->where('output_id', $context['card']->id)
            ->where('revision_number', 1)->value('id');
        $this->assertNotNull($versionId);

        $archived = $pdfs->makeVersion($actor, $versionId);
        $this->assertStringEndsWith('-revision-1.pdf', $archived['filename']);
        $this->assertSame([1, 2, 1], $seen);
    }

    public function test_archived_merit_reader_preserves_values_and_rejects_tampering(): void
    {
        [$context, $actor] = $this->reissueRequestContext();
        $this->grantReissueTestPermission($actor, 'view_assessment_publication_history');

        $row = (array) DB::table('merit_lists')
            ->where('school_id', $actor->school_id)
            ->where('exam_id', $context['exam']->id)
            ->where('learner_id', $context['learner']->id)->first();
        $this->assertNotEmpty($row);

        $row['status'] = 'published';
        $row['published_at'] = now()->format('Y-m-d H:i:s');
        $payload = ['schema_version' => 1, 'output' => $row];
        $id = (string) Str::uuid();

        DB::table('assessment_publication_versions')->insert([
            'id' => $id,
            'school_id' => $actor->school_id,
            'exam_id' => $context['exam']->id,
            'learner_id' => $context['learner']->id,
            'output_type' => 'merit_list',
            'output_id' => $row['id'],
            'revision_number' => 1,
            'reissue_request_id' => null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_sha256' => app(
                AssessmentPublicationArchiveService::class
            )->fingerprint($payload),
            'published_at' => $row['published_at'],
            'published_by' => $actor->id,
            'recorded_at' => now(),
        ]);

        $history = app(
            AssessmentPublicationHistoryService::class
        );
        $before = $history->meritList($actor, $id);
        $this->assertSame(1, $before['revision_number']);
        $this->assertEquals($row['total_score'], $before['merit_list']['total_score']);
        $this->assertArrayNotHasKey('school_id', $before['merit_list']);
        $this->assertArrayNotHasKey('learner', $before['merit_list']);

        DB::table('merit_lists')->where('id', $row['id'])
            ->update(['total_score' => 0, 'school_position' => null]);
        $this->assertSame($before, $history->meritList($actor, $id));

        $payload['output']['total_score'] = 1;
        DB::table('assessment_publication_versions')->where('id', $id)
            ->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        try {
            $history->meritList($actor, $id);
            $this->fail('Tampered historical merit must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('publication_version', $exception->errors());
        }
    }

    public function test_authenticated_merit_history_enforces_permission_and_school_ownership(): void
    {
        [$context, $actor] = $this->reissueRequestContext();
        $this->grantReissueTestPermission($actor, 'view_assessment_publication_history');

        app(MeritListService::class)->publish(
            $actor->school_id, $context['exam']->id,
            null, null, $actor->id
        );
        app(AssessmentPublicationArchiveService::class)
            ->preserveOriginals($actor->school_id, $context['exam']->id);

        $version = DB::table('assessment_publication_versions')
            ->where('school_id', $actor->school_id)
            ->where('output_type', 'merit_list')
            ->where('learner_id', $context['learner']->id)
            ->where('exam_id', $context['exam']->id)->first();
        $this->assertNotNull($version);

        $http = $this->reissueAuthenticatedHttp();
        $url = '/api/assessment-reissues/publications/'.$version->id.'/merit';
        $before = $http($actor, 'GET', $url)->assertOk()
            ->assertJsonPath('data.revision_number', 1)->json('data');

        $this->assertSame($version->output_id, $before['merit_list']['id']);
        $this->assertArrayNotHasKey('school_id', $before['merit_list']);
        $this->assertArrayNotHasKey('learner', $before['merit_list']);

        DB::table('merit_lists')->where('id', $version->output_id)
            ->update(['total_score' => 0]);
        $this->assertSame(
            $before, $http($actor, 'GET', $url)->assertOk()->json('data')
        );

        DB::table('schools')->where('id', $actor->school_id)
            ->update(['lifecycle_state' => 'read_only']);
        $http($actor, 'GET', $url)->assertOk();

        $school = SchoolBuilder::create();
        $role = RoleBuilder::create([
            'role_name' => 'Merit history '.Str::random(12),
        ]);
        $foreign = UserBuilder::create($school, $role);
        $foreign = User::withoutGlobalScopes()->findOrFail($foreign->id);
        $foreign->forceFill(['active' => true, 'is_deleted' => false])->save();
        DB::table('roles')->where('id', $role->id)->update(['active' => true]);
        $this->grantReissueTestPermission($foreign, 'view_assessment_publication_history');

        $http($foreign, 'GET', $url)->assertUnprocessable()
            ->assertJsonValidationErrors('publication_version');

        $permission = DB::table('permissions')
            ->where('permission_name', 'view_assessment_publication_history')
            ->value('id');
        DB::table('role_permissions')->where('role_id', $actor->role_id)
            ->where('permission_id', $permission)->delete();
        $http($actor, 'GET', $url)->assertForbidden();
    }

    public function test_report_resources_distinguish_publication_revision_from_snapshot_version(): void
    {
        [$context, $actor, $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $this->grantReissueTestPermission($actor, 'publish_assessment_reissues');
        $this->grantReissueTestPermission($actor, 'view_assessment_publication_history');

        $read = function ($card): array {
            return [
                'model' => $card->toArray(),
                'staff' => (new ReportCardResource($card))->resolve(request()),
                'parent' => (new ParentReportCardResource([
                    'report_card' => $card,
                    'access' => [
                        'can_view' => true,
                        'can_download' => true,
                        'restriction_message' => null,
                    ],
                ]))->resolve(request()),
            ];
        };

        foreach ($read($context['card']->fresh()) as $payload) {
            $this->assertSame(1, $payload['publication_revision']);
            $this->assertSame(1, $payload['presentation_history']['version']);
        }

        app(AssessmentReissueReviewService::class)->approve(
            $reviewer, $id, $prepared['prepared_sha256'], 'Checked revision metadata.'
        );
        app(AssessmentReissuePublicationService::class)->publish(
            $actor, $id, $prepared['prepared_sha256'], 'Publish checked revision.'
        );

        foreach ($read($context['card']->fresh()) as $payload) {
            $this->assertSame(2, $payload['publication_revision']);
            $this->assertSame(1, $payload['presentation_history']['version']);
        }

        $versionId = DB::table('assessment_publication_versions')
            ->where('school_id', $actor->school_id)
            ->where('output_type', 'report_card')
            ->where('output_id', $context['card']->id)
            ->where('revision_number', 1)->value('id');

        $original = app(
            AssessmentPublicationHistoryService::class
        )->reportCard($actor, $versionId);

        foreach ($read($original['card']) as $payload) {
            $this->assertSame(1, $payload['publication_revision']);
        }

        $preview = $context['card']->fresh();
        $preview->status = 'generated';
        $this->assertNull($preview->publication_revision);
    }

    public function test_archive_checks_original_integrity_and_current_output_after_reissue(): void
    {
        [$context, $actor, $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $this->grantReissueTestPermission($actor, 'publish_assessment_reissues');

        app(AssessmentReissueReviewService::class)->approve(
            $reviewer, $id, $prepared['prepared_sha256'], 'Verified correction.'
        );
        app(AssessmentReissuePublicationService::class)->publish(
            $actor, $id, $prepared['prepared_sha256'], 'Publish correction.'
        );

        $archive = app(
            AssessmentPublicationArchiveService::class
        );
        $archive->preserveOriginals($actor->school_id, $context['exam']->id);

        $original = DB::table('assessment_publication_versions')
            ->where('school_id', $actor->school_id)
            ->where('output_type', 'report_card')
            ->where('output_id', $context['card']->id)
            ->where('revision_number', 1)->first();
        $this->assertNotNull($original);

        DB::table('assessment_publication_versions')->where('id', $original->id)
            ->update(['payload_sha256' => str_repeat('0', 64)]);

        try {
            $archive->preserveOriginals($actor->school_id, $context['exam']->id);
            $this->fail('An altered original must block history verification.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('publication_history', $exception->errors());
        }

        DB::table('assessment_publication_versions')->where('id', $original->id)
            ->update(['payload_sha256' => $original->payload_sha256]);
        $archive->preserveOriginals($actor->school_id, $context['exam']->id);

        DB::table('report_cards')->where('id', $context['card']->id)
            ->update(['overall_score' => 999]);

        try {
            $archive->preserveOriginals($actor->school_id, $context['exam']->id);
            $this->fail('Unarchived changes to the working publication must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('publication_history', $exception->errors());
        }

        $this->assertSame(
            2,
            DB::table('assessment_publication_versions')
                ->where('output_type', 'report_card')
                ->where('output_id', $context['card']->id)->count()
        );
    }

    public function test_reissue_publication_rejects_changed_roster_grade_education_level(): void
    {
        [$context, $actor, $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $this->grantReissueTestPermission($actor, 'publish_assessment_reissues');

        app(AssessmentReissueReviewService::class)->approve(
            $reviewer, $id, $prepared['prepared_sha256'], 'Verified original classification.'
        );

        $before = $this->reissueCalculationStoredState($context['exam']->id);
        $versionCount = DB::table('assessment_publication_versions')
            ->where('exam_id', $context['exam']->id)->count();

        $gradeId = DB::table('exam_roster_learners')
            ->where('school_id', $actor->school_id)
            ->where('exam_id', $context['exam']->id)
            ->where('learner_id', $context['learner']->id)->value('grade_id');
        $this->assertNotNull($gradeId);

        $level = EducationLevelBuilder::create([
            'level_name' => 'Changed classification '.Str::random(12),
            'level_order' => 99,
        ]);
        DB::table('grades')->where('school_id', $actor->school_id)
            ->where('id', $gradeId)->update(['education_level_id' => $level->id]);

        try {
            app(AssessmentReissuePublicationService::class)->publish(
                $actor, $id, $prepared['prepared_sha256'], 'Attempt outdated publication.'
            );
            $this->fail('Changed grading classification must invalidate approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reissue_request', $exception->errors());
        }

        $this->assertSame($before, $this->reissueCalculationStoredState($context['exam']->id));
        $this->assertSame(
            $versionCount,
            DB::table('assessment_publication_versions')
                ->where('exam_id', $context['exam']->id)->count()
        );
        $this->assertSame(
            'ready',
            DB::table('assessment_reissue_requests')->where('id', $id)->value('status')
        );
        $this->assertSame(
            0,
            DB::table('assessment_reissue_events')->where('request_id', $id)
                ->where('action', 'published')->count()
        );
    }

    public function test_report_contacts_remain_captured_after_profile_changes_and_reissue(): void
    {
        $context = $this->context();
        $schoolId = $context['exam']->school_id;
        $contacts = [
            'email' => 'office@example.test',
            'phone' => '+254700123456',
            'postal_address' => 'P.O. Box 123 - 00100, Nairobi',
            'physical_address' => 'School Road, Nairobi',
        ];
        DB::table('schools')->where('id', $schoolId)->update($contacts);

        app(ReportCardService::class)->publish(
            $schoolId, $context['exam']->id, $context['learner']->id,
            null, null, $context['user']->id
        );

        $card = $context['card']->fresh();
        foreach ($contacts as $field => $value) {
            $this->assertSame($value, $card->presentation_snapshot['school'][$field]);
        }

        $render = function ($card): string {
            $display = app(
                ReportCardPresentationSnapshotService::class
            )->forDisplay($card);

            return view('pdf.report-card', [
                'card' => $display,
                'settings' => $display->school->settings,
                'logo' => null,
                'signature' => null,
                'revisionNumber' => 1,
            ])->render();
        };

        $before = $render($card);
        foreach ($contacts as $value) {
            $this->assertStringContainsString($value, $before);
        }

        DB::table('schools')->where('id', $schoolId)->update([
            'email' => 'changed@example.test',
            'phone' => '+254799999999',
            'postal_address' => 'CHANGED POSTAL ADDRESS',
            'physical_address' => 'CHANGED PHYSICAL ADDRESS',
        ]);

        $this->assertSame($before, $render($context['card']->fresh()));

        $actor = User::withoutGlobalScopes()->findOrFail($context['user']->id);
        $actor->forceFill(['active' => true, 'is_deleted' => false])->save();
        DB::table('roles')->where('id', $actor->role_id)->update(['active' => true]);

        foreach ([
            'request_assessment_reissues', 'approve_assessment_reissues',
            'prepare_assessment_reissues', 'review_assessment_reissues',
            'publish_assessment_reissues', 'view_assessment_publication_history',
        ] as $permission) {
            $this->grantReissueTestPermission($actor, $permission);
        }

        $reviewer = $actor->replicate();
        $reviewer->id = (string) Str::uuid();
        $reviewer->username = 'contact-review-'.Str::random(12);
        $reviewer->email = Str::random(12).'@example.test';
        $reviewer->phone = null;
        $reviewer->save();

        $result = DB::table('exam_results')->where('exam_id', $context['exam']->id)
            ->where('learner_id', $context['learner']->id)
            ->where('is_deleted', false)->where('is_absent', false)->first();
        $this->assertNotNull($result);

        $requests = app(AssessmentReissueRequestService::class);
        $id = $requests->submit($actor, $context['exam']->id, 'Verified mark correction.', [[
            'exam_result_id' => $result->id,
            'marks' => (float) $result->marks === 0.0 ? 1 : 0,
            'is_absent' => false,
        ]]);
        $requests->decide($reviewer, $id, 'approved', 'Checked source marks.');

        $prepared = app(
            AssessmentReissuePreparationService::class
        )->prepare($actor, $id, 'Prepare correction.');

        app(AssessmentReissueReviewService::class)->approve(
            $reviewer, $id, $prepared['prepared_sha256'], 'Checked prepared report.'
        );
        app(AssessmentReissuePublicationService::class)->publish(
            $actor, $id, $prepared['prepared_sha256'], 'Publish correction.'
        );

        $history = app(
            AssessmentPublicationHistoryService::class
        );
        $versions = DB::table('assessment_publication_versions')
            ->where('school_id', $schoolId)->where('output_type', 'report_card')
            ->where('output_id', $card->id)->orderBy('revision_number')->get();
        $this->assertCount(2, $versions);

        foreach ($versions as $version) {
            $archived = $history->reportCard($actor, $version->id)['card'];
            $html = $render($archived);

            foreach ($contacts as $field => $value) {
                $this->assertSame($value, $archived->school->$field);
                $this->assertStringContainsString($value, $html);
            }
            $this->assertStringNotContainsString('changed@example.test', $html);
            $this->assertStringNotContainsString('CHANGED POSTAL ADDRESS', $html);
        }
    }

    public function test_uploaded_branding_reaches_preview_and_publication_without_changing_history(): void
    {
        $context = $this->context();
        $schoolId = $context['exam']->school_id;
        $actor = User::withoutGlobalScopes()->findOrFail($context['user']->id);

        // Exercise real image validation, quarantine, encryption and decryption.
        // Portal authorization is isolated here; HTTP permission tests are separate.
        $this->mock(
            AdministratorPortalAccessService::class
        )->shouldReceive('require')
            ->with($actor, 'manage_school_branding')
            ->andReturn(['school_id' => $schoolId]);

        $branding = app(
            SchoolBrandingAdministrationService::class
        );
        $reader = app(
            ReportBrandingAssetService::class
        );
        $storage = app(SecureFileStorage::class);

        try {
            $branding->upload(
                $actor,
                UploadedFile::fake()->image('school-logo.png', 100, 100),
                'report_card_logo'
            );
            $captured = $reader->read($schoolId, 'logo');
            $this->assertSame('captured', $captured['status']);
            $this->assertSame('image/png', $captured['mime']);
            $expected = 'data:'.$captured['mime'].';base64,'.$captured['base64'];

            $logos = [];
            $document = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
            $document->shouldReceive('setPaper')->with('a4', 'portrait')
                ->twice()->andReturnSelf();

            Pdf::shouldReceive('loadView')
                ->twice()->andReturnUsing(
                    function ($view, $data) use (&$logos, $document) {
                        $this->assertSame('pdf.report-card', $view);
                        $logos[] = $data['logo'];

                        return $document;
                    }
                );

            $pdfs = app(ReportCardPdfService::class);
            $pdfs->make($schoolId, $context['card']->id);

            app(ReportCardService::class)->publish(
                $schoolId, $context['exam']->id, $context['learner']->id,
                null, null, $actor->id
            );

            $card = $context['card']->fresh();
            $storedLogo = $card->presentation_snapshot['assets']['logo'];
            $expectedLogo = $captured;
            ksort($storedLogo);
            ksort($expectedLogo);
            $this->assertSame($expectedLogo, $storedLogo);

            $branding->upload(
                $actor,
                UploadedFile::fake()->image('replacement.png', 140, 90),
                'report_card_logo'
            );
            $this->assertNotSame(
                $captured['sha256'], $reader->read($schoolId, 'logo')['sha256']
            );

            $pdfs->make($schoolId, $card->id);
            $this->assertSame([$expected, $expected], $logos);
            $refreshedLogo = $card->fresh()->presentation_snapshot['assets']['logo'];
            ksort($refreshedLogo);
            $this->assertSame($expectedLogo, $refreshedLogo);

            $this->assertNull(
                $reader->read((string) Str::uuid(), 'logo')
            );
        } finally {
            $ids = DB::table('administrator_branding_assets')
                ->where('school_id', $schoolId)->pluck('storage_id');
            foreach ($ids as $storageId) {
                $storage->delete($storageId);
            }
        }
    }

    public function test_report_learning_areas_sort_alphabetically_using_captured_names(): void
    {
        $context = $this->context();
        $details = $context['card']->fresh()->learningAreas;
        $this->assertCount(2, $details);

        DB::table('learning_areas')->where('id', $details[0]->learning_area_id)
            ->update(['learning_area_name' => 'zoology']);
        DB::table('learning_areas')->where('id', $details[1]->learning_area_id)
            ->update(['learning_area_name' => 'English']);

        $service = app(
            ReportCardPresentationSnapshotService::class
        );
        $names = fn ($card) => $service->forDisplay($card)->learningAreas
            ->map(fn ($detail) => $detail->learningArea->learning_area_name)->all();

        $preview = $context['card']->fresh()->load('learningAreas.learningArea');
        $beforeOrder = $preview->learningAreas->pluck('id')->all();
        $this->assertSame(['English', 'zoology'], $names($preview));
        $this->assertSame($beforeOrder, $preview->learningAreas->pluck('id')->all());

        app(ReportCardService::class)->publish(
            $context['exam']->school_id, $context['exam']->id,
            $context['learner']->id, null, null, $context['user']->id
        );

        $snapshot = $context['card']->fresh()->presentation_snapshot;
        DB::table('learning_areas')->where('id', $details[0]->learning_area_id)
            ->update(['learning_area_name' => 'A changed live name']);
        DB::table('learning_areas')->where('id', $details[1]->learning_area_id)
            ->update(['learning_area_name' => 'Z changed live name']);

        $published = $context['card']->fresh()->load('learningAreas.learningArea');
        $published->setRelation('learningAreas', $published->learningAreas->reverse()->values());
        $this->assertSame(['English', 'zoology'], $names($published));
        $this->assertSame($snapshot, $context['card']->fresh()->presentation_snapshot);
    }

    public function test_original_and_reissued_reports_share_alphabetical_display_order(): void
    {
        [$context, $actor, $reviewer, $id, $prepared] = $this->reissueReviewContext();
        $this->grantReissueTestPermission($actor, 'view_assessment_publication_history');
        $this->grantReissueTestPermission($actor, 'publish_assessment_reissues');

        app(AssessmentReissueReviewService::class)->approve(
            $reviewer, $id, $prepared['prepared_sha256'], 'Verified alphabetical report.'
        );
        app(AssessmentReissuePublicationService::class)->publish(
            $actor, $id, $prepared['prepared_sha256'], 'Publish corrected report.'
        );

        $versions = DB::table('assessment_publication_versions')
            ->where('school_id', $context['exam']->school_id)
            ->where('output_type', 'report_card')
            ->where('output_id', $context['card']->id)
            ->orderBy('revision_number')->get();
        $this->assertCount(2, $versions);

        $orders = [];
        foreach ($versions as $version) {
            $card = app(
                AssessmentPublicationHistoryService::class
            )->reportCard($actor, $version->id)['card'];
            $display = app(
                ReportCardPresentationSnapshotService::class
            )->forDisplay($card);
            $names = $display->learningAreas->map(
                fn ($detail) => $detail->learningArea->learning_area_name
            )->all();
            $expected = $names;
            usort($expected, 'strnatcasecmp');
            $this->assertSame($expected, $names);
            $orders[] = $names;
        }

        $this->assertSame($orders[0], $orders[1]);
    }

    private function context(bool $primary = false): array
    {
        $exam = DB::table('exams')
            ->where('exam_name', 'Report Card Exam')
            ->whereNotNull('start_date')
            ->first();

        if (! $exam) {
            throw new \RuntimeException('Report Card Exam fixture was not found.');
        }

        $learner = DB::table('learners as l')
            ->join('grades as g', 'g.id', '=', 'l.grade_id')
            ->join('education_levels as e', 'e.id', '=', 'g.education_level_id')
            ->where('l.school_id', $exam->school_id)
            ->where(
                'e.level_name',
                'like',
                $primary ? 'Primary%' : 'Junior School%'
            )
            ->select('l.*')
            ->first();

        $user = DB::table('users')
            ->where('school_id', $exam->school_id)
            ->first();

        app(ReportCardService::class)->generate(
            $exam->school_id,
            $exam->id,
            $learner->id,
            null,
            null,
            $user->id
        );

        $card = ReportCard::where(
            'learner_id',
            $learner->id
        )->first();

        $auth = new User;
        $auth->forceFill([
            'id' => $user->id,
            'school_id' => $exam->school_id,
        ]);

        Auth::setUser($auth);

        return compact(
            'exam',
            'learner',
            'user',
            'card'
        );
    }

    public function test_tenant_can_stream_and_download_pdf(): void
    {
        $this->withoutMiddleware();

        $context = $this->context();

        $stream = $this->get(
            '/api/report-cards/'
            .$context['card']->id
            .'/pdf?school_id='
            .$context['exam']->school_id
        )
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/pdf'
            );

        $this->assertStringStartsWith(
            '%PDF',
            $stream->getContent()
        );

        $download = $this->get(
            '/api/report-cards/'
            .$context['card']->id
            .'/pdf/download?school_id='
            .$context['exam']->school_id
        )
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/pdf'
            );

        $this->assertStringContainsString(
            'attachment',
            $download->headers->get(
                'content-disposition'
            )
        );
    }

    public function test_cross_school_missing_and_invalid_status_are_rejected(): void
    {
        $this->withoutMiddleware();
        $context = $this->context();

        $otherSchool = DB::table('schools')
            ->where('id', '!=', $context['exam']->school_id)->first();
        $this->assertNotNull($otherSchool);

        $foreignRole = RoleBuilder::create([
            'role_name' => 'Foreign PDF reader '.Str::random(8),
        ]);
        $foreignRecord = UserBuilder::create($otherSchool, $foreignRole);
        $foreignUser = User::withoutGlobalScopes()->findOrFail($foreignRecord->id);
        $owner = User::withoutGlobalScopes()->findOrFail($context['user']->id);

        Auth::setUser($foreignUser);

        try {
            $this->get('/api/report-cards/'.$context['card']->id.'/pdf')
                ->assertNotFound();
        } finally {
            Auth::setUser($owner);
        }

        $this->get(
            '/api/report-cards/00000000-0000-0000-0000-000000000000/pdf'
        )->assertNotFound();

        DB::table('report_cards')
            ->where('id', $context['card']->id)
            ->update(['status' => 'draft']);

        $this->getJson('/api/report-cards/'.$context['card']->id.'/pdf')
            ->assertUnprocessable();
    }

    public function test_pdf_handles_optional_null_fields(): void
    {
        $context = $this->context(true);

        DB::table('report_cards')
            ->where('id', $context['card']->id)
            ->update([
                'class_teacher_comment' => null,
                'principal_comment' => null,
                'attendance_total_sessions' => null,
                'total_points' => null,
                'pathway_recommendation_id' => null,
            ]);

        $document = app(ReportCardPdfService::class)->make(
            $context['exam']->school_id,
            $context['card']->id
        );

        $this->assertStringStartsWith(
            '%PDF',
            $document['pdf']->output()
        );
    }

    public function test_template_includes_pathway_only_when_relationship_exists(): void
    {
        $junior = $this->context();

        $document = app(ReportCardPdfService::class)->make(
            $junior['exam']->school_id,
            $junior['card']->id
        );

        $html = view(
            'pdf.report-card',
            [
                'card' => $junior['card']
                    ->fresh()
                    ->load([
                        'school.settings',
                        'learner.grade',
                        'learner.stream',
                        'exam',
                        'academicYear',
                        'term',
                        'grade',
                        'stream',
                        'overallGradingScale',
                        'pathwayRecommendation',
                        'learningAreas.learningArea',
                        'learningAreas.gradingScale',
                    ]),
                'settings' => null,
                'logo' => null,
                'signature' => null,
            ]
        )->render();

        $this->assertStringContainsString(
            'Junior School Pathway Recommendation',
            $html
        );

        $this->assertStringStartsWith(
            '%PDF',
            $document['pdf']->output()
        );

        $primary = $this->context(true);

        $html = view(
            'pdf.report-card',
            [
                'card' => $primary['card']
                    ->fresh()
                    ->load([
                        'school.settings',
                        'learner.grade',
                        'learner.stream',
                        'exam',
                        'academicYear',
                        'term',
                        'grade',
                        'stream',
                        'overallGradingScale',
                        'pathwayRecommendation',
                        'learningAreas.learningArea',
                        'learningAreas.gradingScale',
                    ]),
                'settings' => null,
                'logo' => null,
                'signature' => null,
            ]
        )->render();

        $this->assertStringNotContainsString(
            'Junior School Pathway Recommendation',
            $html
        );
    }

    public function test_smoke_test_route_is_removed(): void
    {
        $this->withoutMiddleware();

        $this->get(
            '/api/auth/pdf-smoke-test'
        )->assertNotFound();
    }
}
