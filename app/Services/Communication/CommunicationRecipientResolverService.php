<?php

namespace App\Services\Communication;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CommunicationRecipientResolverService
{
    private const TARGETS = ['entire_school', 'all_teachers', 'all_learners', 'all_parents', 'role', 'grade', 'stream', 'class_teacher_stream', 'subject_teacher_assignment', 'explicit_user', 'linked_parents_of_learners', 'finance_balance_group', 'absent_today_group'];

    public function __construct(
        private KenyanPhoneNormalizer $phones,
        private ContactHealthService $contacts,
        private AuthContextService $authContext
    ) {}

    public function resolve(User $sender, iterable $targets): array
    {
        $resolved = collect();
        $rawCount = 0;
        $excluded = ['inactive' => 0, 'missing_email' => 0, 'invalid_email' => 0, 'suppressed_email' => 0, 'invalid_phone' => 0, 'opted_out' => 0];
        foreach ($targets as $target) {
            $type = is_array($target) ? $target['target_type'] : $target->target_type;
            $options = is_array($target) ? ($target['options'] ?? []) : ($target->options ?? []);
            $options = is_string($options) ? json_decode($options, true) : (array) $options;
            if (! in_array($type, self::TARGETS, true)) {
                throw ValidationException::withMessages(['target_type' => 'Unsupported communication target.']);
            }
            $users = $this->target($sender, $type, $options);
            $rawCount += $users->count();
            $resolved = $resolved->concat($users);
        }
        $duplicates = $rawCount - $resolved->pluck('user_id')->unique()->count();
        $phaseTwo = Schema::hasTable('communication_preferences') && Schema::hasTable('communication_contact_health');
        $recipients = $resolved->unique('user_id')->values()->map(function ($recipient) use (&$excluded, $sender, $phaseTwo) {
            $email = trim((string) ($recipient->email ?? ''));
            $valid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            if ($email === '') {
                $excluded['missing_email']++;
            } elseif (! $valid) {
                $excluded['invalid_email']++;
            }
            $recipient->email_valid = $valid;
            $preference = $phaseTwo ? DB::table('communication_preferences')->where('school_id', $sender->school_id)->where('user_id', $recipient->user_id)->first() : null;
            $recipient->email_suppressed = $phaseTwo && $valid && ($this->contacts->suppressed($sender->school_id, $recipient->user_id, 'email', $email) || ($preference && ! $preference->email_enabled));
            if ($recipient->email_suppressed) {
                $excluded['suppressed_email']++;
                $recipient->email_valid = false;
            }
            $recipient->sms_eligible = $phaseTwo && $this->phones->valid($recipient->phone ?? null) && (! $preference || $preference->sms_enabled) && ! $this->contacts->suppressed($sender->school_id, $recipient->user_id, 'sms', (string) ($recipient->phone ?? ''));
            if (! $this->phones->valid($recipient->phone ?? null)) {
                $excluded['invalid_phone']++;
            } elseif ($preference && ! $preference->sms_enabled) {
                $excluded['opted_out']++;
            }

            return $recipient;
        });

        return ['recipients' => $recipients, 'unique_users' => $recipients->count(), 'duplicates_removed' => $duplicates, 'excluded' => $excluded, 'counts' => $recipients->countBy('audience_type'), 'in_app_eligible' => $recipients->count(), 'email_eligible' => $recipients->where('email_valid', true)->count(), 'sms_eligible' => $recipients->where('sms_eligible', true)->count()];
    }

    public function hasPermission(User $user, string $permission): bool
    {
        return $this->authContext->hasPermission($user, $permission);
    }

    private function target(User $sender, string $type, array $options): Collection
    {
        return match ($type) {
            'entire_school' => $this->requirePermission($sender, 'send_schoolwide_communications', fn () => $this->users($sender)),
            'all_teachers' => $this->requirePermission($sender, 'send_staff_communications', fn () => $this->profileUsers($sender, 'teachers', 'teacher')),
            'all_learners' => $this->requirePermission($sender, 'send_learner_communications', fn () => $this->profileUsers($sender, 'learners', 'learner')),
            'all_parents' => $this->requirePermission($sender, 'send_parent_communications', fn () => $this->profileUsers($sender, 'parents', 'parent')),
            'role' => $this->role($sender, $options),
            'grade', 'stream' => $this->classScope($sender, $type, $options),
            'class_teacher_stream' => $this->assignment($sender, $options, true),
            'subject_teacher_assignment' => $this->assignment($sender, $options, false),
            'explicit_user' => $this->explicitUsers($sender, $options),
            'linked_parents_of_learners' => $this->parentsForLearners($sender, $this->validatedLearnerIds($sender, $options['learner_ids'] ?? [])),
            'finance_balance_group' => $this->financeGroup($sender, $options),
            'absent_today_group' => $this->absentGroup($sender, $options),
        };
    }

    private function users(User $sender): Collection
    {
        return DB::table('users as user')->leftJoin('roles as role', 'role.id', '=', 'user.role_id')->where('user.school_id', $sender->school_id)->where('user.active', true)->where('user.is_deleted', false)->select('user.id as user_id', 'user.email', 'user.phone', DB::raw("LOWER(COALESCE(role.role_name, 'staff')) AS audience_type"), 'user.first_name', 'user.last_name')->get();
    }

    private function profileUsers(User $sender, string $table, string $audience): Collection
    {
        return DB::table($table.' as profile')->join('users as user', 'user.id', '=', 'profile.user_id')->where('profile.school_id', $sender->school_id)->where('profile.active', true)->where('profile.is_deleted', false)->where('user.school_id', $sender->school_id)->where('user.active', true)->where('user.is_deleted', false)->select('user.id as user_id', 'user.email', 'user.phone', DB::raw("'{$audience}' AS audience_type"), 'user.first_name', 'user.last_name')->get();
    }

    private function role(User $sender, array $options): Collection
    {
        $this->requirePermission($sender, 'send_staff_communications');
        $roleId = $options['role_id'] ?? null;
        abort_unless(DB::table('roles')->where('id', $roleId)->exists(), 422, 'Invalid role target.');

        return $this->users($sender)->where(fn ($user) => DB::table('users')->where('id', $user->user_id)->where('role_id', $roleId)->exists())->values();
    }

    private function classScope(User $sender, string $type, array $options): Collection
    {
        $gradeId = $options['grade_id'] ?? null;
        $streamId = $options['stream_id'] ?? null;
        abort_unless(DB::table('grades')->where('id', $gradeId)->where('school_id', $sender->school_id)->exists(), 422, 'Invalid grade target.');
        if ($type === 'stream') {
            abort_unless(DB::table('streams')->where('id', $streamId)->where('school_id', $sender->school_id)->where('grade_id', $gradeId)->exists(), 422, 'Invalid stream target.');
        }
        if (! $this->hasPermission($sender, 'send_grade_communications')) {
            $assignment = DB::table('teachers as teacher')->join('teacher_assignments as assignment', 'assignment.teacher_id', '=', 'teacher.id')->where('teacher.user_id', $sender->id)->where('assignment.school_id', $sender->school_id)->where('assignment.active', true)->where('assignment.is_deleted', false)->where('assignment.grade_id', $gradeId)->when($streamId, fn ($query) => $query->where('assignment.stream_id', $streamId))->exists();
            abort_unless($assignment, 403, 'Target is outside sender assignments.');
        }
        $learners = $this->learnerRows($sender, $gradeId, $streamId);

        return $this->audience($sender, $learners, $options['audience'] ?? 'learners');
    }

    private function assignment(User $sender, array $options, bool $classTeacher): Collection
    {
        $assignmentId = $options['teacher_assignment_id'] ?? null;
        $assignment = DB::table('teacher_assignments as assignment')->join('teachers as teacher', 'teacher.id', '=', 'assignment.teacher_id')->where('assignment.id', $assignmentId)->where('assignment.school_id', $sender->school_id)->where('assignment.active', true)->where('assignment.is_deleted', false)->where('teacher.user_id', $sender->id)->when($classTeacher, fn ($query) => $query->where('assignment.is_class_teacher', true))->select('assignment.*')->first();
        abort_unless($assignment, 403, 'Teacher assignment target is not authorized.');
        $permission = $classTeacher ? 'send_own_class_communications' : 'send_assigned_class_communications';
        abort_unless($this->hasPermission($sender, $permission), 403, 'Communication permission is missing.');
        $learners = $this->learnerRows($sender, $assignment->grade_id, $assignment->stream_id);

        return $this->audience($sender, $learners, $options['audience'] ?? 'learners');
    }

    private function learnerRows(User $sender, string $gradeId, ?string $streamId): Collection
    {
        return DB::table('learners')->where('school_id', $sender->school_id)->where('grade_id', $gradeId)->when($streamId, fn ($query) => $query->where('stream_id', $streamId))->where('active', true)->where('is_deleted', false)->select('id', 'user_id')->get();
    }

    private function audience(User $sender, Collection $learners, string $audience): Collection
    {
        abort_unless(in_array($audience, ['learners', 'parents', 'both'], true), 422, 'Invalid audience selection.');
        $result = collect();
        if (in_array($audience, ['learners', 'both'], true)) {
            abort_unless($this->hasPermission($sender, 'send_learner_communications') || $this->hasPermission($sender, 'send_own_class_communications') || $this->hasPermission($sender, 'send_assigned_class_communications'), 403);
            $result = $result->concat($this->learnerUsers($sender, $learners));
        }
        if (in_array($audience, ['parents', 'both'], true)) {
            abort_unless($this->hasPermission($sender, 'send_parent_communications'), 403);
            $result = $result->concat($this->parentsForLearners($sender, $learners->pluck('id')));
        }

        return $result;
    }

    private function learnerUsers(User $sender, Collection $learners): Collection
    {
        return DB::table('users')->where('school_id', $sender->school_id)->whereIn('id', $learners->pluck('user_id')->filter())->where('active', true)->where('is_deleted', false)->select('id as user_id', 'email', 'phone', DB::raw("'learner' AS audience_type"), 'first_name', 'last_name')->get();
    }

    private function parentsForLearners(User $sender, iterable $learnerIds): Collection
    {
        return DB::table('learner_parents as link')->join('parents as parent', 'parent.id', '=', 'link.parent_id')->join('users as user', 'user.id', '=', 'parent.user_id')->whereIn('link.learner_id', collect($learnerIds))->where('link.active', true)->where('link.portal_enabled', true)->where('link.is_deleted', false)->where('parent.school_id', $sender->school_id)->where('parent.active', true)->where('parent.is_deleted', false)->where('user.school_id', $sender->school_id)->where('user.active', true)->where('user.is_deleted', false)->select('user.id as user_id', 'user.email', 'user.phone', DB::raw("'parent' AS audience_type"), 'user.first_name', 'user.last_name')->get();
    }

    private function validatedLearnerIds(User $sender, array $ids): Collection
    {
        $ids = collect($ids)->unique()->values();
        $valid = DB::table('learners')->where('school_id', $sender->school_id)->whereIn('id', $ids)->where('active', true)->where('is_deleted', false)->pluck('id');
        abort_unless($valid->count() === $ids->count(), 422, 'One or more learner targets are invalid.');
        if (! $this->hasPermission($sender, 'send_schoolwide_communications')) {
            $assigned = DB::table('teachers as teacher')->join('teacher_assignments as assignment', 'assignment.teacher_id', '=', 'teacher.id')->join('learners as learner', function ($join) {
                $join->on('learner.grade_id', '=', 'assignment.grade_id')->on('learner.stream_id', '=', 'assignment.stream_id');
            })->where('teacher.user_id', $sender->id)->where('assignment.school_id', $sender->school_id)->where('assignment.active', true)->where('assignment.is_deleted', false)->whereIn('learner.id', $ids)->pluck('learner.id')->unique();
            abort_unless($assigned->count() === $ids->count(), 403, 'Learner target is outside sender assignments.');
        }

        return $valid;
    }

    private function explicitUsers(User $sender, array $options): Collection
    {
        $this->requirePermission($sender, 'send_schoolwide_communications');
        $ids = collect($options['user_ids'] ?? [])->unique();
        $users = $this->users($sender)->whereIn('user_id', $ids)->values();
        abort_unless($users->count() === $ids->count(), 422, 'Explicit user target contains an invalid tenant user.');

        return $users;
    }

    private function financeGroup(User $sender, array $options): Collection
    {
        $this->requirePermission($sender, 'send_finance_communications');
        $operator = $options['operator'] ?? '>';
        abort_unless(in_array($operator, ['>', '>=', '=', '<=', '<'], true), 422, 'Invalid balance operator.');
        $threshold = (string) ($options['threshold'] ?? '0');
        abort_unless(preg_match('/^\d+(?:\.\d{1,2})?$/', $threshold), 422, 'Invalid balance threshold.');
        $learners = DB::table('learner_fee_accounts')->where('school_id', $sender->school_id)->where('active', true)->where('current_balance', $operator, $threshold)->pluck('learner_id');

        return $this->parentsForLearners($sender, $learners);
    }

    private function absentGroup(User $sender, array $options): Collection
    {
        $this->requirePermission($sender, 'send_attendance_communications');
        $date = $options['attendance_date'] ?? now()->toDateString();
        $learners = DB::table('learner_attendance as attendance')
            ->join('attendance_statuses as status', 'status.id', '=', 'attendance.attendance_status_id')
            ->where('attendance.school_id', $sender->school_id)
            ->whereDate('attendance.attendance_date', $date)
            ->whereRaw('UPPER(status.status_code) = ?', ['ABSENT'])
            ->where('attendance.finalized', true)
            ->pluck('attendance.learner_id')
            ->unique();

        return $this->parentsForLearners($sender, $learners);
    }

    private function requirePermission(User $sender, string $permission, ?callable $then = null): mixed
    {
        abort_unless($this->hasPermission($sender, $permission), 403, 'Communication target permission denied.');

        return $then ? $then() : true;
    }
}
