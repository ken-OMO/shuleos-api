<?php

namespace Tests\Feature;

use Tests\TestCase;

class TeacherPushTest extends TestCase
{
    public function test_push_is_disabled_by_default_and_provider_calls_are_job_only(): void
    {
        $service = file_get_contents(app_path('Services/TeacherPortal/TeacherPushService.php'));
        $job = file_get_contents(app_path('Jobs/DeliverTeacherPush.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/TeacherPortalPhaseTwoController.php'));
        $this->assertFalse(config('teacher_portal_phase_two.push_enabled'));
        $this->assertStringContainsString('DeliverTeacherPush::dispatch', $service);
        $this->assertStringContainsString('PushProviderInterface', $job);
        $this->assertStringNotContainsString('PushProviderInterface', $controller);
        $this->assertStringNotContainsString('Http::', $service.$controller);
        $this->assertStringNotContainsString('learner_id', $service.$job);
    }
}
