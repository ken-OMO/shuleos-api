<?php

namespace Tests\Feature;

use App\Contracts\ParentPortal\PaymentProviderInterface;
use App\Http\Resources\ParentPaymentAttemptResource;
use App\Jobs\ProcessParentPaymentCallback;
use App\Models\ParentPaymentAttempt;
use App\Services\ParentPortal\ParentPaymentCallbackService;
use App\Services\ParentPortal\ParentPaymentProcessingService;
use App\Services\ParentPortal\Providers\FakePaymentProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class ParentPortalPhaseTwoTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $user;

    private object $learner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Parent',
        ]);

        $this->user = UserBuilder::create(
            $this->school,
            $role
        );

        $grade = GradeBuilder::create(
            $this->school
        );

        $stream = StreamBuilder::create(
            $this->school,
            $grade
        );

        $this->learner = LearnerBuilder::create(
            $this->school,
            $grade,
            $stream
        );

        config([
            'parent_portal_phase_two.payment_provider' => 'fake',
            'parent_portal_phase_two.callback_secret' => 'test-callback-secret',
        ]);

        app()->instance(
            PaymentProviderInterface::class,
            new FakePaymentProvider
        );
    }

    public function test_verified_callback_is_jwt_free_queued_redacted_and_replay_safe(): void
    {
        Queue::fake();

        $attempt = $this->attempt();

        $payload = [
            'event_key' => 'provider-event-1',
            'checkout_request_id' => $attempt->checkout_request_id,
            'successful' => true,
            'amount_minor' => 25000,
            'currency' => 'KES',
            'receipt' => 'SECRET-RECEIPT',
        ];

        $response = $this
            ->withHeader(
                'X-Callback-Secret',
                'test-callback-secret'
            )
            ->postJson(
                '/api/webhooks/payments/mpesa',
                $payload
            );

        $response
            ->assertAccepted()
            ->assertJson([
                'accepted' => true,
            ]);

        Queue::assertPushed(
            ProcessParentPaymentCallback::class,
            1
        );

        $event = DB::table(
            'parent_payment_callback_events'
        )->first();

        $this->assertNotNull($event);

        $this->assertStringNotContainsString(
            'SECRET-RECEIPT',
            (string) $event->redacted_payload
        );

        app(
            ParentPaymentCallbackService::class
        )->accept($payload);

        $this->assertSame(
            1,
            DB::table(
                'parent_payment_callback_events'
            )->count()
        );

        Queue::assertPushed(
            ProcessParentPaymentCallback::class,
            1
        );
    }

    public function test_callback_authentication_is_required_and_payload_route_has_no_jwt(): void
    {
        $this
            ->postJson(
                '/api/webhooks/payments/mpesa',
                []
            )
            ->assertUnauthorized();

        $route = collect(
            Route::getRoutes()
        )->first(
            fn ($route) => $route->uri()
                === 'api/webhooks/payments/mpesa'
        );

        $this->assertNotNull($route);

        $this->assertNotContains(
            'jwt',
            $route->gatherMiddleware()
        );

        $this->assertContains(
            'throttle:30,1',
            $route->gatherMiddleware()
        );
    }

    public function test_amount_mismatch_requires_reconciliation_and_never_posts_finance(): void
    {
        $attempt = $this->attempt();

        $paymentsBefore = DB::table(
            'payments'
        )->count();

        app(
            ParentPaymentProcessingService::class
        )->process(
            $attempt->id,
            [
                'successful' => true,
                'amount_minor' => 24999,
                'currency' => 'KES',
                'receipt' => 'RCP-1',
            ]
        );

        $stored = DB::table(
            'parent_payment_attempts'
        )
            ->where('id', $attempt->id)
            ->first();

        $this->assertSame(
            'reconciliation_required',
            $stored->status
        );

        $this->assertSame(
            'reconciliation_required',
            $stored->failure_code
        );

        $this->assertNull(
            $stored->payment_id
        );

        $this->assertSame(
            $paymentsBefore,
            DB::table('payments')->count()
        );

        $this->assertDatabaseHas(
            'parent_payment_attempt_history',
            [
                'payment_attempt_id' => $attempt->id,
                'from_status' => 'awaiting_customer',
                'to_status' => 'reconciliation_required',
            ]
        );
    }

    public function test_payment_resource_never_exposes_provider_or_identity_secrets(): void
    {
        $attempt = $this->attempt();

        $data = (
            new ParentPaymentAttemptResource(
                $attempt
            )
        )->toArray(
            Request::create('/')
        );

        $this->assertSame(
            '250.00',
            $data['amount']
        );

        $this->assertArrayNotHasKey(
            'checkout_request_id',
            $data
        );

        $this->assertArrayNotHasKey(
            'idempotency_key_hash',
            $data
        );

        $this->assertArrayNotHasKey(
            'phone_hash',
            $data
        );

        $this->assertSame(
            '254*****1234',
            $data['phone_masked']
        );
    }

    public function test_parent_phase_two_routes_have_explicit_permissions_and_controllers_do_not_write_ledgers(): void
    {
        foreach ([
            'api/parent/payments',
            'api/parent/conversations',
            'api/parent/consents',
            'api/parent/appointments',
            'api/parent/sync/push',
            'api/parent/uploads',
        ] as $uri) {
            $route = collect(
                Route::getRoutes()
            )->first(
                fn ($route) => $route->uri() === $uri
            );

            $this->assertNotNull(
                $route,
                $uri
            );

            $this->assertNotNull(
                collect(
                    $route->gatherMiddleware()
                )->first(
                    fn ($middleware) => str_starts_with(
                        $middleware,
                        'permission:'
                    )
                ),
                $uri
            );
        }

        $controller = file_get_contents(
            app_path(
                'Http/Controllers/Api/ParentPortalPhaseTwoController.php'
            )
        );

        $this->assertStringNotContainsString(
            'FinanceLedgerService',
            $controller
        );

        $this->assertStringNotContainsString(
            "DB::table('finance_ledger",
            $controller
        );
    }

    private function attempt(): ParentPaymentAttempt
    {
        return ParentPaymentAttempt::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $this->school->id,
            'parent_user_id' => $this->user->id,
            'learner_id' => $this->learner->id,
            'payment_reference' => 'PPS-'.strtoupper(
                Str::random(12)
            ),
            'idempotency_key_hash' => hash(
                'sha256',
                (string) Str::uuid()
            ),
            'provider' => 'mpesa',
            'checkout_request_id' => 'checkout-'.Str::random(12),
            'phone_hash' => hash(
                'sha256',
                '254700001234'
            ),
            'phone_masked' => '254*****1234',
            'amount_minor' => 25000,
            'currency' => 'KES',
            'status' => 'awaiting_customer',
            'initiated_at' => now(),
        ]);
    }
}
