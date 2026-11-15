<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\StudentElection\StudentElectionResultService;
use Illuminate\Http\Request;

class StudentElectionDeclarationController extends BaseApiController
{
    public function checks(Request $request, string $election)
    {
        return $this->success(app(StudentElectionResultService::class)->checks($request->user(), $election));
    }
}
