<?php

namespace App\Services\Timetable;

use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TimetableVersionService
{
    public function __construct(private TimetableAuditService $audit) {}

    public function create(User $user, string $id, string $reason): Timetable
    {
        return DB::transaction(function () use ($user, $id, $reason) {
            $source = Timetable::whereKey($id)->where('school_id', $user->school_id)->whereIn('status', ['approved', 'published', 'archived'])->lockForUpdate()->firstOrFail();
            $copy = Timetable::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'timetable_profile_id' => $source->timetable_profile_id, 'academic_year_id' => $source->academic_year_id, 'term_id' => $source->term_id, 'timetable_name' => $source->timetable_name.' v'.((int) $source->version + 1), 'status' => 'draft', 'active' => true, 'created_by' => $user->id, 'version' => (int) $source->version + 1, 'parent_timetable_id' => $source->parent_timetable_id ?: $source->id, 'copied_from_timetable_id' => $source->id, 'revision_reason' => $reason]);
            foreach ($source->entries()->where('is_deleted', false)->get() as $entry) {
                $attributes = collect($entry->getAttributes())->except(['id', 'timetable_id', 'generation_run_id', 'generation_score', 'created_at', 'updated_at', 'deleted_at', 'deleted_by'])->all();
                TimetableEntry::create($attributes + ['timetable_id' => $copy->id, 'entry_status' => 'draft', 'created_by' => $user->id, 'updated_by' => $user->id]);
            }
            $this->audit->record($user, $copy->id, 'version_created', ['previous' => ['source_timetable_id' => $source->id], 'new' => ['version' => $copy->version]], $reason);

            return $copy;
        });
    }

    public function lock(User $user, string $timetableId, string $entryId, bool $locked, string $reason): TimetableEntry
    {
        $timetable = Timetable::whereKey($timetableId)->where('school_id', $user->school_id)->whereIn('status', ['draft', 'invalid', 'valid'])->firstOrFail();
        $entry = TimetableEntry::whereKey($entryId)->where('school_id', $user->school_id)->where('timetable_id', $timetable->id)->firstOrFail();
        $entry->update($locked ? ['is_locked' => true, 'locked_by' => $user->id, 'locked_at' => now(), 'lock_reason' => $reason] : ['is_locked' => false, 'locked_by' => null, 'locked_at' => null, 'lock_reason' => null]);
        $this->audit->record($user, $timetable->id, $locked ? 'entry_locked' : 'entry_unlocked', ['entry_id' => $entry->id], $reason);

        return $entry;
    }
}
