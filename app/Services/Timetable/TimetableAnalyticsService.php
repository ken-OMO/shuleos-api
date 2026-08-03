<?php

namespace App\Services\Timetable;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class TimetableAnalyticsService
{
    public function summary(User $user): array
    {
        $timetable = DB::table('timetables')->where('school_id', $user->school_id)->whereIn('status', ['valid', 'approved', 'published'])->latest('created_at')->first();
        if (! $timetable) {
            return ['available' => false];
        }
        $entries = DB::table('timetable_entries')->where('school_id', $user->school_id)->where('timetable_id', $timetable->id)->where('is_deleted', false);
        $required = DB::table('teacher_assignments')->where('school_id', $user->school_id)->where('academic_year_id', $timetable->academic_year_id)->where('term_id', $timetable->term_id)->where('active', true)->where('is_deleted', false)->sum('lessons_per_week');
        $scheduled = (clone $entries)->count();
        $runs = DB::table('timetable_generation_runs')->where('school_id', $user->school_id)->where('timetable_id', $timetable->id);
        $completedRuns = (clone $runs)->whereIn('status', ['completed', 'completed_with_warnings'])->count();
        $finishedRuns = (clone $runs)->whereIn('status', ['completed', 'completed_with_warnings', 'failed'])->count();
        $locked = (clone $entries)->where('is_locked', true)->count();

        return ['available' => true, 'timetable_id' => $timetable->id, 'required_weekly_lessons' => (int) $required, 'scheduled_weekly_lessons' => $scheduled, 'unscheduled_lessons' => max(0, $required - $scheduled), 'completeness_percentage' => $required > 0 ? round($scheduled / $required * 100, 2) : null, 'generation_success_rate' => $finishedRuns > 0 ? round($completedRuns / $finishedRuns * 100, 2) : null, 'average_generation_score' => (clone $runs)->whereIn('status', ['completed', 'completed_with_warnings'])->avg('score'), 'teacher_workload' => (clone $entries)->selectRaw('teacher_id, COUNT(*) lessons')->groupBy('teacher_id')->get(), 'room_utilization' => (clone $entries)->whereNotNull('room_id')->selectRaw('room_id, COUNT(*) lessons')->groupBy('room_id')->get(), 'unresolved_conflicts' => DB::table('timetable_conflicts')->where('school_id', $user->school_id)->where('timetable_id', $timetable->id)->where('resolved', false)->count(), 'locked_entry_percentage' => $scheduled > 0 ? round($locked / $scheduled * 100, 2) : null, 'substitutions' => DB::table('timetable_substitutions as substitution')->join('timetable_entries as entry', 'entry.id', '=', 'substitution.timetable_entry_id')->where('substitution.school_id', $user->school_id)->where('entry.timetable_id', $timetable->id)->count(), 'revisions' => DB::table('timetables')->where('school_id', $user->school_id)->where(fn ($query) => $query->where('parent_timetable_id', $timetable->parent_timetable_id ?: $timetable->id)->orWhere('copied_from_timetable_id', $timetable->id))->count()];
    }
}
