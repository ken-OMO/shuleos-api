<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\AttendanceRegisterResource;
use App\Models\AttendanceSession;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\Request;

class AttendanceTeacherController extends BaseApiController
{
    public function __construct(private AttendanceService $s) {}

    public function sessions()
    {
        return $this->success(AttendanceSession::where('school_id', auth()->user()->school_id)->where('active', true)->orderBy('session_order')->get());
    }

    public function index()
    {
        return $this->success(AttendanceRegisterResource::collection($this->s->ownQuery(auth()->user())->with('session')->paginate(20)));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['teacher_assignment_id' => 'required|uuid', 'attendance_session_id' => 'required|uuid', 'attendance_date' => 'required|date', 'lesson_period' => 'nullable|string|max:50', 'register_type' => 'required|in:daily,lesson']);

        return $this->created(new AttendanceRegisterResource($this->s->open(auth()->user(), $d)));
    }

    public function show(string $register)
    {
        return $this->success(new AttendanceRegisterResource($this->s->ownQuery(auth()->user())->whereKey($register)->with('session', 'records.learner', 'records.attendanceStatus')->firstOrFail()));
    }

    public function draft(Request $r, string $register)
    {
        $d = $r->validate(['marks' => 'required|array|min:1', 'marks.*.learner_id' => 'required|uuid', 'marks.*.attendance_status_id' => 'nullable|uuid', 'marks.*.status_code' => 'nullable|string|max:30', 'marks.*.remarks' => 'nullable|string|max:2000', 'marks.*.late_minutes' => 'nullable|integer|min:1']);

        return $this->success(new AttendanceRegisterResource($this->s->save(auth()->user(), $register, $d['marks'])));
    }

    public function finalize(string $register)
    {
        return $this->success(new AttendanceRegisterResource($this->s->finalize(auth()->user(), $register)));
    }

    public function reopen(Request $r, string $register)
    {
        $d = $r->validate(['reason' => 'required|string|max:4000']);

        return $this->success($this->s->reopen(auth()->user(), $register, $d['reason']));
    }

    public function correct(Request $r, string $register)
    {
        $d = $r->validate(['reason' => 'required|string|max:4000', 'marks' => 'required|array|min:1', 'marks.*.learner_id' => 'required|uuid', 'marks.*.attendance_status_id' => 'nullable|uuid', 'marks.*.status_code' => 'nullable|string', 'marks.*.remarks' => 'nullable|string', 'marks.*.late_minutes' => 'nullable|integer|min:1']);

        return $this->success($this->s->save(auth()->user(), $register, $d['marks'], $d['reason']));
    }

    public function cancel(string $register)
    {
        $this->s->cancel(auth()->user(), $register);

        return $this->success(null);
    }
}
