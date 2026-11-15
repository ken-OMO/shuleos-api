<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\LeadershipPortal\LeadershipPortalPhaseTwoService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class PortalEntryPermissionBackfillTest extends TestCase
{
    use DatabaseTransactions;

    private const GRANTS = [
        'Teacher' => ['view_own_teacher_assignments'],
        'Principal' => [
            'access_school_leadership_portal',
            'access_leadership_portal_phase_two',
            'view_principal_dashboard',
            'view_school_kpis',
            'view_leadership_action_queue',
            'view_leadership_alerts',
        ],
        'Headteacher' => [
            'access_school_leadership_portal',
            'access_leadership_portal_phase_two',
            'view_principal_dashboard',
            'view_school_kpis',
            'view_leadership_action_queue',
            'view_leadership_alerts',
        ],
        'Parent' => ['access_parent_portal_phase_two'],
        'Learner' => ['access_learner_portal_phase_two'],
    ];

    public function test_fresh_migration_baseline_contains_required_portal_entry_permissions(): void
    {
        foreach (self::GRANTS as $name => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertDatabaseHas('role_permissions', [
                    'role_id' => $this->role($name)->id,
                    'permission_id' => $this->permission($permission),
                ]);
            }
        }
    }

    public function test_existing_database_missing_grants_are_restored_without_changing_other_rows(): void
    {
        $this->removeTargetGrants();
        $before = $this->snapshot();
        $this->migration()->up();
        $after = $this->snapshot();
        $this->assertSame($before, array_intersect_key($after, $before));
        $added = array_diff_key($after, $before);
        $this->assertCount(15, $added);
        $expected = [];
        foreach (self::GRANTS as $name => $permissions) {
            foreach ($permissions as $permission) {
                $expected[] = $this->role($name)->id.':'.$this->permission($permission);
            }
        }
        $actual = array_map(fn ($row) => $row['role_id'].':'.$row['permission_id'], $added);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_partial_upgrade_preserves_existing_grant_identity_and_reruns_are_no_ops(): void
    {
        $teacher = $this->role('Teacher');
        $permission = $this->permission('view_own_teacher_assignments');
        $existing = DB::table('role_permissions')->where('role_id', $teacher->id)->where('permission_id', $permission)->first();
        $this->removeTargetGrants(['Teacher']);
        $this->migration()->up();
        $once = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($once, $this->snapshot());
        $this->assertDatabaseHas('role_permissions', (array) $existing);
    }

    public function test_school_scoped_non_system_and_inactive_roles_are_not_modified(): void
    {
        $school = SchoolBuilder::create();
        $this->removeTargetGrants();
        // Exercise identical target names with ineligible classifications without
        // depending on whether this deployment permits duplicate role names.
        DB::table('roles')->where('id', $this->role('Teacher')->id)->update(['school_id' => $school->id]);
        DB::table('roles')->where('id', $this->role('Parent')->id)->update(['system_role' => false]);
        DB::table('roles')->where('id', $this->role('Learner')->id)->update(['active' => false]);
        $roleSnapshot = DB::table('roles')->orderBy('id')->get()->toJson();
        $before = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($roleSnapshot, DB::table('roles')->orderBy('id')->get()->toJson());
        $after = $this->snapshot();
        $addedRoleIds = array_unique(array_column(array_diff_key($after, $before), 'role_id'));
        sort($addedRoleIds);
        $allowed = [$this->role('Principal')->id, $this->role('Headteacher')->id];
        sort($allowed);
        $this->assertSame($allowed, $addedRoleIds);
    }

    public function test_missing_catalogue_permission_fails_before_any_grants_are_added(): void
    {
        $this->removeTargetGrants();
        $id = $this->permission('view_own_teacher_assignments');
        DB::table('permissions')->where('id', $id)->update(['permission_name' => 'temporarily-unavailable-'.Str::uuid()]);
        $before = $this->snapshot();
        try {
            $this->migration()->up();
            $this->fail('Missing catalogue permission should block migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('view_own_teacher_assignments', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_authoritative_context_exposes_only_role_specific_new_grants_in_each_school(): void
    {
        foreach ([SchoolBuilder::create(), SchoolBuilder::create()] as $school) {
            foreach (self::GRANTS as $name => $expected) {
                $record = UserBuilder::create($school, $this->role($name));
                $context = app(AuthContextService::class)->resolve(User::findOrFail($record->id));
                $this->assertSame($school->id, $context['school_id']);
                foreach ($expected as $permission) {
                    $this->assertContains($permission, $context['permissions']);
                }
                foreach (['view_platform_dashboard', 'manage_school_lifecycle', 'publish_student_election_results', 'manage_school_roles'] as $permission) {
                    $this->assertNotContains($permission, $context['permissions']);
                }
                if ($name !== 'Teacher') {
                    $this->assertNotContains('view_own_teacher_assignments', $context['permissions']);
                }
                if (! in_array($name, ['Principal', 'Headteacher'], true)) {
                    $this->assertNotContains('view_principal_dashboard', $context['permissions']);
                }
            }
        }
    }

    public function test_minimum_leadership_grants_satisfy_real_dashboard_middleware_and_service(): void
    {
        config(['jwt.secret' => str_repeat('portal-entry-regression-', 4)]);
        foreach (['Principal', 'Headteacher'] as $name) {
            $school = SchoolBuilder::create();
            $record = UserBuilder::create($school, $this->role($name));
            $login = $this->postJson('/api/auth/login', [
                'username' => $record->username,
                'password' => 'Password123!',
            ]);
            $login->assertOk();
            $this->withToken($login->json('token'))
                ->getJson('/api/leadership/dashboard/principal')
                ->assertOk()->assertJsonPath('data.role_view', 'principal');
        }
    }

    public function test_existing_academic_insight_grant_uses_parent_exam_tenant_scope(): void
    {
        $permission = $this->permission('view_academic_insights');
        $role = $this->role('Principal');
        if (! DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $permission)->exists()) {
            DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $role->id, 'permission_id' => $permission]);
        }
        $fixtures = [];
        foreach ([2, 3] as $paperCount) {
            $school = SchoolBuilder::create();
            $record = UserBuilder::create($school, $role);
            $year = AcademicYearBuilder::create($school);
            $term = TermBuilder::create($school, $year);
            $grade = GradeBuilder::create($school);
            $stream = StreamBuilder::create($school, $grade);
            $learner = LearnerBuilder::create($school, $grade, $stream);
            $area = LearningAreaBuilder::create();
            $assessmentType = (string) Str::uuid();
            $exam = (string) Str::uuid();
            $examArea = (string) Str::uuid();
            DB::table('assessment_types')->insert(['id' => $assessmentType, 'school_id' => $school->id, 'assessment_type_name' => 'Readiness fixture']);
            DB::table('exams')->insert(['id' => $exam, 'school_id' => $school->id, 'exam_name' => 'Readiness fixture', 'assessment_type_id' => $assessmentType, 'academic_year_id' => $year->id, 'term_id' => $term->id]);
            DB::table('exam_learning_areas')->insert(['id' => $examArea, 'exam_id' => $exam, 'learning_area_id' => $area->id]);
            for ($index = 1; $index <= $paperCount; $index++) {
                $paper = (string) Str::uuid();
                DB::table('exam_papers')->insert(['id' => $paper, 'exam_learning_area_id' => $examArea, 'paper_name' => 'Paper '.$index, 'paper_number' => $index]);
                DB::table('exam_results')->insert(['id' => (string) Str::uuid(), 'exam_id' => $exam, 'learner_id' => $learner->id, 'learning_area_id' => $area->id, 'paper_id' => $paper, 'marks' => 50]);
            }
            $fixtures[] = ['user' => User::findOrFail($record->id), 'exam' => $exam, 'count' => $paperCount];
        }
        foreach ($fixtures as $fixture) {
            $dashboard = app(LeadershipPortalPhaseTwoService::class)
                ->dashboard($fixture['user'], 'principal');
            $this->assertSame($fixture['count'], $dashboard['widgets']['academic_readiness']['exam_papers']);
            $this->assertSame($fixture['count'], $dashboard['widgets']['academic_readiness']['raw_results']);
        }
        DB::table('exams')->where('id', $fixtures[0]['exam'])->update(['is_deleted' => true]);
        $dashboard = app(LeadershipPortalPhaseTwoService::class)
            ->dashboard($fixtures[0]['user'], 'principal');
        $this->assertSame(0, $dashboard['widgets']['academic_readiness']['exam_papers']);
        $this->assertSame(0, $dashboard['widgets']['academic_readiness']['raw_results']);
    }

    public function test_rollback_is_explicitly_refused_without_revoking_existing_grants(): void
    {
        $before = $this->snapshot();
        try {
            $this->migration()->down();
            $this->fail('Forward-only migration must refuse rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_15_160000_backfill_portal_entry_permissions.php');
    }

    private function role(string $name): object
    {
        return DB::table('roles')->where('role_name', $name)->firstOrFail();
    }

    private function permission(string $name): string
    {
        return (string) DB::table('permissions')->where('permission_name', $name)->soleValue('id');
    }

    private function removeTargetGrants(array $except = []): void
    {
        foreach (self::GRANTS as $name => $permissions) {
            if (in_array($name, $except, true)) {
                continue;
            }
            DB::table('role_permissions')->where('role_id', $this->role($name)->id)
                ->whereIn('permission_id', array_map(fn ($permission) => $this->permission($permission), $permissions))
                ->delete();
        }
    }

    private function snapshot(): array
    {
        return DB::table('role_permissions')->orderBy('id')->get()
            ->mapWithKeys(fn ($row) => [$row->id => (array) $row])->all();
    }
}
