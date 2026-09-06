<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ApiExceptionBoundaryTest extends TestCase
{
    public function test_unexpected_api_exception_returns_generic_500_without_leaking_internal_details(): void
    {
        $sensitiveMessage = 'SQLSTATE[08006] password=super-secret database=shuleos_test constraint=bed_allocations_active_bed_unique query=select * from secret_table path=C:\\secret\\config.php';

        Route::get('/api/__test/exception-boundary/unexpected', function () use ($sensitiveMessage): never {
            throw new RuntimeException($sensitiveMessage);
        });

        config([
            'app.debug' => false,
        ]);

        $response = $this->getJson(
            '/api/__test/exception-boundary/unexpected'
        );

        $response->assertStatus(500);

        $body = $response->getContent();

        foreach ([
            'SQLSTATE',
            'super-secret',
            'shuleos_test',
            'bed_allocations_active_bed_unique',
            'secret_table',
            'config.php',
            $sensitiveMessage,
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body
            );
        }
    }

    public function test_validation_exception_keeps_laravel_422_semantics(): void
    {
        Route::post('/api/__test/exception-boundary/validation', function (): never {
            throw ValidationException::withMessages([
                'reason' => [
                    'The reason is invalid.',
                ],
            ]);
        });

        $this->postJson(
            '/api/__test/exception-boundary/validation'
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'reason',
            ]);
    }

    public function test_http_exception_keeps_laravel_status_semantics(): void
    {
        Route::get('/api/__test/exception-boundary/not-found', function (): never {
            abort(404);
        });

        $this->getJson(
            '/api/__test/exception-boundary/not-found'
        )->assertNotFound();
    }
}
