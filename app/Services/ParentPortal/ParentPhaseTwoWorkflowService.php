<?php

namespace App\Services\ParentPortal;

use App\Models\ParentAppointment;
use App\Models\ParentConsent;
use App\Models\ParentConsentResponse;
use App\Models\ParentConversation;
use App\Models\ParentConversationMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParentPhaseTwoWorkflowService
{
    private const CONVERSATIONS = ['class_teacher', 'subject_teacher', 'finance_office', 'administration', 'technical_support', 'safeguarding', 'appointment', 'homework_clarification'];

    private const APPOINTMENTS = ['class_teacher', 'subject_teacher', 'principal', 'deputy', 'hod', 'finance', 'counselling', 'administration'];

    public function __construct(private ParentPortalAccessService $access) {}

    public function conversations(User $user)
    {
        $this->access->parent($user);

        return ParentConversation::withoutGlobalScopes()->where('school_id', $user->school_id)->where('parent_user_id', $user->id)->with('learner:id,first_name,last_name')->latest()->paginate(30);
    }

    public function conversation(User $user, string $id): ParentConversation
    {
        $this->access->parent($user);

        $conversation = ParentConversation::withoutGlobalScopes()->whereKey($id)->where('school_id', $user->school_id)->where('parent_user_id', $user->id)->with('learner:id,first_name,last_name')->firstOrFail();
        if ($conversation->safeguarding_restricted) {
            $this->audit($user, 'safeguarding_conversation_accessed', 'parent_conversation', $conversation->id);
        }

        return $conversation;
    }

    public function createConversation(User $user, array $data): ParentConversation
    {
        $learner = $this->access->requireLinkedLearner($user, $data['learner_id']);
        abort_unless(in_array($data['conversation_type'], self::CONVERSATIONS, true), 422);
        $this->assertMessagingPolicy($user, $data['conversation_type']);
        $staff = $this->resolveStaff($user, $learner->grade_id, $learner->stream_id, $data['conversation_type']);
        if (! $staff && ! in_array($data['conversation_type'], ['technical_support', 'administration'], true)) {
            throw ValidationException::withMessages(['conversation_type' => 'No authorized school destination is configured.']);
        }

        return DB::transaction(function () use ($user, $learner, $data, $staff) {
            $conversation = ParentConversation::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'parent_user_id' => $user->id, 'learner_id' => $learner->id,
                'conversation_type' => $data['conversation_type'], 'subject' => strip_tags($data['subject']),
                'destination_role' => $data['conversation_type'], 'resolved_staff_user_id' => $staff,
                'status' => 'open', 'safeguarding_restricted' => $data['conversation_type'] === 'safeguarding',
            ]);
            if (filled($data['message'] ?? null)) {
                $this->addMessage($user, $conversation->id, $data['message']);
            }
            $this->audit($user, 'conversation_created', 'parent_conversation', $conversation->id, ['type' => $conversation->conversation_type]);

            return $conversation;
        });
    }

    public function messages(User $user, string $id)
    {
        $conversation = $this->conversation($user, $id);

        return ParentConversationMessage::withoutGlobalScopes()->where('school_id', $user->school_id)->where('conversation_id', $conversation->id)->orderBy('sent_at')->paginate(50);
    }

    public function addMessage(User $user, string $id, string $message): ParentConversationMessage
    {
        $conversation = $this->conversation($user, $id);
        abort_unless($conversation->status === 'open', 409, 'Conversation is closed.');
        $this->assertMessagingPolicy($user, $conversation->conversation_type);
        $message = trim(strip_tags($message));
        abort_if($message === '' || mb_strlen($message) > 4000, 422, 'Message must contain 1 to 4000 characters.');

        return ParentConversationMessage::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'conversation_id' => $id, 'sender_user_id' => $user->id, 'sender_type' => 'parent', 'message' => $message, 'sent_at' => now(), 'created_at' => now()]);
    }

    public function closeConversation(User $user, string $id): ParentConversation
    {
        $conversation = $this->conversation($user, $id);
        $conversation->update(['status' => 'closed', 'closed_at' => now(), 'version' => $conversation->version + 1]);

        return $conversation;
    }

    public function consents(User $user, ?string $learnerId = null)
    {
        $learnerIds = $learnerId ? [$this->access->requireLinkedLearner($user, $learnerId)->id] : $this->access->links($user)->pluck('learner_id')->all();

        return ParentConsent::withoutGlobalScopes()->where('school_id', $user->school_id)->where('status', 'published')->whereNull('withdrawn_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('audiences', fn ($query) => $query->whereIn('learner_id', $learnerIds))
            ->with(['audiences' => fn ($query) => $query->whereIn('learner_id', $learnerIds), 'responses' => fn ($query) => $query->where('parent_user_id', $user->id)])->latest('published_at')->get();
    }

    public function consent(User $user, string $id): ParentConsent
    {
        $item = $this->consents($user)->firstWhere('id', $id);
        abort_unless($item, 404);

        return $item;
    }

    public function respondConsent(User $user, string $id, string $response, ?string $reason): ParentConsentResponse
    {
        $consent = $this->consent($user, $id);
        $audience = $consent->audiences->first();
        abort_unless($audience, 404);
        abort_unless(in_array($response, ['accepted', 'declined'], true), 422);
        if ($response === 'declined' && $consent->reason_required_on_decline && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return ParentConsentResponse::withoutGlobalScopes()->firstOrCreate(
            ['consent_id' => $consent->id, 'parent_user_id' => $user->id, 'learner_id' => $audience->learner_id],
            ['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'consent_version' => $consent->consent_version, 'response' => $response, 'reason' => $reason ? strip_tags($reason) : null, 'responded_at' => now()],
        );
    }

    public function appointments(User $user)
    {
        $this->access->parent($user);

        return ParentAppointment::withoutGlobalScopes()->where('school_id', $user->school_id)->where('parent_user_id', $user->id)->with('learner:id,first_name,last_name')->latest()->paginate(30);
    }

    public function appointment(User $user, string $id): ParentAppointment
    {
        $this->access->parent($user);

        return ParentAppointment::withoutGlobalScopes()->whereKey($id)->where('school_id', $user->school_id)->where('parent_user_id', $user->id)->with('learner:id,first_name,last_name')->firstOrFail();
    }

    public function createAppointment(User $user, array $data): ParentAppointment
    {
        $learner = $this->access->requireLinkedLearner($user, $data['learner_id']);
        abort_unless(in_array($data['category'], self::APPOINTMENTS, true), 422);
        $from = now()->parse($data['preferred_from']);
        $to = now()->parse($data['preferred_to']);
        abort_if($from->isPast() || $to->lessThanOrEqualTo($from) || $to->diffInDays($from) > 30, 422, 'Invalid appointment window.');
        $staff = $this->resolveStaff($user, $learner->grade_id, $learner->stream_id, $data['category']);

        return ParentAppointment::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'parent_user_id' => $user->id, 'learner_id' => $learner->id,
            'category' => $data['category'], 'resolved_staff_user_id' => $staff, 'preferred_from' => $from, 'preferred_to' => $to,
            'reason' => strip_tags($data['reason']), 'status' => 'requested',
        ]);
    }

    public function appointmentAction(User $user, string $id, string $action): ParentAppointment
    {
        $appointment = $this->appointment($user, $id);
        $next = match ($action) {
            'accept-proposal' => $appointment->status === 'proposed' ? 'confirmed' : null,
            'decline-proposal' => $appointment->status === 'proposed' ? 'declined' : null,
            'cancel' => in_array($appointment->status, ['requested', 'proposed', 'confirmed'], true) ? 'cancelled' : null,
            default => null,
        };
        abort_unless($next, 409, 'Invalid appointment transition.');
        if ($next === 'confirmed' && $appointment->resolved_staff_user_id) {
            $overlap = ParentAppointment::withoutGlobalScopes()->where('school_id', $user->school_id)
                ->where('resolved_staff_user_id', $appointment->resolved_staff_user_id)->where('status', 'confirmed')
                ->whereKeyNot($appointment->id)->where('preferred_from', '<', $appointment->preferred_to)
                ->where('preferred_to', '>', $appointment->preferred_from)->exists();
            abort_if($overlap, 409, 'The proposed appointment overlaps an existing confirmed appointment.');
        }
        $from = $appointment->status;
        $appointment->update(['status' => $next, 'confirmed_at' => $next === 'confirmed' ? now() : $appointment->confirmed_at, 'version' => $appointment->version + 1]);
        DB::table('parent_appointment_history')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'appointment_id' => $appointment->id, 'actor_user_id' => $user->id, 'from_status' => $from, 'to_status' => $next, 'created_at' => now()]);

        return $appointment;
    }

    private function resolveStaff(User $user, string $grade, ?string $stream, string $type): ?string
    {
        if (in_array($type, ['class_teacher', 'subject_teacher', 'homework_clarification', 'appointment'], true)) {
            return DB::table('teacher_assignments as assignment')->join('teachers as teacher', 'teacher.id', '=', 'assignment.teacher_id')
                ->where('assignment.school_id', $user->school_id)->where('assignment.grade_id', $grade)->where('assignment.stream_id', $stream)
                ->where('assignment.active', true)->where('assignment.is_deleted', false)
                ->when($type === 'class_teacher', fn ($query) => $query->where('assignment.is_class_teacher', true))->value('teacher.user_id');
        }
        $roles = match ($type) {
            'principal', 'safeguarding', 'counselling' => ['Principal'], 'deputy' => ['Deputy Principal'], 'hod' => ['HOD'],
            'finance', 'finance_office' => ['Bursar', 'Accountant', 'Finance Officer'],
            default => ['School Admin', 'Administrator'],
        };

        return DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('users.school_id', $user->school_id)->where('users.active', true)->whereIn('roles.role_name', $roles)->value('users.id');
    }

    private function assertMessagingPolicy(User $user, string $type): void
    {
        $policy = DB::table('communication_policies')->where('school_id', $user->school_id)->where('category', 'parent_messaging')->first();
        if ($policy) {
            $channels = json_decode($policy->enabled_channels, true) ?: [];
            abort_unless(in_array('in_app', $channels, true), 403, 'Parent messaging is disabled by school policy.');
            $roles = json_decode($policy->allowed_sender_roles ?? '[]', true) ?: [];
            abort_if($roles && ! in_array('Parent', $roles, true) && ! in_array('parent', $roles, true), 403, 'Parent messaging is disabled by school policy.');
        }
        if ($type === 'safeguarding') {
            return;
        }
        $preference = DB::table('communication_preferences')->where('school_id', $user->school_id)->where('user_id', $user->id)->first();
        if (! $preference?->quiet_hours_start || ! $preference?->quiet_hours_end) {
            return;
        }
        $time = CarbonImmutable::now($preference->timezone ?: 'Africa/Nairobi')->format('H:i:s');
        $start = (string) $preference->quiet_hours_start;
        $end = (string) $preference->quiet_hours_end;
        $quiet = $start <= $end ? ($time >= $start && $time < $end) : ($time >= $start || $time < $end);
        abort_if($quiet, 409, 'Messaging is paused during configured quiet hours.');
    }

    private function audit(User $user, string $action, ?string $type, ?string $id, array $metadata = []): void
    {
        DB::table('parent_portal_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id, 'safe_metadata' => json_encode($metadata), 'created_at' => now()]);
    }
}
