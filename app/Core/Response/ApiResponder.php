<?php

declare(strict_types=1);

namespace App\Core\Response;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

final class ApiResponder
{
    /**
     * Prevent instantiation.
     */
    private function __construct() {}

    /**
     * Build a standardized payload.
     */
    private static function payload(
        bool $success,
        string $message,
        mixed $data = null,
        mixed $errors = null,
        array $meta = []
    ): array {

        return [

            'success' => $success,

            'message' => $message,

            'data' => $data,

            'errors' => $errors,

            'meta' => [

                'api_version' => config(
                    'app.api_version',
                    'v1'
                ),

                'timestamp' => CarbonImmutable::now()
                    ->toISOString(),

                'request_id' => request()->attributes
                    ->get('request_id'),

                ...$meta,

            ],

        ];
    }

    /**
     * Build JSON response.
     */
    private static function respond(
        bool $success,
        string $message,
        mixed $data = null,
        mixed $errors = null,
        int $status = 200,
        array $meta = []
    ): JsonResponse {

        return response()->json(

            self::payload(

                $success,

                $message,

                $data,

                $errors,

                $meta

            ),

            $status

        );

    }

    /**
     * Successful response.
     */
    public static function success(
        mixed $data = null,
        string $message = 'Request completed successfully.'
    ): JsonResponse {

        return self::respond(

            true,

            $message,

            $data

        );

    }

    /**
     * Resource created.
     */
    public static function created(
        mixed $data = null,
        string $message = 'Resource created successfully.'
    ): JsonResponse {

        return self::respond(

            true,

            $message,

            $data,

            null,

            201

        );

    }

    /**
     * Resource updated.
     */
    public static function updated(
        mixed $data = null,
        string $message = 'Resource updated successfully.'
    ): JsonResponse {

        return self::respond(

            true,

            $message,

            $data

        );

    }

    /**
     * Resource deleted.
     */
    public static function deleted(
        string $message = 'Resource deleted successfully.'
    ): JsonResponse {

        return self::respond(

            true,

            $message

        );

    }

    /**
     * Validation failed.
     */
    public static function validation(
        mixed $errors,
        string $message = 'Validation failed.'
    ): JsonResponse {

        return self::respond(

            false,

            $message,

            null,

            $errors,

            422

        );

    }

    /**
     * General error.
     */
    public static function error(
        string $message = 'An error occurred.',
        int $status = 400,
        mixed $errors = null
    ): JsonResponse {

        return self::respond(

            false,

            $message,

            null,

            $errors,

            $status

        );

    }

    /**
     * Resource not found.
     */
    public static function notFound(
        string $message = 'Resource not found.'
    ): JsonResponse {

        return self::error(

            $message,

            404

        );

    }

    /**
     * Unauthorized request.
     */
    public static function unauthorized(
        string $message = 'Unauthorized.'
    ): JsonResponse {

        return self::error(

            $message,

            401

        );

    }

    /**
     * Forbidden request.
     */
    public static function forbidden(
        string $message = 'Forbidden.'
    ): JsonResponse {

        return self::error(

            $message,

            403

        );

    }

    /**
     * Internal server error.
     */
    public static function serverError(
        string $message = 'Internal server error.'
    ): JsonResponse {

        return self::error(

            $message,

            500

        );

    }

    /**
     * Paginated response.
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        string $message = 'Request completed successfully.'
    ): JsonResponse {

        return self::respond(

            true,

            $message,

            $paginator->items(),

            null,

            200,

            [

                'pagination' => [

                    'current_page' => $paginator->currentPage(),

                    'last_page' => $paginator->lastPage(),

                    'per_page' => $paginator->perPage(),

                    'total' => $paginator->total(),

                    'from' => $paginator->firstItem(),

                    'to' => $paginator->lastItem(),

                    'has_more_pages' => $paginator->hasMorePages(),

                ],

            ]

        );

    }

    /**
     * Empty response.
     */
    public static function noContent(): JsonResponse
    {
        return response()->json(

            null,

            204

        );
    }
}
