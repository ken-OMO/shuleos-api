<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudentElectionTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
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
        DB::table('roles')->insert(['id' => $this->id['role'], 'role_name' => 'Learner']);
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
    }

    private function u(): User
    {
        return User::with('role')->find($this->id['user']);
    }

    public function test_vote_is_anonymous_and_registry_records_only_participation(): void
    {
        $r = app(StudentElectionVotingService::class)->vote($this->u(), $this->id['election'], $this->id['ep'], $this->id['candidate']);
        $this->assertTrue($r['confirmed']);
        $this->assertDatabaseCount('student_election_ballots', 1);
        $this->assertTrue((bool) DB::table('student_election_voters')->value('has_voted'));
        $columns = Schema::getColumnListing('student_election_ballots');
        $this->assertNotContains('learner_id', $columns);
        $this->assertNotContains('user_id', $columns);
    }

    public function test_learner_cannot_vote_twice(): void
    {
        $s = app(StudentElectionVotingService::class);
        $s->vote($this->u(), $this->id['election'], $this->id['ep'], $this->id['candidate']);
        $this->expectException(ValidationException::class);
        $s->vote($this->u(), $this->id['election'], $this->id['ep'], $this->id['candidate']);
    }

    public function test_voting_window_and_candidate_approval_are_enforced(): void
    {
        DB::table('student_elections')->update(['voting_closes_at' => now()->subMinute()]);
        try {
            app(StudentElectionVotingService::class)->vote($this->u(), $this->id['election'], $this->id['ep'], $this->id['candidate']);
            $this->fail();
        } catch (ValidationException) {
            $this->assertTrue(true);
        }DB::table('student_elections')->update(['voting_closes_at' => now()->addHour()]);
        DB::table('student_election_candidates')->update(['status' => 'rejected']);
        $this->expectException(ValidationException::class);
        app(StudentElectionVotingService::class)->vote($this->u(), $this->id['election'], $this->id['ep'], $this->id['candidate']);
    }
}
