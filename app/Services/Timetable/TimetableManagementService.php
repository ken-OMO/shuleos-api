<?php

namespace App\Services\Timetable;

use App\Models\Timetable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TimetableManagementService
{
    public function create(User $user, array $data): Timetable
    {
        $profile = DB::table('timetable_profiles')->where('id', $data['timetable_profile_id'])->where('school_id', $user->school_id)->where('active', true)->first();
        $year = DB::table('academic_years')->where('id', $data['academic_year_id'])->where('school_id', $user->school_id)->first();
        $term = DB::table('terms')->where('id', $data['term_id'])->where('school_id', $user->school_id)->where('academic_year_id', $data['academic_year_id'])->first();
        if (! $profile || ! $year || ! $term) {
            throw ValidationException::withMessages(['scope' => 'Profile, academic year, and term must belong to this school and form a valid scope.']);
        }

        return Timetable::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'timetable_profile_id' => $profile->id, 'academic_year_id' => $year->id, 'term_id' => $term->id, 'timetable_name' => $data['timetable_name'], 'status' => 'draft', 'active' => true, 'created_by' => $user->id]);
    }

    public function update(User $user, string $id, array $data): Timetable
    {
        $timetable = Timetable::whereKey($id)->where('school_id', $user->school_id)->firstOrFail();
        abort_unless(in_array($timetable->status, ['draft', 'invalid'], true), 409, 'Timetable is read-only in its current state.');
        $timetable->update(['timetable_name' => $data['timetable_name']]);

        return $timetable;
    }
}
