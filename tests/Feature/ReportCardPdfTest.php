<?php

namespace Tests\Feature;

use App\Http\Resources\ParentReportCardResource;
use App\Http\Resources\ReportCardLearningAreaResource;
use App\Http\Resources\ReportCardResource;
use App\Models\Learner;
use App\Models\ReportCard;
use App\Models\User;
use App\Services\Assessment\ReportCardPresentationSnapshotService;
use App\Services\Assessment\ReportCardService;
use App\Services\LearnerPortal\LearnerAccountService;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\LearnerPortal\LearnerPortalService;
use App\Services\Pdf\ReportCardPdfService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

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
            ->where('id', '!=', $context['exam']->school_id)
            ->value('id');

        $this->get(
            '/api/report-cards/'
            .$context['card']->id
            .'/pdf?school_id='
            .$otherSchool
        )->assertNotFound();

        $this->get(
            '/api/report-cards/'
            .'00000000-0000-0000-0000-000000000000'
            .'/pdf?school_id='
            .$context['exam']->school_id
        )->assertNotFound();

        DB::table('report_cards')
            ->where('id', $context['card']->id)
            ->update(['status' => 'draft']);

        $this->getJson(
            '/api/report-cards/'
            .$context['card']->id
            .'/pdf?school_id='
            .$context['exam']->school_id
        )->assertUnprocessable();
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
