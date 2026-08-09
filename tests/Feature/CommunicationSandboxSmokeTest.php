<?php

namespace Tests\Feature;

use App\Services\Communication\CommunicationSandboxSmokeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommunicationSandboxSmokeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_is_blocked_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['communication.sandbox_smoke_enabled' => true]);

        $this->assertSame(1, Artisan::call('communications:sandbox-smoke', ['--email' => 'smoke@example.test']));
        $this->assertStringContainsString('blocked outside', Artisan::output());
    }

    public function test_feature_flag_is_required(): void
    {
        config(['app.env' => 'testing', 'communication.sandbox_smoke_enabled' => false]);

        $this->assertSame(1, Artisan::call('communications:sandbox-smoke', ['--email' => 'smoke@example.test']));
        $this->assertStringContainsString('COMMUNICATION_SANDBOX_SMOKE_ENABLED=true', Artisan::output());
    }

    public function test_valid_email_is_required(): void
    {
        config(['app.env' => 'testing', 'communication.sandbox_smoke_enabled' => true]);

        $this->assertSame(1, Artisan::call('communications:sandbox-smoke', ['--email' => 'not-an-email']));
        $this->assertStringContainsString('valid --email', Artisan::output());
    }

    public function test_sms_is_never_queued_wallet_is_compared_and_provider_is_job_only(): void
    {
        $smoke = file_get_contents(app_path('Services/Communication/CommunicationSandboxSmokeService.php'));
        $emailChannel = file_get_contents(app_path('Services/Communication/CommunicationEmailChannel.php'));

        $this->assertStringContainsString("'channels' => ['in_app', 'email']", $smoke);
        $this->assertStringContainsString("'attempt_sms' => false", $smoke);
        $this->assertStringContainsString("'sms_delivery_not_created'", $smoke);
        $this->assertStringContainsString("'sms_job_not_created'", $smoke);
        $this->assertStringContainsString("'sms_usage_not_created'", $smoke);
        $this->assertStringContainsString("'sms_wallet_unchanged'", $smoke);
        $this->assertStringContainsString('afterCommit()', $emailChannel);
        $this->assertStringNotContainsString('Http::', $smoke);
        $this->assertStringNotContainsString('EmailProviderInterface', $smoke);
        $this->assertStringNotContainsString('SmsProviderInterface', $smoke);
    }

    public function test_cleanup_touches_only_the_owned_prefixed_school(): void
    {

        $owned = (string) Str::uuid();
        $unrelated = (string) Str::uuid();
        $prefix = 'SANDBOX-COMMUNICATION-SMOKE-20260718120000000-TEST';
        DB::table('schools')->insert([
            [
                'id' => $owned,
                'school_name' => $prefix,
                'school_code' => 'SMOKE-'.strtoupper(Str::random(8)),
                'active' => true,
                'is_deleted' => false,
            ],
            [
                'id' => $unrelated,
                'school_name' => 'Unrelated School',
                'school_code' => 'OTHER-'.strtoupper(Str::random(8)),
                'active' => true,
                'is_deleted' => false,
            ],
        ]);

        $result = app(CommunicationSandboxSmokeService::class)->cleanup(['school_id' => $owned], $prefix);

        $this->assertFalse($result['fixtures_retained']);
        $this->assertDatabaseMissing('schools', ['id' => $owned]);
        $this->assertDatabaseHas('schools', ['id' => $unrelated, 'school_name' => 'Unrelated School']);
    }
}
