<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\StudentElection\StudentElectionStationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\StudentElectionConcurrencyFixture;
use Tests\Support\StudentElectionConcurrencyRunner;
use Tests\Support\StudentElectionRoundFixture as R;
use Tests\Support\StudentElectionStationFixture as F;
use Tests\TestCase;

class StudentElectionStationConcurrencyTest extends TestCase
{
    private array $id = [];

    private array $state = [];

    protected function setUp(): void
    {
        parent::setUp();
        StudentElectionConcurrencyFixture::assertIsolated();
        $this->assertSame(0, DB::transactionLevel());
        $this->id = F::create();
        $this->state = F::ready($this->id);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->id !== []) {
                F::cleanup($this->id);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function job(string $operation): array
    {
        return ['operation' => $operation, 'actor' => $this->id['admin'], 'school' => $this->id['school'], 'election' => $this->id['election'],
            'credential' => $this->id['credential'], 'station' => $this->id['station'], 'session' => $this->state['session_id'],
            'digest' => $this->state['definition_digest'], 'answers' => F::answers($this->state)];
    }

    private function race(array $first, array $second, string $outcome = 'committed', bool $blocking = true, ?callable $after = null): array
    {
        $r = (new StudentElectionConcurrencyRunner)->run($first, $second, $blocking, null, $after);
        $this->assertTrue($r['separate_sessions']);
        $this->assertSame($blocking, $r['blocking_verified']);
        $this->assertSame('committed', $r['first']['status']);
        $this->assertSame($outcome, $r['second']['status']);

        return $r;
    }

    private function ballots(): int
    {
        return DB::table('student_election_ballots')->where('election_id', $this->id['election'])->count();
    }

    public function test_duplicate_complete_submissions_create_only_one_complete_ballot(): void
    {
        $r = $this->race($this->job('station_submit'), $this->job('station_submit'), 'rejected');
        $this->assertContains('session', $r['second']['fields']);
        $this->assertSame(2, $this->ballots());
        $this->assertSame(2, DB::table('student_election_voters')->where('election_id', $this->id['election'])->where('has_voted', true)->count());
    }

    public function test_distinct_students_on_distinct_devices_can_commit_in_parallel(): void
    {
        $svc = app(StudentElectionStationService::class);
        $other = $this->id;
        $other['station'] = $svc->register(R::user($this->id), $this->id['election'], $this->id['assignment'], 'device 2')['id'];
        $pair = $svc->pair(R::user($this->id), $this->id['election'], $other['station']);
        $other['credential'] = $svc->activate($other['station'], $pair['pairing_token'])['credential'];
        $second = F::ready($other, true);
        $job = $this->job('station_submit');
        $job['station'] = $other['station'];
        $job['credential'] = $other['credential'];
        $job['session'] = $second['session_id'];
        $job['digest'] = $second['definition_digest'];
        $job['answers'] = F::answers($second);
        $r = $this->race($this->job('station_submit'), $job, 'committed', false);
        $this->assertTrue($r['parallel_commit_verified']);
        $this->assertSame(4, $this->ballots());
    }

    public function test_election_closure_waits_for_all_positions_to_commit(): void
    {
        $this->race($this->job('station_submit'), $this->job('close'));
        $this->assertSame(2, $this->ballots());
        $this->assertSame('voting_closed', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
    }

    public function test_closure_first_rejects_the_entire_complete_ballot(): void
    {
        $this->race($this->job('close'), $this->job('station_submit'), 'rejected');
        $this->assertSame(0, $this->ballots());
    }

    public function test_reset_first_invalidates_approval_without_accepting_any_position(): void
    {
        $r = $this->race($this->job('station_reset'), $this->job('station_submit'), 'rejected');
        $this->assertContains('session', $r['second']['fields']);
        $this->assertSame(0, $this->ballots());
    }

    public function test_reset_waiting_for_submission_cannot_undo_votes(): void
    {
        $this->race($this->job('station_submit'), $this->job('station_reset'));
        $this->assertSame(2, $this->ballots());
        $this->assertSame('submitted', DB::table('student_election_station_sessions')->where('id', $this->state['session_id'])->value('status'));
    }

    public function test_approval_expiry_is_rechecked_after_registry_contention(): void
    {
        $expires = now()->addSeconds(4)->startOfSecond();
        DB::table('student_election_station_sessions')->where('id', $this->state['session_id'])->update(['expires_at' => $expires->copy()->utc()->toIso8601String()]);
        $hold = $this->job('station_hold_voter') + ['voter' => $this->id['voter']];
        $r = $this->race($hold, $this->job('station_submit'), 'rejected', true, function () use ($expires): void {
            $this->assertTrue(now()->lt($expires), 'Submission must reach the registry lock before expiry.');
            while (now()->lte($expires)) {
                usleep(20000);
            }
        });
        $this->assertContains('session', $r['second']['fields']);
        $this->assertSame(0, $this->ballots());
    }

    public function test_teacher_assignment_revocation_during_wait_blocks_submission(): void
    {
        $hold = $this->job('station_hold_learner') + ['learner' => $this->id['learner']];
        $r = $this->race($hold, $this->job('station_submit'), 'rejected', true, function (): void {
            DB::table('teacher_assignments')->where('id', $this->id['assignment'])->update(['active' => false]);
        });
        $this->assertContains('assignment', $r['second']['fields']);
        $this->assertSame(0, $this->ballots());
    }

    public function test_finalized_attendance_correction_serializes_with_submission(): void
    {
        $correction = $this->job('station_attendance_change') + ['register' => $this->id['register'], 'attendance' => $this->id['attendance']];
        $r = $this->race($correction, $this->job('station_submit'), 'rejected');
        $this->assertContains('attendance', $r['second']['fields']);
        $this->assertSame(0, $this->ballots());
    }
}
