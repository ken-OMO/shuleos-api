<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\AssessmentTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class AssessmentTenantContextTest extends TestCase
{
    public function test_client_and_server_school_values_cannot_replace_authentication(): void
    {
        $schoolId = (string) Str::uuid();
        $request = Request::create('/', 'GET', ['school_id' => $schoolId]);
        $request->attributes->set('tenant_school_id', $schoolId);

        $this->assertContextForbidden($request);
    }

    public function test_authenticated_user_without_a_school_cannot_use_client_school(): void
    {
        $user = new User;
        $user->setRawAttributes(['id' => (string) Str::uuid(), 'school_id' => null]);
        auth()->setUser($user);

        $request = Request::create('/', 'POST', [
            'school_id' => (string) Str::uuid(),
        ]);

        $this->assertContextForbidden($request);
    }

    public function test_context_always_uses_the_authenticated_school(): void
    {
        $schoolId = $this->authenticateSchoolUser();

        foreach ([
            [],
            ['school_id' => $schoolId],
            ['school_id' => (string) Str::uuid()],
        ] as $input) {
            $request = Request::create('/', 'POST', $input);

            $this->assertSame(
                $schoolId,
                AssessmentTenantContext::schoolId($request)
            );
        }
    }

    public function test_matching_server_tenant_context_is_accepted(): void
    {
        $schoolId = $this->authenticateSchoolUser();
        $request = Request::create('/');
        $request->attributes->set('tenant_school_id', $schoolId);

        $this->assertSame($schoolId, AssessmentTenantContext::schoolId($request));
    }

    public function test_conflicting_server_tenant_context_is_rejected(): void
    {
        $this->authenticateSchoolUser();
        $request = Request::create('/');
        $request->attributes->set('tenant_school_id', (string) Str::uuid());

        $this->assertContextForbidden($request);
    }

    private function authenticateSchoolUser(): string
    {
        $schoolId = (string) Str::uuid();
        $user = new User;
        $user->setRawAttributes([
            'id' => (string) Str::uuid(),
            'school_id' => $schoolId,
        ]);
        auth()->setUser($user);

        return $schoolId;
    }

    private function assertContextForbidden(Request $request): void
    {
        try {
            AssessmentTenantContext::schoolId($request);
            $this->fail('Expected tenant context rejection.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
