<?php

namespace App\Services\Communication;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunicationService
{
    private const TYPES = ['announcement', 'circular', 'reminder', 'finance_notice', 'attendance_alert', 'homework_notice', 'timetable_notice', 'result_notice', 'behaviour_notice', 'emergency', 'general'];

    private const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    public function __construct(private CommunicationRecipientResolverService $resolver, private CommunicationImpactService $impact, private CommunicationPolicyService $policies, private CommunicationInAppChannel $inApp, private CommunicationEmailChannel $email, private CommunicationSmsChannel $sms, private SmsSegmentCalculator $segments, private SmsWalletService $wallet, private KenyanPhoneNormalizer $phones, private PhaseTwoCommunicationPreviewService $phaseTwoPreview, private CommunicationAuditService $audit) {}

    public function create(User $user, array $data): object
    {
        return DB::transaction(function () use ($user, $data) {
            $this->validateContent($data);
            $policy = $this->policies->policy($user, $data['category']);
            $this->policies->assertSenderAndPriority($user, $policy, $data['priority'] ?? 'normal');
            $channels = $this->policies->validateChannels($data['channels'], $policy);
            $id = (string) Str::uuid();
            DB::table('communications')->insert(['id' => $id, 'school_id' => $user->school_id, 'sender_user_id' => $user->id, 'communication_type' => $data['communication_type'], 'category' => $data['category'], 'priority' => $data['priority'] ?? 'normal', 'subject' => trim($data['subject']), 'body' => trim($data['body']), 'status' => 'draft', 'channels' => json_encode($channels), 'requires_approval' => false, 'risk_level' => 'low', 'expires_at' => $data['expires_at'] ?? null, 'metadata' => isset($data['metadata']) ? json_encode(collect($data['metadata'])->only(['template_id', 'template_version'])->all()) : null, 'recipient_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $this->replaceTargets($user, $id, $data['targets']);
            $this->audit->record($user, 'draft_created', 'communication', $id, $id);

            return $this->find($user, $id);
        });
    }

    public function update(User $user, string $id, array $data): object
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $row = DB::table('communications')->where('id', $id)->where('school_id', $user->school_id)->whereIn('status', ['draft', 'pending_approval', 'approved'])->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->sender_user_id === $user->id || $this->resolver->hasPermission($user, 'approve_communications'), 403);
            $merged = ['communication_type' => $data['communication_type'] ?? $row->communication_type, 'category' => $data['category'] ?? $row->category, 'priority' => $data['priority'] ?? $row->priority, 'subject' => $data['subject'] ?? $row->subject, 'body' => $data['body'] ?? $row->body, 'channels' => $data['channels'] ?? json_decode($row->channels, true)];
            $this->validateContent($merged);
            $policy = $this->policies->policy($user, $merged['category']);
            $this->policies->assertSenderAndPriority($user, $policy, $merged['priority']);
            $channels = $this->policies->validateChannels($merged['channels'], $policy);
            DB::table('communications')->where('id', $id)->update(['communication_type' => $merged['communication_type'], 'category' => $merged['category'], 'priority' => $merged['priority'], 'subject' => trim($merged['subject']), 'body' => trim($merged['body']), 'channels' => json_encode($channels), 'expires_at' => $data['expires_at'] ?? $row->expires_at, 'status' => 'draft', 'requires_approval' => false, 'approved_by' => null, 'approved_at' => null, 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null, 'updated_at' => now()]);
            if (array_key_exists('targets', $data)) {
                $this->replaceTargets($user, $id, $data['targets']);
            }
            $this->audit->record($user, 'draft_edited', 'communication', $id, $id);

            return $this->find($user, $id);
        });
    }

    public function preview(User $user, string $id): array
    {
        $communication = $this->find($user, $id);
        abort_unless(in_array($communication->status, ['draft', 'pending_approval', 'approved', 'scheduled'], true), 409, 'Communication cannot be previewed in its current state.');
        $resolution = $this->resolver->resolve($user, $communication->targets);
        $policy = $this->policies->policy($user, $communication->category);
        $impact = $this->impact->analyze($resolution, $communication->targets, $communication->priority, $communication->communication_type, $policy);
        $this->audit->record($user, 'preview_generated', 'communication', $id, $id, ['unique_users' => $resolution['unique_users'], 'risk_level' => $impact['risk_level']]);

        $channels = json_decode($communication->channels, true);
        $base = ['target_summary' => $communication->targets->countBy('target_type'), 'parents_count' => $resolution['counts']['parent'] ?? 0, 'learners_count' => $resolution['counts']['learner'] ?? 0, 'teachers_count' => $resolution['counts']['teacher'] ?? 0, 'staff_count' => $resolution['unique_users'] - ($resolution['counts']['parent'] ?? 0) - ($resolution['counts']['learner'] ?? 0) - ($resolution['counts']['teacher'] ?? 0), 'unique_users_count' => $resolution['unique_users'], 'in_app_eligible_count' => $resolution['in_app_eligible'], 'email_eligible_count' => $resolution['email_eligible'], 'duplicate_recipients_removed' => $resolution['duplicates_removed'], 'inactive_recipients_excluded' => $resolution['excluded']['inactive'], 'missing_email_count' => $resolution['excluded']['missing_email'], 'invalid_email_count' => $resolution['excluded']['invalid_email'], 'estimated_deliveries' => ['in_app' => in_array('in_app', $channels) ? $resolution['in_app_eligible'] : 0, 'email' => in_array('email', $channels) ? $resolution['email_eligible'] : 0, 'sms' => in_array('sms', $channels) ? $resolution['sms_eligible'] : 0], 'risk_level' => $impact['risk_level'], 'risk_reasons' => $impact['reasons'], 'warnings' => $impact['recommended_corrections'], 'approval_required' => $impact['approval_required']];

        return array_merge($base, $this->phaseTwoPreview->extend($user->school_id, $resolution, $communication->body, $channels));
    }

    public function previewDefinition(User $user, array $data): array
    {
        $this->validateContent($data);
        $policy = $this->policies->policy($user, $data['category']);
        $this->policies->assertSenderAndPriority($user, $policy, $data['priority'] ?? 'normal');
        $channels = $this->policies->validateChannels($data['channels'], $policy);
        $resolution = $this->resolver->resolve($user, $data['targets']);
        $impact = $this->impact->analyze($resolution, $data['targets'], $data['priority'] ?? 'normal', $data['communication_type'], $policy);
        $this->audit->record($user, 'preview_generated', 'communication_preview', null, null, ['unique_users' => $resolution['unique_users'], 'risk_level' => $impact['risk_level']]);

        $base = ['target_summary' => collect($data['targets'])->countBy('target_type'), 'parents_count' => $resolution['counts']['parent'] ?? 0, 'learners_count' => $resolution['counts']['learner'] ?? 0, 'teachers_count' => $resolution['counts']['teacher'] ?? 0, 'unique_users_count' => $resolution['unique_users'], 'in_app_eligible_count' => $resolution['in_app_eligible'], 'email_eligible_count' => $resolution['email_eligible'], 'duplicate_recipients_removed' => $resolution['duplicates_removed'], 'missing_email_count' => $resolution['excluded']['missing_email'], 'invalid_email_count' => $resolution['excluded']['invalid_email'], 'estimated_deliveries' => ['in_app' => in_array('in_app', $channels) ? $resolution['in_app_eligible'] : 0, 'email' => in_array('email', $channels) ? $resolution['email_eligible'] : 0, 'sms' => in_array('sms', $channels) ? $resolution['sms_eligible'] : 0], 'risk_level' => $impact['risk_level'], 'risk_reasons' => $impact['reasons'], 'warnings' => $impact['recommended_corrections'], 'approval_required' => $impact['approval_required']];

        return array_merge($base, $this->phaseTwoPreview->extend($user->school_id, $resolution, $data['body'], $channels));
    }

    public function submit(User $user, string $id): object
    {
        return DB::transaction(function () use ($user, $id) {
            $row = DB::table('communications')->where('id', $id)->where('school_id', $user->school_id)->where('sender_user_id', $user->id)->where('status', 'draft')->lockForUpdate()->first();
            abort_unless($row, 404);
            $preview = $this->preview($user, $id);
            DB::table('communications')->where('id', $id)->update(['status' => $preview['approval_required'] ? 'pending_approval' : 'approved', 'requires_approval' => $preview['approval_required'], 'risk_level' => $preview['risk_level'], 'recipient_count' => $preview['unique_users_count'], 'updated_at' => now()]);
            $this->audit->record($user, 'submitted', 'communication', $id, $id, ['approval_required' => $preview['approval_required']]);

            return $this->find($user, $id);
        });
    }

    public function approve(User $user, string $id): object
    {
        return DB::transaction(function () use ($user, $id) {
            $row = DB::table('communications')->where('id', $id)->where('school_id', $user->school_id)->where('status', 'pending_approval')->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->sender_user_id === $user->id, 422, 'Requester cannot approve their own communication.');
            abort_unless($this->resolver->hasPermission($user, 'approve_communications'), 403);
            DB::table('communications')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record($user, 'approved', 'communication', $id, $id);

            return $this->find($user, $id);
        });
    }

    public function reject(User $user, string $id, string $reason): object
    {
        return DB::transaction(function () use ($user, $id, $reason) {
            $row = DB::table('communications')->where('id', $id)->where('school_id', $user->school_id)->where('status', 'pending_approval')->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($this->resolver->hasPermission($user, 'approve_communications'), 403);
            DB::table('communications')->where('id', $id)->update(['status' => 'draft', 'rejected_by' => $user->id, 'rejected_at' => now(), 'rejection_reason' => $reason, 'updated_at' => now()]);
            $this->audit->record($user, 'rejected', 'communication', $id, $id, ['reason' => $reason]);

            return $this->find($user, $id);
        });
    }

    public function schedule(User $user, string $id, string $when): object
    {
        $row = DB::table('communications')->where('id', $id)->where('school_id', $user->school_id)->where('status', 'approved')->first();
        abort_unless($row, 404);
        abort_unless($this->resolver->hasPermission($user, 'schedule_communications'), 403);
        abort_if(CarbonImmutable::parse($when)->isPast(), 422, 'Schedule time must be in the future.');
        $policy = $this->policies->policy($user, $row->category);
        abort_unless($policy['allow_scheduling'], 422, 'Scheduling is disabled by school policy.');
        DB::table('communications')->where('id', $id)->update(['status' => 'scheduled', 'scheduled_for' => $when, 'updated_at' => now()]);
        $this->audit->record($user, 'scheduled', 'communication', $id, $id, ['scheduled_for' => $when]);

        return $this->find($user, $id);
    }

    public function send(User $user, string $id): object
    {
        return DB::transaction(function () use ($user, $id) {
            $communication = DB::table('communications')->where('id', $id)->where('school_id', $user->school_id)->lockForUpdate()->first();
            abort_unless($communication, 404);
            abort_unless($communication->sender_user_id === $user->id || $this->resolver->hasPermission($user, 'approve_communications'), 403);
            if (in_array($communication->status, ['sent', 'partially_failed'], true)) {
                return $this->find($user, $id);
            }
            abort_unless(in_array($communication->status, ['approved', 'scheduled'], true), 409, 'Communication is not approved for sending.');
            abort_if($communication->status === 'scheduled' && CarbonImmutable::parse($communication->scheduled_for)->isFuture(), 409, 'Scheduled communication is not due.');
            abort_if($communication->expires_at && CarbonImmutable::parse($communication->expires_at)->isPast(), 409, 'Communication has expired.');
            if ($communication->requires_approval) {
                abort_unless($communication->approved_at, 409, 'Required approval is missing.');
            }
            $sender = User::where('id', $communication->sender_user_id)->where('school_id', $communication->school_id)->where('active', true)->where('is_deleted', false)->firstOrFail();
            $targets = DB::table('communication_targets')->where('school_id', $user->school_id)->where('communication_id', $id)->get();
            $resolution = $this->resolver->resolve($sender, $targets);
            abort_if($resolution['unique_users'] > config('communication.maximum_recipients', 5000), 422, 'Recipient limit exceeds the Phase 1 safety bound.');
            $policy = $this->policies->policy($user, $communication->category);
            $this->policies->assertSenderAndPriority($sender, $policy, $communication->priority);
            $channels = $this->policies->validateChannels(json_decode($communication->channels, true), $policy);
            if (in_array('sms', $channels, true)) {
                $this->policies->assertSmsPermission($sender, $communication->category);
            }
            $impact = $this->impact->analyze($resolution, $targets, $communication->priority, $communication->communication_type, $policy);
            abort_if($impact['approval_required'] && ! $communication->approved_at, 409, 'Current audience impact requires approval.');
            DB::table('communications')->where('id', $id)->update(['status' => 'sending', 'recipient_count' => $resolution['unique_users'], 'risk_level' => $impact['risk_level'], 'updated_at' => now()]);
            foreach ($resolution['recipients'] as $recipient) {
                DB::table('communication_recipient_snapshots')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'communication_id' => $id, 'user_id' => $recipient->user_id, 'audience_type' => $recipient->audience_type, 'context' => null, 'email' => $recipient->email, 'email_valid' => $recipient->email_valid, 'email_suppressed' => $recipient->email_suppressed, 'sms_eligible' => $recipient->sms_eligible, 'phone_hash' => $recipient->sms_eligible ? hash('sha256', $this->phones->normalize($recipient->phone)) : null, 'resolved_at' => now()]);
                foreach ($channels as $channel) {
                    $deliveryId = (string) Str::uuid();
                    $key = $id.':'.$recipient->user_id.':'.$channel;
                    $eligible = $channel === 'email' ? $recipient->email_valid : ($channel === 'sms' ? $recipient->sms_eligible : true);
                    $inserted = DB::table('communication_deliveries')->insertOrIgnore(['id' => $deliveryId, 'school_id' => $user->school_id, 'communication_id' => $id, 'recipient_user_id' => $recipient->user_id, 'channel' => $channel, 'status' => $channel === 'in_app' ? 'pending' : ($eligible ? 'queued' : 'skipped'), 'delivery_key' => hash('sha256', $key), 'destination_encrypted' => $channel === 'sms' && $eligible ? Crypt::encryptString($this->phones->normalize($recipient->phone)) : null, 'destination_hash' => $channel === 'sms' && $eligible ? hash('sha256', $this->phones->normalize($recipient->phone)) : null, 'attempt_count' => 0, 'failure_reason' => ! $eligible ? 'Channel destination unavailable or suppressed.' : null, 'queued_at' => $channel !== 'in_app' && $eligible ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
                    if (! $inserted) {
                        continue;
                    }
                    if ($channel === 'in_app') {
                        $this->inApp->deliver($communication, $recipient, $deliveryId);
                    } elseif ($channel === 'email' && $recipient->email_valid) {
                        $this->email->queue($deliveryId);
                    } elseif ($channel === 'sms' && $recipient->sms_eligible) {
                        $segment = $this->segments->calculate($communication->body);
                        $this->wallet->reserve($user->school_id, $id, $deliveryId, $segment['segments']);
                        $this->sms->queue($deliveryId);
                    }
                }
            }
            DB::table('communications')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
            $this->audit->record($user, 'sent', 'communication', $id, $id, ['recipient_count' => $resolution['unique_users'], 'channels' => $channels]);

            return $this->find($user, $id);
        });
    }

    public function cancel(User $user, string $id, string $reason): object
    {
        $row = DB::table('communications')->where('id', $id)->where('school_id', $user->school_id)->whereNotIn('status', ['sent', 'cancelled', 'archived'])->first();
        abort_unless($row, 404);
        abort_unless($row->sender_user_id === $user->id || $this->resolver->hasPermission($user, 'cancel_communications'), 403);
        DB::table('communications')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'updated_at' => now()]);
        DB::table('communication_deliveries')->where('communication_id', $id)->whereIn('status', ['pending', 'queued'])->update(['status' => 'cancelled', 'updated_at' => now()]);
        $this->audit->record($user, 'cancelled', 'communication', $id, $id, ['reason' => $reason]);

        return $this->find($user, $id);
    }

    public function archive(User $user, string $id): object
    {
        $row = DB::table('communications')->where('id', $id)->where('school_id', $user->school_id)->whereIn('status', ['sent', 'expired', 'cancelled'])->first();
        abort_unless($row, 404);
        DB::table('communications')->where('id', $id)->update(['status' => 'archived', 'updated_at' => now()]);
        $this->audit->record($user, 'archived', 'communication', $id, $id);

        return $this->find($user, $id);
    }

    public function find(User $user, string $id): object
    {
        $row = DB::table('communications')->where('id', $id)->where('school_id', $user->school_id)->first();
        abort_unless($row, 404);
        $row->targets = DB::table('communication_targets')->where('school_id', $user->school_id)->where('communication_id', $id)->get();

        return $row;
    }

    private function replaceTargets(User $user, string $id, array $targets): void
    {
        abort_if(empty($targets), 422, 'At least one communication target is required.');
        DB::table('communication_targets')->where('communication_id', $id)->where('school_id', $user->school_id)->delete();
        foreach ($targets as $target) {
            DB::table('communication_targets')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'communication_id' => $id, 'target_type' => $target['target_type'], 'options' => json_encode($target['options'] ?? []), 'created_at' => now()]);
        }
    }

    private function validateContent(array $data): void
    {
        if (! in_array($data['communication_type'], self::TYPES, true) || ! in_array($data['priority'] ?? 'normal', self::PRIORITIES, true)) {
            throw ValidationException::withMessages(['communication' => 'Unsupported communication type or priority.']);
        }
        if ($data['subject'] !== strip_tags($data['subject']) || $data['body'] !== strip_tags($data['body']) || preg_match('/<script|javascript:|<iframe|<form|tracking/i', $data['body'])) {
            throw ValidationException::withMessages(['body' => 'Phase 1 communications must use safe plain text.']);
        }
    }
}
