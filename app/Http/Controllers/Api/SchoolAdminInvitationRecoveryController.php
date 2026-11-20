<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Platform\SchoolAdminInvitationRecovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolAdminInvitationRecoveryController extends Controller
{
    public function __invoke(Request $request, string $school, string $user, SchoolAdminInvitationRecovery $recovery): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $recovery->resend($request->user(), $school, $user),
        ]);
    }
}
