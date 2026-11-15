<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\StudentElectionSubscriptionFixture;
use Tests\TestCase;

class StudentElectionHttpPrivacyTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        $connection = DB::connection()->getConfig();
        if (! app()->environment('testing') || app()->configurationIsCached()
            || ($connection['driver'] ?? null) !== 'pgsql'
            || ($connection['database'] ?? null) !== 'shuleos_assessment_fk_test'
            || ! empty($connection['url'])
            || DB::selectOne('SELECT current_database() AS name')->name !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Exact isolated Elections test database required.');
        }
        config(['jwt.secret' => str_repeat('elections-http-test-secret-', 3)]);
        $this->mock(SchoolSetupReadinessService::class)->shouldReceive('isReady')->andReturn(true);
        foreach (['school', 'role', 'user', 'grade', 'stream', 'academic_year', 'learner', 'election', 'position', 'ep', 'candidate', 'voter'] as $k) {
            $this->id[$k] = (string) Str::uuid();
        }

        DB::table('schools')->insert([
            'id' => $this->id['school'],
            'school_name' => 'Student Election Test School',
            'school_code' => 'SETS-'.strtoupper(Str::random(8)),
            'active' => true,
            'is_deleted' => false,
        ]);
        $learnerRoleId = DB::table('roles')
            ->where('role_name', 'Learner')
            ->whereNull('school_id')
            ->where('system_role', true)
            ->where('active', true)
            ->value('id');

        if (! $learnerRoleId) {
            throw new \RuntimeException(
                'Required migrated Learner role was not found.'
            );
        }

        $this->id['role'] = $learnerRoleId;
        foreach (['access_learner_portal', 'access_student_elections', 'view_student_elections',
            'view_published_election_results', 'view_student_leaders', 'vote_in_student_elections'] as $permission) {
            $this->grant($learnerRoleId, $permission);
        }
        DB::table('users')->insert([
            'id' => $this->id['user'],
            'school_id' => $this->id['school'],
            'role_id' => $this->id['role'],
            'username' => 'student-election-'.Str::lower(Str::random(10)),
            'password_hash' => bcrypt('password'),
            'first_name' => 'Election',
            'last_name' => 'Learner',
            'active' => true,
            'is_deleted' => false,
        ]);
        DB::table('school_settings')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'learner_portal_enabled' => 1]);
        DB::table('grades')->insert([
            'id' => $this->id['grade'],
            'school_id' => $this->id['school'],
            'grade_name' => 'Student Election Grade',
            'grade_order' => 99,
            'active' => true,
        ]);

        DB::table('streams')->insert([
            'id' => $this->id['stream'],
            'school_id' => $this->id['school'],
            'grade_id' => $this->id['grade'],
            'stream_name' => 'Student Election Stream',
            'active' => true,
        ]);
        DB::table('learners')->insert(['id' => $this->id['learner'], 'school_id' => $this->id['school'], 'user_id' => $this->id['user'], 'admission_no' => 'SET-'.strtoupper(Str::random(10)), 'first_name' => 'Election', 'last_name' => 'Learner', 'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream'], 'active' => 1, 'portal_enabled' => 1, 'is_deleted' => 0]);
        DB::table('student_leadership_positions')->insert(['id' => $this->id['position'], 'school_id' => $this->id['school'], 'name' => 'President', 'scope' => 'school', 'number_of_winners' => 1, 'created_by' => $this->id['user'], 'active' => 1, 'is_deleted' => 0]);
        DB::table('academic_years')->insert([
            'id' => $this->id['academic_year'],
            'school_id' => $this->id['school'],
            'year_name' => '2026',
            'active' => true,
        ]);
        DB::table('student_elections')->insert(['id' => $this->id['election'], 'school_id' => $this->id['school'], 'academic_year_id' => $this->id['academic_year'], 'title' => 'Student Election Test', 'created_by' => $this->id['user'], 'status' => 'voting_open', 'voting_opens_at' => now()->subHour(), 'voting_closes_at' => now()->addHour(), 'is_deleted' => 0]);
        DB::table('student_election_positions')->insert(['id' => $this->id['ep'], 'school_id' => $this->id['school'], 'election_id' => $this->id['election'], 'position_id' => $this->id['position'], 'active' => 1]);
        DB::table('student_election_candidates')->insert(['id' => $this->id['candidate'], 'school_id' => $this->id['school'], 'election_position_id' => $this->id['ep'], 'learner_id' => $this->id['learner'], 'nomination_type' => 'manual', 'status' => 'approved', 'is_deleted' => 0]);
        DB::table('student_election_voters')->insert(['id' => $this->id['voter'], 'school_id' => $this->id['school'], 'election_id' => $this->id['election'], 'election_position_id' => $this->id['ep'], 'learner_id' => $this->id['learner'], 'eligible' => 1, 'has_voted' => 0]);
        StudentElectionSubscriptionFixture::activate($this->id['school']);
    }

    private function u(): User
    {
        return User::with('role')->find($this->id['user']);
    }

    private function grant(string $role, string $name): void
    {
        $id = DB::table('permissions')->where('permission_name', $name)->value('id');
        $this->assertNotNull($id, 'Missing migrated permission: '.$name);
        if (! DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $id)->exists()) {
            DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $role, 'permission_id' => $id]);
        }
    }

    private function revoke(string $role, string $name): void
    {
        DB::table('role_permissions')->where('role_id', $role)->where('permission_id',
            DB::table('permissions')->where('permission_name', $name)->value('id'))->delete();
    }

    private function httpAs(User $user): void
    {
        Auth::forgetGuards();
        $token = JWTAuth::fromUser($user);
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    private function candidatesUrl(?string $election = null, ?string $position = null): string
    {
        return '/api/learner/elections/'.($election ?? $this->id['election']).'/positions/'.($position ?? $this->id['ep']).'/candidates';
    }

    private function staff(array $permissions): User
    {
        $role = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $role, 'role_name' => 'Election staff '.Str::random(12), 'active' => true]);
        $copy = (array) DB::table('users')->where('id', $this->id['user'])->first();
        $copy['id'] = (string) Str::uuid();
        $copy['username'] = 'election-staff-'.Str::random(12);
        $copy['role_id'] = $role;
        DB::table('users')->insert($copy);
        foreach ($permissions as $name) {
            $this->grant($role, $name);
        }

        return User::withoutGlobalScopes()->findOrFail($copy['id']);
    }

    private function electionCopy(bool $foreignSchool = false): string
    {
        $copy = (array) DB::table('student_elections')->where('id', $this->id['election'])->first();
        $copy['id'] = (string) Str::uuid();
        if ($foreignSchool) {
            $school = (string) Str::uuid();
            DB::table('schools')->insert(['id' => $school, 'school_name' => 'Other election school',
                'school_code' => 'OE-'.Str::random(10), 'active' => true, 'is_deleted' => false]);
            $copy['school_id'] = $school;
        }
        DB::table('student_elections')->insert($copy);

        return $copy['id'];
    }

    private function createElectionResult(): string
    {
        $id = (string) Str::uuid();
        DB::table('student_election_results')->insert([
            'id' => $id, 'school_id' => $this->id['school'], 'election_id' => $this->id['election'],
            'election_position_id' => $this->id['ep'], 'candidate_id' => $this->id['candidate'],
            'vote_count' => 1, 'rank' => 1, 'result_status' => 'winner',
            'tallied_at' => now(), 'tallied_by' => $this->id['user'],
        ]);

        return $id;
    }

    public function test_anonymous_requests_cannot_read_candidates(): void
    {
        $this->getJson($this->candidatesUrl())->assertUnauthorized();
    }

    public function test_candidate_allowlist_is_enforced_through_real_http(): void
    {
        DB::table('student_election_candidates')->where('id', $this->id['candidate'])
            ->update(['manifesto' => 'Safer school.', 'slogan' => 'Serve together.',
                'photo_path' => 'private/DO-NOT-EXPOSE.jpg', 'review_comments' => 'PRIVATE-REVIEW']);
        $this->httpAs($this->u());
        $response = $this->getJson($this->candidatesUrl())->assertOk();
        $this->assertSame(['id', 'election_position_id', 'display_name', 'manifesto', 'slogan'], array_keys($response->json('data.0')));
        foreach ([$this->id['learner'], $this->id['user'], 'PRIVATE-REVIEW', 'DO-NOT-EXPOSE', 'admission_no', 'date_of_birth'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_wrong_election_position_pair_returns_not_found(): void
    {
        $other = $this->electionCopy();
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl($other))->assertNotFound();
    }

    public function test_foreign_school_election_returns_not_found(): void
    {
        $other = $this->electionCopy(true);
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl($other))->assertNotFound();
        $this->getJson('/api/learner/elections/'.$other)->assertNotFound();
    }

    public function test_missing_action_permission_denies_candidates(): void
    {
        $this->revoke($this->id['role'], 'view_student_elections');
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl())->assertForbidden();
    }

    public function test_missing_module_permission_denies_candidates(): void
    {
        $this->revoke($this->id['role'], 'access_student_elections');
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl())->assertForbidden();
    }

    public function test_staff_cannot_impersonate_a_learner_even_with_portal_permissions(): void
    {
        $this->httpAs($this->staff(['access_learner_portal', 'access_student_elections', 'view_student_elections']));
        $this->getJson($this->candidatesUrl())->assertForbidden();
    }

    public function test_elections_access_does_not_enable_the_general_learner_portal(): void
    {
        DB::table('school_settings')->where('school_id', $this->id['school'])->update(['learner_portal_enabled' => false]);
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl())->assertOk();
    }

    public function test_inactive_linked_learner_cannot_read_candidates(): void
    {
        DB::table('learners')->where('id', $this->id['learner'])->update(['active' => false]);
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl())->assertForbidden();
    }

    public function test_draft_election_is_hidden_on_all_learner_read_paths(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'draft']);
        $this->httpAs($this->u());
        $this->getJson('/api/learner/elections')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/learner/elections/'.$this->id['election'])->assertNotFound();
        $this->getJson($this->candidatesUrl())->assertNotFound();
    }

    public function test_campaign_gallery_is_hidden_after_declaration(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'published']);
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl())->assertNotFound();
    }

    public function test_campaign_gallery_is_hidden_after_participation(): void
    {
        DB::table('student_election_voters')->where('id', $this->id['voter'])->update(['has_voted' => true]);
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl())->assertForbidden();
    }

    public function test_inactive_election_position_is_hidden(): void
    {
        DB::table('student_election_positions')->where('id', $this->id['ep'])->update(['active' => false]);
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl())->assertNotFound();
    }

    public function test_another_grade_position_is_hidden(): void
    {
        $grade = (string) Str::uuid();
        DB::table('grades')->insert(['id' => $grade, 'school_id' => $this->id['school'], 'grade_name' => 'Other grade', 'grade_order' => 98, 'active' => true]);
        DB::table('student_leadership_positions')->where('id', $this->id['position'])->update(['scope' => 'grade', 'grade_id' => $grade]);
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl())->assertNotFound();
        $this->getJson('/api/learner/elections')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unknown_voter_rules_fail_closed(): void
    {
        DB::table('student_election_positions')->where('id', $this->id['ep'])->update(['voter_eligibility_rules' => json_encode(['custom' => true])]);
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl())->assertNotFound();
    }

    public function test_rejected_candidates_are_not_returned(): void
    {
        DB::table('student_election_candidates')->where('id', $this->id['candidate'])->update(['status' => 'rejected']);
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_election_response_does_not_expose_audit_identities_or_rules(): void
    {
        $this->httpAs($this->u());
        $response = $this->getJson('/api/learner/elections/'.$this->id['election'])->assertOk();
        $this->assertArrayNotHasKey('created_by', $response->json('data'));
        $this->assertArrayNotHasKey('candidate_eligibility_rules', $response->json('data.positions.0'));
        $this->assertStringNotContainsString($this->id['user'], $response->getContent());
    }

    public function test_unpublished_results_are_hidden(): void
    {
        $this->createElectionResult();
        $this->httpAs($this->u());
        $this->getJson('/api/learner/elections/'.$this->id['election'].'/results')->assertNotFound();
    }

    public function test_published_results_use_an_explicit_allowlist(): void
    {
        $this->createElectionResult();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'published']);
        $this->httpAs($this->u());
        $response = $this->getJson('/api/learner/elections/'.$this->id['election'].'/results')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(['id', 'election_id', 'election_position_id', 'candidate_id', 'vote_count', 'rank', 'result_status'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString($this->id['user'], $response->getContent());
    }

    public function test_council_response_never_serializes_the_learner_model(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'published']);
        DB::table('student_leadership_terms')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'position_id' => $this->id['position'],
            'learner_id' => $this->id['learner'], 'election_id' => $this->id['election'], 'academic_year_id' => $this->id['academic_year'],
            'starts_at' => today(), 'status' => 'active', 'appointment_type' => 'elected',
        ]);
        $this->httpAs($this->u());
        $response = $this->getJson('/api/learner/student-leaders')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Election Learner', $response->json('data.0.display_name'));
        foreach ([$this->id['learner'], $this->id['user'], 'admission_no', 'date_of_birth', 'learner_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_ordinary_learner_vote_route_is_disabled_without_writes(): void
    {
        $this->httpAs($this->u());
        $before = DB::table('student_election_ballots')->count();
        $this->postJson('/api/learner/elections/'.$this->id['election'].'/positions/'.$this->id['ep'].'/vote',
            ['candidate_id' => $this->id['candidate']])->assertForbidden();
        $this->assertSame($before, DB::table('student_election_ballots')->count());
        $this->assertFalse((bool) DB::table('student_election_voters')->where('id', $this->id['voter'])->value('has_voted'));
    }

    public function test_manage_permission_alone_cannot_tally_or_publish_or_generate_voters(): void
    {
        $this->httpAs($this->staff(['manage_student_elections']));
        foreach (['tally', 'publish', 'generate-voters'] as $action) {
            $this->postJson('/api/student-elections/'.$this->id['election'].'/'.$action)->assertForbidden();
        }
        $this->assertSame('voting_open', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
    }

    public function test_admin_cannot_read_running_candidate_totals(): void
    {
        $this->createElectionResult();
        $this->httpAs($this->staff(['manage_student_elections', 'tally_student_elections']));
        $this->getJson('/api/student-elections/'.$this->id['election'].'/results')->assertNotFound();
    }

    public function test_admin_cannot_read_another_school_election(): void
    {
        $other = $this->electionCopy(true);
        $this->httpAs($this->staff(['manage_student_elections', 'tally_student_elections']));
        $this->getJson('/api/student-elections/'.$other)->assertNotFound();
        $this->getJson('/api/student-elections/'.$other.'/results')->assertNotFound();
    }

    public function test_service_rejects_a_position_from_another_election_before_casting(): void
    {
        $other = $this->electionCopy();
        // Even an inconsistent registry row must not let a position escape its parent.
        DB::table('student_election_voters')->where('id', $this->id['voter'])->update(['election_id' => $other]);
        $before = DB::table('student_election_ballots')->count();
        try {
            app(StudentElectionVotingService::class)->vote($this->u(), $other, $this->id['ep'], $this->id['candidate']);
            $this->fail('Mismatched election and position accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('position', $exception->errors());
        }
        $this->assertSame($before, DB::table('student_election_ballots')->count());
    }

    public function test_deleted_position_is_hidden(): void
    {
        DB::table('student_leadership_positions')->where('id', $this->id['position'])->update(['is_deleted' => true]);
        $this->httpAs($this->u());
        $this->getJson($this->candidatesUrl())->assertNotFound();
    }

    public function test_missing_results_permission_is_denied_even_after_publication(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'published']);
        $this->revoke($this->id['role'], 'view_published_election_results');
        $this->httpAs($this->u());
        $this->getJson('/api/learner/elections/'.$this->id['election'].'/results')->assertForbidden();
    }

    public function test_admin_list_uses_allowlisted_fields_and_preserves_pagination(): void
    {
        $this->httpAs($this->staff(['manage_student_elections']));
        $response = $this->getJson('/api/student-elections')->assertOk();
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($this->id['election'], $response->json('data.data.0.id'));
        $this->assertArrayNotHasKey('created_by', $response->json('data.data.0'));
        $this->assertArrayNotHasKey('school_id', $response->json('data.data.0'));
    }

    public function test_admin_create_rejects_foreign_academic_year_without_writes(): void
    {
        $foreign = $this->electionCopy(true);
        $school = DB::table('student_elections')->where('id', $foreign)->value('school_id');
        $year = (string) Str::uuid();
        DB::table('academic_years')->insert(['id' => $year, 'school_id' => $school, 'year_name' => '2027', 'active' => true]);
        $this->httpAs($this->staff(['manage_student_elections']));
        $before = DB::table('student_elections')->count();
        $this->postJson('/api/student-elections', [
            'academic_year_id' => $year, 'title' => 'Must be rejected',
            'voting_opens_at' => now()->toIso8601String(), 'voting_closes_at' => now()->addHour()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('academic_year_id');
        $this->assertSame($before, DB::table('student_elections')->count());
    }

    public function test_admin_can_read_allowlisted_totals_after_closure(): void
    {
        $this->createElectionResult();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'voting_closed']);
        $this->httpAs($this->staff(['manage_student_elections', 'tally_student_elections']));
        $response = $this->getJson('/api/student-elections/'.$this->id['election'].'/results')->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('tallied_by', $response->json('data.0'));
    }
}
