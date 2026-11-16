<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\StudentElectionConcurrencyFixture;
use Tests\TestCase;

class StudentElectionRouteBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    private array $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = StudentElectionConcurrencyFixture::create();
        config(['jwt.secret' => str_repeat('election-route-boundary-', 3)]);
        $this->mock(SchoolSetupReadinessService::class)->shouldReceive('isReady')->andReturn(true);
        foreach (['manage_student_elections', 'manage_election_voters', 'manage_election_candidates', 'tally_student_elections', 'publish_student_election_results'] as $permission) {
            $permissionId = DB::table('permissions')->where('permission_name', $permission)->value('id');
            $this->assertNotNull($permissionId);
            DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $this->id['admin_role'], 'permission_id' => $permissionId]);
        }
        Auth::forgetGuards();
        $token = JWTAuth::fromUser(User::withoutGlobalScopes()->findOrFail($this->id['admin']));
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    public function test_malformed_admin_path_ids_are_rejected_before_election_queries_or_mutations(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            foreach (['student_elections', 'student_election_candidates', 'student_election_voters', 'student_election_positions'] as $table) {
                if (str_contains($query->sql, '"'.$table.'"')) {
                    $queries[] = $query->sql;
                }
            }
        });
        foreach (['not-a-uuid', '123', '00000000-0000-4000-8000-00000000000g'] as $id) {
            $this->getJson('/api/student-elections/'.$id)->assertNotFound();
            $this->getJson('/api/student-elections/'.$id.'/results')->assertNotFound();
            foreach (['positions', 'generate-voters', 'open-nominations', 'close-nominations', 'open-voting', 'close-voting', 'cancel', 'tally', 'publish'] as $action) {
                $this->postJson('/api/student-elections/'.$id.'/'.$action, ['position_id' => $this->id['position']])->assertNotFound();
            }
            foreach (['approve', 'reject', 'disqualify'] as $action) {
                $this->patchJson('/api/student-election-candidates/'.$id.'/'.$action)->assertNotFound();
            }
        }
        $this->assertSame([], $queries);
    }

    public function test_well_formed_unknown_ids_still_reach_controlled_not_found_responses(): void
    {
        $id = (string) Str::uuid();
        $this->getJson('/api/student-elections/'.$id)->assertNotFound();
        $this->patchJson('/api/student-election-candidates/'.$id.'/approve')->assertNotFound();
    }
}
