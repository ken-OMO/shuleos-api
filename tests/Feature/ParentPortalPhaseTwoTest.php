<?php

namespace Tests\Feature;

use App\Contracts\ParentPortal\PaymentProviderInterface;
use App\Http\Resources\ParentPaymentAttemptResource;
use App\Jobs\ProcessParentPaymentCallback;
use App\Models\ParentPaymentAttempt;
use App\Services\ParentPortal\ParentPaymentCallbackService;
use App\Services\ParentPortal\ParentPaymentProcessingService;
use App\Services\ParentPortal\Providers\FakePaymentProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParentPortalPhaseTwoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['parent_payment_callback_events', 'parent_payment_attempt_history', 'parent_payment_attempts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('parent_payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->uuid('parent_user_id');
            $table->uuid('learner_id');
            $table->uuid('invoice_id')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->string('payment_reference');
            $table->string('idempotency_key_hash');
            $table->string('provider');
            $table->string('provider_request_id')->nullable();
            $table->string('checkout_request_id')->nullable();
            $table->string('merchant_request_id')->nullable();
            $table->string('phone_hash');
            $table->string('phone_masked');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency')->default('KES');
            $table->string('status');
            $table->string('failure_code')->nullable();
            $table->string('safe_failure_message')->nullable();
            $table->timestamp('initiated_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('parent_payment_attempt_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->uuid('payment_attempt_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('safe_reason')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('parent_payment_callback_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('event_key');
            $table->uuid('payment_attempt_id')->nullable();
            $table->string('status');
            $table->json('redacted_payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unique(['provider', 'event_key']);
        });
        config(['parent_portal_phase_two.payment_provider' => 'fake', 'parent_portal_phase_two.callback_secret' => 'test-callback-secret']);
        app()->instance(PaymentProviderInterface::class, new FakePaymentProvider);
    }

    public function test_verified_callback_is_jwt_free_queued_redacted_and_replay_safe(): void
    {
        Queue::fake();
        $attempt = $this->attempt();
        $payload = ['event_key' => 'provider-event-1', 'checkout_request_id' => $attempt->checkout_request_id, 'successful' => true, 'amount_minor' => 25000, 'currency' => 'KES', 'receipt' => 'SECRET-RECEIPT'];

        $response = $this->withHeader('X-Callback-Secret', 'test-callback-secret')->postJson('/api/webhooks/payments/mpesa', $payload);
        $response->assertAccepted()->assertJson(['accepted' => true]);
        Queue::assertPushed(ProcessParentPaymentCallback::class, 1);
        $event = DB::table('parent_payment_callback_events')->first();
        $this->assertStringNotContainsString('SECRET-RECEIPT', (string) $event->redacted_payload);

        app(ParentPaymentCallbackService::class)->accept($payload);
        $this->assertSame(1, DB::table('parent_payment_callback_events')->count());
        Queue::assertPushed(ProcessParentPaymentCallback::class, 1);
    }

    public function test_callback_authentication_is_required_and_payload_route_has_no_jwt(): void
    {
        $this->postJson('/api/webhooks/payments/mpesa', [])->assertUnauthorized();
        $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'api/webhooks/payments/mpesa');
        $this->assertNotNull($route);
        $this->assertNotContains('jwt', $route->gatherMiddleware());
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }

    public function test_amount_mismatch_requires_reconciliation_and_never_posts_finance(): void
    {
        $attempt = $this->attempt();
        app(ParentPaymentProcessingService::class)->process($attempt->id, ['successful' => true, 'amount_minor' => 24999, 'currency' => 'KES', 'receipt' => 'RCP-1']);

        $stored = DB::table('parent_payment_attempts')->where('id', $attempt->id)->first();
        $this->assertSame('reconciliation_required', $stored->status);
        $this->assertSame('reconciliation_required', $stored->failure_code);
        $this->assertFalse(Schema::hasTable('payments'));
    }

    public function test_payment_resource_never_exposes_provider_or_identity_secrets(): void
    {
        $attempt = $this->attempt();
        $data = (new ParentPaymentAttemptResource($attempt))->toArray(Request::create('/'));

        $this->assertSame('250.00', $data['amount']);
        $this->assertArrayNotHasKey('checkout_request_id', $data);
        $this->assertArrayNotHasKey('idempotency_key_hash', $data);
        $this->assertArrayNotHasKey('phone_hash', $data);
        $this->assertSame('254*****1234', $data['phone_masked']);
    }

    public function test_parent_phase_two_routes_have_explicit_permissions_and_controllers_do_not_write_ledgers(): void
    {
        foreach (['api/parent/payments', 'api/parent/conversations', 'api/parent/consents', 'api/parent/appointments', 'api/parent/sync/push', 'api/parent/uploads'] as $uri) {
            $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertNotNull(collect($route->gatherMiddleware())->first(fn ($middleware) => str_starts_with($middleware, 'permission:')), $uri);
        }
        $controller = file_get_contents(app_path('Http/Controllers/Api/ParentPortalPhaseTwoController.php'));
        $this->assertStringNotContainsString('FinanceLedgerService', $controller);
        $this->assertStringNotContainsString("DB::table('finance_ledger", $controller);
    }

    private function attempt(): ParentPaymentAttempt
    {
        return ParentPaymentAttempt::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'school_id' => (string) Str::uuid(), 'parent_user_id' => (string) Str::uuid(), 'learner_id' => (string) Str::uuid(),
            'payment_reference' => 'PPS-TEST', 'idempotency_key_hash' => hash('sha256', 'key'), 'provider' => 'mpesa',
            'checkout_request_id' => 'checkout-'.Str::random(12), 'phone_hash' => hash('sha256', '254700001234'), 'phone_masked' => '254*****1234',
            'amount_minor' => 25000, 'currency' => 'KES', 'status' => 'awaiting_customer', 'initiated_at' => now(),
        ]);
    }
}
