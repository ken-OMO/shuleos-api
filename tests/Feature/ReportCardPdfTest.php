<?php

namespace Tests\Feature;

use App\Models\ReportCard;
use App\Models\User;
use App\Services\Assessment\ReportCardService;
use App\Services\Pdf\ReportCardPdfService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportCardPdfTest extends ReportCardTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('school_settings');
        Schema::create('school_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->unique();
            $table->string('school_motto')->nullable();
            $table->string('principal_name')->nullable();
            $table->text('principal_signature_url')->nullable();
            $table->text('school_logo_url')->nullable();
            $table->text('report_header')->nullable();
            $table->text('report_footer')->nullable();
            $table->boolean('pathway_enabled')->default(true);
            $table->timestamps();
        });
    }

    private function context(bool $primary = false): array
    {
        $exam = DB::table('exams')->whereNotNull('start_date')->first();
        $learner = DB::table('learners as l')->join('grades as g', 'g.id', '=', 'l.grade_id')->join('education_levels as e', 'e.id', '=', 'g.education_level_id')->where('e.level_name', $primary ? 'Primary' : 'Junior School')->select('l.*')->first();
        $user = DB::table('users')->first();
        app(ReportCardService::class)->generate($exam->school_id, $exam->id, $learner->id, null, null, $user->id);
        $card = ReportCard::where('learner_id', $learner->id)->first();
        $auth = new User;
        $auth->forceFill(['id' => $user->id, 'school_id' => $exam->school_id]);
        Auth::setUser($auth);

        return compact('exam', 'learner', 'user', 'card');
    }

    public function test_tenant_can_stream_and_download_pdf(): void
    {
        $this->withoutMiddleware();
        $c = $this->context();
        $stream = $this->get('/api/report-cards/'.$c['card']->id.'/pdf?school_id='.$c['exam']->school_id)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $stream->getContent());
        $download = $this->get('/api/report-cards/'.$c['card']->id.'/pdf/download?school_id='.$c['exam']->school_id)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment', $download->headers->get('content-disposition'));
    }

    public function test_cross_school_missing_and_invalid_status_are_rejected(): void
    {
        $this->withoutMiddleware();
        $c = $this->context();
        $other = DB::table('schools')->where('id', '!=', $c['exam']->school_id)->value('id');
        $this->get('/api/report-cards/'.$c['card']->id.'/pdf?school_id='.$other)->assertNotFound();
        $this->get('/api/report-cards/00000000-0000-0000-0000-000000000000/pdf?school_id='.$c['exam']->school_id)->assertNotFound();
        DB::table('report_cards')->where('id', $c['card']->id)->update(['status' => 'draft']);
        $this->getJson('/api/report-cards/'.$c['card']->id.'/pdf?school_id='.$c['exam']->school_id)->assertUnprocessable();
    }

    public function test_pdf_handles_optional_null_fields(): void
    {
        $c = $this->context(true);
        DB::table('report_cards')->where('id', $c['card']->id)->update(['class_teacher_comment' => null, 'principal_comment' => null, 'attendance_total_sessions' => null, 'total_points' => null, 'pathway_recommendation_id' => null]);
        $document = app(ReportCardPdfService::class)->make($c['exam']->school_id, $c['card']->id);
        $this->assertStringStartsWith('%PDF', $document['pdf']->output());
    }

    public function test_template_includes_pathway_only_when_relationship_exists(): void
    {
        $junior = $this->context();
        $doc = app(ReportCardPdfService::class)->make($junior['exam']->school_id, $junior['card']->id);
        $html = view('pdf.report-card', ['card' => $junior['card']->fresh()->load(['school.settings', 'learner.grade', 'learner.stream', 'exam', 'academicYear', 'term', 'grade', 'stream', 'overallGradingScale', 'pathwayRecommendation', 'learningAreas.learningArea', 'learningAreas.gradingScale']), 'settings' => null, 'logo' => null, 'signature' => null])->render();
        $this->assertStringContainsString('Junior School Pathway Recommendation', $html);
        $this->assertStringStartsWith('%PDF', $doc['pdf']->output());
        $primary = $this->context(true);
        $html = view('pdf.report-card', ['card' => $primary['card']->fresh()->load(['school.settings', 'learner.grade', 'learner.stream', 'exam', 'academicYear', 'term', 'grade', 'stream', 'overallGradingScale', 'pathwayRecommendation', 'learningAreas.learningArea', 'learningAreas.gradingScale']), 'settings' => null, 'logo' => null, 'signature' => null])->render();
        $this->assertStringNotContainsString('Junior School Pathway Recommendation', $html);
    }

    public function test_smoke_test_route_is_removed(): void
    {
        $this->withoutMiddleware();
        $this->get('/api/auth/pdf-smoke-test')->assertNotFound();
    }
}
