<?php

namespace Tests\Feature;

use App\Http\Resources\TeacherAttachmentResource;
use App\Http\Resources\TeacherPushDeliveryResource;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherPortalPhaseTwoTest extends TestCase
{
    public function test_phase_two_routes_are_explicitly_permissioned(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        foreach (['api/teacher/tasks', 'api/teacher/hod/review-queue', 'api/teacher/marks-entry/batches', 'api/teacher/sync/push', 'api/teacher/uploads', 'api/teacher/push/deliveries'] as $uri) {
            $route = $routes->first(fn ($item) => $item->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertNotEmpty(collect($route->gatherMiddleware())->first(fn ($item) => str_starts_with($item, 'permission:')), $uri);
        }
    }

    public function test_workflow_history_is_append_only_and_push_is_idempotent_per_device(): void
    {
        Schema::dropIfExists('test_push');
        Schema::dropIfExists('test_history');
        Schema::create('test_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->string('from_state');
            $table->string('to_state');
        });
        Schema::create('test_push', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id');
            $table->string('idempotency_key');
            $table->unique(['device_id', 'idempotency_key']);
        });
        $workflow = (string) Str::uuid();
        DB::table('test_history')->insert([['id' => (string) Str::uuid(), 'workflow_id' => $workflow, 'from_state' => 'draft', 'to_state' => 'submitted'], ['id' => (string) Str::uuid(), 'workflow_id' => $workflow, 'from_state' => 'submitted', 'to_state' => 'approved']]);
        $this->assertSame(2, DB::table('test_history')->where('workflow_id', $workflow)->count());
        $device = (string) Str::uuid();
        $this->assertTrue(DB::table('test_push')->insertOrIgnore(['id' => (string) Str::uuid(), 'device_id' => $device, 'idempotency_key' => 'event-1']) === 1);
        $this->assertSame(0, DB::table('test_push')->insertOrIgnore(['id' => (string) Str::uuid(), 'device_id' => $device, 'idempotency_key' => 'event-1']));
    }

    public function test_phase_two_resources_hide_storage_tokens_and_provider_internals(): void
    {
        $request = Request::create('/');
        $payload = ['id' => 'safe', 'storage_id' => 'hidden', 'source_hash' => 'hidden', 'push_token_encrypted' => 'hidden', 'provider_message_id' => 'hidden', 'status' => 'pending_scan'];
        $this->assertArrayNotHasKey('storage_id', (new TeacherAttachmentResource($payload))->toArray($request));
        $this->assertArrayNotHasKey('provider_message_id', (new TeacherPushDeliveryResource($payload))->toArray($request));
    }
}
