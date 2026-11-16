<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\StudentElection\StudentElectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\StudentElectionConcurrencyFixture;
use Tests\TestCase;

class StudentElectionUnsupportedSettingsTest extends TestCase
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

    public function test_http_rejects_unsupported_settings_without_creating_an_election(): void
    {
        $before = DB::table('student_elections')->where('school_id', $this->id['school'])->count();
        $row = DB::table('student_elections')->where('id', $this->id['election'])->first();
        $data = ['academic_year_id' => $row->academic_year_id, 'title' => 'Unsupported settings fixture',
            'voting_opens_at' => now()->addDay()->toIso8601String(), 'voting_closes_at' => now()->addDay()->addHour()->toIso8601String()];
        foreach (['allow_self_nomination' => false, 'require_candidate_approval' => null,
            'show_live_turnout' => true, 'results_visibility' => 'after_close'] as $field => $value) {
            $this->postJson('/api/student-elections', $data + [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, DB::table('student_elections')->where('school_id', $this->id['school'])->count());
    }

    public function test_direct_service_call_cannot_bypass_setting_validation(): void
    {
        $this->expectException(ValidationException::class);
        app(StudentElectionService::class)->create($this->id['school'],
            ['require_candidate_approval' => false], $this->id['admin']);
    }
}
