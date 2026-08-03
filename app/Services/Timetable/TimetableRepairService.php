<?php

namespace App\Services\Timetable;

use App\Models\Timetable;
use App\Models\User;

class TimetableRepairService
{
    public function __construct(private TimetableGenerationService $generation, private TimetableAuditService $audit) {}

    public function repair(User $user, string $id, array $options, string $type): array
    {
        $timetable = Timetable::whereKey($id)->where('school_id', $user->school_id)->whereIn('status', ['draft', 'invalid'])->firstOrFail();
        if ($timetable->status === 'invalid') {
            $timetable->update(['status' => 'draft']);
        }
        $result = $this->generation->generate($user, $id, ['generation_type' => $type, 'preserve_existing_entries' => true, 'max_iterations' => min((int) ($options['max_changes'] ?? 500), 2000), 'random_seed' => $options['random_seed'] ?? 1, 'focus_teacher_assignment_ids' => $options['teacher_assignment_ids'] ?? []]);
        $this->audit->record($user, $id, 'repair_performed', ['run_id' => $result['run_id'], 'new' => ['type' => $type]]);

        return $result;
    }
}
