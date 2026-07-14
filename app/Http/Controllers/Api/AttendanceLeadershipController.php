<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\AttendanceAnalyticsResource;
use App\Http\Resources\AttendanceRegisterResource;
use App\Services\Attendance\AttendanceAnalyticsService;

class AttendanceLeadershipController extends BaseApiController
{
    public function __construct(private AttendanceAnalyticsService $s) {}

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
}
