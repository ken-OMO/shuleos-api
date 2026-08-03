<?php

namespace App\Services\Timetable;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class CurrentPeriodService
{
    public function current(User $user): array
    {
        $now = now();
        $timetable = DB::table('timetables')->where('school_id', $user->school_id)->where('status', 'published')->where('active', true)->latest('published_at')->first();
        if (! $timetable) {
            return ['current' => null, 'next' => null, 'school_open' => false];
        }
        $day = DB::table('timetable_days')->where('school_id', $user->school_id)->where('timetable_profile_id', $timetable->timetable_profile_id)->where('day_of_week', $now->dayOfWeekIso)->where('active', true)->first();
        if (! $day) {
            return ['current' => null, 'next' => null, 'school_open' => false];
        }
        $periods = DB::table('timetable_periods')->where('timetable_profile_id', $timetable->timetable_profile_id)->where('active', true)->orderBy('period_order')->get();
        $time = $now->format('H:i:s');
        $current = $periods->first(fn ($p) => $p->start_time <= $time && $p->end_time > $time);
        $next = $periods->first(fn ($p) => $p->start_time > $time);

        return ['current' => $current ? $this->period($current, $now) : null, 'next' => $next ? $this->period($next, $now) : null, 'school_open' => (bool) $current];
    }

    private function period(object $period, $now): array
    {
        $type = $period->is_teaching_period ? 'teaching' : ($period->is_break ? 'break' : ($period->is_lunch ? 'lunch' : ($period->is_assembly ? 'assembly' : ($period->is_games ? 'games' : ($period->is_club ? 'club' : 'other')))));

        return ['id' => $period->id, 'name' => $period->period_name, 'type' => $type, 'start_time' => $period->start_time, 'end_time' => $period->end_time, 'minutes_remaining' => max(0, $now->diffInMinutes($now->copy()->setTimeFromTimeString($period->end_time), false))];
    }
}
