<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\StudentElection\StudentElectionLifecycleAudit;
use App\Services\StudentElection\StudentElectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\StudentElectionConcurrencyFixture;
use Tests\TestCase;

class StudentElectionLifecycleRepairTest extends TestCase
{
    use DatabaseTransactions;

    private array $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = StudentElectionConcurrencyFixture::create();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'draft']);
    }

    private function service(): StudentElectionService
    {
        return app(StudentElectionService::class);
    }

    private function electionData(): array
    {
        return ['academic_year_id' => $this->id['academic_year'], 'title' => 'Audited election',
            'voting_opens_at' => now()->addDay(), 'voting_closes_at' => now()->addDay()->addHour()];
    }

    private function failAudit(): void
    {
        $this->mock(StudentElectionLifecycleAudit::class)->shouldReceive('record')->once()
            ->andThrow(new \RuntimeException('Deliberate lifecycle audit failure.'));
    }

    private function rolledBack(callable $action): void
    {
        try {
            $action();
            $this->fail('Mutation committed without audit.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Deliberate lifecycle audit failure.', $error->getMessage());
        }
    }

    public function test_codeless_positions_are_distinct_but_names_and_nonblank_codes_remain_unique(): void
    {
        $s = $this->id['school'];
        $u = $this->id['admin'];
        $first = $this->service()->position($s, ['name' => 'Vice President', 'scope' => 'school'], $u);
        $second = $this->service()->position($s, ['name' => 'Secretary', 'scope' => 'school', 'code' => ' '], $u);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($second->code);
        $this->service()->position($s, ['name' => 'Treasurer', 'scope' => 'school', 'code' => 'TR'], $u);
        foreach ([['name' => 'Secretary'], ['name' => 'Another Treasurer', 'code' => 'TR']] as $data) {
            try {
                $this->service()->position($s, $data + ['scope' => 'school'], $u);
                $this->fail('Duplicate position accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('name', $error->errors());
            }
        }
        $this->assertSame(3, DB::table('student_election_audit_logs')->where('school_id', $s)->where('action', 'position_created')->count());
    }

    public function test_generation_reports_insertions_and_preserves_existing_eligibility(): void
    {
        DB::table('student_election_voters')->where('id', $this->id['voter'])->update(['eligible' => false]);
        $this->assertSame(0, $this->service()->generateVoters($this->id['school'], $this->id['election'], $this->id['admin']));
        DB::table('student_election_voters')->where('id', $this->id['voter2'])->delete();
        $this->assertSame(1, $this->service()->generateVoters($this->id['school'], $this->id['election'], $this->id['admin']));
        $this->assertSame(0, $this->service()->generateVoters($this->id['school'], $this->id['election'], $this->id['admin']));
        $this->assertFalse((bool) DB::table('student_election_voters')->where('id', $this->id['voter'])->value('eligible'));
        $rows = DB::table('student_election_audit_logs')->where('election_id', $this->id['election'])->where('action', 'voters_generated')->get();
        $this->assertCount(3, $rows);
        $counts = $rows->map(fn ($r) => json_decode($r->metadata, true, 512, JSON_THROW_ON_ERROR)['inserted'])->sort()->values()->all();
        $this->assertSame([0, 0, 1], $counts);
        foreach ($rows as $row) {
            $this->assertSame($this->id['admin'], $row->actor_user_id);
            $this->assertSame(['inserted'], array_keys(json_decode($row->metadata, true, 512, JSON_THROW_ON_ERROR)));
        }
    }

    public function test_election_creation_writes_lifecycle_evidence(): void
    {
        $election = $this->service()->create($this->id['school'], $this->electionData(), $this->id['admin']);
        $this->assertDatabaseHas('student_election_audit_logs', ['election_id' => $election->id,
            'actor_user_id' => $this->id['admin'], 'action' => 'created']);
    }

    public function test_creation_rolls_back_when_audit_fails(): void
    {
        $this->failAudit();
        $this->rolledBack(fn () => $this->service()->create($this->id['school'], $this->electionData(), $this->id['admin']));
        $this->assertSame(1, DB::table('student_elections')->where('school_id', $this->id['school'])->count());
    }

    public function test_position_creation_rolls_back_when_audit_fails(): void
    {
        $this->failAudit();
        $this->rolledBack(fn () => $this->service()->position($this->id['school'], ['name' => 'Secretary', 'scope' => 'school'], $this->id['admin']));
        $this->assertSame(1, DB::table('student_leadership_positions')->where('school_id', $this->id['school'])->count());
    }

    public function test_attachment_rolls_back_when_audit_fails(): void
    {
        $position = $this->service()->position($this->id['school'], ['name' => 'Secretary', 'scope' => 'school'], $this->id['admin']);
        $this->failAudit();
        $this->rolledBack(fn () => $this->service()->attach($this->id['school'], $this->id['election'], $position->id, [], $this->id['admin']));
        $this->assertSame(1, DB::table('student_election_positions')->where('election_id', $this->id['election'])->count());
    }

    public function test_voter_generation_rolls_back_when_audit_fails(): void
    {
        DB::table('student_election_voters')->where('id', $this->id['voter2'])->delete();
        $this->failAudit();
        $this->rolledBack(fn () => $this->service()->generateVoters($this->id['school'], $this->id['election'], $this->id['admin']));
        $this->assertSame(1, DB::table('student_election_voters')->where('election_id', $this->id['election'])->count());
    }

    public function test_closure_rolls_back_when_audit_fails(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'voting_open']);
        $this->failAudit();
        $this->rolledBack(fn () => $this->service()->transition($this->id['school'], $this->id['election'], 'voting_closed', $this->id['admin']));
        $this->assertDatabaseHas('student_elections', ['id' => $this->id['election'], 'status' => 'voting_open']);
    }

    public function test_legacy_review_rolls_back_when_audit_fails(): void
    {
        $this->failAudit();
        $this->rolledBack(fn () => $this->service()->review($this->id['school'], $this->id['candidate'], 'rejected', $this->id['admin']));
        $this->assertDatabaseHas('student_election_candidates', ['id' => $this->id['candidate'], 'status' => 'approved']);
    }

    public function test_other_school_cannot_supply_the_audit_actor(): void
    {
        $other = StudentElectionConcurrencyFixture::create();
        try {
            $this->service()->create($this->id['school'], $this->electionData(), $other['admin']);
            $this->fail('Foreign audit actor accepted.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(1, DB::table('student_elections')->where('school_id', $this->id['school'])->count());
    }

    public function test_opening_rolls_back_phase_receipt_and_electorate_when_lifecycle_audit_fails(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'nominations_closed']);
        $this->failAudit();
        $this->rolledBack(fn () => $this->service()->transition($this->id['school'], $this->id['election'], 'voting_open', $this->id['admin']));
        $this->assertDatabaseHas('student_elections', ['id' => $this->id['election'], 'status' => 'nominations_closed']);
        $this->assertSame(0, DB::table('student_election_commercial_openings')->where('election_id', $this->id['election'])->count());
        $this->assertSame(0, DB::table('student_election_electorates')->where('election_id', $this->id['election'])->count());
    }
}
