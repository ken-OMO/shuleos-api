<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireOperationalSchoolSetup;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SchoolOperationalSetupGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_incomplete_school_is_denied_operational_access(): void
    {
        [$school, $user] = $this->schoolUser();

        $response = $this->throughGate($user, $school);

        $response
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'Initial school setup must be completed before this operation.'
            );
    }

    public function test_complete_school_passes_operational_gate(): void
    {
        [$school, $user] = $this->schoolUser();

        $this->completeSetup($school);

        $response = $this->throughGate($user, $school);

        $response
            ->assertOk()
            ->assertJsonPath('passed', true);
    }

    public function test_gate_fails_closed_without_authenticated_school_context(): void
    {
        $middleware = app(RequireOperationalSchoolSetup::class);

        $request = Request::create('/internal-operational-test', 'POST');

        $response = $middleware->handle(
            $request,
            fn () => response()->json(['passed' => true])
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true);

        $this->assertFalse($payload['success']);
        $this->assertSame('School context not found.', $payload['message']);
    }

    public function test_gate_fails_closed_when_tenant_context_is_missing(): void
    {
        [$school, $user] = $this->schoolUser();

        $this->completeSetup($school);

        auth()->guard()->setUser($user);

        $request = Request::create(
            '/internal-operational-test',
            'POST'
        );

        $request->setUserResolver(fn () => $user);

        $middleware = app(
            RequireOperationalSchoolSetup::class
        );

        $response = $middleware->handle(
            $request,
            fn () => response()->json([
                'passed' => true,
            ])
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode()
        );

        $payload = json_decode(
            $response->getContent(),
            true
        );

        $this->assertFalse($payload['success']);

        $this->assertSame(
            'School context not found.',
            $payload['message']
        );
    }

    public function test_another_schools_setup_cannot_satisfy_the_gate(): void
    {
        [$school, $user] = $this->schoolUser();
        [$otherSchool] = $this->schoolUser();

        $this->completeSetup($otherSchool);

        $response = $this->throughGate($user, $school);

        $response
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_inactive_academic_year_does_not_satisfy_gate(): void
    {
        [$school, $user] = $this->schoolUser();

        $setup = $this->completeSetup($school);

        DB::table('academic_years')
            ->where('id', $setup['academic_year_id'])
            ->update(['active' => false]);

        $this->throughGate($user, $school)->assertStatus(403);
    }

    public function test_inactive_term_does_not_satisfy_gate(): void
    {
        [$school, $user] = $this->schoolUser();

        $setup = $this->completeSetup($school);

        DB::table('terms')
            ->where('id', $setup['term_id'])
            ->update(['active' => false]);

        $this->throughGate($user, $school)->assertStatus(403);
    }

    public function test_inactive_grade_does_not_satisfy_gate(): void
    {
        [$school, $user] = $this->schoolUser();

        $setup = $this->completeSetup($school);

        DB::table('grades')
            ->where('id', $setup['grade_id'])
            ->update(['active' => false]);

        $this->throughGate($user, $school)->assertStatus(403);
    }

    public function test_inactive_stream_does_not_satisfy_gate(): void
    {
        [$school, $user] = $this->schoolUser();

        $setup = $this->completeSetup($school);

        DB::table('streams')
            ->where('id', $setup['stream_id'])
            ->update(['active' => false]);

        $this->throughGate($user, $school)->assertStatus(403);
    }

    public function test_denial_response_does_not_disclose_missing_setup_component(): void
    {
        [$school, $user] = $this->schoolUser();

        $response = $this->throughGate($user, $school);

        $response->assertStatus(403);

        $content = $response->getContent();

        $this->assertStringNotContainsString('academic_year', $content);
        $this->assertStringNotContainsString('current_term', $content);
        $this->assertStringNotContainsString('grades', $content);
        $this->assertStringNotContainsString('streams', $content);
        $this->assertStringNotContainsString('school_profile', $content);
    }

    public function test_setup_status_route_does_not_have_operational_gate(): void
    {
        $route = $this->routeFor('GET', 'api/admin/school/setup');

        $this->assertNotNull($route);

        $this->assertNotContains(
            'school.operational',
            $route->gatherMiddleware()
        );
    }

    public function test_profile_completion_route_does_not_have_operational_gate(): void
    {
        $route = $this->routeFor(
            'PUT',
            'api/admin/school/complete-profile'
        );

        $this->assertNotNull($route);

        $this->assertNotContains(
            'school.operational',
            $route->gatherMiddleware()
        );
    }

    public function test_academic_setup_mutations_remain_outside_operational_gate(): void
    {
        foreach ([
            ['POST', 'api/academic-years'],
            ['POST', 'api/terms'],
            ['POST', 'api/grades'],
            ['POST', 'api/streams'],
        ] as [$method, $uri]) {
            $route = $this->routeFor($method, $uri);

            $this->assertNotNull(
                $route,
                "Expected route {$method} {$uri} to exist."
            );

            $this->assertNotContains(
                'school.operational',
                $route->gatherMiddleware(),
                "{$method} {$uri} must remain available during setup."
            );
        }
    }

    public function test_learner_creation_route_has_operational_gate(): void
    {
        $route = $this->routeFor('POST', 'api/learners');

        $this->assertNotNull($route);

        $this->assertContains(
            'school.operational',
            $route->gatherMiddleware()
        );
    }

    public function test_learner_read_routes_remain_outside_operational_gate(): void
    {
        foreach ([
            ['GET', 'api/learners'],
            ['GET', 'api/learners/{id}'],
        ] as [$method, $uri]) {
            $route = $this->routeFor($method, $uri);

            $this->assertNotNull($route);

            $this->assertNotContains(
                'school.operational',
                $route->gatherMiddleware()
            );
        }
    }

    private function throughGate(User $user, School $school)
    {
        auth()->guard()->setUser($user);

        $request = Request::create(
            '/internal-operational-test',
            'POST'
        );

        $request->setUserResolver(fn () => $user);

        $request->attributes->set(
            'tenant_school_id',
            $school->id
        );

        $middleware = app(
            RequireOperationalSchoolSetup::class
        );

        $response = $middleware->handle(
            $request,
            fn () => response()->json([
                'passed' => true,
            ])
        );

        return new TestResponse(
            $response
        );
    }

    private function schoolUser(): array
    {
        $school = School::create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Operational '.Str::upper(Str::random(6)),
            'school_code' => 'OPS-'.Str::upper(Str::random(8)),
            'short_name' => 'OPS',
            'registration_number' => 'REG-'.Str::upper(Str::random(8)),
            'school_type' => 'Junior School',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(10000000, 99999999),
            'email' => Str::lower(Str::random(8)).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);

        $role = Role::query()
            ->where('role_name', 'School Admin')
            ->first();

        if (! $role) {
            $role = Role::create([
                'id' => (string) Str::uuid(),
                'role_name' => 'School Admin',
                'description' => 'School administrator',
                'active' => true,
            ]);
        }

        $user = User::create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'School',
            'last_name' => 'Admin',
            'username' => 'admin_'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'is_deleted' => false,
            'first_login' => false,
        ]);

        return [$school, $user];
    }

    private function completeSetup(School $school): array
    {
        $academicYearId = (string) Str::uuid();
        $termId = (string) Str::uuid();
        $gradeId = (string) Str::uuid();
        $streamId = (string) Str::uuid();

        DB::table('academic_years')->insert([
            'id' => $academicYearId,
            'school_id' => $school->id,
            'year_name' => '2026-'.Str::upper(Str::random(4)),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('terms')->insert([
            'id' => $termId,
            'school_id' => $school->id,
            'academic_year_id' => $academicYearId,
            'term_name' => 'Term 1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-04-30',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('grades')->insert([
            'id' => $gradeId,
            'school_id' => $school->id,
            'grade_name' => 'Grade '.random_int(1, 99),
            'grade_order' => random_int(1, 99),
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('streams')->insert([
            'id' => $streamId,
            'school_id' => $school->id,
            'grade_id' => $gradeId,
            'stream_name' => 'Stream '.Str::upper(Str::random(5)),
            'active' => true,
            'created_at' => now(),
        ]);

        return [
            'academic_year_id' => $academicYearId,
            'term_id' => $termId,
            'grade_id' => $gradeId,
            'stream_id' => $streamId,
        ];
    }

    private function routeFor(
        string $method,
        string $uri
    ): ?\Illuminate\Routing\Route {
        return collect(Route::getRoutes()->getRoutes())
            ->first(
                fn ($route) => in_array(
                    $method,
                    $route->methods(),
                    true
                ) && $route->uri() === $uri
            );
    }
}
