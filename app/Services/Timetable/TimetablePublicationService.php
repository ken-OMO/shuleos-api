<?php

namespace App\Services\Timetable;

use App\Models\Timetable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TimetablePublicationService
{
    public function approve(User $user, string $id): Timetable
    {
        $timetable = Timetable::whereKey($id)->where('school_id', $user->school_id)->where('status', 'valid')->firstOrFail();
        $timetable->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);

        return $timetable;
    }

    public function publish(User $user, string $id): Timetable
    {
        return DB::transaction(function () use ($user, $id) {
            $timetable = Timetable::whereKey($id)->where('school_id', $user->school_id)->where('status', 'approved')->lockForUpdate()->firstOrFail();
            abort_if(DB::table('timetable_conflicts')->where('timetable_id', $id)->where('resolved', false)->whereIn('severity', ['error', 'critical'])->exists(), 409, 'Blocking conflicts prevent publication.');
            Timetable::where('school_id', $user->school_id)->where('academic_year_id', $timetable->academic_year_id)->where('term_id', $timetable->term_id)->where('timetable_profile_id', $timetable->timetable_profile_id)->where('status', 'published')->whereKeyNot($id)->update(['status' => 'archived', 'active' => false, 'archived_at' => now()]);
            $timetable->update(['status' => 'published', 'active' => true, 'published_by' => $user->id, 'published_at' => now()]);
            DB::table('timetable_publications')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'timetable_id' => $id, 'publication_status' => 'published', 'published_by' => $user->id, 'published_at' => now(), 'created_at' => now()]);

            return $timetable;
        });
    }

    public function archive(User $user, string $id): Timetable
    {
        $timetable = Timetable::whereKey($id)->where('school_id', $user->school_id)->whereIn('status', ['approved', 'published'])->firstOrFail();
        $timetable->update(['status' => 'archived', 'active' => false, 'archived_at' => now()]);

        return $timetable;
    }
}
