<?php

namespace App\Services\Timetable;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RoomAllocationService
{
    public function compatible(string $schoolId, string $learningAreaId, string $timetableId, int $day, string $periodId): Collection
    {
        $occupied = DB::table('timetable_entries')->where('school_id', $schoolId)->where('timetable_id', $timetableId)->where('day_of_week', $day)->where('period_id', $periodId)->where('is_deleted', false)->whereNotNull('room_id')->pluck('room_id');

        return DB::table('rooms')->where('school_id', $schoolId)->where('active', true)->whereNotIn('id', $occupied)->whereNotExists(function ($query) use ($schoolId, $learningAreaId) {
            $query->selectRaw('1')->from('room_constraints')->whereColumn('room_constraints.room_id', 'rooms.id')->where('room_constraints.school_id', $schoolId)->where('room_constraints.active', true)->where('room_constraints.constraint_type', 'required_learning_area')->whereNotNull('room_constraints.learning_area_id')->where('room_constraints.learning_area_id', '!=', $learningAreaId);
        })->orderBy('room_code')->orderBy('room_name')->get();
    }
}
