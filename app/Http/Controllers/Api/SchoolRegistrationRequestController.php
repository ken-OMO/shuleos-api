<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Requests\PublicSchoolRegistrationRequest;
use App\Services\Platform\SchoolRegistrationRequestService;
use Illuminate\Http\Request;

class SchoolRegistrationRequestController extends BaseApiController
{
    public function __construct(private SchoolRegistrationRequestService $registrations) {}

    public function store(PublicSchoolRegistrationRequest $request)
    {
        $this->registrations->submit($request->validated());

        return response()->json(['success' => true, 'message' => 'Registration requests are reviewed before accounts are created. An administrator invitation is sent only after approval.'], 202);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['status' => 'sometimes|in:pending,approved,rejected', 'page' => 'sometimes|integer|min:1']);

        return $this->success($this->registrations->index($request->user(), $data['status'] ?? 'pending'));
    }

    public function approve(Request $request, string $registration)
    {
        return $this->success($this->registrations->review($request->user(), $registration, 'approve', null));
    }

    public function reject(Request $request, string $registration)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $reason = trim(strip_tags($data['reason']));
        validator(['reason' => $reason], ['reason' => 'required|string|max:500'])->validate();

        return $this->success($this->registrations->review($request->user(), $registration, 'reject', $reason));
    }
}
