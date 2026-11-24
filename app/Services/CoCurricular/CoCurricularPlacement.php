<?php

namespace App\Services\CoCurricular;

use App\Services\Homework\EnterpriseHomeworkAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CoCurricularPlacement
{
    public const KINDS = ['club', 'house', 'society'];

    public function authorize(string $schoolId): void
    {
        abort_unless(app(EnterpriseHomeworkAccess::class)->allows($schoolId), 403, 'Co-curricular Management requires an eligible Enterprise subscription.');
    }

    public function save(string $schoolId, string $learnerId, array $groups): void
    {
        if ($groups === []) {
            return;
        }

        $this->authorize($schoolId);
        $learner = DB::table('learners')->where('school_id', $schoolId)->where('id', $learnerId)
            ->where('is_deleted', false)->lockForUpdate()->first();
        abort_unless($learner, 404);

        foreach (self::KINDS as $kind) {
            if (! array_key_exists($kind, $groups)) {
                continue;
            }

            $groupId = $groups[$kind];
            if ($groupId !== null && ! DB::table('co_curricular_groups')->where('school_id', $schoolId)
                ->where('kind', $kind)->where('id', $groupId)->whereNull('archived_at')->sharedLock()->first()) {
                throw ValidationException::withMessages(['co_curricular.'.$kind => 'Select an active group of this type from your school.']);
            }

            $current = DB::table('co_curricular_placements')->where('school_id', $schoolId)
                ->where('learner_id', $learnerId)->where('kind', $kind)->whereNull('ended_at');

            if ($current->value('group_id') === $groupId) {
                continue;
            }

            $at = now();
            $current->update(['ended_at' => $at]);
            if ($groupId !== null) {
                DB::table('co_curricular_placements')->insert([
                    'id' => (string) Str::uuid(), 'school_id' => $schoolId, 'learner_id' => $learnerId,
                    'group_id' => $groupId, 'kind' => $kind, 'started_at' => $at,
                ]);
            }
        }
    }
}
