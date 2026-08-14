<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PlatformAuthController extends Controller
{
    public function __construct(
        private readonly PlatformAuthenticationService $authentication,
    ) {}

    public function login(
        Request $request
    ): JsonResponse {
        $validator = Validator::make(
            $request->all(),
            [
                'identifier' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'password' => [
                    'required',
                    'string',
                    'max:4096',
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->validationFailure(
                $validator->errors()->toArray()
            );
        }

        try {
            $challenge = $this->authentication->begin(
                $request->string(
                    'identifier'
                )->toString(),

                $request->string(
                    'password'
                )->toString(),

                $request
            );
        } catch (RuntimeException) {
            return $this->authenticationFailure();
        }

        return response()->json([
            'success' => true,

            'message' => 'Verification code sent.',

            'data' => [
                'challenge_id' => $challenge[
                    'challenge_id'
                ],

                'challenge_token' => $challenge[
                    'challenge_token'
                ],

                'purpose' => $challenge[
                    'purpose'
                ],

                'expires_in' => $challenge[
                    'expires_in'
                ],

                'destination' => $challenge[
                    'destination'
                ],

                'authenticated' => false,
            ],
        ], 202);
    }

    public function verifyOtp(
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
            return $this->validationFailure(
                $validator->errors()->toArray()
            );
        }

        try {
            $result = $this->authentication->verifyOtp(
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
        } catch (RuntimeException) {
            return response()->json([
                'success' => false,
                'message' => 'Verification failed.',
            ], 401);
        }

        if ($result['activation_required']) {
            return response()->json([
                'success' => true,

                'message' => 'Verification successful. Password activation is required.',

                'data' => $result,
            ], 202);
        }

        return response()->json([
            'success' => true,

            'message' => 'Authentication successful.',

            'token' => $result['token'],

            'token_type' => $result[
                'token_type'
            ],

            'expires_in' => $result[
                'expires_in'
            ],

            'user' => $result['user'],

            'data' => $result,
        ]);
    }

    public function activate(
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
            return $this->validationFailure(
                $validator->errors()->toArray()
            );
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
            return $this->validationFailure([
                'password' => [
                    'The password confirmation does not match.',
                ],
            ]);
        }

        try {
            $result = $this->authentication
                ->activateFirstLogin(
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
            return $this->validationFailure(
                $exception->errors()
            );
        } catch (RuntimeException) {
            return response()->json([
                'success' => false,
                'message' => 'Activation failed.',
            ], 401);
        }

        return response()->json([
            'success' => true,

            'message' => 'Platform Owner activated successfully.',

            'token' => $result['token'],

            'token_type' => $result[
                'token_type'
            ],

            'expires_in' => $result[
                'expires_in'
            ],

            'user' => $result['user'],

            'data' => $result,
        ]);
    }

    private function authenticationFailure(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Authentication failed.',
        ], 401);
    }

    private function validationFailure(
        array $errors
    ): JsonResponse {
        return response()->json([
            'success' => false,

            'message' => 'The given data was invalid.',

            'errors' => $errors,
        ], 422);
    }
}
