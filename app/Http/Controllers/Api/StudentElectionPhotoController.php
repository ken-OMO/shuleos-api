<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\StudentElection\StudentElectionPhotoService;
use Illuminate\Http\Request;

class StudentElectionPhotoController extends BaseApiController
{
    public function __construct(private readonly StudentElectionPhotoService $photos) {}

    public function upload(Request $request, string $learner)
    {
        $v = $request->validate(['photo' => 'required|file|max:5120', 'expected_version' => 'nullable|uuid', 'identity_attested' => 'required|accepted']);

        return response()->json(['success' => true, 'data' => $this->photos->upload($request->user(), $learner, $v['expected_version'] ?? null, $request->file('photo'), true)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function profile(Request $request, string $learner)
    {
        return response()->json(['success' => true, 'data' => $this->photos->profile($request->user(), $learner)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function profileImage(Request $request, string $learner)
    {
        return $this->photos->profileImage($request->user(), $learner);
    }

    public function applicationImage(Request $request, string $election, string $application)
    {
        return $this->photos->applicationImage($request->user(), $election, $application);
    }

    public function campaignImage(Request $request, string $election, string $position, string $candidate)
    {
        return $this->photos->campaignImage($request->user(), $election, $position, $candidate);
    }
}
