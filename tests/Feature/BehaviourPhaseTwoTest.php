<?php

namespace Tests\Feature;

use App\Http\Resources\AttendanceRiskFlagResource;
use App\Http\Resources\CounsellingReferralSummaryResource;
use App\Models\AttendanceRiskFlag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BehaviourPhaseTwoTest extends TestCase
{
    public function test_phase_two_routes_are_registered(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->methods()[0].' '.$route->uri());

        foreach ([
            'POST api/teacher/behaviour/cases',
            'POST api/teacher/behaviour/recognitions',
            'GET api/learner/behaviour/recognitions',
            'GET api/parent/learners/{learner}/behaviour/recognitions',
            'GET api/behaviour/analytics',
            'GET api/behaviour/risk-indicators',
            'GET api/attendance/risk-flags',
            'POST api/attendance/{register}/correct',
        ] as $expected) {
            $this->assertContains($expected, $routes);
        }
    }

    public function test_leadership_correction_route_has_explicit_permission(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/attendance/{register}/correct');

        $this->assertNotNull($route);
        $this->assertContains('permission:correct_finalized_attendance', $route->gatherMiddleware());
    }

    public function test_risk_resource_hides_tenant_and_sensitive_metadata(): void
    {
        $flag = new AttendanceRiskFlag([
            'id' => 'flag-1',
            'school_id' => 'hidden-school',
            'learner_id' => 'learner-1',
            'flag_type' => 'repeated_absence',
            'status' => 'open',
            'metadata' => ['private' => true],
            'resolution_notes' => 'private note',
        ]);

        $data = (new AttendanceRiskFlagResource($flag))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('school_id', $data);
        $this->assertArrayNotHasKey('metadata', $data);
        $this->assertArrayNotHasKey('resolution_notes', $data);
    }

    public function test_referral_summary_never_exposes_confidential_notes(): void
    {
        $referral = (object) [
            'id' => 'referral-1',
            'learner_id' => 'learner-1',
            'priority' => 'high',
            'status' => 'referred',
            'referred_at' => now(),
            'accepted_at' => null,
            'completed_at' => null,
            'referral_reason' => 'private reason',
            'outcome_summary' => 'private outcome',
        ];

        $data = (new CounsellingReferralSummaryResource($referral))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('referral_reason', $data);
        $this->assertArrayNotHasKey('outcome_summary', $data);
    }

    public function test_behaviour_intelligence_cannot_write_official_results_or_trigger_actions(): void
    {
        $source = file_get_contents(app_path('Services/Behaviour/BehaviourAnalyticsService.php'));

        $this->assertStringContainsString("'automatic_action' => false", $source);
        $this->assertStringNotContainsString('exam_results', $source);
        $this->assertStringNotContainsString('learning_area_results', $source);
        $this->assertStringNotContainsString('DisciplineAction::create', $source);
    }
}
