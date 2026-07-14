<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\TimetableEntryResource;
use App\Http\Resources\TimetableResource;
use App\Models\Timetable;
use App\Services\Timetable\CurrentPeriodService;
use App\Services\Timetable\TimetableAnalyticsService;
use App\Services\Timetable\TimetableEntryService;
use App\Services\Timetable\TimetableManagementService;
use App\Services\Timetable\TimetablePublicationService;
use App\Services\Timetable\TimetableValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SmartTimetableController extends BaseApiController
{
    public function __construct(private TimetableManagementService $management, private TimetableEntryService $entries, private TimetableValidationService $validation, private TimetablePublicationService $publication, private TimetableAnalyticsService $analytics, private CurrentPeriodService $period) {}

    public function index()
    {
        return $this->success(TimetableResource::collection(Timetable::where('school_id', auth()->user()->school_id)->where('is_deleted', false)->latest('created_at')->paginate(30)));
    }

    public function show(string $timetable)
    {
        return $this->success(new TimetableResource(Timetable::whereKey($timetable)->where('school_id', auth()->user()->school_id)->where('is_deleted', false)->with('entries.period', 'entries.assignment')->firstOrFail()));
    }

    public function store(Request $request)
    {
        return $this->created(new TimetableResource($this->management->create(auth()->user(), $request->validate(['timetable_profile_id' => 'required|uuid', 'academic_year_id' => 'required|uuid', 'term_id' => 'required|uuid', 'timetable_name' => 'required|string|max:255']))));
    }

    public function update(Request $request, string $timetable)
    {
        return $this->success(new TimetableResource($this->management->update(auth()->user(), $timetable, $request->validate(['timetable_name' => 'required|string|max:255']))));
    }

    public function entry(Request $request, string $timetable, ?string $entry = null)
    {
        return $this->created(new TimetableEntryResource($this->entries->save(auth()->user(), $timetable, $request->validate(['teacher_assignment_id' => 'required|uuid', 'timetable_day_id' => 'required|uuid', 'period_id' => 'required|uuid', 'room_id' => 'nullable|uuid', 'is_double_lesson' => 'sometimes|boolean', 'remarks' => 'nullable|string|max:2000']), $entry)));
    }

    public function deleteEntry(string $timetable, string $entry)
    {
        $this->entries->delete(auth()->user(), $timetable, $entry);

        return $this->success();
    }

    public function validateTimetable(string $timetable)
    {
        return $this->success($this->validation->validate(auth()->user(), $timetable));
    }

    public function conflicts(string $timetable)
    {
        Timetable::whereKey($timetable)->where('school_id', auth()->user()->school_id)->firstOrFail();

        return $this->success(DB::table('timetable_conflicts')->where('school_id', auth()->user()->school_id)->where('timetable_id', $timetable)->where('resolved', false)->get());
    }

    public function grid(string $timetable)
    {
        $model = Timetable::whereKey($timetable)->where('school_id', auth()->user()->school_id)->firstOrFail();

        return $this->success(TimetableEntryResource::collection($model->entries()->where('is_deleted', false)->with('period', 'assignment', 'room')->orderBy('day_of_week')->orderBy('period_id')->get()));
    }

    public function approve(string $timetable)
    {
        return $this->success(new TimetableResource($this->publication->approve(auth()->user(), $timetable)));
    }

    public function publish(string $timetable)
    {
        return $this->success(new TimetableResource($this->publication->publish(auth()->user(), $timetable)));
    }

    public function archive(string $timetable)
    {
        return $this->success(new TimetableResource($this->publication->archive(auth()->user(), $timetable)));
    }

    public function analytics()
    {
        return $this->success($this->analytics->summary(auth()->user()));
    }

    public function currentPeriod()
    {
        return $this->success($this->period->current(auth()->user()));
    }
}
