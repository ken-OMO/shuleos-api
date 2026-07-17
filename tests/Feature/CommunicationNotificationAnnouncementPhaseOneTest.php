<?php

namespace Tests\Feature;

use App\Http\Resources\CommunicationDeliveryResource;
use App\Http\Resources\CommunicationPreviewResource;
use App\Http\Resources\NotificationResource;
use App\Services\Communication\CommunicationImpactService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CommunicationNotificationAnnouncementPhaseOneTest extends TestCase
{
    public function test_phase_one_schema_preserves_tenant_delivery_and_audit_invariants(): void
    {
        $source = file_get_contents(database_path('migrations/2026_07_17_060001_create_communication_notification_phase_one_tables.php'));
        foreach (['communications', 'communication_targets', 'communication_recipient_snapshots', 'communication_deliveries', 'communication_templates', 'communication_policies', 'communication_audit_logs', 'announcement_reads', 'school_id', 'sender_user_id', 'requires_approval', 'risk_level', 'delivery_key', 'state'] as $invariant) {
            $this->assertStringContainsString($invariant, $source);
        }
    }

    public function test_workflow_routes_have_specific_permissions(): void
    {
        $expected = [
            'api/communications/preview' => 'permission:preview_communication_recipients',
            'api/communications/{communication}/approve' => 'permission:approve_communications',
            'api/communications/{communication}/schedule' => 'permission:schedule_communications',
            'api/communications/{communication}/audit' => 'permission:view_communication_history',
            'api/notifications/{notification}/read' => 'permission:view_own_notifications',
            'api/announcements/{announcement}/publish' => 'permission:create_communications',
            'api/communication-templates/{template}/archive' => 'permission:manage_communication_templates',
        ];

        $routes = collect(Route::getRoutes()->getRoutes());
        foreach ($expected as $uri => $permission) {
            $route = $routes->first(fn ($candidate) => $candidate->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertContains('jwt', $route->gatherMiddleware());
            $this->assertContains('tenant', $route->gatherMiddleware());
            $this->assertContains('module.permission', $route->gatherMiddleware());
            $this->assertContains($permission, $route->gatherMiddleware());
        }
    }

    public function test_impact_analysis_is_deterministic_and_requires_mass_audience_approval(): void
    {
        $resolution = ['unique_users' => 150, 'excluded' => ['missing_email' => 0, 'invalid_email' => 0]];
        $policy = ['approval_recipient_threshold' => 100, 'critical_recipient_threshold' => 1000, 'requires_approval' => false];
        $result = app(CommunicationImpactService::class)->analyze($resolution, [['target_type' => 'entire_school']], 'normal', 'announcement', $policy);

        $this->assertSame('high', $result['risk_level']);
        $this->assertTrue($result['approval_required']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function test_safe_resources_hide_recipient_contacts_delivery_keys_and_internal_counts(): void
    {
        $request = Request::create('/');
        $delivery = (new CommunicationDeliveryResource((object) ['id' => '1', 'channel' => 'email', 'status' => 'queued', 'attempt_count' => 0, 'queued_at' => null, 'sent_at' => null, 'delivered_at' => null, 'delivery_key' => 'secret', 'recipient_user_id' => 'secret', 'failure_reason' => 'private']))->toArray($request);
        $notification = (new NotificationResource((object) ['id' => '1', 'title' => 'Title', 'message' => 'Message', 'state' => 'unread', 'is_read' => false, 'action_url' => null, 'read_at' => null, 'created_at' => null, 'communication_id' => 'internal', 'delivery_id' => 'internal']))->toArray($request);
        $preview = (new CommunicationPreviewResource(['unique_users_count' => 2, 'email_eligible_count' => 1, 'risk_level' => 'low', 'risk_reasons' => [], 'warnings' => [], 'approval_required' => false]))->toArray($request);

        $this->assertArrayNotHasKey('delivery_key', $delivery);
        $this->assertArrayNotHasKey('recipient_user_id', $delivery);
        $this->assertArrayNotHasKey('communication_id', $notification);
        $this->assertArrayNotHasKey('recipients', $preview);
        $this->assertArrayNotHasKey('emails', $preview);
    }

    public function test_delivery_and_scope_controls_are_present_in_central_services(): void
    {
        $service = file_get_contents(app_path('Services/Communication/CommunicationService.php'));
        $resolver = file_get_contents(app_path('Services/Communication/CommunicationRecipientResolverService.php'));
        $inApp = file_get_contents(app_path('Services/Communication/CommunicationInAppChannel.php'));
        $email = file_get_contents(app_path('Services/Communication/CommunicationEmailChannel.php'));

        $this->assertStringContainsString('lockForUpdate()', $service);
        $this->assertStringContainsString('resolve($sender, $targets)', $service);
        $this->assertStringContainsString('sender_user_id === $user->id', $service);
        $this->assertStringContainsString("hasPermission(\$user, 'approve_communications')", $service);
        $this->assertStringContainsString('insertOrIgnore', $inApp);
        $this->assertStringContainsString('afterCommit()', $email);
        $this->assertStringContainsString("where('assignment.school_id', \$sender->school_id)", $resolver);
        $this->assertStringContainsString("where('user.school_id', \$sender->school_id)", $resolver);
    }

    public function test_email_is_not_sent_by_controller_or_inside_communication_transaction(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Api/CommunicationController.php'));
        $service = file_get_contents(app_path('Services/Communication/CommunicationService.php'));
        $job = file_get_contents(app_path('Jobs/DeliverCommunicationEmail.php'));

        $this->assertStringNotContainsString('Mail::', $controller.$service);
        $this->assertStringContainsString('implements ShouldQueue', $job);
        $this->assertStringContainsString('public int $tries = 3', $job);
    }

    public function test_phase_one_contains_no_external_channel_integrations(): void
    {
        $source = collect(glob(app_path('Services/Communication/*.php')))
            ->merge(glob(app_path('Jobs/*Communication*.php')))
            ->map(fn ($file) => file_get_contents($file))
            ->implode("\n");

        foreach (['Twilio', 'WhatsApp', 'Firebase', 'M-Pesa', 'Mpesa', 'AmazonSes', 'Vonage'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_scheduling_and_cleanup_commands_are_registered(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('communications:dispatch-scheduled', $commands);
        $this->assertArrayHasKey('communications:cleanup', $commands);
    }
}
