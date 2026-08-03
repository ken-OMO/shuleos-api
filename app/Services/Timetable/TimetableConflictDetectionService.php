<?php

namespace App\Services\Timetable;

use App\Models\Timetable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TimetableConflictDetectionService
{
    public function detect(Timetable $timetable): array
    {
        DB::table('timetable_conflicts')->where('school_id', $timetable->school_id)->where('timetable_id', $timetable->id)->where('resolved', false)->update(['resolved' => true, 'resolved_at' => now()]);
        $conflicts = [];
        foreach (['teacher_id' => 'teacher_clash', 'stream_id' => 'stream_clash', 'room_id' => 'room_clash'] as $column => $type) {
            $groups = DB::table('timetable_entries')->where('timetable_id', $timetable->id)->where('school_id', $timetable->school_id)->where('is_deleted', false)->whereNotNull($column)->select($column, 'day_of_week', 'period_id', DB::raw('COUNT(*) total'), DB::raw("string_agg(id::text, ',') entry_ids"))->groupBy($column, 'day_of_week', 'period_id')->havingRaw('COUNT(*) > 1')->get();
            foreach ($groups as $group) {
                $conflicts[] = $this->store($timetable, $type, 'error', str_replace('_', ' ', ucfirst($type)).' detected in one timetable slot.', ['entry_ids' => explode(',', $group->entry_ids)]);
            }
        }
        $allocations = DB::table('teacher_assignments')->where('school_id', $timetable->school_id)->where('academic_year_id', $timetable->academic_year_id)->where('term_id', $timetable->term_id)->where('active', true)->where('is_deleted', false)->get();
        foreach ($allocations as $assignment) {
            $scheduled = DB::table('timetable_entries')->where('timetable_id', $timetable->id)->where('teacher_assignment_id', $assignment->id)->where('is_deleted', false)->count();
            if ($scheduled !== (int) $assignment->lessons_per_week) {
                $conflicts[] = $this->store($timetable, $scheduled > $assignment->lessons_per_week ? 'overallocated_assignment' : 'underallocated_assignment', $scheduled > $assignment->lessons_per_week ? 'error' : 'warning', "Assignment requires {$assignment->lessons_per_week} lessons and has {$scheduled}.", ['teacher_assignment_id' => $assignment->id, 'required' => $assignment->lessons_per_week, 'scheduled' => $scheduled]);
            }
        }
        $brokenGroups = DB::table('timetable_entries')->where('timetable_id', $timetable->id)->where('school_id', $timetable->school_id)->where('is_deleted', false)->whereNotNull('lesson_group_id')->select('lesson_group_id', DB::raw('COUNT(*) total'), DB::raw('MIN(lesson_span) expected'))->groupBy('lesson_group_id')->havingRaw('COUNT(*) <> MIN(lesson_span)')->get();
        foreach ($brokenGroups as $group) {
            $conflicts[] = $this->store($timetable, 'broken_double_lesson', 'error', 'Double-lesson group is incomplete.', ['lesson_group_id' => $group->lesson_group_id, 'expected' => $group->expected, 'actual' => $group->total]);
        }

        return $conflicts;
    }

    private function store(Timetable $timetable, string $type, string $severity, string $description, array $metadata): array
    {
        $row = ['id' => (string) Str::uuid(), 'school_id' => $timetable->school_id, 'timetable_id' => $timetable->id, 'conflict_type' => $type, 'severity' => $severity, 'description' => $description, 'metadata' => json_encode($metadata), 'resolved' => false, 'detected_at' => now(), 'created_at' => now()];
        DB::table('timetable_conflicts')->insert($row);

        return $row;
    }
}
