<?php

namespace App\Services\Timetable;

use Illuminate\Support\Facades\DB;

class TimetableWorkloadService
{
    public function score(string $school, string $timetable, object $assignment, int $day, object $period): array
    {
        $teacherDaily = DB::table('timetable_entries')->where('timetable_id', $timetable)->where('teacher_id', $assignment->teacher_id)->where('day_of_week', $day)->where('is_deleted', false)->count();
        $areaDaily = DB::table('timetable_entries')->where('timetable_id', $timetable)->where('stream_id', $assignment->stream_id)->where('learning_area_id', $assignment->learning_area_id)->where('day_of_week', $day)->where('is_deleted', false)->count();
        $latePenalty = max(0, $period->period_order - 6);
        $preference = 0;
        $softViolations = [];
        $constraints = DB::table('timetable_constraints')->where('school_id', $school)->where('active', true)->where('is_hard', false)->where(fn ($query) => $query->where('scope_id', $assignment->teacher_id)->orWhere('scope_id', $assignment->learning_area_id)->orWhere('scope_id', $assignment->id))->get();
        foreach ($constraints as $constraint) {
            $configuration = json_decode($constraint->configuration ?: '{}', true);
            $periodIds = $configuration['period_ids'] ?? [];
            if ($constraint->constraint_type === 'preferred_period' && in_array($period->id, $periodIds, true)) {
                $preference += 12;
            }
            if ($constraint->constraint_type === 'avoid_period' && in_array($period->id, $periodIds, true)) {
                $preference -= 12;
                $softViolations[] = 'avoid_period';
            }
        }
        $score = 100 - ($teacherDaily * 8) - ($areaDaily * 15) - $latePenalty + $preference;

        return ['score' => $score, 'factors' => ['teacher_daily_load_penalty' => $teacherDaily * 8, 'same_area_day_penalty' => $areaDaily * 15, 'late_period_penalty' => $latePenalty, 'preference_adjustment' => $preference], 'violated_soft_constraints' => $softViolations];
    }
}
