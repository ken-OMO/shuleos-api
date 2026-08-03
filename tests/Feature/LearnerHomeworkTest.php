<?php

namespace Tests\Feature;

use App\Http\Resources\LearnerHomeworkSubmissionResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LearnerHomeworkTest extends TestCase
{
    public function test_draft_lifecycle_is_versioned_and_submitted_work_is_immutable(): void
    {
        $source = file_get_contents(app_path('Services/Homework/HomeworkLearnerService.php'));
        $this->assertStringContainsString("where('version', \$baseVersion)", $source);
        $this->assertStringContainsString("where('submission_status', 'draft')", $source);
        $this->assertStringContainsString("['returned', 'resubmission_required']", $source);
        $this->assertStringContainsString('now()->gt($homework->due_at)', $source);
    }

    public function test_teacher_feedback_is_hidden_until_released(): void
    {
        $request = Request::create('/');
        $hidden = (new LearnerHomeworkSubmissionResource(['id' => 'one', 'mark' => ['status' => 'marked', 'teacher_feedback' => 'private']]))->toArray($request);
        $released = (new LearnerHomeworkSubmissionResource(['id' => 'one', 'mark' => ['status' => 'released', 'teacher_feedback' => 'visible']]))->toArray($request);
        $this->assertArrayNotHasKey('mark', $hidden);
        $this->assertSame('visible', $released['mark']['teacher_feedback']);
    }

    public function test_homework_routes_have_no_official_result_mutation(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/learner/homework'));
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $action = $route->getActionName();
            $this->assertStringNotContainsString('ExamResult', $action);
            $this->assertStringNotContainsString('LearningAreaResult', $action);
        }
    }
}
