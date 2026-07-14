<?php

namespace App\Services\Attendance;

use App\Models\LearnerAttendance;
use App\Models\User;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\ParentPortal\ParentPortalAccessService;

class AttendanceReadService
{
    public function __construct(private AttendanceStatusService $statuses, private LearnerPortalAccessService $learners, private ParentPortalAccessService $parents) {}

    public function learner(User $u)
    {
        $l = $this->learners->learner($u);

        return $this->query($u->school_id, $l->id);
    }

    public function parent(User $u, string $learner)
    {
        $l = $this->parents->requireLinkedLearner($u, $learner);

        return $this->query($u->school_id, $l->id);
    }

    public function query(string $school, string $learner)
    {
        return LearnerAttendance::where('school_id', $school)->where('learner_id', $learner)->where('finalized', true)->whereHas('register', fn ($q) => $q->where('status', 'finalized'))->with('attendanceStatus', 'attendanceSession', 'register')->orderByDesc('attendance_date');
    }

    public function summary($query): array
    {
        $rows = (clone $query)->get();
        $possible = 0;
        $attended = 0;
        $counts = [];
        foreach ($rows as $row) {
            $code = $row->attendanceStatus?->status_code ?? '';
            $category = $this->statuses->category($code);
            $counts[$category] = ($counts[$category] ?? 0) + 1;
            if ($this->statuses->denominator($code)) {
                $possible++;
            }if ($this->statuses->attended($code)) {
                $attended++;
            }
        }

        return ['attended' => $attended, 'total_possible' => $possible, 'attendance_percentage' => $possible ? round($attended / $possible * 100, 2) : null, 'categories' => $counts];
    }
}
