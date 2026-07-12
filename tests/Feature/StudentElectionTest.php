<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudentElectionTest extends TestCase
{
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['student_election_ballots', 'student_election_voters', 'student_election_candidates', 'student_election_positions', 'student_elections', 'student_leadership_positions', 'learners', 'school_settings', 'user_roles', 'users', 'roles', 'schools'] as $t) {
            Schema::dropIfExists($t);
        }Schema::create('schools', fn (Blueprint $t) => $t->uuid('id')->primary());
        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('role_name');
        });
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('role_id');
            $t->boolean('active');
            $t->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $t) {
            $t->uuid('user_id');
            $t->uuid('role_id');
        });
        Schema::create('school_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->boolean('learner_portal_enabled');
        });
        Schema::create('learners', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('user_id');
            $t->uuid('grade_id');
            $t->uuid('stream_id');
            $t->string('gender')->nullable();
            $t->boolean('active');
            $t->boolean('portal_enabled');
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('student_leadership_positions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('name');
            $t->string('scope');
            $t->uuid('grade_id')->nullable();
            $t->uuid('stream_id')->nullable();
            $t->integer('number_of_winners');
            $t->boolean('active');
            $t->boolean('is_deleted');
        });
        Schema::create('student_elections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('status');
            $t->timestamp('voting_opens_at');
            $t->timestamp('voting_closes_at');
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('student_election_positions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('election_id');
            $t->uuid('position_id');
            $t->integer('number_of_winners')->nullable();
            $t->boolean('active');
            $t->timestamps();
        });
        Schema::create('student_election_candidates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('election_position_id');
            $t->uuid('learner_id');
            $t->string('status');
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('student_election_voters', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('election_id');
            $t->uuid('election_position_id');
            $t->uuid('learner_id');
            $t->boolean('eligible');
            $t->string('voting_token_hash')->nullable();
            $t->boolean('has_voted');
            $t->timestamp('voted_at')->nullable();
            $t->timestamps();
            $t->unique(['election_position_id', 'learner_id']);
        });
        Schema::create('student_election_ballots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('election_id');
            $t->uuid('election_position_id');
            $t->uuid('candidate_id');
            $t->string('anonymous_ballot_token_hash');
            $t->timestamp('cast_at');
            $t->string('ballot_status');
            $t->timestamp('invalidated_at')->nullable();
            $t->uuid('invalidated_by')->nullable();
            $t->text('invalidation_reason')->nullable();
            $t->timestamp('created_at');
        });
        foreach (['school', 'role', 'user', 'learner', 'election', 'position', 'ep', 'candidate', 'voter'] as $k) {
            $this->id[$k] = (string) Str::uuid();
        }DB::table('schools')->insert(['id' => $this->id['school']]);
        DB::table('roles')->insert(['id' => $this->id['role'], 'role_name' => 'Learner']);
        DB::table('users')->insert(['id' => $this->id['user'], 'school_id' => $this->id['school'], 'role_id' => $this->id['role'], 'active' => 1]);
        DB::table('school_settings')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'learner_portal_enabled' => 1]);
        DB::table('learners')->insert(['id' => $this->id['learner'], 'school_id' => $this->id['school'], 'user_id' => $this->id['user'], 'grade_id' => (string) Str::uuid(), 'stream_id' => (string) Str::uuid(), 'active' => 1, 'portal_enabled' => 1, 'is_deleted' => 0]);
        DB::table('student_leadership_positions')->insert(['id' => $this->id['position'], 'school_id' => $this->id['school'], 'name' => 'President', 'scope' => 'school', 'number_of_winners' => 1, 'active' => 1, 'is_deleted' => 0]);
        DB::table('student_elections')->insert(['id' => $this->id['election'], 'school_id' => $this->id['school'], 'status' => 'voting_open', 'voting_opens_at' => now()->subHour(), 'voting_closes_at' => now()->addHour(), 'is_deleted' => 0]);
        DB::table('student_election_positions')->insert(['id' => $this->id['ep'], 'school_id' => $this->id['school'], 'election_id' => $this->id['election'], 'position_id' => $this->id['position'], 'active' => 1]);
        DB::table('student_election_candidates')->insert(['id' => $this->id['candidate'], 'school_id' => $this->id['school'], 'election_position_id' => $this->id['ep'], 'learner_id' => $this->id['learner'], 'status' => 'approved', 'is_deleted' => 0]);
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
