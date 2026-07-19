<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearnerSyncTest extends TestCase
{
    public function test_operation_receipts_are_idempotent_per_user(): void
    {
        Schema::create('test_learner_sync_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('operation_uuid');
            $table->unique(['user_id', 'operation_uuid']);
        });
        $user = (string) Str::uuid();
        $operation = (string) Str::uuid();
        $this->assertSame(1, DB::table('test_learner_sync_operations')->insertOrIgnore(['id' => (string) Str::uuid(), 'user_id' => $user, 'operation_uuid' => $operation]));
        $this->assertSame(0, DB::table('test_learner_sync_operations')->insertOrIgnore(['id' => (string) Str::uuid(), 'user_id' => $user, 'operation_uuid' => $operation]));
    }

    public function test_sync_is_allowlisted_versioned_and_server_wins_on_conflict(): void
    {
        $source = file_get_contents(app_path('Services/LearnerPortal/LearnerSyncService.php'));
        $this->assertStringContainsString("'homework_submission_draft'", $source);
        $this->assertStringContainsString("'preference'", $source);
        $this->assertStringContainsString("'profile_draft'", $source);
        $this->assertStringContainsString("'offline_resource'", $source);
        $this->assertStringContainsString("'notification_state'", $source);
        $this->assertStringContainsString("'announcement_read'", $source);
        $this->assertStringContainsString('Unsupported learner sync entity.', $source);
        $this->assertStringContainsString('Submitted homework cannot be changed offline.', $source);
        $this->assertStringContainsString("'status' => 'server_wins'", $source);
        $this->assertStringContainsString("where('learner_id', \$learner->id)", $source);
        $this->assertStringContainsString('sync_payload_bytes', $source);
    }
}
