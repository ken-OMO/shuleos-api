<?php

namespace App\Services\Communication;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunicationNotificationService
{
    public function __construct(private CommunicationAuditService $audit) {}

    public function index(User $user)
    {
        return DB::table('notifications')->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNotIn('state', ['archived', 'dismissed'])->latest('created_at')->paginate(30);
    }

    public function unreadCount(User $user): int
    {
        return DB::table('notifications')->where('school_id', $user->school_id)->where('user_id', $user->id)->where(fn ($query) => $query->where('state', 'unread')->orWhere(fn ($legacy) => $legacy->whereNull('state')->where('is_read', false)))->count();
    }

    public function state(User $user, string $id, string $state): object
    {
        if (! in_array($state, ['read', 'unread', 'archived', 'dismissed'], true)) {
            throw ValidationException::withMessages(['state' => 'Unsupported notification state.']);
        }
        $notification = DB::table('notifications')->where('id', $id)->where('school_id', $user->school_id)->where('user_id', $user->id)->first();
        abort_unless($notification, 404);
        if ($notification->state === $state) {
            return $notification;
        }
        $values = ['state' => $state, 'is_read' => $state !== 'unread', 'version' => ((int) ($notification->version ?? 1)) + 1, 'updated_at' => now()];
        if ($state === 'read') {
            $values['read_at'] = now();
        } elseif ($state === 'unread') {
            $values['read_at'] = null;
        } elseif ($state === 'archived') {
            $values['archived_at'] = now();
        } elseif ($state === 'dismissed') {
            $values['dismissed_at'] = now();
        }
        DB::table('notifications')->where('id', $id)->update($values);
        $this->audit->record($user, 'notification_'.$state, 'notification', $id, $notification->communication_id ?? null);

        return DB::table('notifications')->where('id', $id)->first();
    }

    public function announcements(User $user)
    {
        return $this->announcementQuery($user)->paginate(20);
    }

    public function portalAnnouncements(User $user)
    {
        return $this->announcementQuery($user)->limit(20)->get();
    }

    private function announcementQuery(User $user)
    {
        return DB::table('communications as communication')->join('communication_recipient_snapshots as recipient', function ($join) use ($user) {
            $join->on('recipient.communication_id', '=', 'communication.id')->where('recipient.user_id', $user->id);
        })->leftJoin('announcement_reads as read', function ($join) use ($user) {
            $join->on('read.communication_id', '=', 'communication.id')->where('read.user_id', $user->id);
        })->where('communication.school_id', $user->school_id)->where('recipient.school_id', $user->school_id)->where('communication.communication_type', 'announcement')->where('communication.status', 'sent')->where(fn ($query) => $query->whereNull('communication.expires_at')->orWhere('communication.expires_at', '>', now()))->select('communication.id', 'communication.subject', 'communication.body', 'communication.priority', 'communication.sent_at', 'communication.expires_at', 'read.read_at')->latest('communication.sent_at');
    }

    public function readAnnouncement(User $user, string $id): void
    {
        abort_unless(DB::table('communication_recipient_snapshots')->where('communication_id', $id)->where('school_id', $user->school_id)->where('user_id', $user->id)->exists(), 404);
        DB::table('announcement_reads')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'communication_id' => $id, 'user_id' => $user->id, 'read_at' => now(), 'version' => 2, 'updated_at' => now()]);
        $this->audit->record($user, 'announcement_read', 'communication', $id, $id);
    }
}
