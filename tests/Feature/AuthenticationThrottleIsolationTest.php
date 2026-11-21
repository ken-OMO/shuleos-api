<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthenticationThrottleIsolationTest extends TestCase
{
    public function test_each_authentication_step_retains_its_own_rate_limit(): void
    {
        $steps = [
            ['/api/auth/login', 5],
            ['/api/auth/first-login/verify-otp', 10],
            ['/api/auth/first-login/activate', 5],
            ['/api/platform/auth/login', 5],
            ['/api/platform/auth/verify-otp', 10],
            ['/api/platform/auth/activate', 5],
        ];

        foreach ($steps as [$path, $limit]) {
            // Invalid empty input reaches validation, without creating challenges or mail.
            for ($attempt = 0; $attempt < $limit; $attempt++) {
                $this->postJson($path, [])->assertUnprocessable();
            }
            $this->postJson($path, [])->assertStatus(429);
        }
    }
}
