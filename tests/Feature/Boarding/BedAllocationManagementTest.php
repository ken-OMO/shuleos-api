<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\Hostel;
use App\Models\HostelBed;
use App\Models\HostelRoom;
use App\Models\Learner;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Boarding\BedAllocationService;
use App\Services\SchoolSetupReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class BedAllocationManagementTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT = '/api/boarding/bed-allocations';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_bed_allocation_route_has_expected_security_and_operational_gates(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(
                fn ($route): bool => in_array(
                    'POST',
                    $route->methods(),
                    true
                )
                && $route->uri() === 'api/boarding/bed-allocations'
            );

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains(
            'permission:manage_boarding',
            $middleware
        );

        $this->assertContains(
            'school.operational',
            $middleware
        );
    }

    public function test_unauthenticated_user_cannot_allocate_bed(): void
    {
        $this->postJson(self::ENDPOINT, [
            'learner_id' => (string) Str::uuid(),
            'bed_id' => (string) Str::uuid(),
        ])->assertUnauthorized();
    }

    public function test_user_without_manage_boarding_cannot_allocate_bed(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $this->completeOperationalSetup($school);

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ])
            ->assertForbidden();

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_non_operational_school_cannot_allocate_bed(): void
    {
        [$school, $user] = $this->authorizedSchoolUser(
            operational: false
        );

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ])
            ->assertForbidden();

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_authorized_active_boarder_can_be_allocated_and_audit_is_written(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $response = $this->withToken(
            $this->tokenFor($user)
        )->postJson(self::ENDPOINT, [
            'learner_id' => $learner->id,
            'bed_id' => $bed->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Bed allocated successfully.'
            )
            ->assertJsonPath(
                'data.learner_id',
                $learner->id
            )
            ->assertJsonPath(
                'data.bed_id',
                $bed->id
            )
            ->assertJsonPath(
                'data.active',
                true
            )
            ->assertJsonPath(
                'data.allocated_by',
                $user->id
            )
            ->assertJsonMissingPath(
                'data.school_id'
            );

        $allocationId = (string) $response->json(
            'data.id'
        );

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $allocationId,
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
                'active' => true,
                'allocated_by' => $user->id,
            ]
        );

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Create')
                ->count()
        );
    }

    public function test_client_school_id_cannot_redirect_allocation_to_foreign_tenant(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $foreignSchool = $this->school();

        $learner = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'BOYS'
        );

        $response = $this->withToken(
            $this->tokenFor($user)
        )->postJson(self::ENDPOINT, [
            'school_id' => $foreignSchool->id,
            'learner_id' => $learner->id,
            'bed_id' => $bed->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonMissingPath(
                'data.school_id'
            );

        $allocationId = (string) $response->json(
            'data.id'
        );

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $allocationId,
                'school_id' => $school->id,
            ]
        );

        $this->assertDatabaseMissing(
            'bed_allocations',
            [
                'id' => $allocationId,
                'school_id' => $foreignSchool->id,
            ]
        );
    }

    public function test_allocation_server_owned_fields_are_prohibited(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
                'allocated_by' => (string) Str::uuid(),
                'allocation_date' => '2000-01-01',
                'release_date' => '2000-01-02',
                'active' => false,
                'id' => (string) Str::uuid(),
                'created_at' => '2000-01-01 00:00:00',
                'updated_at' => '2000-01-01 00:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'allocated_by',
                'allocation_date',
                'release_date',
                'active',
                'id',
                'created_at',
                'updated_at',
            ]);

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_foreign_tenant_learner_identifier_fails_closed(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $foreignSchool = $this->school();

        $foreignLearner = $this->learner(
            $foreignSchool,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $foreignLearner->id,
                'bed_id' => $bed->id,
            ])
            ->assertNotFound();

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_foreign_tenant_bed_identifier_fails_closed(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $foreignSchool = $this->school();

        $learner = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $foreignBed = $this->boardingBed(
            $foreignSchool,
            'BOYS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $foreignBed->id,
            ])
            ->assertNotFound();

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_day_scholar_cannot_receive_bed_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'day_scholar',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'learner_id',
            ]);
    }

    public function test_inactive_learner_cannot_receive_bed_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            null,
            false
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'learner_id',
            ]);
    }

    public function test_terminal_lifecycle_learner_cannot_receive_bed_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'withdrawn',
            false
        );

        /*
         * A terminal lifecycle learner is correctly inactive by
         * database invariant. The HTTP contract only needs to prove
         * that this valid terminal state cannot receive a bed.
         */
        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'learner_id',
            ]);
    }

    public function test_boys_and_girls_hostel_separation_is_enforced_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $femaleLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $boysBed = $this->boardingBed(
            $school,
            'BOYS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $femaleLearner->id,
                'bed_id' => $boysBed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'bed_id',
            ]);

        $maleLearner = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $girlsBed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $maleLearner->id,
                'bed_id' => $girlsBed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'bed_id',
            ]);

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_same_learner_cannot_receive_second_active_bed_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bedOne = $this->boardingBed(
            $school,
            'GIRLS',
            'ONE'
        );

        $bedTwo = $this->boardingBed(
            $school,
            'GIRLS',
            'TWO'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bedOne->id,
            ])
            ->assertCreated();

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bedTwo->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'learner_id',
            ]);

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_same_bed_cannot_receive_second_active_learner_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learnerOne = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $learnerTwo = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'BOYS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learnerOne->id,
                'bed_id' => $bed->id,
            ])
            ->assertCreated();

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learnerTwo->id,
                'bed_id' => $bed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'bed_id',
            ]);

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('bed_id', $bed->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_server_controls_actor_and_school_local_allocation_date(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-01 21:30:00',
                'UTC'
            )
        );

        try {
            [$school, $user] = $this->authorizedSchoolUser();

            $learner = $this->learner(
                $school,
                'Male',
                'boarder',
                'active',
                true
            );

            $bed = $this->boardingBed(
                $school,
                'BOYS'
            );

            $response = $this->withToken(
                $this->tokenFor($user)
            )->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ]);

            $response
                ->assertCreated()
                ->assertJsonPath(
                    'data.allocation_date',
                    '2026-09-02'
                )
                ->assertJsonPath(
                    'data.allocated_by',
                    $user->id
                );

            $this->assertDatabaseHas(
                'bed_allocations',
                [
                    'school_id' => $school->id,
                    'learner_id' => $learner->id,
                    'bed_id' => $bed->id,
                    'allocation_date' => '2026-09-02',
                    'allocated_by' => $user->id,
                    'active' => true,
                ]
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function authorizedSchoolUser(
        bool $operational = true
    ): array {
        $school = $this->school();
        $user = $this->user($school);

        if ($operational) {
            $this->completeOperationalSetup($school);
        }

        $this->grantManageBoarding($user);

        return [
            $school,
            $user,
        ];
    }

    public function test_bed_allocation_lifecycle_routes_have_expected_security_and_operational_gates(): void
    {
        $routes = collect(
            app('router')->getRoutes()->getRoutes()
        );

        foreach ([
            [
                'PATCH',
                'api/boarding/bed-allocations/{allocation}/release',
                false,
            ],
            [
                'POST',
                'api/boarding/bed-allocations/{allocation}/transfer',
                true,
            ],
            [
                'GET',
                'api/boarding/bed-allocations/{allocation}/history',
                false,
            ],
        ] as [$method, $uri, $operational]) {
            $route = $routes->first(
                fn ($candidate) => in_array(
                    $method,
                    $candidate->methods(),
                    true
                ) && $candidate->uri() === $uri
            );

            $this->assertNotNull(
                $route,
                "{$method} {$uri} must be registered."
            );

            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'permission:manage_boarding',
                $middleware,
                "{$method} {$uri} must require manage_boarding."
            );

            if ($operational) {
                $this->assertContains(
                    'school.operational',
                    $middleware,
                    "{$method} {$uri} must require school.operational."
                );
            } else {
                $this->assertNotContains(
                    'school.operational',
                    $middleware,
                    "{$method} {$uri} must remain available outside the operational gate."
                );
            }
        }
    }

    public function test_unauthenticated_user_cannot_use_bed_allocation_lifecycle_endpoints(): void
    {
        $allocation = (string) Str::uuid();

        $this->patchJson(
            self::ENDPOINT."/{$allocation}/release",
            [
                'reason' => 'Release attempt.',
            ]
        )->assertUnauthorized();

        $this->postJson(
            self::ENDPOINT."/{$allocation}/transfer",
            [
                'destination_bed_id' => (string) Str::uuid(),
                'reason' => 'Transfer attempt.',
            ]
        )->assertUnauthorized();

        $this->getJson(
            self::ENDPOINT."/{$allocation}/history"
        )->assertUnauthorized();
    }

    public function test_authorized_boarder_can_release_allocation_and_read_immediate_history_with_audit(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A1'
        );

        $token = $this->tokenFor($user);

        /*
         * Establish the occupancy episode through the public HTTP
         * allocation contract rather than bypassing it in the fixture.
         */
        $allocationResponse = $this
            ->withToken($token)
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ]);

        $allocationResponse
            ->assertCreated()
            ->assertJsonPath('data.learner_id', $learner->id)
            ->assertJsonPath('data.bed_id', $bed->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.active', true);

        $allocationId = (string) $allocationResponse->json(
            'data.id'
        );

        $this->assertNotSame('', $allocationId);

        $reason = 'End of boarding occupancy.';

        /*
         * Release itself is the HTTP-2A.1 subject.
         * school_id is intentionally not client supplied: the
         * authoritative tenant context must already be established
         * by the secure middleware chain.
         */
        $releaseResponse = $this
            ->withToken($token)
            ->patchJson(
                self::ENDPOINT."/{$allocationId}/release",
                [
                    'reason' => $reason,
                ]
            );

        $releaseResponse
            ->assertOk()
            ->assertJsonPath('data.id', $allocationId)
            ->assertJsonPath('data.learner_id', $learner->id)
            ->assertJsonPath('data.bed_id', $bed->id)
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.active', false);

        $this->assertNotNull(
            $releaseResponse->json('data.release_date')
        );

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $allocationId,
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
                'status' => 'released',
                'active' => false,
            ]
        );

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->where('active', true)
                ->count()
        );

        /*
         * The immutable lifecycle event must be readable immediately
         * through the public history endpoint.
         */
        $historyResponse = $this
            ->withToken($token)
            ->getJson(
                self::ENDPOINT."/{$allocationId}/history"
            );

        $historyResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.event_type',
                'release'
            )
            ->assertJsonPath(
                'data.0.learner_id',
                $learner->id
            )
            ->assertJsonPath(
                'data.0.source_allocation_id',
                $allocationId
            )
            ->assertJsonPath(
                'data.0.destination_allocation_id',
                null
            )
            ->assertJsonPath(
                'data.0.from_status',
                'active'
            )
            ->assertJsonPath(
                'data.0.to_status',
                'released'
            )
            ->assertJsonPath(
                'data.0.reason',
                $reason
            )
            ->assertJsonPath(
                'data.0.changed_by',
                $user->id
            );

        $eventId = (string) $historyResponse->json(
            'data.0.event_id'
        );

        $this->assertNotSame('', $eventId);

        $this->assertDatabaseHas(
            'bed_allocation_history',
            [
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'event_id' => $eventId,
                'event_type' => 'release',
                'source_allocation_id' => $allocationId,
                'destination_allocation_id' => null,
                'from_status' => 'active',
                'to_status' => 'released',
                'reason' => $reason,
                'changed_by' => $user->id,
            ]
        );

        /*
         * The general ShuleOS audit trail is separate from the
         * immutable occupancy lifecycle history.
         */
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Release')
                ->count()
        );
    }

    public function test_authorized_boarder_can_transfer_allocation_and_read_correlated_history_with_audit(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $sourceBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A2-SOURCE'
        );

        $destinationBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A2-DEST'
        );

        $token = $this->tokenFor($user);

        /*
         * Establish the source occupancy episode through the same
         * public HTTP allocation contract used by the application.
         */
        $allocationResponse = $this
            ->withToken($token)
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $sourceBed->id,
            ]);

        $allocationResponse
            ->assertCreated()
            ->assertJsonPath('data.learner_id', $learner->id)
            ->assertJsonPath('data.bed_id', $sourceBed->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.active', true);

        $sourceAllocationId = (string) $allocationResponse->json(
            'data.id'
        );

        $this->assertNotSame('', $sourceAllocationId);

        $reason = 'Move learner to destination bed.';

        /*
         * Transfer must atomically close the source occupancy episode
         * and create a distinct active destination episode.
         */
        $transferResponse = $this
            ->withToken($token)
            ->postJson(
                self::ENDPOINT."/{$sourceAllocationId}/transfer",
                [
                    'destination_bed_id' => $destinationBed->id,
                    'reason' => $reason,
                ]
            );

        $transferResponse
            ->assertOk()
            ->assertJsonPath('data.learner_id', $learner->id)
            ->assertJsonPath('data.bed_id', $destinationBed->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.active', true);

        $destinationAllocationId = (string) $transferResponse->json(
            'data.id'
        );

        $this->assertNotSame('', $destinationAllocationId);

        $this->assertNotSame(
            $sourceAllocationId,
            $destinationAllocationId
        );

        /*
         * The original occupancy episode must remain permanently
         * identifiable as the transferred source.
         */
        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $sourceAllocationId,
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'bed_id' => $sourceBed->id,
                'status' => 'transferred',
                'active' => false,
            ]
        );

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $destinationAllocationId,
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'bed_id' => $destinationBed->id,
                'status' => 'active',
                'active' => true,
            ]
        );

        $this->assertSame(
            2,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->where('active', true)
                ->count()
        );

        /*
         * Reading history through the source episode must expose the
         * single logical transfer event correlating both episodes.
         */
        $sourceHistoryResponse = $this
            ->withToken($token)
            ->getJson(
                self::ENDPOINT."/{$sourceAllocationId}/history"
            );

        $sourceHistoryResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.event_type',
                'transfer'
            )
            ->assertJsonPath(
                'data.0.learner_id',
                $learner->id
            )
            ->assertJsonPath(
                'data.0.source_allocation_id',
                $sourceAllocationId
            )
            ->assertJsonPath(
                'data.0.destination_allocation_id',
                $destinationAllocationId
            )
            ->assertJsonPath(
                'data.0.from_status',
                'active'
            )
            ->assertJsonPath(
                'data.0.to_status',
                'transferred'
            )
            ->assertJsonPath(
                'data.0.reason',
                $reason
            )
            ->assertJsonPath(
                'data.0.changed_by',
                $user->id
            );

        $eventId = (string) $sourceHistoryResponse->json(
            'data.0.event_id'
        );

        $historyId = (string) $sourceHistoryResponse->json(
            'data.0.id'
        );

        $this->assertNotSame('', $eventId);
        $this->assertNotSame('', $historyId);
        $this->assertNotSame($historyId, $eventId);

        /*
         * The destination episode must resolve to that exact same
         * immutable logical transfer event rather than a duplicate.
         */
        $destinationHistoryResponse = $this
            ->withToken($token)
            ->getJson(
                self::ENDPOINT."/{$destinationAllocationId}/history"
            );

        $destinationHistoryResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                $historyId
            )
            ->assertJsonPath(
                'data.0.event_id',
                $eventId
            )
            ->assertJsonPath(
                'data.0.event_type',
                'transfer'
            )
            ->assertJsonPath(
                'data.0.source_allocation_id',
                $sourceAllocationId
            )
            ->assertJsonPath(
                'data.0.destination_allocation_id',
                $destinationAllocationId
            );

        /*
         * There must be one database lifecycle event for the transfer,
         * not one event per allocation episode.
         */
        $this->assertSame(
            1,
            DB::table('bed_allocation_history')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->where('event_type', 'transfer')
                ->where('source_allocation_id', $sourceAllocationId)
                ->where(
                    'destination_allocation_id',
                    $destinationAllocationId
                )
                ->count()
        );

        $this->assertDatabaseHas(
            'bed_allocation_history',
            [
                'id' => $historyId,
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'event_id' => $eventId,
                'event_type' => 'transfer',
                'source_allocation_id' => $sourceAllocationId,
                'destination_allocation_id' => $destinationAllocationId,
                'from_status' => 'active',
                'to_status' => 'transferred',
                'reason' => $reason,
                'changed_by' => $user->id,
            ]
        );

        /*
         * General API audit remains separate from immutable occupancy
         * history. Exactly one Transfer action belongs to this request.
         */
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Transfer')
                ->count()
        );
    }

    public function test_non_operational_school_can_release_and_read_history_but_cannot_transfer(): void
    {
        /*
         * Create the complete boarding fixture while the school is
         * operational. We then deliberately make the school
         * non-operational before exercising lifecycle endpoints.
         */
        [$school, $user] = $this->authorizedSchoolUser();

        $releaseLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $releaseBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A3-RELEASE'
        );

        $transferLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $sourceBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A3-SOURCE'
        );

        $destinationBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A3-DEST'
        );

        $token = $this->tokenFor($user);

        $releaseAllocationResponse = $this
            ->withToken($token)
            ->postJson(self::ENDPOINT, [
                'learner_id' => $releaseLearner->id,
                'bed_id' => $releaseBed->id,
            ]);

        $releaseAllocationResponse->assertCreated();

        $releaseAllocationId = (string) $releaseAllocationResponse->json(
            'data.id'
        );

        $this->assertNotSame('', $releaseAllocationId);

        $transferAllocationResponse = $this
            ->withToken($token)
            ->postJson(self::ENDPOINT, [
                'learner_id' => $transferLearner->id,
                'bed_id' => $sourceBed->id,
            ]);

        $transferAllocationResponse->assertCreated();

        $sourceAllocationId = (string) $transferAllocationResponse->json(
            'data.id'
        );

        $this->assertNotSame('', $sourceAllocationId);

        /*
         * Force this already-valid school outside the operational
         * gate only after both occupancy episodes exist.
         *
         * SchoolSetupReadinessService requires an active current term.
         * Learner fixtures may add grades and streams, so the term is
         * the deterministic readiness prerequisite to withdraw here.
         */
        $this->assertTrue(
            app(SchoolSetupReadinessService::class)
                ->isReady($school->id)
        );

        $activeTermsChanged = DB::table('terms')
            ->where('school_id', $school->id)
            ->where('active', true)
            ->update([
                'active' => false,
            ]);

        $this->assertSame(1, $activeTermsChanged);

        $this->assertFalse(
            app(SchoolSetupReadinessService::class)
                ->isReady($school->id)
        );

        $this->assertSame(
            0,
            DB::table('terms')
                ->where('school_id', $school->id)
                ->where('active', true)
                ->count()
        );

        /*
         * RELEASE MUST REMAIN AVAILABLE.
         *
         * Cleanup of a real occupancy episode cannot depend on the
         * school currently passing the operational gate.
         */
        $releaseReason = 'Operational-gate cleanup proof.';

        $releaseResponse = $this
            ->withToken($token)
            ->patchJson(
                self::ENDPOINT."/{$releaseAllocationId}/release",
                [
                    'reason' => $releaseReason,
                ]
            );

        $releaseResponse
            ->assertOk()
            ->assertJsonPath('data.id', $releaseAllocationId)
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $releaseAllocationId,
                'school_id' => $school->id,
                'learner_id' => $releaseLearner->id,
                'bed_id' => $releaseBed->id,
                'status' => 'released',
                'active' => false,
            ]
        );

        /*
         * HISTORY MUST ALSO REMAIN AVAILABLE while non-operational.
         */
        $historyResponse = $this
            ->withToken($token)
            ->getJson(
                self::ENDPOINT."/{$releaseAllocationId}/history"
            );

        $historyResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.event_type',
                'release'
            )
            ->assertJsonPath(
                'data.0.source_allocation_id',
                $releaseAllocationId
            )
            ->assertJsonPath(
                'data.0.destination_allocation_id',
                null
            )
            ->assertJsonPath(
                'data.0.reason',
                $releaseReason
            );

        /*
         * Snapshot the transfer source before the denied request.
         */
        $sourceBefore = DB::table('bed_allocations')
            ->where('id', $sourceAllocationId)
            ->first();

        $this->assertNotNull($sourceBefore);
        $this->assertSame('active', $sourceBefore->status);
        $this->assertTrue((bool) $sourceBefore->active);
        $this->assertNull($sourceBefore->release_date);

        $destinationCountBefore = DB::table('bed_allocations')
            ->where('school_id', $school->id)
            ->where('bed_id', $destinationBed->id)
            ->count();

        $transferHistoryBefore = DB::table(
            'bed_allocation_history'
        )
            ->where('school_id', $school->id)
            ->where('event_type', 'transfer')
            ->count();

        $transferAuditBefore = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('module', 'Boarding')
            ->where('action', 'Transfer')
            ->count();

        $this->assertSame(0, $destinationCountBefore);
        $this->assertSame(0, $transferHistoryBefore);
        $this->assertSame(0, $transferAuditBefore);

        /*
         * TRANSFER MUST BE REJECTED by school.operational.
         *
         * We deliberately do not freeze a specific non-2xx status
         * here until the middleware's established response contract
         * is proven separately. The security property is rejection
         * plus complete absence of lifecycle side effects.
         */
        $transferResponse = $this
            ->withToken($token)
            ->postJson(
                self::ENDPOINT."/{$sourceAllocationId}/transfer",
                [
                    'destination_bed_id' => $destinationBed->id,
                    'reason' => 'This transfer must be blocked.',
                ]
            );

        $transferResponse
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'Initial school setup must be completed before this operation.',
            ]);

        /*
         * The denied request must not partially close the source,
         * allocate the destination, create lifecycle history, or
         * emit a successful Transfer audit.
         */
        $sourceAfter = DB::table('bed_allocations')
            ->where('id', $sourceAllocationId)
            ->first();

        $this->assertNotNull($sourceAfter);
        $this->assertSame($sourceBefore->id, $sourceAfter->id);
        $this->assertSame($sourceBefore->bed_id, $sourceAfter->bed_id);
        $this->assertSame('active', $sourceAfter->status);
        $this->assertTrue((bool) $sourceAfter->active);
        $this->assertNull($sourceAfter->release_date);

        $this->assertSame(
            $destinationCountBefore,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('bed_id', $destinationBed->id)
                ->count()
        );

        $this->assertSame(
            $transferHistoryBefore,
            DB::table('bed_allocation_history')
                ->where('school_id', $school->id)
                ->where('event_type', 'transfer')
                ->count()
        );

        $this->assertSame(
            $transferAuditBefore,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Transfer')
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $transferLearner->id)
                ->where('active', true)
                ->count()
        );

        /*
         * History remains readable even for the still-active source.
         * Since its transfer was rejected, it must have no lifecycle
         * event yet.
         */
        $sourceHistoryResponse = $this
            ->withToken($token)
            ->getJson(
                self::ENDPOINT."/{$sourceAllocationId}/history"
            );

        $sourceHistoryResponse
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_user_without_manage_boarding_cannot_release_transfer_or_read_history(): void
    {
        /*
         * The privileged actor establishes a legitimate active
         * occupancy episode. Authorization denial is then exercised
         * by a second authenticated user in the same school whose
         * role has not been granted manage_boarding.
         */
        [$school, $authorizedUser] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $sourceBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A4-SOURCE'
        );

        $destinationBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A4-DEST'
        );

        $allocationResponse = $this
            ->withToken($this->tokenFor($authorizedUser))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $sourceBed->id,
            ]);

        $allocationResponse->assertCreated();

        $sourceAllocationId = (string) $allocationResponse->json(
            'data.id'
        );

        $this->assertNotSame('', $sourceAllocationId);

        /*
         * Existing repository authorization precedent:
         * a normal same-school user receives no manage_boarding
         * grant unless grantManageBoarding() is called explicitly.
         */
        $unauthorizedUser = $this->user($school);

        $permissionId = DB::table('permissions')
            ->where(
                'permission_name',
                'manage_boarding'
            )
            ->value('id');

        $this->assertNotNull(
            $permissionId,
            'manage_boarding must be provisioned.'
        );

        $this->assertFalse(
            DB::table('role_permissions')
                ->where(
                    'role_id',
                    $unauthorizedUser->role_id
                )
                ->where(
                    'permission_id',
                    $permissionId
                )
                ->exists(),
            'Adversarial actor must not have manage_boarding.'
        );

        $unauthorizedToken = $this->tokenFor(
            $unauthorizedUser
        );

        /*
         * Snapshot every lifecycle side effect before the denied
         * requests. None of these values may change.
         */
        $sourceBefore = DB::table('bed_allocations')
            ->where('id', $sourceAllocationId)
            ->first();

        $this->assertNotNull($sourceBefore);
        $this->assertSame('active', $sourceBefore->status);
        $this->assertTrue((bool) $sourceBefore->active);
        $this->assertNull($sourceBefore->release_date);

        $destinationCountBefore = DB::table('bed_allocations')
            ->where('school_id', $school->id)
            ->where('bed_id', $destinationBed->id)
            ->count();

        $historyCountBefore = DB::table(
            'bed_allocation_history'
        )
            ->where('school_id', $school->id)
            ->where(function ($query) use ($sourceAllocationId) {
                $query
                    ->where(
                        'source_allocation_id',
                        $sourceAllocationId
                    )
                    ->orWhere(
                        'destination_allocation_id',
                        $sourceAllocationId
                    );
            })
            ->count();

        $releaseAuditBefore = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('module', 'Boarding')
            ->where('action', 'Release')
            ->count();

        $transferAuditBefore = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('module', 'Boarding')
            ->where('action', 'Transfer')
            ->count();

        $this->assertSame(0, $destinationCountBefore);
        $this->assertSame(0, $historyCountBefore);
        $this->assertSame(0, $releaseAuditBefore);
        $this->assertSame(0, $transferAuditBefore);

        /*
         * RELEASE: authenticated, correct tenant, operational school,
         * but missing manage_boarding. Permission middleware must
         * reject before the controller/service can mutate anything.
         */
        $this->withToken($unauthorizedToken)
            ->patchJson(
                self::ENDPOINT."/{$sourceAllocationId}/release",
                [
                    'reason' => 'Must not be accepted.',
                ]
            )
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Permission denied.',
            ]);

        /*
         * TRANSFER: same authorization failure. The operational gate
         * is satisfied, so this specifically proves permission denial
         * rather than school-readiness denial.
         */
        $this->withToken($unauthorizedToken)
            ->postJson(
                self::ENDPOINT."/{$sourceAllocationId}/transfer",
                [
                    'destination_bed_id' => $destinationBed->id,
                    'reason' => 'Must not be accepted.',
                ]
            )
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Permission denied.',
            ]);

        /*
         * HISTORY is also privileged boarding information and must
         * not be disclosed to an authenticated user lacking the
         * canonical boarding permission.
         */
        $this->withToken($unauthorizedToken)
            ->getJson(
                self::ENDPOINT."/{$sourceAllocationId}/history"
            )
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Permission denied.',
            ]);

        /*
         * Prove all three denials were side-effect free.
         */
        $sourceAfter = DB::table('bed_allocations')
            ->where('id', $sourceAllocationId)
            ->first();

        $this->assertNotNull($sourceAfter);
        $this->assertSame(
            $sourceBefore->id,
            $sourceAfter->id
        );
        $this->assertSame(
            $sourceBefore->bed_id,
            $sourceAfter->bed_id
        );
        $this->assertSame(
            $sourceBefore->learner_id,
            $sourceAfter->learner_id
        );
        $this->assertSame(
            'active',
            $sourceAfter->status
        );
        $this->assertTrue(
            (bool) $sourceAfter->active
        );
        $this->assertNull(
            $sourceAfter->release_date
        );

        $this->assertSame(
            $destinationCountBefore,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('bed_id', $destinationBed->id)
                ->count()
        );

        $this->assertSame(
            $historyCountBefore,
            DB::table('bed_allocation_history')
                ->where('school_id', $school->id)
                ->where(function ($query) use (
                    $sourceAllocationId
                ) {
                    $query
                        ->where(
                            'source_allocation_id',
                            $sourceAllocationId
                        )
                        ->orWhere(
                            'destination_allocation_id',
                            $sourceAllocationId
                        );
                })
                ->count()
        );

        $this->assertSame(
            $releaseAuditBefore,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Release')
                ->count()
        );

        $this->assertSame(
            $transferAuditBefore,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Transfer')
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->where('active', true)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('bed_id', $sourceBed->id)
                ->where('active', true)
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('bed_id', $destinationBed->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_cross_tenant_lifecycle_resources_fail_closed_without_side_effects(): void
    {
        /*
         * School A is the attacking tenant. It is fully authorized
         * and operational so failures below must come from tenant
         * ownership boundaries rather than permission/readiness.
         */
        [$schoolA, $userA] = $this->authorizedSchoolUser();

        /*
         * School B owns a legitimate active occupancy episode.
         */
        $schoolB = $this->school();
        $userB = $this->user($schoolB);
        $this->completeOperationalSetup($schoolB);
        $this->grantManageBoarding($userB);

        $learnerB = $this->learner(
            $schoolB,
            'Female',
            'boarder',
            'active',
            true
        );

        $bedB = $this->boardingBed(
            $schoolB,
            'GIRLS',
            'HTTP-2A5-B-SOURCE'
        );

        $allocationBModel = app(
            BedAllocationService::class
        )->allocate(
            (string) $schoolB->id,
            (string) $learnerB->id,
            (string) $bedB->id,
            (string) $userB->id
        );

        $allocationB = (string) $allocationBModel->id;

        $this->assertNotSame('', $allocationB);

        /*
         * Create School B's unused destination BEFORE any School A
         * authenticated HTTP request. TenantModel::saving() derives
         * tenant ownership from the authenticated user when one exists,
         * so this ordering is deliberate and security-significant.
         */
        $foreignDestinationBed = $this->boardingBed(
            $schoolB,
            'GIRLS',
            'HTTP-2A5-B-DEST'
        );

        /*
         * School A also owns a legitimate active source allocation.
         * This is used separately to attack a School B destination
         * bed while keeping the source tenant-local.
         */
        $learnerA = $this->learner(
            $schoolA,
            'Female',
            'boarder',
            'active',
            true
        );

        $sourceBedA = $this->boardingBed(
            $schoolA,
            'GIRLS',
            'HTTP-2A5-A-SOURCE'
        );

        $localAllocationResponse = $this
            ->withToken($this->tokenFor($userA))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learnerA->id,
                'bed_id' => $sourceBedA->id,
            ]);

        $localAllocationResponse->assertCreated();

        $allocationA = (string) $localAllocationResponse->json(
            'data.id'
        );

        $this->assertNotSame('', $allocationA);

        /*
         * Authoritative fixture ownership proof.
         *
         * Use raw Query Builder reads so TenantScope cannot conceal or
         * reinterpret the fixture. These assertions must pass before
         * any adversarial request is sent.
         */
        $sourceBedARow = DB::table('hostel_beds')
            ->where('id', $sourceBedA->id)
            ->first();

        $foreignDestinationBedRow = DB::table('hostel_beds')
            ->where('id', $foreignDestinationBed->id)
            ->first();

        $this->assertNotNull($sourceBedARow);
        $this->assertNotNull($foreignDestinationBedRow);

        $this->assertNotSame(
            (string) $schoolA->id,
            (string) $schoolB->id
        );

        $this->assertSame(
            (string) $schoolA->id,
            (string) $sourceBedARow->school_id
        );

        $this->assertSame(
            (string) $schoolB->id,
            (string) $foreignDestinationBedRow->school_id
        );

        $this->assertNotSame(
            (string) $sourceBedARow->school_id,
            (string) $foreignDestinationBedRow->school_id
        );

        /*
         * Snapshot both tenants before adversarial requests.
         */
        $allocationABefore = DB::table('bed_allocations')
            ->where('id', $allocationA)
            ->first();

        $allocationBBefore = DB::table('bed_allocations')
            ->where('id', $allocationB)
            ->first();

        $this->assertNotNull($allocationABefore);
        $this->assertNotNull($allocationBBefore);

        $this->assertSame(
            (string) $schoolA->id,
            (string) $allocationABefore->school_id
        );

        $this->assertSame(
            (string) $schoolB->id,
            (string) $allocationBBefore->school_id
        );

        $this->assertSame('active', $allocationABefore->status);
        $this->assertSame('active', $allocationBBefore->status);
        $this->assertTrue((bool) $allocationABefore->active);
        $this->assertTrue((bool) $allocationBBefore->active);
        $this->assertNull($allocationABefore->release_date);
        $this->assertNull($allocationBBefore->release_date);

        $historyABefore = DB::table('bed_allocation_history')
            ->where('school_id', $schoolA->id)
            ->count();

        $historyBBefore = DB::table('bed_allocation_history')
            ->where('school_id', $schoolB->id)
            ->count();

        $releaseAuditABefore = DB::table('audit_logs')
            ->where('school_id', $schoolA->id)
            ->where('module', 'Boarding')
            ->where('action', 'Release')
            ->count();

        $releaseAuditBBefore = DB::table('audit_logs')
            ->where('school_id', $schoolB->id)
            ->where('module', 'Boarding')
            ->where('action', 'Release')
            ->count();

        $transferAuditABefore = DB::table('audit_logs')
            ->where('school_id', $schoolA->id)
            ->where('module', 'Boarding')
            ->where('action', 'Transfer')
            ->count();

        $transferAuditBBefore = DB::table('audit_logs')
            ->where('school_id', $schoolB->id)
            ->where('module', 'Boarding')
            ->where('action', 'Transfer')
            ->count();

        $schoolAAllocationCountBefore = DB::table('bed_allocations')
            ->where('school_id', $schoolA->id)
            ->count();

        $schoolBAllocationCountBefore = DB::table('bed_allocations')
            ->where('school_id', $schoolB->id)
            ->count();

        $foreignDestinationCountBefore = DB::table('bed_allocations')
            ->where('school_id', $schoolB->id)
            ->where('bed_id', $foreignDestinationBed->id)
            ->count();

        $this->assertSame(0, $historyABefore);
        $this->assertSame(0, $historyBBefore);
        $this->assertSame(0, $foreignDestinationCountBefore);

        $tokenA = $this->tokenFor($userA);

        /*
         * ATTACK 1:
         * School A attempts to release School B's allocation.
         * Tenant-scoped allocation resolution must conceal it.
         */
        $this->withToken($tokenA)
            ->patchJson(
                self::ENDPOINT."/{$allocationB}/release",
                [
                    'reason' => 'Cross-tenant release attempt.',
                ]
            )
            ->assertNotFound();

        /*
         * ATTACK 2:
         * School A attempts to transfer School B's source
         * allocation. Source discovery itself must fail closed.
         */
        $this->withToken($tokenA)
            ->postJson(
                self::ENDPOINT."/{$allocationB}/transfer",
                [
                    'destination_bed_id' => $sourceBedA->id,
                    'reason' => 'Cross-tenant source attempt.',
                ]
            )
            ->assertNotFound();

        /*
         * ATTACK 3:
         * School A attempts to read School B's immutable lifecycle
         * history. Existence of the allocation must not be exposed.
         */
        $this->withToken($tokenA)
            ->getJson(
                self::ENDPOINT."/{$allocationB}/history"
            )
            ->assertNotFound();

        /*
         * ATTACK 4:
         * Source allocation belongs to School A, but destination bed
         * belongs to School B. Current service semantics resolve
         * transfer bed resources inside School A and return a
         * controlled validation failure when the foreign destination
         * cannot be resolved.
         */
        $this->withToken($tokenA)
            ->postJson(
                self::ENDPOINT."/{$allocationA}/transfer",
                [
                    'destination_bed_id' => $foreignDestinationBed->id,
                    'reason' => 'Cross-tenant destination attempt.',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'bed_id',
            ]);

        /*
         * BOTH TENANTS MUST REMAIN EXACTLY IN THEIR ORIGINAL
         * OCCUPANCY STATE.
         */
        $allocationAAfter = DB::table('bed_allocations')
            ->where('id', $allocationA)
            ->first();

        $allocationBAfter = DB::table('bed_allocations')
            ->where('id', $allocationB)
            ->first();

        $this->assertNotNull($allocationAAfter);
        $this->assertNotNull($allocationBAfter);

        foreach ([
            [$allocationABefore, $allocationAAfter],
            [$allocationBBefore, $allocationBAfter],
        ] as [$before, $after]) {
            $this->assertSame(
                (string) $before->school_id,
                (string) $after->school_id
            );

            $this->assertSame(
                (string) $before->learner_id,
                (string) $after->learner_id
            );

            $this->assertSame(
                (string) $before->bed_id,
                (string) $after->bed_id
            );

            $this->assertSame(
                'active',
                $after->status
            );

            $this->assertTrue(
                (bool) $after->active
            );

            $this->assertNull(
                $after->release_date
            );
        }

        /*
         * No destination episode may have been created in either
         * tenant.
         */
        $this->assertSame(
            $schoolAAllocationCountBefore,
            DB::table('bed_allocations')
                ->where('school_id', $schoolA->id)
                ->count()
        );

        $this->assertSame(
            $schoolBAllocationCountBefore,
            DB::table('bed_allocations')
                ->where('school_id', $schoolB->id)
                ->count()
        );

        $this->assertSame(
            $foreignDestinationCountBefore,
            DB::table('bed_allocations')
                ->where('school_id', $schoolB->id)
                ->where('bed_id', $foreignDestinationBed->id)
                ->count()
        );

        /*
         * Neither tenant receives lifecycle history from any denied
         * cross-tenant operation.
         */
        $this->assertSame(
            $historyABefore,
            DB::table('bed_allocation_history')
                ->where('school_id', $schoolA->id)
                ->count()
        );

        $this->assertSame(
            $historyBBefore,
            DB::table('bed_allocation_history')
                ->where('school_id', $schoolB->id)
                ->count()
        );

        /*
         * Failed requests must not create successful lifecycle audit
         * records in either tenant.
         */
        $this->assertSame(
            $releaseAuditABefore,
            DB::table('audit_logs')
                ->where('school_id', $schoolA->id)
                ->where('module', 'Boarding')
                ->where('action', 'Release')
                ->count()
        );

        $this->assertSame(
            $releaseAuditBBefore,
            DB::table('audit_logs')
                ->where('school_id', $schoolB->id)
                ->where('module', 'Boarding')
                ->where('action', 'Release')
                ->count()
        );

        $this->assertSame(
            $transferAuditABefore,
            DB::table('audit_logs')
                ->where('school_id', $schoolA->id)
                ->where('module', 'Boarding')
                ->where('action', 'Transfer')
                ->count()
        );

        $this->assertSame(
            $transferAuditBBefore,
            DB::table('audit_logs')
                ->where('school_id', $schoolB->id)
                ->where('module', 'Boarding')
                ->where('action', 'Transfer')
                ->count()
        );

        /*
         * Final active-occupancy proof.
         */
        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $schoolA->id)
                ->where('learner_id', $learnerA->id)
                ->where('active', true)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $schoolB->id)
                ->where('learner_id', $learnerB->id)
                ->where('active', true)
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $schoolB->id)
                ->where('bed_id', $foreignDestinationBed->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_client_cannot_control_release_or_transfer_lifecycle_and_history_fields(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $sourceBed = $this->boardingBed(
            $school,
            'GIRLS',
            'INJECTION-SOURCE'
        );

        $destinationBed = $this->boardingBed(
            $school,
            'GIRLS',
            'INJECTION-DESTINATION'
        );

        $token = $this->tokenFor($user);

        /*
         * Establish a legitimate occupancy episode through the public
         * allocation contract. The adversarial requests below must not
         * be able to rewrite any server-owned lifecycle or history state.
         */
        $allocationResponse = $this
            ->withToken($token)
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $sourceBed->id,
            ]);

        $allocationResponse
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.active', true);

        $allocationId = (string) $allocationResponse->json(
            'data.id'
        );

        $this->assertNotSame('', $allocationId);

        $baselineAllocationCount = DB::table('bed_allocations')
            ->where('school_id', $school->id)
            ->where('learner_id', $learner->id)
            ->count();

        $baselineHistoryCount = DB::table('bed_allocation_history')
            ->where('school_id', $school->id)
            ->where('learner_id', $learner->id)
            ->count();

        $baselineReleaseAuditCount = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('module', 'Boarding')
            ->where('action', 'Release')
            ->count();

        $baselineTransferAuditCount = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('module', 'Boarding')
            ->where('action', 'Transfer')
            ->count();

        $releaseInjectedFields = [
            'id' => (string) Str::uuid(),
            'learner_id' => (string) Str::uuid(),
            'bed_id' => (string) Str::uuid(),
            'source_allocation_id' => (string) Str::uuid(),
            'destination_allocation_id' => (string) Str::uuid(),
            'destination_bed_id' => (string) Str::uuid(),
            'status' => 'released',
            'active' => false,
            'allocation_date' => '2000-01-01',
            'release_date' => '2000-01-02',
            'event_id' => (string) Str::uuid(),
            'event_type' => 'release',
            'from_status' => 'transferred',
            'to_status' => 'active',
            'effective_date' => '2000-01-03',
            'allocated_by' => (string) Str::uuid(),
            'changed_by' => (string) Str::uuid(),
            'changed_at' => '2000-01-04 00:00:00',
            'created_at' => '2000-01-05 00:00:00',
            'updated_at' => '2000-01-06 00:00:00',
        ];

        $this->withToken($token)
            ->patchJson(
                self::ENDPOINT.'/'.$allocationId.'/release',
                array_merge(
                    [
                        'reason' => 'Injected release attempt.',
                    ],
                    $releaseInjectedFields
                )
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                array_keys($releaseInjectedFields)
            );

        /*
         * A rejected release must leave the original episode untouched.
         */
        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $allocationId,
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'bed_id' => $sourceBed->id,
                'status' => 'active',
                'active' => true,
                'release_date' => null,
            ]
        );

        $this->assertSame(
            $baselineAllocationCount,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->count()
        );

        $this->assertSame(
            $baselineHistoryCount,
            DB::table('bed_allocation_history')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->count()
        );

        $this->assertSame(
            $baselineReleaseAuditCount,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Release')
                ->count()
        );

        $transferInjectedFields = [
            'id' => (string) Str::uuid(),
            'learner_id' => (string) Str::uuid(),
            'bed_id' => (string) Str::uuid(),
            'source_allocation_id' => (string) Str::uuid(),
            'destination_allocation_id' => (string) Str::uuid(),
            'status' => 'transferred',
            'active' => false,
            'allocation_date' => '2001-01-01',
            'release_date' => '2001-01-02',
            'event_id' => (string) Str::uuid(),
            'event_type' => 'transfer',
            'from_status' => 'released',
            'to_status' => 'active',
            'effective_date' => '2001-01-03',
            'allocated_by' => (string) Str::uuid(),
            'changed_by' => (string) Str::uuid(),
            'changed_at' => '2001-01-04 00:00:00',
            'created_at' => '2001-01-05 00:00:00',
            'updated_at' => '2001-01-06 00:00:00',
        ];

        $this->withToken($token)
            ->postJson(
                self::ENDPOINT.'/'.$allocationId.'/transfer',
                array_merge(
                    [
                        'destination_bed_id' => $destinationBed->id,
                        'reason' => 'Injected transfer attempt.',
                    ],
                    $transferInjectedFields
                )
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                array_keys($transferInjectedFields)
            );

        /*
         * A rejected transfer must not close the source, create a
         * destination episode, append history or emit a transfer audit.
         */
        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $allocationId,
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'bed_id' => $sourceBed->id,
                'status' => 'active',
                'active' => true,
                'release_date' => null,
            ]
        );

        $this->assertSame(
            $baselineAllocationCount,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('bed_id', $destinationBed->id)
                ->count()
        );

        $this->assertSame(
            $baselineHistoryCount,
            DB::table('bed_allocation_history')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->count()
        );

        $this->assertSame(
            $baselineTransferAuditCount,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Transfer')
                ->count()
        );
    }

    public function test_release_and_transfer_reason_boundary_is_enforced_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        /*
         * RELEASE 501:
         * The public Form Request must reject the request before
         * lifecycle, history or audit state can change.
         */
        $releaseRejectedLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $releaseRejectedBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-RELEASE-REASON-501'
        );

        $releaseRejectedAllocationId = $this->withToken(
            $this->tokenFor($user)
        )
            ->postJson(self::ENDPOINT, [
                'learner_id' => $releaseRejectedLearner->id,
                'bed_id' => $releaseRejectedBed->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotEmpty($releaseRejectedAllocationId);

        $releaseRejectedHistoryBefore = DB::table(
            'bed_allocation_history'
        )
            ->where(
                'source_allocation_id',
                $releaseRejectedAllocationId
            )
            ->count();

        $releaseAuditBefore = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('module', 'Boarding')
            ->where('action', 'Release')
            ->count();

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                self::ENDPOINT
                    .'/'
                    .$releaseRejectedAllocationId
                    .'/release',
                [
                    'reason' => str_repeat('R', 501),
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'reason',
            ]);

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $releaseRejectedAllocationId,
                'school_id' => $school->id,
                'learner_id' => $releaseRejectedLearner->id,
                'bed_id' => $releaseRejectedBed->id,
                'status' => 'active',
                'active' => true,
                'release_date' => null,
            ]
        );

        $this->assertSame(
            $releaseRejectedHistoryBefore,
            DB::table('bed_allocation_history')
                ->where(
                    'source_allocation_id',
                    $releaseRejectedAllocationId
                )
                ->count()
        );

        $this->assertSame(
            $releaseAuditBefore,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Release')
                ->count()
        );

        /*
         * RELEASE 500:
         * Exact database bound must pass through the public API
         * and be preserved byte-for-byte in lifecycle history.
         */
        $releaseAcceptedLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $releaseAcceptedBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-RELEASE-REASON-500'
        );

        $releaseAcceptedAllocationId = $this->withToken(
            $this->tokenFor($user)
        )
            ->postJson(self::ENDPOINT, [
                'learner_id' => $releaseAcceptedLearner->id,
                'bed_id' => $releaseAcceptedBed->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotEmpty($releaseAcceptedAllocationId);

        $releaseReason = str_repeat('R', 500);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                self::ENDPOINT
                    .'/'
                    .$releaseAcceptedAllocationId
                    .'/release',
                [
                    'reason' => $releaseReason,
                ]
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.active', false);

        $releaseHistory = DB::table('bed_allocation_history')
            ->where('school_id', $school->id)
            ->where(
                'source_allocation_id',
                $releaseAcceptedAllocationId
            )
            ->where('event_type', 'release')
            ->sole();

        $this->assertSame(
            $releaseReason,
            $releaseHistory->reason
        );

        $this->assertSame(
            500,
            mb_strlen((string) $releaseHistory->reason)
        );

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $releaseAcceptedAllocationId,
                'status' => 'released',
                'active' => false,
            ]
        );

        /*
         * TRANSFER 501:
         * Rejection must leave the source active, destination empty,
         * history unchanged and no transfer audit emitted.
         */
        $transferRejectedLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $transferRejectedSourceBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-TRANSFER-REASON-501-SOURCE'
        );

        $transferRejectedDestinationBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-TRANSFER-REASON-501-DEST'
        );

        $transferRejectedAllocationId = $this->withToken(
            $this->tokenFor($user)
        )
            ->postJson(self::ENDPOINT, [
                'learner_id' => $transferRejectedLearner->id,
                'bed_id' => $transferRejectedSourceBed->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotEmpty($transferRejectedAllocationId);

        $transferRejectedHistoryBefore = DB::table(
            'bed_allocation_history'
        )
            ->where(
                'source_allocation_id',
                $transferRejectedAllocationId
            )
            ->count();

        $transferAuditBefore = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('module', 'Boarding')
            ->where('action', 'Transfer')
            ->count();

        $this->withToken($this->tokenFor($user))
            ->postJson(
                self::ENDPOINT
                    .'/'
                    .$transferRejectedAllocationId
                    .'/transfer',
                [
                    'destination_bed_id' => $transferRejectedDestinationBed->id,
                    'reason' => str_repeat('T', 501),
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'reason',
            ]);

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $transferRejectedAllocationId,
                'school_id' => $school->id,
                'learner_id' => $transferRejectedLearner->id,
                'bed_id' => $transferRejectedSourceBed->id,
                'status' => 'active',
                'active' => true,
                'release_date' => null,
            ]
        );

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where(
                    'bed_id',
                    $transferRejectedDestinationBed->id
                )
                ->count()
        );

        $this->assertSame(
            $transferRejectedHistoryBefore,
            DB::table('bed_allocation_history')
                ->where(
                    'source_allocation_id',
                    $transferRejectedAllocationId
                )
                ->count()
        );

        $this->assertSame(
            $transferAuditBefore,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Transfer')
                ->count()
        );

        /*
         * TRANSFER 500:
         * Exact database bound must succeed and the full reason must
         * survive into the correlated immutable history event.
         */
        $transferAcceptedLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $transferAcceptedSourceBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-TRANSFER-REASON-500-SOURCE'
        );

        $transferAcceptedDestinationBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-TRANSFER-REASON-500-DEST'
        );

        $transferAcceptedAllocationId = $this->withToken(
            $this->tokenFor($user)
        )
            ->postJson(self::ENDPOINT, [
                'learner_id' => $transferAcceptedLearner->id,
                'bed_id' => $transferAcceptedSourceBed->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotEmpty($transferAcceptedAllocationId);

        $transferReason = str_repeat('T', 500);

        $transferResponse = $this->withToken(
            $this->tokenFor($user)
        )
            ->postJson(
                self::ENDPOINT
                    .'/'
                    .$transferAcceptedAllocationId
                    .'/transfer',
                [
                    'destination_bed_id' => $transferAcceptedDestinationBed->id,
                    'reason' => $transferReason,
                ]
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.active', true);

        $transferDestinationAllocationId = $transferResponse->json(
            'data.id'
        );

        $this->assertNotEmpty(
            $transferDestinationAllocationId
        );

        $this->assertNotSame(
            $transferAcceptedAllocationId,
            $transferDestinationAllocationId
        );

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $transferAcceptedAllocationId,
                'status' => 'transferred',
                'active' => false,
            ]
        );

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $transferDestinationAllocationId,
                'school_id' => $school->id,
                'learner_id' => $transferAcceptedLearner->id,
                'bed_id' => $transferAcceptedDestinationBed->id,
                'status' => 'active',
                'active' => true,
                'release_date' => null,
            ]
        );

        $transferHistory = DB::table('bed_allocation_history')
            ->where('school_id', $school->id)
            ->where(
                'source_allocation_id',
                $transferAcceptedAllocationId
            )
            ->where(
                'destination_allocation_id',
                $transferDestinationAllocationId
            )
            ->where('event_type', 'transfer')
            ->sole();

        $this->assertSame(
            $transferReason,
            $transferHistory->reason
        );

        $this->assertSame(
            500,
            mb_strlen((string) $transferHistory->reason)
        );
    }

    public function test_terminal_allocation_cannot_be_released_or_transferred_again_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $token = $this->tokenFor($user);

        /*
         * CASE A:
         * Create a legitimate allocation and release it once.
         * That terminal episode must never be released or transferred again.
         */
        $releasedLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $releasedSourceBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A8-RELEASED-SOURCE'
        );

        $releasedRetryDestinationBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A8-RELEASED-RETRY-DEST'
        );

        $releasedAllocationResponse = $this
            ->withToken($token)
            ->postJson(self::ENDPOINT, [
                'learner_id' => $releasedLearner->id,
                'bed_id' => $releasedSourceBed->id,
            ])
            ->assertCreated();

        $releasedAllocationId = (string) $releasedAllocationResponse->json(
            'data.id'
        );

        $this->assertNotSame('', $releasedAllocationId);

        $this->withToken($token)
            ->patchJson(
                self::ENDPOINT.'/'.$releasedAllocationId.'/release',
                [
                    'reason' => 'Legitimate terminal release.',
                ]
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.active', false);

        $releasedRow = DB::table('bed_allocations')
            ->where('id', $releasedAllocationId)
            ->where('school_id', $school->id)
            ->first();

        $this->assertNotNull($releasedRow);
        $this->assertSame('released', $releasedRow->status);
        $this->assertFalse((bool) $releasedRow->active);
        $this->assertNotNull($releasedRow->release_date);

        $releasedReleaseDate = $releasedRow->release_date;

        $releasedHistoryCount = DB::table('bed_allocation_history')
            ->where('school_id', $school->id)
            ->where('source_allocation_id', $releasedAllocationId)
            ->count();

        $this->assertSame(1, $releasedHistoryCount);

        $releasedReleaseAuditCount = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('module', 'Boarding')
            ->where('action', 'Release')
            ->count();

        $releasedTransferAuditCount = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('module', 'Boarding')
            ->where('action', 'Transfer')
            ->count();

        /*
         * ATTACK A1:
         * released -> release
         */
        $this->withToken($token)
            ->patchJson(
                self::ENDPOINT.'/'.$releasedAllocationId.'/release',
                [
                    'reason' => 'Illegal second release.',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'allocation_id',
            ]);

        /*
         * ATTACK A2:
         * released -> transfer
         */
        $this->withToken($token)
            ->postJson(
                self::ENDPOINT.'/'.$releasedAllocationId.'/transfer',
                [
                    'destination_bed_id' => $releasedRetryDestinationBed->id,
                    'reason' => 'Illegal transfer after release.',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'allocation_id',
            ]);

        $releasedAfterRetries = DB::table('bed_allocations')
            ->where('id', $releasedAllocationId)
            ->where('school_id', $school->id)
            ->first();

        $this->assertNotNull($releasedAfterRetries);
        $this->assertSame('released', $releasedAfterRetries->status);
        $this->assertFalse((bool) $releasedAfterRetries->active);
        $this->assertSame(
            (string) $releasedReleaseDate,
            (string) $releasedAfterRetries->release_date
        );
        $this->assertSame(
            (string) $releasedSourceBed->id,
            (string) $releasedAfterRetries->bed_id
        );
        $this->assertSame(
            (string) $releasedLearner->id,
            (string) $releasedAfterRetries->learner_id
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $releasedLearner->id)
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $releasedLearner->id)
                ->where('active', true)
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('bed_id', $releasedRetryDestinationBed->id)
                ->count()
        );

        $this->assertSame(
            $releasedHistoryCount,
            DB::table('bed_allocation_history')
                ->where('school_id', $school->id)
                ->where('source_allocation_id', $releasedAllocationId)
                ->count()
        );

        $this->assertSame(
            $releasedReleaseAuditCount,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Release')
                ->count()
        );

        $this->assertSame(
            $releasedTransferAuditCount,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Transfer')
                ->count()
        );

        /*
         * CASE B:
         * Create a separate allocation and legitimately transfer it once.
         * The old source becomes terminal while the new destination is the
         * learner's sole active occupancy.
         */
        $transferredLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $transferredSourceBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A8-TRANSFERRED-SOURCE'
        );

        $legitimateDestinationBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A8-TRANSFERRED-DEST'
        );

        $illegalRetryDestinationBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A8-TRANSFERRED-RETRY-DEST'
        );

        $transferredSourceResponse = $this
            ->withToken($token)
            ->postJson(self::ENDPOINT, [
                'learner_id' => $transferredLearner->id,
                'bed_id' => $transferredSourceBed->id,
            ])
            ->assertCreated();

        $transferredSourceAllocationId = (string) $transferredSourceResponse
            ->json('data.id');

        $this->assertNotSame('', $transferredSourceAllocationId);

        $legitimateTransferResponse = $this
            ->withToken($token)
            ->postJson(
                self::ENDPOINT
                    .'/'
                    .$transferredSourceAllocationId
                    .'/transfer',
                [
                    'destination_bed_id' => $legitimateDestinationBed->id,
                    'reason' => 'Legitimate transfer.',
                ]
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.active', true);

        $legitimateDestinationAllocationId = (string)
            $legitimateTransferResponse->json('data.id');

        $this->assertNotSame('', $legitimateDestinationAllocationId);
        $this->assertNotSame(
            $transferredSourceAllocationId,
            $legitimateDestinationAllocationId
        );

        $transferredSourceRow = DB::table('bed_allocations')
            ->where('id', $transferredSourceAllocationId)
            ->where('school_id', $school->id)
            ->first();

        $this->assertNotNull($transferredSourceRow);
        $this->assertSame('transferred', $transferredSourceRow->status);
        $this->assertFalse((bool) $transferredSourceRow->active);
        $this->assertNotNull($transferredSourceRow->release_date);

        $transferredReleaseDate = $transferredSourceRow->release_date;

        $transferHistory = DB::table('bed_allocation_history')
            ->where('school_id', $school->id)
            ->where(
                'source_allocation_id',
                $transferredSourceAllocationId
            )
            ->where(
                'destination_allocation_id',
                $legitimateDestinationAllocationId
            )
            ->where('event_type', 'transfer')
            ->sole();

        $this->assertSame(
            'active',
            $transferHistory->from_status
        );
        $this->assertSame(
            'transferred',
            $transferHistory->to_status
        );

        $transferredHistoryCount = DB::table('bed_allocation_history')
            ->where('school_id', $school->id)
            ->where(function ($query) use (
                $transferredSourceAllocationId,
                $legitimateDestinationAllocationId
            ): void {
                $query
                    ->where(
                        'source_allocation_id',
                        $transferredSourceAllocationId
                    )
                    ->orWhere(
                        'destination_allocation_id',
                        $legitimateDestinationAllocationId
                    );
            })
            ->count();

        $this->assertSame(1, $transferredHistoryCount);

        $transferCaseReleaseAuditCount = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('module', 'Boarding')
            ->where('action', 'Release')
            ->count();

        $transferCaseTransferAuditCount = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('module', 'Boarding')
            ->where('action', 'Transfer')
            ->count();

        /*
         * ATTACK B1:
         * transferred source -> release
         */
        $this->withToken($token)
            ->patchJson(
                self::ENDPOINT
                    .'/'
                    .$transferredSourceAllocationId
                    .'/release',
                [
                    'reason' => 'Illegal release after transfer.',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'allocation_id',
            ]);

        /*
         * ATTACK B2:
         * transferred source -> transfer
         */
        $this->withToken($token)
            ->postJson(
                self::ENDPOINT
                    .'/'
                    .$transferredSourceAllocationId
                    .'/transfer',
                [
                    'destination_bed_id' => $illegalRetryDestinationBed->id,
                    'reason' => 'Illegal second transfer.',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'allocation_id',
            ]);

        /*
         * Source terminal state must be byte-for-byte lifecycle stable.
         */
        $transferredSourceAfterRetries = DB::table('bed_allocations')
            ->where('id', $transferredSourceAllocationId)
            ->where('school_id', $school->id)
            ->first();

        $this->assertNotNull($transferredSourceAfterRetries);
        $this->assertSame(
            'transferred',
            $transferredSourceAfterRetries->status
        );
        $this->assertFalse(
            (bool) $transferredSourceAfterRetries->active
        );
        $this->assertSame(
            (string) $transferredReleaseDate,
            (string) $transferredSourceAfterRetries->release_date
        );
        $this->assertSame(
            (string) $transferredSourceBed->id,
            (string) $transferredSourceAfterRetries->bed_id
        );
        $this->assertSame(
            (string) $transferredLearner->id,
            (string) $transferredSourceAfterRetries->learner_id
        );

        /*
         * Legitimate destination must remain exactly the learner's sole
         * active occupancy after both attacks.
         */
        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $legitimateDestinationAllocationId,
                'school_id' => $school->id,
                'learner_id' => $transferredLearner->id,
                'bed_id' => $legitimateDestinationBed->id,
                'status' => 'active',
                'active' => true,
                'release_date' => null,
            ]
        );

        $this->assertSame(
            2,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $transferredLearner->id)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $transferredLearner->id)
                ->where('active', true)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('bed_id', $legitimateDestinationBed->id)
                ->where('active', true)
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('bed_id', $illegalRetryDestinationBed->id)
                ->count()
        );

        /*
         * The one legitimate transfer event must remain the only event
         * connected to this transfer pair.
         */
        $this->assertSame(
            $transferredHistoryCount,
            DB::table('bed_allocation_history')
                ->where('school_id', $school->id)
                ->where(function ($query) use (
                    $transferredSourceAllocationId,
                    $legitimateDestinationAllocationId
                ): void {
                    $query
                        ->where(
                            'source_allocation_id',
                            $transferredSourceAllocationId
                        )
                        ->orWhere(
                            'destination_allocation_id',
                            $legitimateDestinationAllocationId
                        );
                })
                ->count()
        );

        $this->assertDatabaseHas(
            'bed_allocation_history',
            [
                'id' => $transferHistory->id,
                'event_id' => $transferHistory->event_id,
                'school_id' => $school->id,
                'learner_id' => $transferredLearner->id,
                'event_type' => 'transfer',
                'source_allocation_id' => $transferredSourceAllocationId,
                'destination_allocation_id' => $legitimateDestinationAllocationId,
                'from_status' => 'active',
                'to_status' => 'transferred',
            ]
        );

        /*
         * Rejected retries must not emit successful lifecycle audits.
         */
        $this->assertSame(
            $transferCaseReleaseAuditCount,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Release')
                ->count()
        );

        $this->assertSame(
            $transferCaseTransferAuditCount,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Transfer')
                ->count()
        );
    }

    public function test_history_json_is_privacy_safe_and_preserves_release_and_transfer_correlation(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $token = $this->tokenFor($user);

        /*
         * The public history serializer is an explicit allowlist.
         * Freeze that contract so tenant ownership and any future
         * database-only columns cannot leak through model serialization.
         */
        $expectedHistoryKeys = [
            'id',
            'event_id',
            'event_type',
            'learner_id',
            'source_allocation_id',
            'destination_allocation_id',
            'from_status',
            'to_status',
            'effective_date',
            'reason',
            'changed_by',
            'changed_at',
            'created_at',
        ];

        /*
         * RELEASE HISTORY
         */
        $releasedLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $releasedBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A9-RELEASE'
        );

        $releaseAllocationResponse = $this
            ->withToken($token)
            ->postJson(self::ENDPOINT, [
                'learner_id' => $releasedLearner->id,
                'bed_id' => $releasedBed->id,
            ])
            ->assertCreated();

        $releaseAllocationId = (string) $releaseAllocationResponse
            ->json('data.id');

        $this->assertNotSame('', $releaseAllocationId);

        $releaseReason = 'HTTP-2A.9 release correlation.';

        $this->withToken($token)
            ->patchJson(
                self::ENDPOINT.'/'.$releaseAllocationId.'/release',
                [
                    'reason' => $releaseReason,
                ]
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.active', false);

        $releaseHistoryResponse = $this
            ->withToken($token)
            ->getJson(
                self::ENDPOINT.'/'.$releaseAllocationId.'/history'
            )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_type', 'release')
            ->assertJsonPath(
                'data.0.learner_id',
                $releasedLearner->id
            )
            ->assertJsonPath(
                'data.0.source_allocation_id',
                $releaseAllocationId
            )
            ->assertJsonPath(
                'data.0.destination_allocation_id',
                null
            )
            ->assertJsonPath('data.0.from_status', 'active')
            ->assertJsonPath('data.0.to_status', 'released')
            ->assertJsonPath('data.0.reason', $releaseReason)
            ->assertJsonPath('data.0.changed_by', $user->id);

        $releaseEvent = $releaseHistoryResponse->json('data.0');

        $this->assertIsArray($releaseEvent);

        $releaseKeys = array_keys($releaseEvent);
        sort($releaseKeys);

        $expectedReleaseKeys = $expectedHistoryKeys;
        sort($expectedReleaseKeys);

        $this->assertSame(
            $expectedReleaseKeys,
            $releaseKeys,
            'Release history JSON must expose only the frozen public allowlist.'
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $releaseEvent
        );

        $this->assertNotEmpty($releaseEvent['id']);
        $this->assertNotEmpty($releaseEvent['event_id']);
        $this->assertNotSame(
            $releaseEvent['id'],
            $releaseEvent['event_id']
        );
        $this->assertNotEmpty($releaseEvent['effective_date']);
        $this->assertNotEmpty($releaseEvent['changed_at']);
        $this->assertNotEmpty($releaseEvent['created_at']);

        $releaseDbEvent = DB::table('bed_allocation_history')
            ->where('school_id', $school->id)
            ->where('source_allocation_id', $releaseAllocationId)
            ->where('event_type', 'release')
            ->sole();

        $this->assertSame(
            (string) $releaseDbEvent->id,
            (string) $releaseEvent['id']
        );
        $this->assertSame(
            (string) $releaseDbEvent->event_id,
            (string) $releaseEvent['event_id']
        );
        $this->assertNull(
            $releaseDbEvent->destination_allocation_id
        );

        /*
         * TRANSFER HISTORY
         *
         * A transfer is one logical immutable event. Reading history
         * through either the terminal source episode or the new active
         * destination episode must therefore expose the same event.
         */
        $transferredLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $transferSourceBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A9-TRANSFER-SOURCE'
        );

        $transferDestinationBed = $this->boardingBed(
            $school,
            'GIRLS',
            'HTTP-2A9-TRANSFER-DEST'
        );

        $transferSourceResponse = $this
            ->withToken($token)
            ->postJson(self::ENDPOINT, [
                'learner_id' => $transferredLearner->id,
                'bed_id' => $transferSourceBed->id,
            ])
            ->assertCreated();

        $transferSourceAllocationId = (string) $transferSourceResponse
            ->json('data.id');

        $this->assertNotSame('', $transferSourceAllocationId);

        $transferReason = 'HTTP-2A.9 transfer correlation.';

        $transferResponse = $this
            ->withToken($token)
            ->postJson(
                self::ENDPOINT
                    .'/'
                    .$transferSourceAllocationId
                    .'/transfer',
                [
                    'destination_bed_id' => $transferDestinationBed->id,
                    'reason' => $transferReason,
                ]
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.active', true);

        $transferDestinationAllocationId = (string)
            $transferResponse->json('data.id');

        $this->assertNotSame(
            '',
            $transferDestinationAllocationId
        );

        $this->assertNotSame(
            $transferSourceAllocationId,
            $transferDestinationAllocationId
        );

        $sourceHistoryResponse = $this
            ->withToken($token)
            ->getJson(
                self::ENDPOINT
                    .'/'
                    .$transferSourceAllocationId
                    .'/history'
            )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_type', 'transfer')
            ->assertJsonPath(
                'data.0.learner_id',
                $transferredLearner->id
            )
            ->assertJsonPath(
                'data.0.source_allocation_id',
                $transferSourceAllocationId
            )
            ->assertJsonPath(
                'data.0.destination_allocation_id',
                $transferDestinationAllocationId
            )
            ->assertJsonPath('data.0.from_status', 'active')
            ->assertJsonPath('data.0.to_status', 'transferred')
            ->assertJsonPath('data.0.reason', $transferReason)
            ->assertJsonPath('data.0.changed_by', $user->id);

        $destinationHistoryResponse = $this
            ->withToken($token)
            ->getJson(
                self::ENDPOINT
                    .'/'
                    .$transferDestinationAllocationId
                    .'/history'
            )
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $sourceEvent = $sourceHistoryResponse->json('data.0');
        $destinationEvent = $destinationHistoryResponse->json(
            'data.0'
        );

        $this->assertIsArray($sourceEvent);
        $this->assertIsArray($destinationEvent);

        /*
         * Exact response equality freezes source/destination lookup
         * correlation: both endpoints describe the same immutable event.
         */
        $this->assertSame(
            $sourceEvent,
            $destinationEvent
        );

        $sourceKeys = array_keys($sourceEvent);
        sort($sourceKeys);

        $destinationKeys = array_keys($destinationEvent);
        sort($destinationKeys);

        $expectedTransferKeys = $expectedHistoryKeys;
        sort($expectedTransferKeys);

        $this->assertSame(
            $expectedTransferKeys,
            $sourceKeys,
            'Source history JSON must expose only the frozen public allowlist.'
        );

        $this->assertSame(
            $expectedTransferKeys,
            $destinationKeys,
            'Destination history JSON must expose only the frozen public allowlist.'
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $sourceEvent
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $destinationEvent
        );

        $this->assertNotEmpty($sourceEvent['id']);
        $this->assertNotEmpty($sourceEvent['event_id']);
        $this->assertNotSame(
            $sourceEvent['id'],
            $sourceEvent['event_id']
        );
        $this->assertNotEmpty($sourceEvent['effective_date']);
        $this->assertNotEmpty($sourceEvent['changed_at']);
        $this->assertNotEmpty($sourceEvent['created_at']);

        $this->assertSame(
            (string) $sourceEvent['id'],
            (string) $destinationEvent['id']
        );

        $this->assertSame(
            (string) $sourceEvent['event_id'],
            (string) $destinationEvent['event_id']
        );

        $transferDbEvent = DB::table('bed_allocation_history')
            ->where('school_id', $school->id)
            ->where(
                'source_allocation_id',
                $transferSourceAllocationId
            )
            ->where(
                'destination_allocation_id',
                $transferDestinationAllocationId
            )
            ->where('event_type', 'transfer')
            ->sole();

        $this->assertSame(
            (string) $transferDbEvent->id,
            (string) $sourceEvent['id']
        );

        $this->assertSame(
            (string) $transferDbEvent->event_id,
            (string) $sourceEvent['event_id']
        );

        $this->assertSame(
            (string) $transferSourceAllocationId,
            (string) $transferDbEvent->source_allocation_id
        );

        $this->assertSame(
            (string) $transferDestinationAllocationId,
            (string) $transferDbEvent->destination_allocation_id
        );

        /*
         * Final occupancy proof: correlation reads are read-only.
         */
        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $transferSourceAllocationId,
                'school_id' => $school->id,
                'learner_id' => $transferredLearner->id,
                'bed_id' => $transferSourceBed->id,
                'status' => 'transferred',
                'active' => false,
            ]
        );

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $transferDestinationAllocationId,
                'school_id' => $school->id,
                'learner_id' => $transferredLearner->id,
                'bed_id' => $transferDestinationBed->id,
                'status' => 'active',
                'active' => true,
                'release_date' => null,
            ]
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $transferredLearner->id)
                ->where('active', true)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('bed_allocation_history')
                ->where('school_id', $school->id)
                ->where('event_id', $sourceEvent['event_id'])
                ->count()
        );
    }

    private function grantManageBoarding(
        User $user
    ): void {
        $permissionId = DB::table('permissions')
            ->where(
                'permission_name',
                'manage_boarding'
            )
            ->value('id');

        $this->assertNotNull(
            $permissionId,
            'manage_boarding must be provisioned by the production migration.'
        );

        DB::table('role_permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'role_id' => $user->role_id,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Allocation HTTP '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'BAH-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'BAH',
            'registration_number' => 'REG-'.Str::upper(
                Str::random(10)
            ),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(
                10000000,
                99999999
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function user(
        School $school
    ): User {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Allocation HTTP '.Str::upper(
                Str::random(8)
            ),
            'description' => 'Bed allocation HTTP test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Boarding',
            'last_name' => 'HTTP',
            'username' => 'boarding_http_'.Str::lower(
                Str::random(10)
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
        ]);
    }

    private function completeOperationalSetup(
        School $school
    ): void {
        $academicYearId = (string) Str::uuid();
        $gradeId = (string) Str::uuid();

        DB::table('academic_years')->insert([
            'id' => $academicYearId,
            'school_id' => $school->id,
            'year_name' => 'Operational '.Str::upper(
                Str::random(8)
            ),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('terms')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'academic_year_id' => $academicYearId,
            'term_name' => 'Operational '.Str::upper(
                Str::random(6)
            ),
            'start_date' => '2026-01-01',
            'end_date' => '2026-04-30',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('grades')->insert([
            'id' => $gradeId,
            'school_id' => $school->id,
            'grade_name' => 'Readiness '.Str::upper(
                Str::random(8)
            ),
            'grade_order' => random_int(
                1001,
                2000
            ),
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('streams')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $gradeId,
            'stream_name' => 'Readiness '.Str::upper(
                Str::random(8)
            ),
            'active' => true,
            'created_at' => now(),
        ]);
    }

    private function learner(
        School $school,
        string $gender,
        string $modeOfStudy,
        ?string $lifecycleStatus,
        bool $active
    ): Learner {
        $gradeId = (string) Str::uuid();
        $streamId = (string) Str::uuid();

        DB::table('grades')->insert([
            'id' => $gradeId,
            'school_id' => $school->id,
            'grade_name' => 'Grade '.Str::upper(
                Str::random(8)
            ),
            'grade_order' => random_int(
                2001,
                1000000
            ),
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('streams')->insert([
            'id' => $streamId,
            'school_id' => $school->id,
            'grade_id' => $gradeId,
            'stream_name' => 'Stream '.Str::upper(
                Str::random(8)
            ),
            'active' => true,
            'created_at' => now(),
        ]);

        $learner = new Learner;

        $learner->id = (string) Str::uuid();
        $learner->school_id = $school->id;
        $learner->admission_no = 'HTTP-'.Str::upper(
            Str::random(8)
        );
        $learner->first_name = 'Allocation';
        $learner->last_name = 'Learner';
        $learner->gender = $gender;
        $learner->grade_id = $gradeId;
        $learner->stream_id = $streamId;
        $learner->admission_date = now()->toDateString();
        $learner->active = $active;
        $learner->lifecycle_status = $lifecycleStatus;
        $learner->mode_of_study = $modeOfStudy;

        $learner->save();

        return $learner->refresh();
    }

    private function boardingBed(
        School $school,
        string $hostelType,
        string $suffix = ''
    ): HostelBed {
        $suffix = $suffix !== ''
            ? '-'.$suffix
            : '-'.Str::upper(Str::random(4));

        $hostel = new Hostel;

        $hostel->school_id = $school->id;
        $hostel->hostel_name = 'Hostel '
            .$hostelType
            .$suffix;
        $hostel->hostel_type = $hostelType;
        $hostel->capacity = 20;
        $hostel->active = true;
        $hostel->is_deleted = false;
        $hostel->save();

        $room = new HostelRoom;

        $room->school_id = $school->id;
        $room->hostel_id = $hostel->id;
        $room->room_name = 'Room'.$suffix;
        $room->floor_number = null;
        $room->capacity = 10;
        $room->active = true;
        $room->is_deleted = false;
        $room->save();

        $bed = new HostelBed;

        $bed->school_id = $school->id;
        $bed->room_id = $room->id;
        $bed->bed_number = 'BED'.$suffix;
        $bed->active = true;
        $bed->is_deleted = false;
        $bed->save();

        return $bed->refresh();
    }

    private function tokenFor(
        User $user
    ): string {
        return JWTAuth::fromUser(
            User::query()
                ->withoutGlobalScopes()
                ->findOrFail($user->id)
        );
    }
}
