<?php

namespace App\Services\Timetable;

use App\Models\Timetable;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TimetableValidationService
{
    public function __construct(private TimetableConflictDetectionService $conflicts) {}

    public function validate(User $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id) {
            $timetable = Timetable::whereKey($id)->where('school_id', $user->school_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($timetable->status, ['draft', 'invalid', 'valid'], true), 409);
            $timetable->update(['status' => 'validating']);
            $conflicts = $this->conflicts->detect($timetable);
            $blocking = collect($conflicts)->whereIn('severity', ['error', 'critical'])->values();
            $summary = ['blocking_conflicts' => $blocking->count(), 'warnings' => collect($conflicts)->where('severity', 'warning')->count(), 'scheduled_entries' => $timetable->entries()->where('is_deleted', false)->count()];
            $timetable->update(['status' => $blocking->isEmpty() ? 'valid' : 'invalid', 'validation_summary' => $summary, 'validated_at' => now()]);

            return ['status' => $timetable->status, 'conflicts' => $conflicts, 'summary' => $summary];
        });
    }
}
