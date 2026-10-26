<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LearnerController;
use App\Models\AuditLog;
use App\Models\Grade;
use App\Models\Learner;
use App\Models\Role;
use App\Models\School;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SchoolAdminLearnerAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_school_admin_can_admit_learner_without_supplying_school_id(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertSuccessful();

        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('admission_no', 'ADM-001')
            ->firstOrFail();

        $this->assertSame($school->id, $learner->school_id);
    }

    public function test_admission_sets_active_lifecycle_status_and_active_true(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners',
                $this->learnerPayload($grade, $stream)
            )
            ->assertSuccessful();

        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('admission_no', 'ADM-001')
            ->firstOrFail();

        $this->assertTrue((bool) $learner->active);
        $this->assertSame(
            'active',
            $learner->lifecycle_status
        );
    }

    public function test_admission_client_cannot_control_active_or_lifecycle_status(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $payload = $this->learnerPayload($grade, $stream);

        $payload['active'] = false;
        $payload['lifecycle_status'] = 'graduated';

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $payload)
            ->assertSuccessful();

        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('admission_no', 'ADM-001')
            ->firstOrFail();

        $this->assertTrue((bool) $learner->active);
        $this->assertSame(
            'active',
            $learner->lifecycle_status
        );
    }

    public function test_client_supplied_foreign_school_id_cannot_redirect_admission(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $foreignSchool = $this->school();
        [$grade, $stream] = $this->gradeAndStream($school);

        $payload = $this->learnerPayload($grade, $stream);
        $payload['school_id'] = $foreignSchool->id;

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $payload)
            ->assertSuccessful();

        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('admission_no', 'ADM-001')
            ->firstOrFail();

        $this->assertSame($school->id, $learner->school_id);
        $this->assertNotSame($foreignSchool->id, $learner->school_id);
    }

    public function test_foreign_grade_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $foreignSchool = $this->school();

        [$grade, $stream] = $this->gradeAndStream($school);
        [$foreignGrade] = $this->gradeAndStream($foreignSchool);

        $payload = $this->learnerPayload($grade, $stream);
        $payload['grade_id'] = $foreignGrade->id;

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $payload)
            ->assertStatus(422);

        $this->assertDatabaseMissing('learners', [
            'school_id' => $school->id,
            'admission_no' => 'ADM-001',
        ]);
    }

    public function test_foreign_stream_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $foreignSchool = $this->school();

        [$grade] = $this->gradeAndStream($school);
        [, $foreignStream] = $this->gradeAndStream($foreignSchool);

        $payload = $this->learnerPayload($grade, $foreignStream);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $payload)
            ->assertStatus(422);

        $this->assertDatabaseMissing('learners', [
            'school_id' => $school->id,
            'admission_no' => 'ADM-001',
        ]);
    }

    public function test_stream_must_belong_to_selected_grade(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade] = $this->gradeAndStream($school, 'Grade 7', 'East');
        [, $otherStream] = $this->gradeAndStream($school, 'Grade 8', 'West');

        $payload = $this->learnerPayload($grade, $otherStream);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $payload)
            ->assertStatus(422);
    }

    public function test_inactive_grade_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $grade->update(['active' => false]);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertStatus(422);
    }

    public function test_inactive_stream_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $stream->update(['active' => false]);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertStatus(422);
    }

    public function test_duplicate_active_admission_number_is_rejected_within_same_school(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $this->learner($school, $grade, $stream, 'ADM-001');

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertStatus(422);
    }

    public function test_same_admission_number_is_allowed_in_another_school(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$grade, $stream] = $this->gradeAndStream($school);
        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);

        $this->learner($otherSchool, $otherGrade, $otherStream, 'ADM-001');

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertSuccessful();

        $this->assertSame(
            2,
            Learner::query()
                ->withoutGlobalScopes()
                ->where('admission_no', 'ADM-001')
                ->count()
        );
    }

    public function test_soft_deleted_admission_number_can_be_reused(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $old = $this->learner($school, $grade, $stream, 'ADM-001');

        DB::table('learners')
            ->where('id', $old->id)
            ->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $user->id,
            ]);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertSuccessful();
    }

    public function test_listing_is_tenant_scoped_and_excludes_deleted_learners(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$grade, $stream] = $this->gradeAndStream($school);
        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);

        $visible = $this->learner($school, $grade, $stream, 'OWN-001');
        $deleted = $this->learner($school, $grade, $stream, 'OWN-002');
        $this->learner($otherSchool, $otherGrade, $otherStream, 'FOREIGN-001');

        DB::table('learners')
            ->where('id', $deleted->id)
            ->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $user->id,
            ]);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/learners')
            ->assertSuccessful();

        $content = $response->getContent();

        $this->assertStringContainsString($visible->id, $content);
        $this->assertStringNotContainsString($deleted->id, $content);
        $this->assertStringNotContainsString('FOREIGN-001', $content);
    }

    public function test_foreign_learner_cannot_be_viewed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);
        $foreign = $this->learner($otherSchool, $otherGrade, $otherStream);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/learners/'.$foreign->id)
            ->assertStatus(404);
    }

    public function test_foreign_learner_cannot_be_updated(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);
        $foreign = $this->learner($otherSchool, $otherGrade, $otherStream);

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/learners/'.$foreign->id, [
                'first_name' => 'Compromised',
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('learners', [
            'id' => $foreign->id,
            'first_name' => 'Compromised',
        ]);
    }

    public function test_foreign_learner_cannot_be_deleted(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);
        $foreign = $this->learner($otherSchool, $otherGrade, $otherStream);

        $this->withToken($this->tokenFor($user))
            ->deleteJson('/api/learners/'.$foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('learners', [
            'id' => $foreign->id,
            'is_deleted' => false,
        ]);
    }

    public function test_update_cannot_move_learner_to_foreign_grade_or_stream(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$grade, $stream] = $this->gradeAndStream($school);
        [$foreignGrade, $foreignStream] = $this->gradeAndStream($otherSchool);

        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/learners/'.$learner->id, [
                'grade_id' => $foreignGrade->id,
                'stream_id' => $foreignStream->id,
            ])
            ->assertStatus(422);
    }

    public function test_update_requires_stream_to_match_effective_grade(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $stream] = $this->gradeAndStream($school, 'Grade 7', 'East');
        [, $otherStream] = $this->gradeAndStream($school, 'Grade 8', 'West');

        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/learners/'.$learner->id, [
                'stream_id' => $otherStream->id,
            ])
            ->assertStatus(422);
    }

    public function test_delete_soft_deletes_learner_and_records_authenticated_user(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->deleteJson('/api/learners/'.$learner->id)
            ->assertSuccessful();

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'school_id' => $school->id,
            'is_deleted' => true,
            'deleted_by' => $user->id,
        ]);

        $this->assertNotNull(
            DB::table('learners')
                ->where('id', $learner->id)
                ->value('deleted_at')
        );
    }

    public function test_admission_response_does_not_expose_school_authority_object(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $response = $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertSuccessful();

        $response->assertJsonMissingPath('data.school');
    }

    public function test_admission_does_not_create_or_activate_learner_portal_account(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertSuccessful();

        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('admission_no', 'ADM-001')
            ->firstOrFail();

        $this->assertNull($learner->user_id);
        $this->assertFalse((bool) $learner->portal_enabled);
        $this->assertNull($learner->portal_activated_at);
    }

    public function test_unauthenticated_user_cannot_admit_learner(): void
    {
        $school = $this->school();
        [$grade, $stream] = $this->gradeAndStream($school);

        $this->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertUnauthorized();
    }

    public function test_user_without_manage_learners_permission_cannot_admit_learner(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $this->completeOperationalSetup($school);

        [$grade, $stream] = $this->gradeAndStream($school);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertStatus(403);
    }

    public function test_learner_crud_routes_use_manage_learners_permission(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        foreach ([
            ['GET', 'api/learners'],
            ['POST', 'api/learners'],
            ['GET', 'api/learners/{id}'],
            ['PUT', 'api/learners/{id}'],
            ['DELETE', 'api/learners/{id}'],
        ] as [$method, $uri]) {
            $route = $routes->first(
                fn ($route) => in_array($method, $route->methods(), true)
                    && $route->uri() === $uri
            );

            $this->assertNotNull($route, "{$method} {$uri} route is missing.");

            $this->assertContains(
                'permission:manage_learners',
                $route->gatherMiddleware(),
                "{$method} {$uri} must require manage_learners."
            );
        }
    }

    public function test_only_learner_admission_route_requires_operational_setup_gate(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        $post = $routes->first(
            fn ($route) => in_array('POST', $route->methods(), true)
                && $route->uri() === 'api/learners'
        );

        $this->assertNotNull($post);
        $this->assertContains('school.operational', $post->gatherMiddleware());

        foreach ([
            ['GET', 'api/learners'],
            ['GET', 'api/learners/{id}'],
            ['PUT', 'api/learners/{id}'],
            ['DELETE', 'api/learners/{id}'],
        ] as [$method, $uri]) {
            $route = $routes->first(
                fn ($route) => in_array($method, $route->methods(), true)
                    && $route->uri() === $uri
            );

            $this->assertNotNull($route);
            $this->assertNotContains('school.operational', $route->gatherMiddleware());
        }
    }

    public function test_manage_learners_permission_is_provisioned_for_authorized_system_roles(): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_learners')
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
                "{$roleName} must receive manage_learners."
            );
        }
    }

    public function test_manage_learners_permission_is_not_granted_to_unauthorized_system_roles(): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_learners')
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

    public function test_archived_listing_is_paginated_and_tenant_scoped(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $archived = $this->learner($school, $grade, $stream, 'ARCHIVED-001');
        $this->learner($school, $grade, $stream, 'CURRENT-001');

        $otherSchool = $this->school();
        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);
        $foreign = $this->learner(
            $otherSchool, $otherGrade, $otherStream, 'FOREIGN-ARCHIVED'
        );

        DB::table('learners')->whereIn('id', [$archived->id, $foreign->id])
            ->update(['is_deleted' => true, 'active' => false, 'deleted_at' => now()]);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/learners/archived?per_page=1&school_id='.$otherSchool->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1);

        $this->assertSame([$archived->id], array_column($response->json('data'), 'id'));

        $this->getJson('/api/learners/archived?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_deleted_learner_can_be_restored_with_same_identity_and_profile(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);
        DB::table('learners')->where('id', $learner->id)->update([
            'lifecycle_status' => 'active',
        ]);
        $before = (array) DB::table('learners')->where('id', $learner->id)->first();
        $count = DB::table('learners')->where('school_id', $school->id)->count();

        $this->withToken($this->tokenFor($user))
            ->deleteJson('/api/learners/'.$learner->id)->assertSuccessful();

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id, 'is_deleted' => true, 'active' => false,
        ]);
        $this->getJson('/api/learners/'.$learner->id)->assertNotFound();

        $this->postJson('/api/learners/'.$learner->id.'/restore', [
            'reason' => 'Archived in error; restore the existing record.',
        ])->assertSuccessful();

        $after = (array) DB::table('learners')->where('id', $learner->id)->first();
        foreach ([
            'id', 'school_id', 'admission_no', 'upi', 'assessment_no',
            'first_name', 'middle_name', 'last_name', 'grade_id', 'stream_id',
            'lifecycle_status', 'user_id', 'portal_enabled', 'created_at',
        ] as $field) {
            $this->assertSame($before[$field], $after[$field], $field.' changed.');
        }
        $this->assertFalse((bool) $after['is_deleted']);
        $this->assertTrue((bool) $after['active']);
        $this->assertNull($after['deleted_at']);
        $this->assertNull($after['deleted_by']);
        $this->assertSame($count, DB::table('learners')
            ->where('school_id', $school->id)->count());
        $this->getJson('/api/learners/'.$learner->id)->assertOk();
    }

    public function test_restoration_preserves_terminal_and_unclassified_lifecycle(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $this->withToken($this->tokenFor($user));

        foreach (['withdrawn', 'transferred', 'graduated', null] as $index => $status) {
            $learner = $this->learner($school, $grade, $stream, 'STATE-'.$index);
            DB::table('learners')->where('id', $learner->id)->update([
                'lifecycle_status' => $status,
                'active' => false,
                'is_deleted' => true,
                'deleted_at' => now(),
            ]);

            $this->postJson('/api/learners/'.$learner->id.'/restore', [
                'reason' => 'Recover retained records without changing enrolment.',
            ])->assertSuccessful();

            $row = DB::table('learners')->where('id', $learner->id)->first();
            $this->assertSame($status, $row->lifecycle_status);
            $this->assertFalse((bool) $row->active);
            $this->assertFalse((bool) $row->is_deleted);
        }
    }

    public function test_restore_requires_reason_and_rejects_current_or_foreign_learner(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);
        $otherSchool = $this->school();
        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);
        $foreign = $this->learner($otherSchool, $otherGrade, $otherStream);

        DB::table('learners')->where('id', $foreign->id)->update([
            'is_deleted' => true, 'active' => false,
        ]);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners/'.$learner->id.'/restore', [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson('/api/learners/'.$learner->id.'/restore', [
            'reason' => 'Already current.',
        ])->assertUnprocessable()->assertJsonValidationErrors('learner');

        $this->postJson('/api/learners/'.$foreign->id.'/restore', [
            'reason' => 'Attempt another school.',
            'school_id' => $otherSchool->id,
        ])->assertNotFound();

        $this->assertDatabaseHas('learners', [
            'id' => $foreign->id, 'is_deleted' => true,
        ]);
    }

    public function test_archive_endpoints_require_authentication_and_permission(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);
        DB::table('learners')->where('id', $learner->id)->update([
            'is_deleted' => true, 'active' => false,
        ]);

        $this->getJson('/api/learners/archived')->assertUnauthorized();
        $this->postJson('/api/learners/'.$learner->id.'/restore', [
            'reason' => 'Restore.',
        ])->assertUnauthorized();

        DB::table('role_permissions')->where('role_id', $user->role_id)->delete();

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/learners/archived')->assertForbidden();
        $this->postJson('/api/learners/'.$learner->id.'/restore', [
            'reason' => 'Restore.',
        ])->assertForbidden();

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id, 'is_deleted' => true,
        ]);
    }

    public function test_archive_restore_preserves_linked_records(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        DB::table('learners')->where('id', $learner->id)->update([
            'lifecycle_status' => 'active',
        ]);

        $parentId = (string) Str::uuid();
        DB::table('parents')->insert([
            'id' => $parentId,
            'school_id' => $school->id,
            'first_name' => 'Archive',
            'last_name' => 'Guardian',
            'phone' => '+254700000001',
            'active' => true,
            'is_deleted' => false,
        ]);

        DB::table('learner_parents')->insert([
            'id' => (string) Str::uuid(),
            'learner_id' => $learner->id,
            'parent_id' => $parentId,
            'relationship' => 'Parent',
            'is_primary_contact' => true,
            'active' => true,
            'portal_enabled' => false,
            'is_deleted' => false,
        ]);

        DB::table('learner_fee_accounts')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'learner_id' => $learner->id,
            'account_number' => 'ARCHIVE-'.Str::random(12),
            'current_balance' => 1250,
            'active' => true,
        ]);

        $year = DB::table('academic_years')
            ->where('school_id', $school->id)->first();
        $term = DB::table('terms')
            ->where('school_id', $school->id)
            ->where('academic_year_id', $year->id)->first();

        $invoiceId = (string) Str::uuid();
        DB::table('fee_invoices')->insert([
            'id' => $invoiceId,
            'school_id' => $school->id,
            'learner_id' => $learner->id,
            'academic_year_id' => $year->id,
            'term_id' => $term->id,
            'invoice_number' => 'ARCHIVE-'.Str::random(12),
            'total_amount' => 2000,
            'amount_paid' => 750,
            'balance' => 1250,
            'status' => 'UNPAID',
            'invoice_date' => now()->toDateString(),
        ]);

        DB::table('learner_fee_ledger')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'learner_id' => $learner->id,
            'academic_year_id' => $year->id,
            'term_id' => $term->id,
            'transaction_date' => now(),
            'transaction_type' => 'INVOICE',
            'reference_type' => 'fee_invoice',
            'reference_id' => $invoiceId,
            'debit_amount' => 2000,
            'credit_amount' => 0,
            'running_balance' => 2000,
            'created_by' => $user->id,
        ]);

        DB::table('learner_medical_profiles')->insert([
            'id' => (string) Str::uuid(),
            'learner_id' => $learner->id,
            'allergies' => 'Fixture allergy',
            'emergency_contact' => '+254700000002',
        ]);

        $tables = [
            'learner_parents',
            'learner_fee_accounts',
            'fee_invoices',
            'learner_fee_ledger',
            'learner_medical_profiles',
        ];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)
                ->where('learner_id', $learner->id)
                ->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
            $this->assertNotEmpty($before[$table]);
        }
        $parentBefore = (array) DB::table('parents')
            ->where('id', $parentId)->first();

        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->withHeaders($headers)
            ->deleteJson('/api/learners/'.$learner->id)
            ->assertSuccessful();

        foreach ($tables as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->where('learner_id', $learner->id)
                    ->orderBy('id')->get()
                    ->map(fn ($row) => (array) $row)->all(),
                "Archiving changed {$table}."
            );
        }

        $this->withHeaders($headers)
            ->postJson('/api/learners/'.$learner->id.'/restore', [
                'reason' => 'Restore learner with existing linked records.',
            ])->assertSuccessful();

        foreach ($tables as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->where('learner_id', $learner->id)
                    ->orderBy('id')->get()
                    ->map(fn ($row) => (array) $row)->all(),
                "Restoration changed {$table}."
            );
        }

        $this->assertSame(
            $parentBefore,
            (array) DB::table('parents')->where('id', $parentId)->first()
        );
        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'is_deleted' => false,
            'active' => true,
            'lifecycle_status' => 'active',
        ]);
    }

    public function test_restore_rejects_reused_identifiers_without_changing_either_learner(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        foreach (['admission_no', 'upi', 'assessment_no'] as $field) {
            $archived = $this->learner(
                $school, $grade, $stream, 'OLD-'.Str::random(12)
            );
            $value = 'REUSED-'.Str::random(12);

            DB::table('learners')->where('id', $archived->id)->update([
                $field => $value,
                'is_deleted' => true,
                'active' => false,
                'deleted_at' => now(),
                'deleted_by' => $user->id,
            ]);

            $current = $this->learner(
                $school, $grade, $stream, 'NEW-'.Str::random(12)
            );
            DB::table('learners')->where('id', $current->id)->update([
                $field => $value,
            ]);

            $before = DB::table('learners')
                ->whereIn('id', [$archived->id, $current->id])
                ->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();

            $this->withHeaders($headers)
                ->postJson('/api/learners/'.$archived->id.'/restore', [
                    'reason' => 'Attempt restoration with an identifier conflict.',
                ])->assertUnprocessable()
                ->assertJsonValidationErrors($field);

            $this->assertSame(
                $before,
                DB::table('learners')
                    ->whereIn('id', [$archived->id, $current->id])
                    ->orderBy('id')->get()
                    ->map(fn ($row) => (array) $row)->all()
            );
        }
    }

    public function test_restoration_rolls_back_when_audit_fails(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        DB::table('learners')->where('id', $learner->id)->update([
            'lifecycle_status' => 'active',
            'is_deleted' => true,
            'active' => false,
            'deleted_at' => now(),
            'deleted_by' => $user->id,
        ]);

        $before = (array) DB::table('learners')
            ->where('id', $learner->id)->first();
        $auditCount = DB::table('audit_logs')->count();

        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(function ($audit) {
                if ($audit->action === 'Restore') {
                    throw new \RuntimeException('Simulated restoration audit failure.');
                }
            });

            $request = Request::create(
                '/api/learners/'.$learner->id.'/restore',
                'POST',
                ['reason' => 'Test atomic restoration.']
            );
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('tenant_school_id', $school->id);

            try {
                app(LearnerController::class)
                    ->restore($request, $learner->id);
                $this->fail('Restoration should fail when its audit fails.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Simulated restoration audit failure.',
                    $exception->getMessage()
                );
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertSame(
            $before,
            (array) DB::table('learners')->where('id', $learner->id)->first()
        );
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_assessment_number_is_required_for_every_grade_three_to_nine(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $token = $this->tokenFor($user);

        foreach (range(3, 9) as $number) {
            [$grade, $stream] = $this->gradeAndStream($school, 'Grade '.$number);
            $payload = $this->learnerPayload($grade, $stream);
            $payload['admission_no'] = 'REQUIRED-'.$number;
            unset($payload['assessment_no']);

            $this->withToken($token)->postJson('/api/learners', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('assessment_no');

            $this->assertDatabaseMissing('learners', [
                'school_id' => $school->id,
                'admission_no' => $payload['admission_no'],
            ]);

            $payload['assessment_no'] = '0000'.$number;
            $this->withToken($token)->postJson('/api/learners', $payload)
                ->assertCreated()
                ->assertJsonPath('data.assessment_no', '0000'.$number);
        }
    }

    public function test_early_grades_allow_missing_assessment_numbers(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $token = $this->tokenFor($user);

        foreach (['PP1', 'PP2', 'Grade 1', 'Grade 2'] as $index => $name) {
            [$grade, $stream] = $this->gradeAndStream($school, $name);
            $payload = $this->learnerPayload($grade, $stream);
            $payload['admission_no'] = 'EARLY-'.$index;
            unset($payload['assessment_no']);

            $this->withToken($token)->postJson('/api/learners', $payload)
                ->assertCreated()->assertJsonPath('data.assessment_no', null);
        }
    }

    public function test_kemis_and_assessment_numbers_preserve_leading_zeros_and_validate_updates(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school, 'Grade 3');
        $payload = $this->learnerPayload($grade, $stream);
        $payload['kemis_no'] = '0000123456';
        $payload['assessment_no'] = '0000987654';
        $token = $this->tokenFor($user);

        $response = $this->withToken($token)->postJson('/api/learners', $payload)
            ->assertCreated()
            ->assertJsonPath('data.kemis_no', '0000123456')
            ->assertJsonPath('data.assessment_no', '0000987654');
        $id = $response->json('data.id');

        $this->assertDatabaseHas('learners', [
            'id' => $id,
            'kemis_no' => '0000123456',
            'assessment_no' => '0000987654',
        ]);

        foreach ([null, '', '   '] as $missing) {
            $this->withToken($token)->putJson('/api/learners/'.$id, [
                'assessment_no' => $missing,
                'first_name' => 'MustNotChange',
            ])->assertUnprocessable()->assertJsonValidationErrors('assessment_no');
        }

        $this->assertDatabaseHas('learners', [
            'id' => $id,
            'first_name' => $payload['first_name'],
            'assessment_no' => '0000987654',
        ]);

        $this->withToken($token)->putJson('/api/learners/'.$id, [
            'kemis_no' => '0000000042',
        ])->assertOk()
            ->assertJsonPath('data.kemis_no', '0000000042')
            ->assertJsonPath('data.assessment_no', '0000987654');

        $this->withToken($token)->putJson('/api/learners/'.$id, [
            'kemis_no' => str_repeat('1', 51),
        ])->assertUnprocessable()->assertJsonValidationErrors('kemis_no');

        $this->withToken($token)->putJson('/api/learners/'.$id, [
            'kemis_no' => null,
        ])->assertOk()->assertJsonPath('data.kemis_no', null);
    }

    public function test_renamed_grade_keeps_its_assessment_number_requirement(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school, 'Grade 3');

        $grade->update(['grade_name' => 'Cedar Class']);
        $this->assertSame(3, $grade->fresh()->grade_number);

        $payload = $this->learnerPayload($grade, $stream);
        unset($payload['assessment_no']);
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson('/api/learners', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('assessment_no');

        $this->assertDatabaseMissing('learners', [
            'school_id' => $school->id,
            'admission_no' => $payload['admission_no'],
        ]);

        $payload['assessment_no'] = '00000309';
        $this->withToken($token)->postJson('/api/learners', $payload)
            ->assertCreated()->assertJsonPath('data.assessment_no', '00000309');
    }

    public function test_unclassified_custom_grade_blocks_admission_until_classified(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school, 'Legacy Custom Class');
        $this->assertNull($grade->fresh()->grade_number);

        $payload = $this->learnerPayload($grade, $stream);
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson('/api/learners', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('grade_id');

        $this->assertDatabaseMissing('learners', [
            'school_id' => $school->id,
            'admission_no' => $payload['admission_no'],
        ]);

        $grade->update(['grade_number' => 7]);
        unset($payload['assessment_no']);

        $this->withToken($token)->postJson('/api/learners', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('assessment_no');

        $payload['assessment_no'] = '00000709';
        $this->withToken($token)->postJson('/api/learners', $payload)
            ->assertCreated()->assertJsonPath('data.assessment_no', '00000709');
    }

    private function schoolAdminWithPermission(): array
    {
        $school = $this->school();
        $user = $this->user($school);

        $this->completeOperationalSetup($school);
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_learners')
            ->value('id');

        $this->assertNotNull(
            $permissionId,
            'manage_learners must be provisioned by the production migration.'
        );

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $user->role_id,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);

        return [$school, $user];
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
            'grade_name' => $gradeName,
            'grade_order' => random_int(1, 1000),
            'active' => true,
        ]);

        $stream = Stream::query()->withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'stream_name' => $streamName,
            'active' => true,
            'created_at' => now(),
        ]);

        return [$grade, $stream];
    }

    private function learnerPayload(Grade $grade, Stream $stream): array
    {
        return [
            'admission_no' => 'ADM-001',
            'first_name' => 'Amina',
            'middle_name' => 'Njeri',
            'last_name' => 'Kamau',
            'gender' => 'Female',
            'date_of_birth' => '2014-05-12',
            'grade_id' => $grade->id,
            'stream_id' => $stream->id,
            'admission_date' => now()->toDateString(),
            'upi' => 'UPI-'.Str::upper(Str::random(10)),
            'assessment_no' => 'ASM-'.Str::upper(Str::random(10)),
        ];
    }

    private function learner(
        School $school,
        Grade $grade,
        Stream $stream,
        string $admissionNo = 'ADM-EXISTING'
    ): Learner {
        return Learner::query()->withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
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
        ]);
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
