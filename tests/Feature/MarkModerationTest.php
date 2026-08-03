<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarkModerationTest extends TestCase
{
    public function test_batches_enforce_completeness_locking_scope_and_no_publication(): void
    {
        $batch = file_get_contents(app_path('Services/TeacherPortal/MarkEntryBatchService.php'));
        $moderation = file_get_contents(app_path('Services/TeacherPortal/MarkModerationService.php'));
        $this->assertStringContainsString('Every expected learner must have a mark', $batch);
        $this->assertStringContainsString('Submitted or locked mark batches are immutable', $batch);
        $this->assertStringContainsString('Self-moderation is not allowed', $moderation);
        $this->assertStringContainsString("'approved' => ['locked']", $moderation);
        $this->assertStringNotContainsString('report_cards', $batch.$moderation);
        $this->assertStringNotContainsString('merit_lists', $batch.$moderation);
    }
}
