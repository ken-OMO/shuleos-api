<?php

namespace Tests\Feature;

use App\Mail\SchoolAdminInvitationMail;
use App\Services\Platform\SchoolAdminInvitationDelivery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SchoolAdminInvitationDeliveryTest extends TestCase
{
    public function test_invitation_targets_admin_email_and_preserves_activation_instructions(): void
    {
        Mail::fake();
        config(['communication.frontend_url' => 'http://127.0.0.1:3000']);

        $result = app(SchoolAdminInvitationDelivery::class)->send($this->payload());

        $this->assertSame('accepted', $result);
        Mail::assertSent(SchoolAdminInvitationMail::class, function ($mail) {
            $this->assertTrue($mail->hasTo('admin@example.test'));
            $this->assertSame('http://127.0.0.1:3000/login', $mail->loginUrl);
            $text = $mail->render();
            $this->assertStringContainsString('Demo School', $text);
            $this->assertStringContainsString('demo.admin', $text);
            $this->assertStringContainsString('Synthetic#Password123', $text);
            $this->assertStringContainsString('Account type: School', $text);
            $this->assertStringContainsString('verification', $text);
            $this->assertStringContainsString('permanent password', $text);

            return true;
        });
        Mail::assertSentCount(1);
    }

    public function test_delivery_failure_is_explicit_and_logs_only_record_ids(): void
    {
        Mail::shouldReceive('to')->once()->with('admin@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('secret-provider-detail'));
        Log::shouldReceive('warning')->once()->with(
            'School administrator invitation delivery failed.',
            ['school_id' => 'school-test-id', 'user_id' => 'admin-test-id'],
        );

        $this->assertSame('failed', app(SchoolAdminInvitationDelivery::class)->send($this->payload()));
    }

    private function payload(): array
    {
        return [
            'school' => ['id' => 'school-test-id', 'school_name' => 'Demo School', 'school_code' => 'SCH-DEMO'],
            'initial_admin' => [
                'id' => 'admin-test-id',
                'email' => 'admin@example.test',
                'username' => 'demo.admin',
                'temporary_password' => 'Synthetic#Password123',
                'temporary_password_expires_at' => now()->addDay(),
            ],
        ];
    }
}
