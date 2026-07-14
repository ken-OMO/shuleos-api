<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\Attendance\AttendanceReadService;

class AttendanceLearnerController extends BaseApiController
{
    public function __construct(private AttendanceReadService $s) {}

    public function index()
    {
        return $this->success($this->s->learner(auth()->user())->paginate(30));
    }

    public function summary()
    {
        return $this->success($this->s->summary($this->s->learner(auth()->user())));
    }

    public function history()
    {
        return $this->index();
    }
}
