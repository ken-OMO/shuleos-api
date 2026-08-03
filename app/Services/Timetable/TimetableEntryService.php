<?php

namespace App\Services\Timetable;

use App\Models\Room;
use App\Models\TeacherAssignment;
use App\Models\Timetable;
use App\Models\TimetableDay;
use App\Models\TimetableEntry;
use App\Models\TimetablePeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimetableEntryService
{
    public function save(User $user, string $timetableId, array $data, ?string $entryId = null): TimetableEntry
    {
        return DB::transaction(function () use ($user, $timetableId, $data, $entryId) {
            $timetable = Timetable::whereKey($timetableId)->where('school_id', $user->school_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($timetable->status, ['draft', 'invalid'], true), 409, 'Only draft or invalid timetables are editable.');
            $assignment = TeacherAssignment::current()->whereKey($data['teacher_assignment_id'])->where('school_id', $user->school_id)->where('active', true)->where('academic_year_id', $timetable->academic_year_id)->where('term_id', $timetable->term_id)->firstOrFail();
            $period = TimetablePeriod::whereKey($data['period_id'])->where('timetable_profile_id', $timetable->timetable_profile_id)->where('active', true)->where('is_teaching_period', true)->firstOrFail();
            $day = TimetableDay::whereKey($data['timetable_day_id'])->where('school_id', $user->school_id)->where('timetable_profile_id', $timetable->timetable_profile_id)->where('active', true)->firstOrFail();
            $room = null;
            if (! empty($data['room_id'])) {
                $room = Room::whereKey($data['room_id'])->where('school_id', $user->school_id)->where('active', true)->firstOrFail();
            }
            if (! $assignment->stream_id) {
                throw ValidationException::withMessages(['teacher_assignment_id' => 'Phase 1 manual scheduling requires a stream-scoped assignment.']);
            }
            $ignore = $entryId ? [$entryId] : [];
            $slot = TimetableEntry::where('timetable_id', $timetable->id)->where('day_of_week', $day->day_of_week)->where('period_id', $period->id)->where('is_deleted', false)->whereNotIn('id', $ignore);
            if ((clone $slot)->where('teacher_id', $assignment->teacher_id)->exists()) {
                throw ValidationException::withMessages(['teacher_assignment_id' => 'Teacher clash in this slot.']);
            }
            if ((clone $slot)->where('stream_id', $assignment->stream_id)->exists()) {
                throw ValidationException::withMessages(['stream_id' => 'Stream clash in this slot.']);
            }
            if ($room && (clone $slot)->where('room_id', $room->id)->exists()) {
                throw ValidationException::withMessages(['room_id' => 'Room clash in this slot.']);
            }
            if ($room && DB::table('room_constraints')->where('school_id', $user->school_id)->where('room_id', $room->id)->where('active', true)->where('constraint_type', 'required_learning_area')->whereNotNull('learning_area_id')->where('learning_area_id', '!=', $assignment->learning_area_id)->exists()) {
                throw ValidationException::withMessages(['room_id' => 'Room is incompatible with this learning area.']);
            }
            $values = ['school_id' => $user->school_id, 'timetable_id' => $timetable->id, 'teacher_assignment_id' => $assignment->id, 'timetable_day_id' => $day->id, 'day_of_week' => $day->day_of_week, 'period_id' => $period->id, 'grade_id' => $assignment->grade_id, 'stream_id' => $assignment->stream_id, 'learning_area_id' => $assignment->learning_area_id, 'teacher_id' => $assignment->teacher_id, 'room_id' => $room?->id, 'is_double_lesson' => $data['is_double_lesson'] ?? false, 'remarks' => $data['remarks'] ?? null, 'entry_status' => 'draft', 'updated_by' => $user->id];
            if ($values['is_double_lesson'] && ! $timetable->profile->allow_double_lessons) {
                throw ValidationException::withMessages(['is_double_lesson' => 'The timetable profile does not allow double lessons.']);
            }
            if ($entryId) {
                $entry = TimetableEntry::whereKey($entryId)->where('timetable_id', $timetable->id)->where('school_id', $user->school_id)->firstOrFail();
                $entry->update($values);

                return $entry->fresh();
            }

            return TimetableEntry::create($values + ['created_by' => $user->id]);
        });
    }

    public function delete(User $user, string $timetableId, string $entryId): void
    {
        $timetable = Timetable::whereKey($timetableId)->where('school_id', $user->school_id)->firstOrFail();
        abort_unless(in_array($timetable->status, ['draft', 'invalid'], true), 409);
        $entry = TimetableEntry::whereKey($entryId)->where('timetable_id', $timetable->id)->where('school_id', $user->school_id)->firstOrFail();
        abort_if($entry->is_locked, 409, 'Locked entries cannot be deleted.');
        if ($entry->lesson_group_id) {
            TimetableEntry::where('timetable_id', $timetable->id)->where('lesson_group_id', $entry->lesson_group_id)->where('is_locked', false)->get()->each->delete();

            return;
        }
        $entry->delete();
    }
}
