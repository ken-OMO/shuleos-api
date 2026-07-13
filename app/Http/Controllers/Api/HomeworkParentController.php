<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\Homework\HomeworkParentService;

class HomeworkParentController extends BaseApiController
{
    public function __construct(private HomeworkParentService $s) {}

    public function index(string $learner)
    {
        return $this->success($this->s->records(auth()->user(), $learner));
    }

    public function show(string $learner, string $assignment)
    {
        return $this->success($this->s->record(auth()->user(), $learner, $assignment));
    }
}
