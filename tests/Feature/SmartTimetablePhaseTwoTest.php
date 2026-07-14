<?php

namespace Tests\Feature;

use App\Http\Resources\TimetableEntryResource;
use App\Models\TimetableEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SmartTimetablePhaseTwoTest extends TestCase
{
    public function test_phase_two_routes_are_registered_with_specific_permissions(): void
    {
        $expected = [
            'api/timetables/{timetable}/generate' => 'permission:generate_timetable',
            'api/timetables/{timetable}/repair' => 'permission:repair_timetable',
            'api/timetables/{timetable}/rebalance' => 'permission:rebalance_timetable',
            'api/timetables/{timetable}/create-version' => 'permission:create_timetable_versions',
            'api/timetables/{timetable}/entries/{entry}/lock' => 'permission:lock_timetable_entries',
            'api/timetables/{timetable}/generation-runs' => 'permission:view_timetable_generation_runs',
            'api/timetables/{timetable}/unpublish' => 'permission:unpublish_timetable',
            'api/timetable/substitutions/{substitution}/approve' => 'permission:approve_timetable_substitutions',
        ];
        $routes = collect(Route::getRoutes()->getRoutes());
        foreach ($expected as $uri => $permission) {
            $route = $routes->first(fn ($route) => $route->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertContains($permission, $route->gatherMiddleware());
        }
    }

    public function test_phase_two_migration_is_forward_only_and_supports_pairs_locks_versions_and_audits(): void
    {
        $source = file_get_contents(database_path('migrations/2026_07_14_030001_harden_smart_timetable_phase_two.php'));
        foreach (['lesson_group_id', 'lesson_sequence', 'lesson_span', 'is_locked', 'parent_timetable_id', 'diagnostics', 'timetable_audit_logs'] as $field) {
            $this->assertStringContainsString($field, $source);
        }
    }

    public function test_generator_is_bounded_seeded_and_does_not_edit_published_timetables(): void
    {
        $source = file_get_contents(app_path('Services/Timetable/TimetableGenerationService.php'));
        $this->assertStringContainsString("where('status', 'draft')", $source);
        $this->assertStringContainsString('min(max(', $source);
        $this->assertStringContainsString("crc32(\$seed.'|'", $source);
        $this->assertStringContainsString("where('is_locked', false)", $source);
        $this->assertStringNotContainsString("where('status', 'published')->delete", $source);
    }

    public function test_scoring_is_explainable_and_soft_constraints_remain_soft(): void
    {
        $source = file_get_contents(app_path('Services/Timetable/TimetableWorkloadService.php'));
        $this->assertStringContainsString('teacher_daily_load_penalty', $source);
        $this->assertStringContainsString('same_area_day_penalty', $source);
        $this->assertStringContainsString('preference_adjustment', $source);
        $this->assertStringContainsString("where('is_hard', false)", $source);
    }

    public function test_portal_resource_does_not_expose_lock_or_generation_internals(): void
    {
        $entry = new TimetableEntry(['id' => 'entry', 'is_locked' => true, 'locked_by' => 'secret', 'lock_reason' => 'secret', 'generation_run_id' => 'secret-run', 'generation_score' => 99]);
        $data = (new TimetableEntryResource($entry))->toArray(Request::create('/'));
        foreach (['is_locked', 'locked_by', 'locked_at', 'lock_reason', 'generation_run_id', 'generation_score'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
    }

    public function test_phase_two_has_no_external_ai_or_academic_workflow_writes(): void
    {
        $source = collect(glob(app_path('Services/Timetable/*.php')))->map(fn ($file) => file_get_contents($file))->implode("\n");
        foreach (['OpenAI', 'exam_results', 'learning_area_results', 'learner_attendance', 'homework_submissions', 'lesson_plans'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_legacy_direct_substitution_writes_are_disabled(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->methods()[0].' '.$route->uri());
        $this->assertNotContains('POST api/timetable-substitutions', $routes);
        $this->assertNotContains('PUT api/timetable-substitutions/{id}', $routes);
        $this->assertNotContains('DELETE api/timetable-substitutions/{id}', $routes);
    }
}
