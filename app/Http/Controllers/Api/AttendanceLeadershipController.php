<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\AttendanceAnalyticsResource;
use App\Http\Resources\AttendanceRegisterResource;
use App\Services\Attendance\AttendanceAnalyticsService;
use App\Services\Attendance\AttendanceIntelligenceService;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\Request;

class AttendanceLeadershipController extends BaseApiController
{
    public function __construct(
        private AttendanceAnalyticsService $s,
        private AttendanceService $attendance,
        private AttendanceIntelligenceService $intelligence,
    ) {}

    public function index()
    {
        return $this->success(AttendanceRegisterResource::collection($this->s->registers(auth()->user())->paginate(30)));
    }

    public function analytics()
    {
        return $this->success(new AttendanceAnalyticsResource($this->s->summary(auth()->user())));
    }

    public function absentees()
    {
        return $this->success($this->s->chronic(auth()->user()));
    }

    public function completion()
    {
        return $this->analytics();
    }

    public function show(string $register)
    {
        return $this->success(new AttendanceRegisterResource($this->s->registers(auth()->user())->whereKey($register)->with('session', 'records.attendanceStatus')->firstOrFail()));
    }

    public function correct(Request $request, string $register)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:4000',
            'marks' => 'required|array|min:1',
            'marks.*.learner_id' => 'required|uuid',
            'marks.*.attendance_status_id' => 'required_without:marks.*.status_code|nullable|uuid',
            'marks.*.status_code' => 'required_without:marks.*.attendance_status_id|nullable|string',
            'marks.*.late_minutes' => 'nullable|integer|min:1',
            'marks.*.remarks' => 'nullable|string|max:2000',
        ]);

        $corrected = $this->attendance->leadershipCorrect(auth()->user(), $register, $data['marks'], $data['reason']);
        $this->intelligence->generate(auth()->user()->school_id);

        return $this->success(new AttendanceRegisterResource($corrected));
    }
}
