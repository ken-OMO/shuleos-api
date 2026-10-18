<?php

namespace Tests\Feature;

use App\Http\Resources\ReportCardLearningAreaResource;
use App\Http\Resources\ReportCardResource;
use App\Models\ReportCard;
use App\Models\User;
use App\Services\Assessment\ReportCardService;
use App\Services\Pdf\ReportCardPdfService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
