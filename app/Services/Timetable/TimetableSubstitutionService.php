<?php

namespace App\Services\Timetable;

use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\TimetableSubstitution;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TimetableSubstitutionService
{
    public function __construct(private TimetableAuditService $audit) {}

    public function suggestions(User $user, string $entryId, string $date)
    {
        $entry = $this->publishedEntry($user, $entryId);
        $day = Carbon::parse($date)->dayOfWeekIso;
        $busy = DB::table('timetable_entries as e')->join('timetables as t', 't.id', '=', 'e.timetable_id')->where('t.school_id', $user->school_id)->where('t.status', 'published')->where('e.day_of_week', $day)->where('e.period_id', $entry->period_id)->where('e.is_deleted', false)->pluck('e.teacher_id');

        return Teacher::current()->where('school_id', $user->school_id)->whereKeyNot($entry->teacher_id)->whereNotIn('id', $busy)->get()->sortByDesc(fn ($teacher) => DB::table('teacher_assignments')->where('teacher_id', $teacher->id)->where('learning_area_id', $entry->learning_area_id)->where('active', true)->exists() ? 1 : 0)->values();
    }

    public function create(User $user, array $data): TimetableSubstitution
    {
        $entry = $this->publishedEntry($user, $data['timetable_entry_id']);
        $teacher = Teacher::current()->whereKey($data['substitute_teacher_id'])->where('school_id', $user->school_id)->firstOrFail();
        if ($teacher->id === $entry->teacher_id) {
            throw ValidationException::withMessages(['substitute_teacher_id' => 'Substitute and absent teacher must differ.']);
        }
        $term = DB::table('terms')->where('id', $entry->timetable->term_id)->where('school_id', $user->school_id)->first();
        if (! $term || $data['substitution_date'] < $term->start_date || $data['substitution_date'] > $term->end_date) {
            throw ValidationException::withMessages(['substitution_date' => 'Date must fall within the timetable term.']);
        }
        $day = Carbon::parse($data['substitution_date'])->dayOfWeekIso;
        $busy = DB::table('timetable_entries as e')->join('timetables as t', 't.id', '=', 'e.timetable_id')->where('t.school_id', $user->school_id)->where('t.status', 'published')->where('e.teacher_id', $teacher->id)->where('e.day_of_week', $day)->where('e.period_id', $entry->period_id)->where('e.is_deleted', false)->exists();
        if ($busy) {
            throw ValidationException::withMessages(['substitute_teacher_id' => 'Substitute teacher has a timetable clash.']);
        }
        $substitution = TimetableSubstitution::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'timetable_entry_id' => $entry->id, 'absent_teacher_id' => $entry->teacher_id, 'substitute_teacher_id' => $teacher->id, 'substitution_date' => $data['substitution_date'], 'reason' => $data['reason'], 'status' => 'pending']);
        $this->audit->record($user, $entry->timetable_id, 'substitution_created', ['substitution_id' => $substitution->id]);

        return $substitution;
    }

    public function approve(User $user, string $id): TimetableSubstitution
    {
        $substitution = TimetableSubstitution::whereKey($id)->where('school_id', $user->school_id)->where('status', 'pending')->firstOrFail();
        $substitution->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
        $entry = TimetableEntry::whereKey($substitution->timetable_entry_id)->firstOrFail();
        $this->audit->record($user, $entry->timetable_id, 'substitution_approved', ['substitution_id' => $substitution->id]);

        return $substitution;
    }

    public function cancel(User $user, string $id, string $reason): TimetableSubstitution
    {
        $substitution = TimetableSubstitution::whereKey($id)->where('school_id', $user->school_id)->whereIn('status', ['pending', 'approved'])->firstOrFail();
        $substitution->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
        $entry = TimetableEntry::whereKey($substitution->timetable_entry_id)->firstOrFail();
        $this->audit->record($user, $entry->timetable_id, 'substitution_cancelled', ['substitution_id' => $substitution->id], $reason);

        return $substitution;
    }

    private function publishedEntry(User $user, string $id): TimetableEntry
    {
        return TimetableEntry::whereKey($id)->where('school_id', $user->school_id)->where('is_deleted', false)->whereHas('timetable', fn ($query) => $query->where('status', 'published')->where('active', true))->with('timetable')->firstOrFail();
    }
}
