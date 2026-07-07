<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Response\ApiResponder;
use Illuminate\Http\JsonResponse;

abstract class BaseApiController extends Controller
{
    /**
     * Success response.
     */
    protected function success(
        mixed $data = null,
        string $message = 'Request completed successfully.'
    ): JsonResponse {

        return ApiResponder::success(

            $data,

            $message

        );

    }

    /**
     * Created response.
     */
    protected function created(
        mixed $data = null,
        string $message = 'Resource created successfully.'
    ): JsonResponse {

        return ApiResponder::created(

            $data,

            $message

        );

    }

    /**
     * Bad request.
     */
    protected function badRequest(
        string $message = 'Bad request.'
    ): JsonResponse {

        return ApiResponder::error(

            $message,

            400

        );

    }

    /**
     * Unauthorized.
     */
    protected function unauthorized(
        string $message = 'Unauthorized.'
    ): JsonResponse {

        return ApiResponder::unauthorized(

            $message

        );

    }

    /**
     * Forbidden.
     */
    protected function forbidden(
        string $message = 'Forbidden.'
    ): JsonResponse {

        return ApiResponder::forbidden(

            $message

        );

    }

    /**
     * Not found.
     */
    protected function notFound(
        string $message = 'Resource not found.'
    ): JsonResponse {

        return ApiResponder::notFound(

            $message

        );

    }

    /**
     * Validation failed.
     */
    protected function validation(
        mixed $errors,
        string $message = 'Validation failed.'
    ): JsonResponse {

        return ApiResponder::validation(

            $errors,

            $message

        );

    }

    /**
     * Server error.
     */
    protected function error(
        string $message = 'Internal server error.'
    ): JsonResponse {

        return ApiResponder::serverError(

            $message

        );

    }
}
