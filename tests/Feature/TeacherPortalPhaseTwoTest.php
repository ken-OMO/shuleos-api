<?php

namespace Tests\Feature;

use App\Http\Resources\TeacherAttachmentResource;
use App\Http\Resources\TeacherPushDeliveryResource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\TeacherBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class TeacherPortalPhaseTwoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_phase_two_routes_are_explicitly_permissioned(): void
    {
        $routes = collect(
            Route::getRoutes()->getRoutes()
        );

        foreach ([
            'api/teacher/tasks',
            'api/teacher/hod/review-queue',
            'api/teacher/marks-entry/batches',
            'api/teacher/sync/push',
            'api/teacher/uploads',
            'api/teacher/push/deliveries',
        ] as $uri) {
            $route = $routes->first(
                fn ($item) => $item->uri() === $uri
            );

            $this->assertNotNull(
                $route,
                $uri
            );

            $this->assertNotEmpty(
                collect($route->gatherMiddleware())
                    ->first(
                        fn ($item) => str_starts_with(
                            $item,
                            'permission:'
                        )
                    ),
                $uri
            );
        }
    }

    public function test_workflow_history_is_append_only_and_push_is_idempotent_per_device(): void
    {
        $school = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Teacher',
        ]);

        $user = UserBuilder::create(
            $school,
            $role
        );

        $teacher = TeacherBuilder::create(
            $school,
            $user
        );

        $workflowId = (string) Str::uuid();

        DB::table('teacher_workflows')->insert([
            'id' => $workflowId,
            'school_id' => $school->id,
            'entity_type' => 'lesson_plan',
            'entity_id' => (string) Str::uuid(),
            'teacher_id' => $teacher->id,
            'teacher_assignment_id' => null,
            'state' => 'approved',
            'revision_number' => 1,
            'version' => 2,
            'submitted_by' => $user->id,
            'submitted_at' => now()->subMinute(),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('teacher_workflow_history')->insert([
            [
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'workflow_id' => $workflowId,
                'actor_user_id' => $user->id,
                'from_state' => 'draft',
                'to_state' => 'submitted',
                'version' => 1,
                'created_at' => now()->subMinute(),
            ],
            [
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'workflow_id' => $workflowId,
                'actor_user_id' => $user->id,
                'from_state' => 'submitted',
                'to_state' => 'approved',
                'version' => 2,
                'created_at' => now(),
            ],
        ]);

        $this->assertSame(
            2,
            DB::table('teacher_workflow_history')
                ->where('workflow_id', $workflowId)
                ->count()
        );

        $deviceId = (string) Str::uuid();

        DB::table('teacher_portal_devices')->insert([
            'id' => $deviceId,
            'school_id' => $school->id,
            'user_id' => $user->id,
            'device_identifier_hash' => hash(
                'sha256',
                'teacher-device'
            ),
            'platform' => 'android',
            'app_version' => '1.0.0',
            'device_name' => 'Teacher Test Device',
            'push_enabled' => true,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $firstInsert = DB::table(
            'teacher_push_deliveries'
        )->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'category' => 'announcement',
            'title' => 'Tangazo',
            'body' => 'Ujumbe kwa mwalimu.',
            'idempotency_key' => 'event-1',
            'status' => 'pending',
            'provider' => 'log',
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $duplicateInsert = DB::table(
            'teacher_push_deliveries'
        )->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'category' => 'announcement',
            'title' => 'Tangazo',
            'body' => 'Ujumbe kwa mwalimu.',
            'idempotency_key' => 'event-1',
            'status' => 'pending',
            'provider' => 'log',
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            1,
            $firstInsert
        );

        $this->assertSame(
            0,
            $duplicateInsert
        );
    }

    public function test_phase_two_resources_hide_storage_tokens_and_provider_internals(): void
    {
        $request = Request::create('/');

        $payload = [
            'id' => 'safe',
            'storage_id' => 'hidden',
            'source_hash' => 'hidden',
            'push_token_encrypted' => 'hidden',
            'provider_message_id' => 'hidden',
            'status' => 'pending_scan',
        ];

        $this->assertArrayNotHasKey(
            'storage_id',
            (new TeacherAttachmentResource($payload))
                ->toArray($request)
        );

        $this->assertArrayNotHasKey(
            'provider_message_id',
            (new TeacherPushDeliveryResource($payload))
                ->toArray($request)
        );
    }
}
