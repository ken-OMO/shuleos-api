<?php

namespace Tests\Feature;

use App\Http\Resources\LearnerAnalyticsResource;
use App\Http\Resources\LearnerPortalSafeResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LearnerPortalPhaseTwoTest extends TestCase
{
    public function test_phase_two_routes_are_registered_and_explicitly_permissioned(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        foreach (['api/learner/dashboard', 'api/learner/tasks', 'api/learner/homework/{homework}/submission', 'api/learner/uploads', 'api/learner/learning-resources', 'api/learner/sync/push', 'api/learner/devices', 'api/learner/results', 'api/learner/progress', 'api/learner/help-requests', 'api/learner/analytics'] as $uri) {
            $route = $routes->first(fn ($item) => $item->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertNotEmpty(collect($route->gatherMiddleware())->first(fn ($item) => str_starts_with($item, 'permission:')), $uri);
        }
    }

    public function test_safe_resources_remove_ownership_secrets_and_ranking_data(): void
    {
        $request = Request::create('/');
        $payload = ['id' => 'visible', 'school_id' => 'hidden', 'learner_id' => 'hidden', 'user_id' => 'hidden', 'storage_id' => 'hidden', 'push_token_encrypted' => 'hidden', 'private_teacher_notes' => 'hidden'];
        $safe = (new LearnerPortalSafeResource($payload))->toArray($request);
        foreach (['school_id', 'learner_id', 'user_id', 'storage_id', 'push_token_encrypted', 'private_teacher_notes'] as $field) {
            $this->assertArrayNotHasKey($field, $safe);
        }
        $analytics = (new LearnerAnalyticsResource(['ranking_included' => false, 'ai_score' => null]))->toArray($request);
        $this->assertFalse($analytics['ranking_included']);
        $this->assertNull($analytics['ai_score']);
    }

    public function test_commands_are_bounded_local_application_commands(): void
    {
        $commands = Artisan::all();
        foreach (['learner-tasks:generate', 'learner-sync:cleanup', 'learner-uploads:cleanup-quarantine', 'learner-push:retry-failed', 'learner-offline:expire-resources'] as $name) {
            $this->assertArrayHasKey($name, $commands);
        }
    }

    public function test_task_inbox_covers_every_supported_safe_task_type(): void
    {
        $source = file_get_contents(app_path('Services/LearnerPortal/LearnerTaskService.php'));
        foreach (['homework_due', 'homework_overdue', 'submission_draft', 'submission_returned', 'feedback_available', 'resource_available', 'report_card_available', 'result_published', 'attendance_alert', 'announcement', 'communication', 'sync_conflict', 'profile_action_required'] as $type) {
            $this->assertStringContainsString("'{$type}'", $source, $type);
        }
        $this->assertStringContainsString('->unique(', $source);
        $this->assertStringContainsString('self::LIMIT', $source);
    }

    public function test_mutation_validation_never_accepts_client_ownership_fields(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/LearnerPortalPhaseTwoController.php'));
        $this->assertStringNotContainsString("'school_id' =>", $source);
        $this->assertStringNotContainsString("'learner_id' =>", $source);
        $this->assertStringNotContainsString("'user_id' =>", $source);
    }
}
