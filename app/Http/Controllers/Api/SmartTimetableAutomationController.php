<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\TimetableEntryResource;
use App\Http\Resources\TimetableResource;
use App\Models\Timetable;
use App\Models\TimetableSubstitution;
use App\Services\Timetable\TimetableGenerationService;
use App\Services\Timetable\TimetablePublicationService;
use App\Services\Timetable\TimetableRepairService;
use App\Services\Timetable\TimetableSubstitutionService;
use App\Services\Timetable\TimetableVersionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SmartTimetableAutomationController extends BaseApiController
{
    public function __construct(private TimetableGenerationService $generation, private TimetableRepairService $repair, private TimetableVersionService $versions, private TimetableSubstitutionService $substitutions, private TimetablePublicationService $publication) {}

    public function generate(Request $request, string $timetable)
    {
        $data = $request->validate(['generation_type' => 'sometimes|in:full,repair,regenerate_unallocated,rebalance', 'preserve_existing_entries' => 'sometimes|boolean', 'allow_soft_constraint_violations' => 'sometimes|boolean', 'max_iterations' => 'sometimes|integer|min:1|max:20000', 'random_seed' => 'nullable|integer', 'focus_teacher_assignment_ids' => 'nullable|array|max:100', 'focus_teacher_assignment_ids.*' => 'uuid']);

        return $this->success($this->generation->generate(auth()->user(), $timetable, $data));
    }

    public function repair(Request $request, string $timetable, string $type)
    {
        $data = $request->validate(['teacher_assignment_ids' => 'nullable|array|max:100', 'teacher_assignment_ids.*' => 'uuid', 'entry_ids' => 'nullable|array|max:100', 'entry_ids.*' => 'uuid', 'max_changes' => 'sometimes|integer|min:1|max:2000', 'random_seed' => 'nullable|integer']);

        return $this->success($this->repair->repair(auth()->user(), $timetable, $data, $type));
    }

    public function createVersion(Request $request, string $timetable)
    {
        $data = $request->validate(['reason' => 'required|string|max:4000']);

        return $this->created(new TimetableResource($this->versions->create(auth()->user(), $timetable, $data['reason'])));
    }

    public function lock(Request $request, string $timetable, string $entry, bool $locked)
    {
        $data = $request->validate(['reason' => 'required|string|max:4000']);

        return $this->success(new TimetableEntryResource($this->versions->lock(auth()->user(), $timetable, $entry, $locked, $data['reason'])));
    }

    public function runs(string $timetable, ?string $run = null)
    {
        Timetable::whereKey($timetable)->where('school_id', auth()->user()->school_id)->firstOrFail();
        $query = DB::table('timetable_generation_runs')->where('school_id', auth()->user()->school_id)->where('timetable_id', $timetable)->select('id', 'generation_type', 'status', 'required_lessons', 'scheduled_lessons', 'unscheduled_lessons', 'hard_conflicts', 'soft_warnings', 'score', 'diagnostics', 'failed_reason', 'started_at', 'completed_at');

        return $this->success($run ? $query->where('id', $run)->firstOrFail() : $query->latest('created_at')->paginate(20));
    }

    public function unpublish(Request $request, string $timetable)
    {
        $reason = $request->validate(['reason' => 'required|string|max:4000'])['reason'];

        return $this->success(new TimetableResource($this->publication->unpublish(auth()->user(), $timetable, $reason)));
    }

    public function supersede(Request $request, string $timetable)
    {
        $reason = $request->validate(['reason' => 'required|string|max:4000'])['reason'];

        return $this->success(new TimetableResource($this->publication->supersede(auth()->user(), $timetable, $reason)));
    }

    public function suggestions(Request $request)
    {
        $data = $request->validate(['timetable_entry_id' => 'required|uuid', 'substitution_date' => 'required|date']);

        return $this->success($this->substitutions->suggestions(auth()->user(), $data['timetable_entry_id'], $data['substitution_date']));
    }

    public function createSubstitution(Request $request)
    {
        $data = $request->validate(['timetable_entry_id' => 'required|uuid', 'substitute_teacher_id' => 'required|uuid', 'substitution_date' => 'required|date', 'reason' => 'required|string|max:4000']);

        return $this->created($this->substitutions->create(auth()->user(), $data));
    }

    public function substitutions(?string $substitution = null)
    {
        $query = TimetableSubstitution::where('school_id', auth()->user()->school_id);

        return $this->success($substitution ? $query->whereKey($substitution)->firstOrFail() : $query->latest('created_at')->paginate(20));
    }

    public function substitutionAction(Request $request, string $substitution, string $action)
    {
        if ($action === 'approve') {
            return $this->success($this->substitutions->approve(auth()->user(), $substitution));
        }
        $reason = $request->validate(['reason' => 'required|string|max:4000'])['reason'];

        return $this->success($this->substitutions->cancel(auth()->user(), $substitution, $reason));
    }
}
