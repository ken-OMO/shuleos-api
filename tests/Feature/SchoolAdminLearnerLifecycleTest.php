<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\LearnerLifecycleController;
use App\Models\Grade;
use App\Models\Learner;
use App\Models\Role;
use App\Models\School;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SchoolAdminLearnerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_school_admin_can_view_active_learner_lifecycle(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->getJson("/api/learners/{$learner->id}/lifecycle")
            ->assertSuccessful()
            ->assertJsonPath('data.learner_id', $learner->id)
            ->assertJsonPath('data.lifecycle_status', 'active')
            ->assertJsonPath('data.active', true);
    }

    public function test_active_learner_can_be_withdrawn(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'withdrawn',
                    '2026-08-30',
                    'Parent formally withdrew learner.'
                )
            )
            ->assertSuccessful()
            ->assertJsonPath(
                'data.lifecycle_status',
                'withdrawn'
            )
            ->assertJsonPath('data.active', false)
            ->assertJsonPath(
                'data.change.from_status',
                'active'
            )
            ->assertJsonPath(
                'data.change.to_status',
                'withdrawn'
            );

        $this->assertLifecycleState(
            $learner,
            'withdrawn',
            false
        );

        $this->assertDatabaseHas(
            'learner_lifecycle_history',
            [
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'from_status' => 'active',
                'to_status' => 'withdrawn',
                'effective_date' => '2026-08-30',
                'reason' => 'Parent formally withdrew learner.',
                'changed_by' => $user->id,
            ]
        );
    }

    public function test_active_learner_can_be_transferred(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'transferred',
                    '2026-08-30',
                    'Transferred to another school.'
                )
            )
            ->assertSuccessful()
            ->assertJsonPath(
                'data.lifecycle_status',
                'transferred'
            )
            ->assertJsonPath('data.active', false);

        $this->assertLifecycleState(
            $learner,
            'transferred',
            false
        );
    }

    public function test_active_learner_can_be_graduated(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'graduated',
                    '2026-08-30',
                    'Completed the school programme.'
                )
            )
            ->assertSuccessful()
            ->assertJsonPath(
                'data.lifecycle_status',
                'graduated'
            )
            ->assertJsonPath('data.active', false);

        $this->assertLifecycleState(
            $learner,
            'graduated',
            false
        );
    }

    public function test_terminal_learner_can_be_restored_to_active(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle(
            $learner,
            'withdrawn',
            false
        );

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'active',
                    '2026-08-30',
                    'Withdrawal entered in error.'
                )
            )
            ->assertSuccessful()
            ->assertJsonPath(
                'data.lifecycle_status',
                'active'
            )
            ->assertJsonPath('data.active', true);

        $this->assertLifecycleState(
            $learner,
            'active',
            true
        );

        $this->assertDatabaseHas(
            'learner_lifecycle_history',
            [
                'learner_id' => $learner->id,
                'from_status' => 'withdrawn',
                'to_status' => 'active',
                'changed_by' => $user->id,
            ]
        );
    }

    public function test_terminal_to_terminal_transition_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle(
            $learner,
            'withdrawn',
            false
        );

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'transferred',
                    '2026-08-30',
                    'Attempted reclassification.'
                )
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertLifecycleState(
            $learner,
            'withdrawn',
            false
        );

        $this->assertDatabaseMissing(
            'learner_lifecycle_history',
            [
                'learner_id' => $learner->id,
                'to_status' => 'transferred',
            ]
        );
    }

    public function test_same_lifecycle_status_is_rejected_without_history(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'active',
                    '2026-08-30',
                    'No state change.'
                )
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseMissing(
            'learner_lifecycle_history',
            ['learner_id' => $learner->id]
        );
    }

    public function test_invalid_lifecycle_status_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'suspended',
                    '2026-08-30',
                    'Invalid lifecycle.'
                )
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_status_is_required(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                [
                    'effective_date' => '2026-08-30',
                    'reason' => 'Missing status.',
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_effective_date_is_required(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                [
                    'status' => 'withdrawn',
                    'reason' => 'Missing effective date.',
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'effective_date'
            );
    }

    public function test_invalid_effective_date_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                [
                    'status' => 'withdrawn',
                    'effective_date' => 'not-a-date',
                    'reason' => 'Invalid date.',
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'effective_date'
            );
    }

    public function test_reason_is_required(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                [
                    'status' => 'withdrawn',
                    'effective_date' => '2026-08-30',
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_reason_is_limited_to_500_characters(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                [
                    'status' => 'withdrawn',
                    'effective_date' => '2026-08-30',
                    'reason' => str_repeat('x', 501),
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseMissing(
            'learner_lifecycle_history',
            ['learner_id' => $learner->id]
        );
    }

    public function test_foreign_learner_lifecycle_cannot_be_viewed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $foreignSchool = $this->school();

        [$grade, $stream] =
            $this->gradeAndStream($foreignSchool);

        $learner = $this->learner(
            $foreignSchool,
            $grade,
            $stream
        );

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->getJson(
                "/api/learners/{$learner->id}/lifecycle"
            )
            ->assertNotFound();
    }

    public function test_foreign_learner_lifecycle_cannot_be_changed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $foreignSchool = $this->school();

        [$grade, $stream] =
            $this->gradeAndStream($foreignSchool);

        $learner = $this->learner(
            $foreignSchool,
            $grade,
            $stream
        );

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'withdrawn',
                    '2026-08-30',
                    'Cross-tenant attack.'
                )
            )
            ->assertNotFound();

        $this->assertLifecycleState(
            $learner,
            'active',
            true
        );
    }

    public function test_deleted_learner_lifecycle_cannot_be_viewed_or_changed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        DB::table('learners')
            ->where('id', $learner->id)
            ->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $user->id,
            ]);

        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->getJson(
                "/api/learners/{$learner->id}/lifecycle"
            )
            ->assertNotFound();

        $this->withToken($token)
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'withdrawn',
                    '2026-08-30',
                    'Should not mutate deleted learner.'
                )
            )
            ->assertNotFound();

        $this->assertDatabaseMissing(
            'learner_lifecycle_history',
            ['learner_id' => $learner->id]
        );
    }

    public function test_history_is_ordered_newest_first(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'withdrawn',
                    '2026-08-29',
                    'First change.'
                )
            )
            ->assertSuccessful();

        $firstId = DB::table(
            'learner_lifecycle_history'
        )
            ->where('learner_id', $learner->id)
            ->value('id');

        DB::table('learner_lifecycle_history')
            ->where('id', $firstId)
            ->update([
                'changed_at' => now()->subMinute(),
            ]);

        $this->withToken($token)
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'active',
                    '2026-08-30',
                    'Correction.'
                )
            )
            ->assertSuccessful();

        $this->withToken($token)
            ->getJson(
                "/api/learners/{$learner->id}/lifecycle/history"
            )
            ->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath(
                'data.0.from_status',
                'withdrawn'
            )
            ->assertJsonPath(
                'data.0.to_status',
                'active'
            )
            ->assertJsonPath(
                'data.1.from_status',
                'active'
            )
            ->assertJsonPath(
                'data.1.to_status',
                'withdrawn'
            );
    }

    public function test_foreign_learner_history_cannot_be_viewed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $foreignSchool = $this->school();

        [$grade, $stream] =
            $this->gradeAndStream($foreignSchool);

        $learner = $this->learner(
            $foreignSchool,
            $grade,
            $stream
        );

        $this->withToken($this->tokenFor($user))
            ->getJson(
                "/api/learners/{$learner->id}/lifecycle/history"
            )
            ->assertNotFound();
    }

    public function test_legacy_inactive_learner_can_be_classified(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle(
            $learner,
            null,
            false
        );

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'transferred',
                    '2026-01-15',
                    'Classified legacy inactive learner.'
                )
            )
            ->assertSuccessful()
            ->assertJsonPath(
                'data.change.from_status',
                null
            )
            ->assertJsonPath(
                'data.change.to_status',
                'transferred'
            );

        $this->assertLifecycleState(
            $learner,
            'transferred',
            false
        );
    }

    public function test_legacy_inactive_learner_can_be_restored_to_active(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle(
            $learner,
            null,
            false
        );

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'active',
                    '2026-08-30',
                    'Legacy learner restored.'
                )
            )
            ->assertSuccessful();

        $this->assertLifecycleState(
            $learner,
            'active',
            true
        );
    }

    public function test_generic_update_rejects_active_mutation(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->putJson(
                "/api/learners/{$learner->id}",
                ['active' => false]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'lifecycle_status'
            );

        $this->assertLifecycleState(
            $learner,
            'active',
            true
        );
    }

    public function test_generic_update_rejects_lifecycle_status_mutation(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->putJson(
                "/api/learners/{$learner->id}",
                ['lifecycle_status' => 'withdrawn']
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'lifecycle_status'
            );

        $this->assertLifecycleState(
            $learner,
            'active',
            true
        );
    }

    public function test_user_without_manage_learners_permission_is_forbidden(): void
    {
        [$school] = $this->schoolAdminWithPermission();

        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $user = $this->userWithoutPermission($school);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'withdrawn',
                    '2026-08-30',
                    'Unauthorized attempt.'
                )
            )
            ->assertForbidden();

        $this->assertLifecycleState(
            $learner,
            'active',
            true
        );
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        [$school] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->patchJson(
            "/api/learners/{$learner->id}/lifecycle",
            $this->lifecyclePayload(
                'withdrawn',
                '2026-08-30',
                'Unauthenticated attempt.'
            )
        )->assertUnauthorized();
    }

    public function test_database_rejects_active_terminal_inconsistency(): void
    {
        [$school] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->expectException(
            QueryException::class
        );

        DB::table('learners')
            ->where('id', $learner->id)
            ->update([
                'lifecycle_status' => 'transferred',
                'active' => true,
            ]);
    }

    public function test_database_rejects_active_status_with_active_false(): void
    {
        [$school] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->expectException(
            QueryException::class
        );

        DB::table('learners')
            ->where('id', $learner->id)
            ->update([
                'lifecycle_status' => 'active',
                'active' => false,
            ]);
    }

    public function test_client_cannot_override_lifecycle_history_server_fields(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $foreignSchool = $this->school();

        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $fakeLearnerId = (string) Str::uuid();
        $fakeChangedBy = (string) Str::uuid();
        $fakeChangedAt = '2000-01-01 00:00:00';

        $payload = $this->lifecyclePayload(
            'withdrawn',
            '2026-08-30',
            'Testing server-controlled lifecycle history.'
        );

        $payload['school_id'] = $foreignSchool->id;
        $payload['learner_id'] = $fakeLearnerId;
        $payload['from_status'] = 'graduated';
        $payload['to_status'] = 'transferred';
        $payload['changed_by'] = $fakeChangedBy;
        $payload['changed_at'] = $fakeChangedAt;
        $payload['created_at'] = $fakeChangedAt;

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $payload
            )
            ->assertSuccessful();

        $history = DB::table('learner_lifecycle_history')
            ->where('learner_id', $learner->id)
            ->first();

        $this->assertNotNull($history);
        $this->assertSame($school->id, $history->school_id);
        $this->assertSame($learner->id, $history->learner_id);
        $this->assertSame('active', $history->from_status);
        $this->assertSame('withdrawn', $history->to_status);
        $this->assertSame($user->id, $history->changed_by);
        $this->assertNotSame(
            $fakeChangedAt,
            (string) $history->changed_at
        );
    }

    public function test_lifecycle_transition_is_audited(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'withdrawn',
                    '2026-08-30',
                    'Audit lifecycle transition.'
                )
            )
            ->assertSuccessful();

        $audit = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->where('module', 'Learner Lifecycle')
            ->where('action', 'Change')
            ->where('table_name', 'learners')
            ->where('record_id', $learner->id)
            ->first();

        $this->assertNotNull($audit);
    }

    public function test_lifecycle_transition_preserves_portal_enabled(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        DB::table('learners')
            ->where('id', $learner->id)
            ->update([
                'portal_enabled' => true,
            ]);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'withdrawn',
                    '2026-08-30',
                    'Portal preservation test.'
                )
            )
            ->assertSuccessful();

        $learner->refresh();

        $this->assertTrue(
            (bool) $learner->portal_enabled
        );
    }

    public function test_lifecycle_transition_does_not_soft_delete_learner(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/lifecycle",
                $this->lifecyclePayload(
                    'transferred',
                    '2026-08-30',
                    'Transfer without deletion.'
                )
            )
            ->assertSuccessful();

        $learner->refresh();

        $this->assertFalse(
            (bool) $learner->is_deleted
        );
        $this->assertNull($learner->deleted_at);
        $this->assertNull($learner->deleted_by);
    }

    public function test_lifecycle_routes_do_not_require_operational_setup_gate(): void
    {
        $routes = collect(
            app('router')->getRoutes()->getRoutes()
        );

        foreach ([
            ['GET', 'api/learners/{learner}/lifecycle'],
            ['PATCH', 'api/learners/{learner}/lifecycle'],
            ['GET', 'api/learners/{learner}/lifecycle/history'],
        ] as [$method, $uri]) {
            $route = $routes->first(
                fn ($route) => in_array($method, $route->methods(), true)
                    && $route->uri() === $uri
            );

            $this->assertNotNull(
                $route,
                "{$method} {$uri} must exist."
            );

            $this->assertNotContains(
                'school.operational',
                $route->gatherMiddleware(),
                "{$method} {$uri} must not require school.operational."
            );
        }
    }

    public function test_tenant_context_mismatch_fails_closed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $foreignSchool = $this->school();

        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $request = Request::create(
            "/api/learners/{$learner->id}/lifecycle",
            'GET'
        );

        $request->setUserResolver(
            fn () => $user
        );

        $request->attributes->set(
            'tenant_school_id',
            $foreignSchool->id
        );

        $controller = app(
            LearnerLifecycleController::class
        );

        try {
            $controller->show(
                $request,
                $learner->id
            );
        } catch (
            HttpException $exception
        ) {
            $this->assertSame(
                403,
                $exception->getStatusCode()
            );

            return;
        }

        $this->fail(
            'Tenant context mismatch must fail closed with HTTP 403.'
        );
    }

    public function test_database_rejects_invalid_lifecycle_status(): void
    {
        [$school] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->setLifecycle($learner, 'active', true);

        $this->expectException(
            QueryException::class
        );

        DB::table('learners')
            ->where('id', $learner->id)
            ->update([
                'lifecycle_status' => 'suspended',
            ]);
    }

    private function lifecyclePayload(
        string $status,
        string $effectiveDate,
        string $reason
    ): array {
        return [
            'status' => $status,
            'effective_date' => $effectiveDate,
            'reason' => $reason,
        ];
    }

    private function setLifecycle(
        Learner $learner,
        ?string $status,
        bool $active
    ): void {
        DB::table('learners')
            ->where('id', $learner->id)
            ->update([
                'lifecycle_status' => $status,
                'active' => $active,
            ]);

        $learner->refresh();
    }

    private function assertLifecycleState(
        Learner $learner,
        ?string $status,
        bool $active
    ): void {
        $learner->refresh();

        $this->assertSame(
            $status,
            $learner->lifecycle_status
        );

        $this->assertSame(
            $active,
            (bool) $learner->active
        );
    }

    private function userWithoutPermission(
        School $school
    ): User {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Lifecycle No Permission '.
                Str::upper(Str::random(8)),
            'description' => 'Lifecycle test role without manage_learners',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'No',
            'last_name' => 'Permission',
            'username' => 'no_permission_'.
                Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(10)).
                '@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
        ]);
    }

    private function schoolAdminWithPermission(): array
    {
        $school = $this->school();
        $user = $this->user($school);

        $permissionId = DB::table('permissions')
            ->where(
                'permission_name',
                'manage_learners'
            )
            ->value('id');

        $this->assertNotNull(
            $permissionId,
            'manage_learners must be provisioned.'
        );

        DB::table('role_permissions')
            ->insert([
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
            'school_name' => 'School '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'LRN-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'LRN',
            'registration_number' => 'REG-'.Str::upper(Str::random(10)),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(
                10000000,
                99999999
            ),
            'email' => Str::lower(Str::random(10)).
                '@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function user(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'School Admin '.
                Str::upper(Str::random(8)),
            'description' => 'Test school administrator',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'username' => 'admin_'.
                Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(10)).
                '@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
        ]);
    }

    private function gradeAndStream(
        School $school,
        string $gradeName = 'Grade 7',
        string $streamName = 'East'
    ): array {
        $grade = Grade::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'grade_name' => $gradeName,
                'grade_order' => random_int(1, 1000),
                'active' => true,
            ]);

        $stream = Stream::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'grade_id' => $grade->id,
                'stream_name' => $streamName,
                'active' => true,
                'created_at' => now(),
            ]);

        return [$grade, $stream];
    }

    private function learner(
        School $school,
        Grade $grade,
        Stream $stream,
        ?string $modeOfStudy = null
    ): Learner {
        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'admission_no' => 'ADM-MODE-'.
                    Str::upper(Str::random(8)),
                'first_name' => 'Existing',
                'last_name' => 'Learner',
                'grade_id' => $grade->id,
                'stream_id' => $stream->id,
                'admission_date' => now()->toDateString(),
                'active' => true,
                'is_deleted' => false,
                'portal_enabled' => false,
            ]);

        if ($modeOfStudy !== null) {
            $learner->mode_of_study = $modeOfStudy;
            $learner->save();
            $learner->refresh();
        }

        return $learner;
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
