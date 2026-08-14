<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\Auth\AuthenticationAuditService;
use App\Services\Auth\SchoolAdminActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private AuthContextService $authContext,
        private AuthenticationAuditService $audit,
        private SchoolAdminActivationService $activation,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::query()->where('username', $request->string('username')->toString())->first();
        if (! $this->canAttemptLogin($user) || ! Hash::check($request->string('password')->toString(), $user->password_hash)) {
            if ($user && $this->canAttemptLogin($user)) {
                $this->recordFailedAttempt($user);
            }
            $this->audit->record($request, 'authentication_login_failed', $user);

            return $this->unauthenticated();
        }

        $school = $user->school;
        if (! $school || ! $school->active || $school->is_deleted || in_array($school->lifecycle_state, ['suspended', 'locked', 'archived'], true)) {
            $this->audit->record($request, 'authentication_login_access_unavailable', $user);

            return $this->unavailable();
        }

        /*
         * Temporary first-login credentials fail closed once expired.
         *
         * Never issue a normal JWT from an expired bootstrap credential.
         */
        if ($user->temporaryPasswordExpired()) {
            $this->audit->record(
                $request,
                'authentication_temporary_password_expired',
                $user
            );

            return $this->unauthenticated();
        }

        /*
         * First-login / reset state must be completed before a normal
         * authenticated tenant session can exist.
         *
         * Phase 6A.6 establishes this JWT boundary first. The subsequent
         * activation challenge flow will replace this response with the
         * school-bound challenge details.
         */
        if ($user->requiresPasswordReset()) {
            $challenge = $this->activation->begin(
                $user,
                $request
            );

            $this->audit->record(
                $request,
                'authentication_first_login_activation_required',
                $user
            );

            return response()->json([
                'success' => true,
                'message' => 'Password activation is required.',
                'data' => $challenge,
            ], 202);
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'account_locked_until' => null,
            'last_login' => now(),
        ])->save();
        $this->audit->record($request, 'authentication_login_succeeded', $user);

        $token = JWTAuth::fromUser($user);

        return $this->tokenResponse($request, $user, $token);
    }

    public function verifyFirstLoginOtp(
        Request $request
    ): JsonResponse {
        $validator = Validator::make(
            $request->all(),
            [
                'challenge_id' => [
                    'required',
                    'uuid',
                ],

                'challenge_token' => [
                    'required',
                    'string',
                    'min:32',
                    'max:255',
                ],

                'otp' => [
                    'required',
                    'digits:6',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->activation->verifyOtp(
                $request->string(
                    'challenge_id'
                )->toString(),

                $request->string(
                    'challenge_token'
                )->toString(),

                $request->string(
                    'otp'
                )->toString(),

                $request
            );
        } catch (\RuntimeException) {
            return response()->json([
                'success' => false,
                'message' => 'Verification failed.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification successful. Password activation is required.',
            'data' => $result,
        ], 202);
    }

    public function activateFirstLogin(
        Request $request
    ): JsonResponse {
        $validator = Validator::make(
            $request->all(),
            [
                'challenge_id' => [
                    'required',
                    'uuid',
                ],

                'activation_token' => [
                    'required',
                    'string',
                    'min:32',
                    'max:255',
                ],

                'password' => [
                    'required',
                    'string',
                    'min:12',
                    'max:128',
                ],

                'password_confirmation' => [
                    'required',
                    'string',
                    'max:128',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (
            ! hash_equals(
                $request->string(
                    'password'
                )->toString(),

                $request->string(
                    'password_confirmation'
                )->toString()
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors' => [
                    'password' => [
                        'The password confirmation does not match.',
                    ],
                ],
            ], 422);
        }

        try {
            $user = $this->activation->activate(
                $request->string(
                    'challenge_id'
                )->toString(),

                $request->string(
                    'activation_token'
                )->toString(),

                $request->string(
                    'password'
                )->toString(),

                $request
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (\RuntimeException) {
            return response()->json([
                'success' => false,
                'message' => 'Activation failed.',
            ], 401);
        }

        $this->audit->record(
            $request,
            'authentication_first_login_activated',
            $user
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $safeUser = $this->safeUser(
            $request,
            $user
        );

        $tokenType = 'bearer';
        $expiresIn = (int) config(
            'jwt.ttl',
            60
        ) * 60;

        return response()->json([
            'success' => true,

            'message' => 'Account activated successfully.',

            'token' => $token,

            'token_type' => $tokenType,

            'expires_in' => $expiresIn,

            'user' => $safeUser,

            'data' => [
                'authenticated' => true,

                'activation_required' => false,

                'token' => $token,

                'token_type' => $tokenType,

                'expires_in' => $expiresIn,

                'user' => $safeUser,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (! $user) {
            return $this->unauthenticated();
        }

        $safeUser = $this->safeUser($request, $user);

        return response()->json([
            'success' => true,
            'user' => $safeUser,
            'data' => ['user' => $safeUser],
        ]);
    }

    public function logout(): JsonResponse
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => 'Successfully logged out',
            ]);
        } catch (Throwable) {
            return $this->unauthenticated();
        }
    }

    public function refresh(Request $request): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (! $user) {
                return $this->unauthenticated();
            }

            $newToken = JWTAuth::refresh(JWTAuth::getToken());

            return $this->tokenResponse($request, $user, $newToken);
        } catch (Throwable) {
            return $this->unauthenticated();
        }
    }

    private function tokenResponse(Request $request, User $user, string $token): JsonResponse
    {
        $safeUser = $this->safeUser($request, $user);
        $tokenType = 'bearer';
        $expiresIn = (int) config('jwt.ttl', 60) * 60;

        return response()->json([
            'success' => true,
            'token' => $token,
            'token_type' => $tokenType,
            'expires_in' => $expiresIn,
            'user' => $safeUser,
            'data' => [
                'user' => $safeUser,
                'token' => $token,
                'token_type' => $tokenType,
                'expires_in' => $expiresIn,
            ],
        ]);
    }

    private function safeUser(Request $request, User $user): array
    {
        return (new AuthenticatedUserResource($this->authContext->resolve($user)))->toArray($request);
    }

    private function canAttemptLogin(?User $user): bool
    {
        return $user
            && $user->active
            && ! $user->is_deleted
            && ! $user->suspended_at
            && ! $user->account_locked_until?->isFuture();
    }

    private function recordFailedAttempt(User $user): void
    {
        $attempts = (int) $user->failed_login_attempts + 1;
        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'last_failed_login' => now(),
            'account_locked_until' => $attempts >= 5 ? now()->addMinutes(15) : null,
        ])->save();
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Access is unavailable.'], 403);
    }
}
