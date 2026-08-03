<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Success Response (200)
     */
    public static function success(
        string $message = 'Success',
        mixed $data = null
    ): JsonResponse {

        return response()->json([

            'success' => true,

            'message' => $message,

            'data' => $data,

        ], 200);
    }

    /**
     * Created Response (201)
     */
    public static function created(
        string $message,
        mixed $data = null
    ): JsonResponse {

        return response()->json([

            'success' => true,

            'message' => $message,

            'data' => $data,

        ], 201);
    }

    /**
     * Bad Request (400)
     */
    public static function badRequest(
        string $message
    ): JsonResponse {

        return response()->json([

            'success' => false,

            'message' => $message,

        ], 400);
    }

    /**
     * Unauthorized (401)
     */
    public static function unauthorized(
        string $message = 'Unauthorized.'
    ): JsonResponse {

        return response()->json([

            'success' => false,

            'message' => $message,

        ], 401);
    }

    /**
     * Forbidden (403)
     */
    public static function forbidden(
        string $message = 'Forbidden.'
    ): JsonResponse {

        return response()->json([

            'success' => false,

            'message' => $message,

        ], 403);
    }

    /**
     * Not Found (404)
     */
    public static function notFound(
        string $message
    ): JsonResponse {

        return response()->json([

            'success' => false,

            'message' => $message,

        ], 404);
    }

    /**
     * Validation Error (422)
     */
    public static function validation(
        mixed $errors,
        string $message = 'Validation failed.'
    ): JsonResponse {

        return response()->json([

            'success' => false,

            'message' => $message,

            'errors' => $errors,

        ], 422);
    }

    /**
     * Server Error (500)
     */
    public static function error(
        string $message = 'Internal server error.'
    ): JsonResponse {

        return response()->json([

            'success' => false,

            'message' => $message,

        ], 500);
    }
}
