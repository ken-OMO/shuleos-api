<?php

namespace Tests\Feature;

use App\Http\Resources\LearnerDeviceResource;
use App\Http\Resources\LearnerPushDeliveryResource;
use App\Jobs\DeliverLearnerPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Tests\TestCase;

class LearnerPushTest extends TestCase
{
    public function test_push_is_disabled_by_default_and_delivery_uses_only_the_queued_job(): void
    {
        $this->assertFalse(config('learner_portal_phase_two.push_enabled'));
        $this->assertContains(ShouldQueue::class, class_implements(DeliverLearnerPush::class));
        $source = file_get_contents(app_path('Services/LearnerPortal/LearnerPushService.php'));
        $this->assertStringContainsString('DeliverLearnerPush::dispatch', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringNotContainsString('firebase', strtolower($source));
    }

    public function test_device_and_delivery_resources_hide_tokens_provider_ids_and_ownership(): void
    {
        $request = Request::create('/');
        $payload = ['id' => 'safe', 'school_id' => 'hidden', 'user_id' => 'hidden', 'learner_id' => 'hidden', 'device_identifier_hash' => 'hidden', 'push_token_encrypted' => 'hidden', 'provider_message_id' => 'hidden', 'failure_code' => 'hidden'];
        $device = (new LearnerDeviceResource($payload))->toArray($request);
        $delivery = (new LearnerPushDeliveryResource($payload))->toArray($request);
        foreach (['school_id', 'user_id', 'learner_id', 'device_identifier_hash', 'push_token_encrypted'] as $field) {
            $this->assertArrayNotHasKey($field, $device);
        }
        foreach (['provider_message_id', 'failure_code'] as $field) {
            $this->assertArrayNotHasKey($field, $delivery);
        }
    }

    public function test_invalid_provider_token_is_revoked_without_exposing_it(): void
    {
        $source = file_get_contents(app_path('Jobs/DeliverLearnerPush.php'));
        $this->assertStringContainsString('invalidToken', $source);
        $this->assertStringContainsString("'push_token_encrypted' => null", $source);
        $this->assertStringNotContainsString('logger(', strtolower($source));
    }
}
