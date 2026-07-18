<?php

namespace Tests\Feature;

use App\Http\Resources\ContactHealthResource;
use App\Http\Resources\SmsCreditTransactionResource;
use App\Models\User;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Communication\ContactHealthService;
use App\Services\Communication\KenyanPhoneNormalizer;
use App\Services\Communication\RecurringCommunicationService;
use App\Services\Communication\SmsSegmentCalculator;
use App\Services\Communication\SmsWalletService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CommunicationPhaseTwoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('school_sms_wallets', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('school_id')->unique();
            $table->bigInteger('balance_credits')->default(0);
            $table->bigInteger('low_balance_threshold')->default(0);
            $table->string('status')->default('active');
            $table->bigInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::create('sms_rate_cards', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('provider');
            $table->string('country_code');
            $table->string('route');
            $table->integer('cost_minor_per_segment');
            $table->integer('credits_per_segment');
            $table->integer('version');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('active');
            $table->timestamps();
        });
        Schema::create('sms_credit_transactions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('school_id');
            $table->string('wallet_id');
            $table->string('transaction_type');
            $table->bigInteger('credits_delta');
            $table->bigInteger('balance_after');
            $table->string('reference')->unique();
            $table->string('communication_id')->nullable();
            $table->string('delivery_id')->nullable();
            $table->string('actor_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('sms_usage_records', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('school_id');
            $table->string('communication_id');
            $table->string('delivery_id')->unique();
            $table->string('rate_card_id');
            $table->integer('rate_card_version');
            $table->integer('segment_count');
            $table->integer('credits_reserved');
            $table->integer('credits_consumed')->default(0);
            $table->integer('cost_minor');
            $table->string('status');
            $table->timestamp('reserved_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
        Schema::create('communication_contact_health', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('school_id');
            $table->string('user_id');
            $table->string('channel');
            $table->string('destination_hash');
            $table->string('status');
            $table->string('reason')->nullable();
            $table->integer('hard_bounce_count')->default(0);
            $table->integer('soft_bounce_count')->default(0);
            $table->integer('complaint_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->string('restored_by')->nullable();
            $table->text('restoration_reason')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'channel', 'destination_hash']);
        });
        Schema::create('communication_preferences', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('school_id');
            $table->string('user_id');
            $table->boolean('email_enabled');
            $table->boolean('sms_enabled');
            $table->boolean('in_app_enabled');
            $table->string('digest_frequency');
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->string('timezone');
            $table->string('language');
            $table->boolean('emergency_override');
            $table->boolean('marketing_opt_out');
            $table->timestamps();
            $table->unique(['school_id', 'user_id']);
        });
        Schema::create('communication_policies', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('school_id');
            $table->boolean('sms_enabled');
        });
        Schema::create('communication_audit_logs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('school_id');
            $table->string('communication_id')->nullable();
            $table->string('actor_user_id')->nullable();
            $table->string('action');
            $table->string('entity_type');
            $table->string('entity_id')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function test_kenyan_phone_normalization_and_rejection(): void
    {
        $normalizer = app(KenyanPhoneNormalizer::class);
        $this->assertSame('+254712345678', $normalizer->normalize('0712 345 678'));
        $this->assertSame('+254112345678', $normalizer->normalize('254112345678'));
        $this->expectException(ValidationException::class);
        $normalizer->normalize('020-1234567');
    }

    public function test_gsm_and_unicode_segments_are_deterministic(): void
    {
        $calculator = app(SmsSegmentCalculator::class);
        $this->assertSame(['encoding' => 'gsm', 'characters' => 160, 'segments' => 1], $calculator->calculate(str_repeat('A', 160)));
        $this->assertSame(2, $calculator->calculate(str_repeat('A', 161))['segments']);
        $this->assertSame(['encoding' => 'unicode', 'characters' => 70, 'segments' => 1], $calculator->calculate(str_repeat('✓', 70)));
        $this->assertSame(2, $calculator->calculate(str_repeat('✓', 71))['segments']);
    }

    public function test_wallet_reservation_is_atomic_idempotent_and_preserves_rate(): void
    {
        config(['communication.sms.provider' => 'fake']);
        DB::table('school_sms_wallets')->insert(['id' => 'wallet', 'school_id' => 'school', 'balance_credits' => 10, 'status' => 'active', 'version' => 0]);
        DB::table('sms_rate_cards')->insert(['id' => 'rate', 'provider' => 'fake', 'country_code' => 'KE', 'route' => 'transactional', 'cost_minor_per_segment' => 80, 'credits_per_segment' => 2, 'version' => 3, 'effective_from' => now()->subDay(), 'active' => true]);
        $wallet = app(SmsWalletService::class);
        $first = $wallet->reserve('school', 'communication', 'delivery', 2);
        $second = $wallet->reserve('school', 'communication', 'delivery', 2);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(6, DB::table('school_sms_wallets')->value('balance_credits'));
        $this->assertSame(1, DB::table('sms_credit_transactions')->count());
        $this->assertSame(3, DB::table('sms_usage_records')->value('rate_card_version'));
    }

    public function test_insufficient_wallet_credits_block_before_usage(): void
    {
        config(['communication.sms.provider' => 'fake']);
        DB::table('school_sms_wallets')->insert(['id' => 'wallet', 'school_id' => 'school', 'balance_credits' => 1, 'status' => 'active', 'version' => 0]);
        DB::table('sms_rate_cards')->insert(['id' => 'rate', 'provider' => 'fake', 'country_code' => 'KE', 'route' => 'transactional', 'cost_minor_per_segment' => 80, 'credits_per_segment' => 2, 'version' => 1, 'effective_from' => now()->subDay(), 'active' => true]);
        try {
            app(SmsWalletService::class)->reserve('school', 'communication', 'delivery', 1);
            $this->fail('Expected insufficient-credit rejection.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('sms_credit_transactions', 0);
        $this->assertDatabaseCount('sms_usage_records', 0);
    }

    public function test_hard_bounce_suppresses_and_authorized_restore_requires_reason(): void
    {
        $contacts = app(ContactHealthService::class);
        $contacts->record('school', 'user', 'email', 'user@example.test', 'hard_bounce');
        $this->assertTrue($contacts->suppressed('school', 'user', 'email', 'user@example.test'));
        $row = DB::table('communication_contact_health')->first();
        $actor = new User;
        $actor->forceFill(['id' => 'actor', 'school_id' => 'school']);
        $restored = $contacts->restore($actor, $row->id, 'Address verified by school administrator.');
        $this->assertSame('healthy', $restored->status);
        $this->assertFalse($contacts->suppressed('school', 'user', 'email', 'user@example.test'));
    }

    public function test_school_disabled_sms_cannot_be_enabled_in_user_preferences(): void
    {
        config(['communication.sms.enabled' => true]);
        $user = new User;
        $user->forceFill(['id' => 'user', 'school_id' => 'school']);
        $this->expectException(ValidationException::class);
        app(CommunicationPreferenceService::class)->update($user, ['sms_enabled' => true]);
    }

    public function test_next_run_calculation_is_deterministic(): void
    {
        $service = app(RecurringCommunicationService::class);
        $monday = CarbonImmutable::parse('2026-07-20 08:00:00');
        $this->assertSame('2026-07-21', $service->next($monday, 'daily')->toDateString());
        $this->assertSame('2026-07-27', $service->next($monday, 'weekly')->toDateString());
        $this->assertSame('2026-07-22', $service->next($monday, 'selected_weekdays', [3])->toDateString());
    }

    public function test_phase_two_routes_are_permissioned_and_webhooks_are_not_jwt_routes(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $wallet = $routes->first(fn ($route) => $route->uri() === 'api/communication/sms-wallet/adjustments');
        $emergency = $routes->first(fn ($route) => $route->uri() === 'api/communications/emergency/send');
        $webhook = $routes->first(fn ($route) => $route->uri() === 'api/webhooks/communication/email/resend');
        $this->assertContains('permission:adjust_sms_credits', $wallet->gatherMiddleware());
        $this->assertContains('permission:send_emergency_broadcasts', $emergency->gatherMiddleware());
        $this->assertContains('throttle:30,1', $webhook->gatherMiddleware());
        $this->assertNotContains('jwt', $webhook->gatherMiddleware());
    }

    public function test_safe_resources_hide_hashes_references_and_actor_ids(): void
    {
        $request = Request::create('/');
        $contact = (new ContactHealthResource((object) ['id' => '1', 'user_id' => '2', 'channel' => 'email', 'status' => 'suppressed', 'reason' => 'hard_bounce', 'hard_bounce_count' => 1, 'soft_bounce_count' => 0, 'complaint_count' => 0, 'last_success_at' => null, 'last_failure_at' => null, 'suppressed_at' => null, 'restored_at' => null, 'destination_hash' => 'secret']))->toArray($request);
        $transaction = (new SmsCreditTransactionResource((object) ['id' => '1', 'transaction_type' => 'adjustment', 'credits_delta' => 1, 'balance_after' => 1, 'reason' => 'Top up', 'created_at' => null, 'reference' => 'secret', 'actor_user_id' => 'secret']))->toArray($request);
        $this->assertArrayNotHasKey('destination_hash', $contact);
        $this->assertArrayNotHasKey('reference', $transaction);
        $this->assertArrayNotHasKey('actor_user_id', $transaction);
    }

    public function test_provider_calls_are_job_only_and_no_forbidden_integrations_or_result_writes_exist(): void
    {
        $controller = collect(glob(app_path('Http/Controllers/Api/Communication*.php')))->map(fn ($file) => file_get_contents($file))->implode("\n");
        $services = collect(glob(app_path('Services/Communication/**/*.php')))->merge(glob(app_path('Services/Communication/*.php')))->map(fn ($file) => file_get_contents($file))->implode("\n");
        $this->assertStringNotContainsString('Http::', $controller);
        $this->assertStringContainsString('afterCommit()', file_get_contents(app_path('Services/Communication/CommunicationEmailChannel.php')));
        foreach (['M-Pesa', 'Mpesa', 'WhatsApp', 'exam_results', 'learning_area_results'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $services.$controller);
        }
    }
}
