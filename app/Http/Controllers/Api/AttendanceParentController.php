<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\Attendance\AttendanceReadService;

class AttendanceParentController extends BaseApiController
{
    public function __construct(private AttendanceReadService $s) {}

    public function index(string $learner)
    {
        return $this->success($this->s->parent(auth()->user(), $learner)->paginate(30));
    }

    public function summary(string $learner)
    {
        return $this->success($this->s->summary($this->s->parent(auth()->user(), $learner)));
    }

    public function history(string $learner)
    {
        return $this->index($learner);
    }
}
