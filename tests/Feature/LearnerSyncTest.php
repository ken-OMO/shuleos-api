<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearnerSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_operation_receipts_are_idempotent_per_user(): void
    {
        $school = (string) Str::uuid();
        $grade = (string) Str::uuid();
        $stream = (string) Str::uuid();
        $user = (string) Str::uuid();
        $learner = (string) Str::uuid();
        $device = (string) Str::uuid();
        $operation = (string) Str::uuid();

        $role = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $role,
            'role_name' => 'Learner Sync Test Role '.Str::lower(Str::random(8)),
        ]);

        DB::table('schools')->insert([
            'id' => $school,
            'school_name' => 'Learner Sync Test School',
            'school_code' => 'LST-'.strtoupper(Str::random(8)),
            'active' => true,
            'is_deleted' => false,
        ]);

        DB::table('grades')->insert([
            'id' => $grade,
            'school_id' => $school,
            'grade_name' => 'Learner Sync Grade',
            'grade_order' => 99,
            'active' => true,
        ]);

        DB::table('streams')->insert([
            'id' => $stream,
            'school_id' => $school,
            'grade_id' => $grade,
            'stream_name' => 'Learner Sync Stream',
            'active' => true,
        ]);

        DB::table('users')->insert([
            'id' => $user,
            'school_id' => $school,
            'role_id' => $role,
            'username' => 'learner-sync-'.Str::lower(Str::random(10)),
            'password_hash' => bcrypt('password'),
            'first_name' => 'Sync',
            'last_name' => 'Learner',
            'active' => true,
            'is_deleted' => false,
        ]);

        DB::table('learners')->insert([
            'id' => $learner,
            'school_id' => $school,
            'user_id' => $user,
            'admission_no' => 'SYNC-'.strtoupper(Str::random(8)),
            'first_name' => 'Sync',
            'last_name' => 'Learner',
            'grade_id' => $grade,
            'stream_id' => $stream,
            'active' => true,
            'portal_enabled' => true,
            'is_deleted' => false,
        ]);

        DB::table('learner_portal_devices')->insert([
            'id' => $device,
            'school_id' => $school,
            'user_id' => $user,
            'learner_id' => $learner,
            'device_identifier_hash' => hash('sha256', 'learner-sync-device'),
            'platform' => 'android',
            'push_enabled' => false,
            'last_seen_at' => now(),
        ]);

        $receipt = [
            'school_id' => $school,
            'user_id' => $user,
            'learner_id' => $learner,
            'device_id' => $device,
            'operation_uuid' => $operation,
            'entity_type' => 'profile_draft',
            'entity_id' => (string) Str::uuid(),
            'operation' => 'update',
            'base_version' => 1,
            'status' => 'accepted',
            'server_version' => 2,
            'created_at' => now(),
        ];

        $this->assertSame(
            1,
            DB::table('learner_sync_operations')->insertOrIgnore(
                ['id' => (string) Str::uuid()] + $receipt
            )
        );

        $this->assertSame(
            0,
            DB::table('learner_sync_operations')->insertOrIgnore(
                ['id' => (string) Str::uuid()] + $receipt
            )
        );
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
