<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\StudentElectionConcurrencyFixture;
use Tests\Support\StudentElectionConcurrencyRunner;
use Tests\TestCase;

class StudentElectionResultConcurrencyTest extends TestCase
{
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        StudentElectionConcurrencyFixture::assertIsolated();
        $this->assertSame(0, DB::transactionLevel());
        $this->id = StudentElectionConcurrencyFixture::create();
        foreach (['manage_student_elections', 'tally_student_elections', 'publish_student_election_results'] as $name) {
            $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission);
            DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $this->id['admin_role'], 'permission_id' => $permission]);
        }
        DB::table('student_election_candidates')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'],
            'election_position_id' => $this->id['ep'], 'learner_id' => $this->id['learner2'], 'nomination_type' => 'manual', 'status' => 'approved', 'is_deleted' => false]);
        foreach (['user', 'user2'] as $key) {
            app(StudentElectionVotingService::class)->vote(User::withoutGlobalScopes()->findOrFail($this->id[$key]),
                $this->id['election'], $this->id['ep'], $this->id['candidate']);
        }
        app(StudentElectionService::class)->transition($this->id['school'], $this->id['election'], 'voting_closed', $this->id['admin']);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->id !== []) {
                DB::table('student_election_declarations')->where('school_id', $this->id['school'])->delete();
                DB::table('student_election_tally_snapshots')->where('school_id', $this->id['school'])->delete();
                DB::table('role_permissions')->where('role_id', $this->id['admin_role'])->delete();
                StudentElectionConcurrencyFixture::cleanup($this->id);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function job(string $operation): array
    {
        return ['operation' => $operation, 'school' => $this->id['school'],
            'election' => $this->id['election'], 'actor' => $this->id[$operation === 'publish' ? 'publisher' : 'admin']];
    }

    private function verifyRace(array $race): void
    {
        $this->assertTrue($race['separate_sessions']);
        $this->assertTrue($race['blocking_verified']);
        $this->assertSame('committed', $race['first']['status']);
        $this->assertSame('committed', $race['second']['status']);
    }

    public function test_competing_tallies_create_one_snapshot_and_one_audit(): void
    {
        $race = (new StudentElectionConcurrencyRunner)->run($this->job('tally'), $this->job('tally'));
        $this->verifyRace($race);
        $this->assertSame(1, DB::table('student_election_tally_snapshots')->where('election_id', $this->id['election'])->count());
        $this->assertSame(2, DB::table('student_election_results')->where('election_id', $this->id['election'])->count());
        $this->assertSame(1, DB::table('student_election_audit_logs')->where('election_id', $this->id['election'])->whereIn('action', ['tallied', 'declared'])->count());
    }

    public function test_competing_declarations_create_one_set_of_appointments(): void
    {
        app(StudentElectionService::class)->tally($this->id['school'], $this->id['election'], $this->id['admin']);
        $race = (new StudentElectionConcurrencyRunner)->run($this->job('publish'), $this->job('publish'));
        $this->verifyRace($race);
        $this->assertSame(1, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
        $this->assertSame(1, DB::table('student_election_declarations')->where('election_id', $this->id['election'])->count());
        $this->assertSame(2, DB::table('student_election_audit_logs')->where('election_id', $this->id['election'])->whereIn('action', ['tallied', 'declared'])->count());
    }

    public function test_declaration_waits_for_the_tally_to_commit(): void
    {
        $race = (new StudentElectionConcurrencyRunner)->run($this->job('tally'), $this->job('publish'));
        $this->verifyRace($race);
        $this->assertSame('published', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
        $this->assertSame(1, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
    }
}
