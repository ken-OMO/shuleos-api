<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Learner;
use App\Models\Role;
use App\Models\School;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SchoolAdminGuardianLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_guardian_crud_and_link_routes_require_manage_guardians_permission(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        foreach ([
            ['GET', 'api/guardians'],
            ['POST', 'api/guardians'],
            ['GET', 'api/guardians/{id}'],
            ['PUT', 'api/guardians/{id}'],
            ['DELETE', 'api/guardians/{id}'],
            ['GET', 'api/learners/{learner}/guardians'],
            ['POST', 'api/learners/{learner}/guardians'],
            ['PUT', 'api/learners/{learner}/guardians/{link}'],
            ['DELETE', 'api/learners/{learner}/guardians/{link}'],
        ] as [$method, $uri]) {
            $route = $routes->first(
                fn ($route) => in_array($method, $route->methods(), true)
                    && $route->uri() === $uri
            );

            $this->assertNotNull(
                $route,
                "{$method} {$uri} route is missing."
            );

            $this->assertContains(
                'permission:manage_guardians',
                $route->gatherMiddleware(),
                "{$method} {$uri} must require manage_guardians."
            );
        }
    }

    public function test_user_without_manage_guardians_permission_cannot_create_guardian(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $this->completeOperationalSetup($school);

        $payload = $this->guardianPayload();
        $payload['school_id'] = $school->id;

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/guardians', $payload)
            ->assertStatus(403);

        $this->assertDatabaseMissing('parents', [
            'school_id' => $school->id,
            'phone' => $payload['phone'],
        ]);
    }

    public function test_guardian_can_be_created_without_client_school_id(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $payload = $this->guardianPayload();

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/guardians', $payload)
            ->assertSuccessful();

        $this->assertDatabaseHas('parents', [
            'school_id' => $school->id,
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'phone' => $payload['phone'],
            'active' => true,
            'is_deleted' => false,
        ]);
    }

    public function test_guardian_profile_relationship_is_optional(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $payload = $this->guardianPayload();
        unset($payload['relationship']);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/guardians', $payload)
            ->assertSuccessful();

        $this->assertDatabaseHas('parents', [
            'school_id' => $school->id,
            'phone' => $payload['phone'],
        ]);
    }

    public function test_foreign_school_id_cannot_redirect_guardian_creation(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $foreignSchool = $this->school();

        $payload = $this->guardianPayload();
        $payload['school_id'] = $foreignSchool->id;

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/guardians', $payload)
            ->assertSuccessful();

        $guardian = Guardian::query()
            ->withoutGlobalScopes()
            ->where('phone', $payload['phone'])
            ->firstOrFail();

        $this->assertSame($school->id, $guardian->school_id);
        $this->assertNotSame($foreignSchool->id, $guardian->school_id);
    }

    public function test_guardian_creation_does_not_create_or_assign_parent_user_account(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $otherUser = $this->user($school);

        $payload = $this->guardianPayload();
        $payload['user_id'] = $otherUser->id;

        $usersBefore = DB::table('users')->count();

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/guardians', $payload)
            ->assertSuccessful();

        $guardian = Guardian::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('phone', $payload['phone'])
            ->firstOrFail();

        $this->assertNull($guardian->user_id);
        $this->assertSame($usersBefore, DB::table('users')->count());
    }

    public function test_guardian_listing_is_tenant_scoped_and_excludes_deleted_guardians(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $foreignSchool = $this->school();

        $visible = $this->guardian($school, 'Visible');
        $deleted = $this->guardian($school, 'Deleted');
        $foreign = $this->guardian($foreignSchool, 'Foreign');

        DB::table('parents')
            ->where('id', $deleted->id)
            ->update([
                'active' => false,
                'is_deleted' => true,
                'deleted_at' => now(),
            ]);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/guardians')
            ->assertSuccessful();

        $content = $response->getContent();

        $this->assertStringContainsString($visible->id, $content);
        $this->assertStringNotContainsString($deleted->id, $content);
        $this->assertStringNotContainsString($foreign->id, $content);
    }

    public function test_foreign_guardian_cannot_be_viewed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $foreign = $this->guardian(
            $this->school(),
            'Foreign'
        );

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/guardians/'.$foreign->id)
            ->assertStatus(404);
    }

    public function test_foreign_guardian_cannot_be_updated(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $foreign = $this->guardian(
            $this->school(),
            'Foreign'
        );

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/guardians/'.$foreign->id, [
                'first_name' => 'Compromised',
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('parents', [
            'id' => $foreign->id,
            'first_name' => 'Compromised',
        ]);
    }

    public function test_foreign_guardian_cannot_be_deleted(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $foreign = $this->guardian(
            $this->school(),
            'Foreign'
        );

        $this->withToken($this->tokenFor($user))
            ->deleteJson('/api/guardians/'.$foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('parents', [
            'id' => $foreign->id,
            'is_deleted' => false,
        ]);
    }

    public function test_guardian_response_does_not_expose_school_or_user_authority_objects(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $guardian = $this->guardian($school);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/guardians/'.$guardian->id)
            ->assertSuccessful();

        $response->assertJsonMissingPath('data.school');
        $response->assertJsonMissingPath('data.user');
    }

    public function test_same_school_guardian_can_be_linked_to_learner(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $this->linkPayload($guardian)
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('learner_parents', [
            'learner_id' => $learner->id,
            'parent_id' => $guardian->id,
            'relationship' => 'Mother',
            'active' => true,
            'is_deleted' => false,
        ]);
    }

    public function test_foreign_guardian_cannot_be_linked_to_learner(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $foreignGuardian = $this->guardian($this->school());

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $this->linkPayload($foreignGuardian)
            )
            ->assertStatus(422);

        $this->assertDatabaseMissing('learner_parents', [
            'learner_id' => $learner->id,
            'parent_id' => $foreignGuardian->id,
        ]);
    }

    public function test_foreign_learner_cannot_be_linked_to_guardian(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $guardian = $this->guardian($school);

        $foreignSchool = $this->school();
        [$foreignGrade, $foreignStream] = $this->gradeAndStream($foreignSchool);

        $foreignLearner = $this->learner(
            $foreignSchool,
            $foreignGrade,
            $foreignStream,
            'FOREIGN-001'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$foreignLearner->id.'/guardians',
                $this->linkPayload($guardian)
            )
            ->assertStatus(404);

        $this->assertDatabaseMissing('learner_parents', [
            'learner_id' => $foreignLearner->id,
            'parent_id' => $guardian->id,
        ]);
    }

    public function test_inactive_guardian_cannot_be_linked(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        DB::table('parents')
            ->where('id', $guardian->id)
            ->update(['active' => false]);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $this->linkPayload($guardian)
            )
            ->assertStatus(422);
    }

    public function test_inactive_learner_cannot_be_linked(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        DB::table('learners')
            ->where('id', $learner->id)
            ->update(['active' => false]);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $this->linkPayload($guardian)
            )
            ->assertStatus(422);
    }

    public function test_link_relationship_is_stored_on_learner_parent_record(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        $payload = $this->linkPayload($guardian);
        $payload['relationship'] = 'Grandmother';

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $payload
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('learner_parents', [
            'learner_id' => $learner->id,
            'parent_id' => $guardian->id,
            'relationship' => 'Grandmother',
        ]);

        $this->assertNotSame(
            'Grandmother',
            DB::table('parents')
                ->where('id', $guardian->id)
                ->value('relationship')
        );
    }

    public function test_linking_does_not_enable_parent_portal_by_default_or_trust_client_override(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        $payload = $this->linkPayload($guardian);
        $payload['portal_enabled'] = true;

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $payload
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('learner_parents', [
            'learner_id' => $learner->id,
            'parent_id' => $guardian->id,
            'portal_enabled' => false,
        ]);
    }

    public function test_linked_by_and_linked_at_are_server_controlled(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        $attackerId = (string) Str::uuid();

        $payload = $this->linkPayload($guardian);
        $payload['linked_by'] = $attackerId;
        $payload['linked_at'] = '2000-01-01 00:00:00';

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $payload
            )
            ->assertSuccessful();

        $link = DB::table('learner_parents')
            ->where('learner_id', $learner->id)
            ->where('parent_id', $guardian->id)
            ->first();

        $this->assertNotNull($link);
        $this->assertSame($user->id, $link->linked_by);
        $this->assertNotSame($attackerId, $link->linked_by);
        $this->assertNotNull($link->linked_at);
        $this->assertNotSame(
            '2000-01-01 00:00:00',
            (string) $link->linked_at
        );
    }

    public function test_current_duplicate_link_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        $this->learnerParentLink(
            $learner,
            $guardian,
            $user
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $this->linkPayload($guardian)
            )
            ->assertStatus(422);

        $this->assertSame(
            1,
            DB::table('learner_parents')
                ->where('learner_id', $learner->id)
                ->where('parent_id', $guardian->id)
                ->count()
        );
    }

    public function test_soft_deleted_link_is_restored_instead_of_reinserted(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        $linkId = $this->learnerParentLink(
            $learner,
            $guardian,
            $user,
            [
                'active' => false,
                'portal_enabled' => false,
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $user->id,
            ]
        );

        $payload = $this->linkPayload($guardian);
        $payload['relationship'] = 'Aunt';

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $payload
            )
            ->assertSuccessful();

        $this->assertSame(
            1,
            DB::table('learner_parents')
                ->where('learner_id', $learner->id)
                ->where('parent_id', $guardian->id)
                ->count()
        );

        $this->assertDatabaseHas('learner_parents', [
            'id' => $linkId,
            'learner_id' => $learner->id,
            'parent_id' => $guardian->id,
            'relationship' => 'Aunt',
            'active' => true,
            'portal_enabled' => false,
            'is_deleted' => false,
            'deleted_at' => null,
            'deleted_by' => null,
            'linked_by' => $user->id,
        ]);
    }

    public function test_assigning_new_primary_contact_demotes_previous_primary_contact(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);

        $firstGuardian = $this->guardian($school, 'First');
        $secondGuardian = $this->guardian($school, 'Second');

        $firstLink = $this->learnerParentLink(
            $learner,
            $firstGuardian,
            $user,
            ['is_primary_contact' => true]
        );

        $payload = $this->linkPayload($secondGuardian);
        $payload['is_primary_contact'] = true;

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $payload
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('learner_parents', [
            'id' => $firstLink,
            'is_primary_contact' => false,
        ]);

        $this->assertDatabaseHas('learner_parents', [
            'learner_id' => $learner->id,
            'parent_id' => $secondGuardian->id,
            'is_primary_contact' => true,
            'is_deleted' => false,
        ]);
    }

    public function test_guardian_links_are_tenant_scoped_and_exclude_deleted_links(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);

        $visibleGuardian = $this->guardian($school, 'Visible');
        $deletedGuardian = $this->guardian($school, 'Deleted');

        $visibleLink = $this->learnerParentLink(
            $learner,
            $visibleGuardian,
            $user
        );

        $deletedLink = $this->learnerParentLink(
            $learner,
            $deletedGuardian,
            $user,
            [
                'active' => false,
                'portal_enabled' => false,
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $user->id,
            ]
        );

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/learners/'.$learner->id.'/guardians')
            ->assertSuccessful();

        $content = $response->getContent();

        $this->assertStringContainsString($visibleLink, $content);
        $this->assertStringContainsString($visibleGuardian->id, $content);
        $this->assertStringNotContainsString($deletedLink, $content);
        $this->assertStringNotContainsString($deletedGuardian->id, $content);

        $foreignSchool = $this->school();
        [$foreignGrade, $foreignStream] = $this->gradeAndStream($foreignSchool);

        $foreignLearner = $this->learner(
            $foreignSchool,
            $foreignGrade,
            $foreignStream,
            'FOREIGN-LIST'
        );

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/learners/'.$foreignLearner->id.'/guardians');

        $response->assertStatus(404);
    }

    public function test_foreign_link_cannot_be_updated(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $ownLearner = $this->learner(
            $school,
            $grade,
            $stream,
            'OWN-001'
        );

        $foreignSchool = $this->school();
        [$foreignGrade, $foreignStream] = $this->gradeAndStream($foreignSchool);

        $foreignLearner = $this->learner(
            $foreignSchool,
            $foreignGrade,
            $foreignStream,
            'FOREIGN-001'
        );

        $foreignGuardian = $this->guardian($foreignSchool);

        $foreignUser = $this->user($foreignSchool);

        $foreignLink = $this->learnerParentLink(
            $foreignLearner,
            $foreignGuardian,
            $foreignUser
        );

        $this->withToken($this->tokenFor($user))
            ->putJson(
                '/api/learners/'.$ownLearner->id.'/guardians/'.$foreignLink,
                ['relationship' => 'Compromised']
            )
            ->assertStatus(404);

        $this->assertDatabaseMissing('learner_parents', [
            'id' => $foreignLink,
            'relationship' => 'Compromised',
        ]);
    }

    public function test_foreign_link_cannot_be_unlinked(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $ownLearner = $this->learner(
            $school,
            $grade,
            $stream,
            'OWN-001'
        );

        $foreignSchool = $this->school();
        [$foreignGrade, $foreignStream] = $this->gradeAndStream($foreignSchool);

        $foreignLearner = $this->learner(
            $foreignSchool,
            $foreignGrade,
            $foreignStream,
            'FOREIGN-001'
        );

        $foreignGuardian = $this->guardian($foreignSchool);
        $foreignUser = $this->user($foreignSchool);

        $foreignLink = $this->learnerParentLink(
            $foreignLearner,
            $foreignGuardian,
            $foreignUser
        );

        $this->withToken($this->tokenFor($user))
            ->deleteJson(
                '/api/learners/'.$ownLearner->id.'/guardians/'.$foreignLink
            )
            ->assertStatus(404);

        $this->assertDatabaseHas('learner_parents', [
            'id' => $foreignLink,
            'active' => true,
            'is_deleted' => false,
        ]);
    }

    public function test_unlink_soft_deletes_relationship_and_revokes_portal_access(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        $link = $this->learnerParentLink(
            $learner,
            $guardian,
            $user,
            [
                'active' => true,
                'portal_enabled' => true,
            ]
        );

        $this->withToken($this->tokenFor($user))
            ->deleteJson(
                '/api/learners/'.$learner->id.'/guardians/'.$link
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('learner_parents', [
            'id' => $link,
            'active' => false,
            'portal_enabled' => false,
            'is_deleted' => true,
            'deleted_by' => $user->id,
        ]);

        $this->assertNotNull(
            DB::table('learner_parents')
                ->where('id', $link)
                ->value('deleted_at')
        );
    }

    public function test_deleting_guardian_revokes_current_learner_links(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        $link = $this->learnerParentLink(
            $learner,
            $guardian,
            $user,
            [
                'active' => true,
                'portal_enabled' => true,
            ]
        );

        $this->withToken($this->tokenFor($user))
            ->deleteJson('/api/guardians/'.$guardian->id)
            ->assertSuccessful();

        $this->assertDatabaseHas('parents', [
            'id' => $guardian->id,
            'active' => false,
            'is_deleted' => true,
            'deleted_by' => $user->id,
        ]);

        $this->assertDatabaseHas('learner_parents', [
            'id' => $link,
            'active' => false,
            'portal_enabled' => false,
            'is_deleted' => true,
            'deleted_by' => $user->id,
        ]);

        $this->assertNotNull(
            DB::table('learner_parents')
                ->where('id', $link)
                ->value('deleted_at')
        );
    }

    public function test_guardian_creation_requires_completed_operational_setup(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $this->grantManageGuardians($user);

        $payload = $this->guardianPayload();
        $payload['school_id'] = $school->id;

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/guardians', $payload)
            ->assertStatus(403);

        $this->assertDatabaseMissing('parents', [
            'school_id' => $school->id,
            'phone' => $payload['phone'],
        ]);
    }

    public function test_link_creation_requires_completed_operational_setup(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $this->grantManageGuardians($user);

        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $this->linkPayload($guardian)
            )
            ->assertStatus(403);

        $this->assertDatabaseMissing('learner_parents', [
            'learner_id' => $learner->id,
            'parent_id' => $guardian->id,
        ]);
    }

    public function test_only_guardian_and_link_creation_routes_require_operational_setup_gate(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        foreach ([
            ['POST', 'api/guardians'],
            ['POST', 'api/learners/{learner}/guardians'],
        ] as [$method, $uri]) {
            $route = $routes->first(
                fn ($route) => in_array($method, $route->methods(), true)
                    && $route->uri() === $uri
            );

            $this->assertNotNull($route);
            $this->assertContains(
                'school.operational',
                $route->gatherMiddleware()
            );
        }

        foreach ([
            ['GET', 'api/guardians'],
            ['GET', 'api/guardians/{id}'],
            ['PUT', 'api/guardians/{id}'],
            ['DELETE', 'api/guardians/{id}'],
            ['GET', 'api/learners/{learner}/guardians'],
            ['PUT', 'api/learners/{learner}/guardians/{link}'],
            ['DELETE', 'api/learners/{learner}/guardians/{link}'],
        ] as [$method, $uri]) {
            $route = $routes->first(
                fn ($route) => in_array($method, $route->methods(), true)
                    && $route->uri() === $uri
            );

            $this->assertNotNull($route);
            $this->assertNotContains(
                'school.operational',
                $route->gatherMiddleware()
            );
        }
    }

    public function test_parent_learner_links_legacy_table_receives_no_new_relationship(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners/'.$learner->id.'/guardians',
                $this->linkPayload($guardian)
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('learner_parents', [
            'learner_id' => $learner->id,
            'parent_id' => $guardian->id,
        ]);

        $this->assertDatabaseMissing('parent_learner_links', [
            'learner_id' => $learner->id,
            'parent_id' => $guardian->id,
        ]);
    }

    public function test_manage_guardians_permission_is_provisioned_for_authorized_system_roles(): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_guardians')
            ->value('id');

        $this->assertNotNull($permissionId);

        foreach (['School Admin', 'Administrator'] as $roleName) {
            $roleId = DB::table('roles')
                ->where('role_name', $roleName)
                ->whereNull('school_id')
                ->where('system_role', true)
                ->where('active', true)
                ->value('id');

            $this->assertNotNull(
                $roleId,
                "{$roleName} system role must exist."
            );

            $this->assertTrue(
                DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists(),
                "{$roleName} must receive manage_guardians."
            );
        }
    }

    public function test_manage_guardians_permission_is_not_granted_to_unauthorized_system_roles(): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_guardians')
            ->value('id');

        $this->assertNotNull($permissionId);

        $unauthorizedRoleIds = DB::table('roles')
            ->whereIn('role_name', [
                'Platform Owner',
                'Platform Super Administrator',
                'Principal',
                'Teacher',
                'Learner',
                'Parent',
                'Guardian',
            ])
            ->whereNull('school_id')
            ->where('system_role', true)
            ->pluck('id');

        $this->assertFalse(
            DB::table('role_permissions')
                ->whereIn('role_id', $unauthorizedRoleIds)
                ->where('permission_id', $permissionId)
                ->exists()
        );
    }

    public function test_parents_deleted_by_foreign_key_targets_users_table(): void
    {
        $foreignTable = DB::table('information_schema.table_constraints as tc')
            ->join(
                'information_schema.key_column_usage as kcu',
                function ($join) {
                    $join->on(
                        'tc.constraint_name',
                        '=',
                        'kcu.constraint_name'
                    )->on(
                        'tc.constraint_schema',
                        '=',
                        'kcu.constraint_schema'
                    );
                }
            )
            ->join(
                'information_schema.constraint_column_usage as ccu',
                function ($join) {
                    $join->on(
                        'ccu.constraint_name',
                        '=',
                        'tc.constraint_name'
                    )->on(
                        'ccu.constraint_schema',
                        '=',
                        'tc.constraint_schema'
                    );
                }
            )
            ->where('tc.constraint_type', 'FOREIGN KEY')
            ->where('tc.table_name', 'parents')
            ->where('kcu.column_name', 'deleted_by')
            ->value('ccu.table_name');

        $this->assertSame('users', $foreignTable);
    }

    private function schoolAdminWithPermission(): array
    {
        $school = $this->school();
        $user = $this->user($school);

        $this->completeOperationalSetup($school);
        $this->grantManageGuardians($user);

        return [$school, $user];
    }

    private function grantManageGuardians(User $user): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_guardians')
            ->value('id');

        if (! $permissionId) {
            $permissionId = (string) Str::uuid();

            DB::table('permissions')->insert([
                'id' => $permissionId,
                'permission_name' => 'manage_guardians',
                'module_name' => 'administrator_portal',
                'description' => 'Manage Guardians',
                'created_at' => now(),
            ]);
        }

        DB::table('role_permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'role_id' => $user->role_id,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);
    }

    public function test_guardian_mutations_are_audited(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $createResponse = $this->withToken($this->tokenFor($user))
            ->postJson('/api/guardians', [
                'first_name' => 'Audit',
                'last_name' => 'Guardian',
                'phone' => '0712345678',
                'relationship' => 'Mother',
            ])
            ->assertCreated();

        $guardianId = $createResponse->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $school->id,
            'user_id' => $user->id,
            'module' => 'Guardians',
            'action' => 'Create',
            'table_name' => 'parents',
            'record_id' => $guardianId,
        ]);

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/guardians/'.$guardianId, [
                'phone' => '0799999999',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $school->id,
            'user_id' => $user->id,
            'module' => 'Guardians',
            'action' => 'Update',
            'table_name' => 'parents',
            'record_id' => $guardianId,
        ]);

        $this->withToken($this->tokenFor($user))
            ->deleteJson('/api/guardians/'.$guardianId)
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $school->id,
            'user_id' => $user->id,
            'module' => 'Guardians',
            'action' => 'Delete',
            'table_name' => 'parents',
            'record_id' => $guardianId,
        ]);
    }

    public function test_guardian_link_mutations_are_audited(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);
        $guardian = $this->guardian($school);

        $linkResponse = $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners/'.$learner->id.'/guardians', [
                'guardian_id' => $guardian->id,
                'relationship' => 'Father',
                'is_primary_contact' => true,
            ])
            ->assertCreated();

        $linkId = $linkResponse->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $school->id,
            'user_id' => $user->id,
            'module' => 'Guardian Links',
            'action' => 'Link',
            'table_name' => 'learner_parents',
            'record_id' => $linkId,
        ]);

        $this->withToken($this->tokenFor($user))
            ->putJson(
                '/api/learners/'.$learner->id.'/guardians/'.$linkId,
                [
                    'relationship' => 'Parent',
                    'receives_sms' => false,
                ]
            )
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $school->id,
            'user_id' => $user->id,
            'module' => 'Guardian Links',
            'action' => 'Update',
            'table_name' => 'learner_parents',
            'record_id' => $linkId,
        ]);

        $this->withToken($this->tokenFor($user))
            ->deleteJson(
                '/api/learners/'.$learner->id.'/guardians/'.$linkId
            )
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $school->id,
            'user_id' => $user->id,
            'module' => 'Guardian Links',
            'action' => 'Unlink',
            'table_name' => 'learner_parents',
            'record_id' => $linkId,
        ]);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners/'.$learner->id.'/guardians', [
                'guardian_id' => $guardian->id,
                'relationship' => 'Father',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $linkId);

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $school->id,
            'user_id' => $user->id,
            'module' => 'Guardian Links',
            'action' => 'Link',
            'table_name' => 'learner_parents',
            'record_id' => $linkId,
            'description' => 'Restored guardian link to learner.',
        ]);
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'School '.Str::upper(Str::random(8)),
            'school_code' => 'LRN-'.Str::upper(Str::random(8)),
            'short_name' => 'LRN',
            'registration_number' => 'REG-'.Str::upper(Str::random(10)),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(10000000, 99999999),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function user(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'School Admin '.Str::upper(Str::random(8)),
            'description' => 'Test school administrator',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'username' => 'admin_'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
        ]);
    }

    private function completeOperationalSetup(School $school): void
    {
        $academicYearId = (string) Str::uuid();
        $gradeId = (string) Str::uuid();

        DB::table('academic_years')->insert([
            'id' => $academicYearId,
            'school_id' => $school->id,
            'year_name' => 'Operational '.Str::upper(Str::random(8)),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('terms')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'academic_year_id' => $academicYearId,
            'term_name' => 'Operational '.Str::upper(Str::random(6)),
            'start_date' => '2026-01-01',
            'end_date' => '2026-04-30',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('grades')->insert([
            'id' => $gradeId,
            'school_id' => $school->id,
            'grade_name' => 'Readiness '.Str::upper(Str::random(8)),
            'grade_order' => random_int(1001, 2000),
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('streams')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $gradeId,
            'stream_name' => 'Readiness '.Str::upper(Str::random(8)),
            'active' => true,
            'created_at' => now(),
        ]);
    }

    private function gradeAndStream(
        School $school,
        string $gradeName = 'Grade 7',
        string $streamName = 'East'
    ): array {
        $grade = Grade::query()->withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_name' => $gradeName.' '.Str::upper(Str::random(4)),
            'grade_order' => random_int(1, 1000),
            'active' => true,
        ]);

        $stream = Stream::query()->withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'stream_name' => $streamName.' '.Str::upper(Str::random(4)),
            'active' => true,
            'created_at' => now(),
        ]);

        return [$grade, $stream];
    }

    private function learner(
        School $school,
        Grade $grade,
        Stream $stream,
        ?string $admissionNo = null
    ): Learner {
        $admissionNo ??= 'ADM-'.Str::upper(Str::random(10));
        $id = (string) Str::uuid();

        DB::table('learners')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'admission_no' => $admissionNo,
            'first_name' => 'Existing',
            'last_name' => 'Learner',
            'grade_id' => $grade->id,
            'stream_id' => $stream->id,
            'admission_date' => now()->toDateString(),
            'active' => true,
            'is_deleted' => false,
            'portal_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Learner::query()
            ->withoutGlobalScopes()
            ->findOrFail($id);
    }

    private function guardian(
        School $school,
        string $firstName = 'Guardian'
    ): Guardian {
        $id = (string) Str::uuid();

        DB::table('parents')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'user_id' => null,
            'first_name' => $firstName,
            'last_name' => 'Parent',
            'phone' => '+2547'.random_int(10000000, 99999999),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'relationship' => 'Parent',
            'active' => true,
            'created_at' => now(),
            'is_deleted' => false,
            'deleted_at' => null,
            'deleted_by' => null,
        ]);

        return Guardian::query()
            ->withoutGlobalScopes()
            ->findOrFail($id);
    }

    private function guardianPayload(): array
    {
        return [
            'first_name' => 'Amina',
            'last_name' => 'Kamau',
            'phone' => '+2547'.random_int(10000000, 99999999),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'relationship' => 'Parent',
        ];
    }

    private function linkPayload(Guardian $guardian): array
    {
        return [
            'guardian_id' => $guardian->id,
            'relationship' => 'Mother',
            'is_primary_contact' => false,
            'receives_sms' => true,
            'receives_email' => true,
            'receives_report_cards' => true,
            'emergency_contact' => false,
            'can_pick_learner' => true,
        ];
    }

    private function learnerParentLink(
        Learner $learner,
        Guardian $guardian,
        User $user,
        array $overrides = []
    ): string {
        $id = (string) Str::uuid();

        DB::table('learner_parents')->insert(array_merge([
            'id' => $id,
            'learner_id' => $learner->id,
            'parent_id' => $guardian->id,
            'relationship' => 'Parent',
            'is_primary_contact' => false,
            'active' => true,
            'receives_sms' => true,
            'receives_email' => true,
            'receives_report_cards' => true,
            'portal_enabled' => false,
            'emergency_contact' => false,
            'can_pick_learner' => true,
            'linked_by' => $user->id,
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => null,
            'is_deleted' => false,
            'deleted_at' => null,
            'deleted_by' => null,
        ], $overrides));

        return $id;
    }

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser(
            User::query()
                ->withoutGlobalScopes()
                ->findOrFail($user->id)
        );
    }
}
