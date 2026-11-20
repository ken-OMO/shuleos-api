<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\Platform\PlatformDashboardReadService;
use Illuminate\Http\Request;

class PlatformDashboardReadController extends BaseApiController
{
    public function tasks(Request $request, PlatformDashboardReadService $reads)
    {
        $this->rejectSchoolSelector($request);

        return $this->success($reads->tasks($request->user()));
    }

    public function alerts(Request $request, PlatformDashboardReadService $reads)
    {
        $this->rejectSchoolSelector($request);

        return $this->success($reads->alerts($request->user()));
    }

    private function rejectSchoolSelector(Request $request): void
    {
        abort_if($request->query->has('school_id') || $request->hasHeader('X-School-Id'), 422, 'Platform reads do not accept a school selector.');
    }
}
