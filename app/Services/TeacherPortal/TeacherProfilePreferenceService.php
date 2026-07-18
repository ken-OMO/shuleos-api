<?php

namespace App\Services\TeacherPortal;

use App\Models\User;
use App\Services\Communication\CommunicationPreferenceService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherProfilePreferenceService
{
    public function __construct(private TeacherPortalAccessService $access, private TeacherPortalService $portal, private CommunicationPreferenceService $communications, private TeacherPortalAuditService $audit) {}

    public function profile(User $user): array
    {
        $teacher = $this->access->teacher($user);

        return ['id' => $teacher->id, 'display_name' => trim($user->first_name.' '.$user->last_name), 'staff_number' => $teacher->staff_no, 'designation' => $teacher->designation, 'professional_summary' => $teacher->professional_summary, 'email_masked' => $this->mask($user->email, true), 'phone_masked' => $this->mask($user->phone), 'school' => DB::table('schools')->where('id', $user->school_id)->select('school_name', 'school_code')->first(), 'assignments' => $this->access->assignments($user), 'pending_contact_changes' => DB::table('teacher_contact_change_requests')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('status', 'pending')->select('id', 'contact_type', 'status', 'created_at')->get()];
    }

    public function update(User $user, array $data): array
    {
        $teacher = $this->access->teacher($user);
        DB::transaction(function () use ($user, $teacher, $data) {
            $names = collect($data)->only(['first_name', 'last_name'])->map(fn ($value) => trim(strip_tags((string) $value)))->all();
            if ($names) {
                DB::table('users')->where('id', $user->id)->where('school_id', $user->school_id)->update($names + ['updated_at' => now()]);
            }
            if (array_key_exists('professional_summary', $data)) {
                DB::table('teachers')->where('id', $teacher->id)->where('school_id', $user->school_id)->update(['professional_summary' => strip_tags((string) $data['professional_summary']), 'updated_at' => now()]);
            }
            foreach (['email', 'phone'] as $type) {
                if (! array_key_exists($type, $data)) {
                    continue;
                }
                $value = trim($data[$type]);
                DB::table('teacher_contact_change_requests')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'contact_type' => $type, 'requested_value_encrypted' => Crypt::encryptString($value), 'requested_value_hash' => hash('sha256', strtolower($value)), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record($user, 'profile_updated', $teacher->id, 'teacher', $teacher->id);
        });

        return $this->profile($user->fresh());
    }

    public function preferences(User $user): array
    {
        return ['dashboard' => $this->portal->preferences($user), 'communication' => $this->communications->get($user)];
    }

    public function updatePreferences(User $user, array $data): array
    {
        $teacher = $this->access->teacher($user);
        $dashboard = collect($data)->only(['show_todays_timetable', 'show_pending_lesson_plans', 'show_curriculum_coverage', 'show_notifications', 'show_announcements', 'show_attendance_summary', 'show_assessment_summary', 'show_performance_analytics', 'preferred_language', 'timezone', 'default_assignment_id', 'default_stream_id', 'timetable_view'])->all();
        if (! empty($dashboard['default_assignment_id'])) {
            $this->access->requireAssignment($user, $dashboard['default_assignment_id']);
        }
        if (! empty($dashboard['default_stream_id'])) {
            $this->access->requireStream($user, $dashboard['default_stream_id']);
        }
        $preference = $this->portal->preferences($user);
        $preference->update($dashboard);
        $communication = collect($data)->only(['email_enabled', 'sms_enabled', 'in_app_enabled', 'digest_frequency', 'quiet_hours_start', 'quiet_hours_end', 'timezone', 'language'])->all();
        $communication = $communication ? $this->communications->update($user, $communication) : $this->communications->get($user);
        $this->audit->record($user, 'preferences_updated', $teacher->id, 'teacher_dashboard_preference', $preference->id);

        return ['dashboard' => $preference->fresh(), 'communication' => $communication];
    }

    private function mask(?string $value, bool $email = false): ?string
    {
        if (! $value) {
            return null;
        }
        if ($email && str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);

            return mb_substr($local, 0, 1).str_repeat('*', max(2, mb_strlen($local) - 1)).'@'.$domain;
        }

        return str_repeat('*', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
    }
}
