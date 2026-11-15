<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\StudentElection\StudentElectionReconciliationService;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionStationService;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\StudentElectionRoundFixture as R;
use Tests\Support\StudentElectionStationFixture as F;
use Tests\TestCase;

class StudentElectionStationIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    private function fixture(bool $original = true, bool $two = true): void
    {
        $this->id = F::create($original, $two);
    }

    private function service(): StudentElectionStationService
    {
        return app(StudentElectionStationService::class);
    }

    private function ready(): array
    {
        return F::ready($this->id);
    }

    private function submit(array $state, ?array $answers = null): array
    {
        return $this->service()->submit($this->id['credential'], $state['session_id'], $state['definition_digest'], $answers ?? F::answers($state));
    }

    private function denied(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Unsafe station action succeeded.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    private function ballotCount(): int
    {
        return DB::table('student_election_ballots')->where('election_id', $this->id['election'])->count();
    }

    public function test_complete_ballot_commits_all_positions_and_erases_named_session(): void
    {
        $this->fixture();
        $state = $this->ready();
        $this->assertCount(2, $state['ballot']['slots']);
        $this->assertSame(['confirmed' => true, 'message' => 'Your vote has been recorded.'], $this->submit($state));
        $this->assertSame(2, $this->ballotCount());
        $this->assertSame(2, DB::table('student_election_voters')->where('election_id', $this->id['election'])->where('learner_id', $this->id['learner'])->where('has_voted', true)->count());
        $row = DB::table('student_election_station_sessions')->where('id', $state['session_id'])->first();
        $this->assertSame('submitted', $row->status);
        foreach (['learner_id', 'approved_by', 'attendance_id', 'expires_at', 'definition', 'definition_digest'] as $field) {
            $this->assertNull($row->$field);
        }
        $this->assertSame('free', $this->service()->state($this->id['credential'])['status']);
    }

    public function test_missing_position_does_not_partially_commit(): void
    {
        $this->fixture();
        $state = $this->ready();
        $this->denied(fn () => $this->submit($state, [F::answers($state)[0]]), 'ballots');
        $this->assertSame(0, $this->ballotCount());
        $this->assertSame('approved', DB::table('student_election_station_sessions')->where('id', $state['session_id'])->value('status'));
    }

    public function test_cross_position_candidate_does_not_partially_commit(): void
    {
        $this->fixture();
        $state = $this->ready();
        $answers = F::answers($state);
        foreach ($answers as &$answer) {
            if ($answer['choice'] === 'selection') {
                $answer['candidate_id'] = $this->id['candidate3'];
            }
        }
        unset($answer);
        $this->denied(fn () => $this->submit($state, $answers), 'ballots');
        $this->assertSame(0, $this->ballotCount());
    }

    public function test_explicit_abstention_reconciles_without_inventing_a_winner(): void
    {
        $this->fixture();
        $state = $this->ready();
        $this->submit($state, F::answers($state, true));
        app(StudentElectionService::class)->transition($this->id['school'], $this->id['election'], 'voting_closed', $this->id['admin']);
        $report = app(StudentElectionReconciliationService::class)->inspect(R::user($this->id), $this->id['election']);
        $this->assertTrue($report['reconciled']);
        $this->assertSame(2, array_sum(array_column($report['positions'], 'abstentions')));
        $this->assertSame(0, array_sum(array_column($report['positions'], 'valid_selections')));
        app(StudentElectionService::class)->tally($this->id['school'], $this->id['election'], $this->id['admin']);
        $this->denied(fn () => app(StudentElectionService::class)->publish($this->id['school'], $this->id['election'], $this->id['publisher']), 'declaration');
    }

    public function test_original_unopposed_confirmation_can_be_declared_without_second_poll(): void
    {
        $this->fixture(true, false);
        $state = $this->ready();
        $this->assertSame('confirmation', $state['ballot']['slots'][0]['kind']);
        $this->submit($state);
        app(StudentElectionService::class)->transition($this->id['school'], $this->id['election'], 'voting_closed', $this->id['admin']);
        app(StudentElectionService::class)->tally($this->id['school'], $this->id['election'], $this->id['admin']);
        app(StudentElectionService::class)->publish($this->id['school'], $this->id['election'], $this->id['publisher']);
        $this->assertSame(1, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
    }

    public function test_linked_confirmation_round_uses_the_same_classroom_device_flow(): void
    {
        $this->fixture(false, false);
        $state = $this->ready();
        $this->submit($state);
        $this->assertSame(1, DB::table('student_election_round_ballots')->where('round_id', $this->id['round']['id'])->count());
        $this->assertSame(0, $this->ballotCount());
        R::finish($this->id, $this->id['round']);
        app(StudentElectionService::class)->publish($this->id['school'], $this->id['election'], $this->id['publisher']);
        $this->assertSame(1, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
    }

    public function test_ballot_is_hidden_until_teacher_approval(): void
    {
        $this->fixture();
        $l = DB::table('learners')->where('id', $this->id['learner'])->first();
        $request = $this->service()->request($this->id['credential'], $l->admission_no);
        $this->assertSame('waiting_for_teacher', $this->service()->state($this->id['credential'])['status']);
        $this->assertArrayNotHasKey('ballot', $this->service()->state($this->id['credential']));
        $this->denied(fn () => $this->service()->submit($this->id['credential'], $request['session_id'], str_repeat('0', 64), []), 'session');
    }

    public function test_same_session_cannot_submit_twice(): void
    {
        $this->fixture();
        $state = $this->ready();
        $this->submit($state);
        $this->denied(fn () => $this->submit($state), 'session');
        $this->assertSame(2, $this->ballotCount());
    }

    public function test_teacher_assignment_revocation_blocks_an_approved_vote(): void
    {
        $this->fixture();
        $state = $this->ready();
        DB::table('teacher_assignments')->where('id', $this->id['assignment'])->update(['active' => false]);
        $this->denied(fn () => $this->submit($state), 'assignment');
        $this->assertSame(0, $this->ballotCount());
    }

    public function test_subject_teacher_assignment_is_not_class_authority(): void
    {
        $this->fixture();
        DB::table('teacher_assignments')->where('id', $this->id['assignment'])->update(['is_class_teacher' => false]);
        $this->denied(fn () => $this->service()->pair(R::user($this->id), $this->id['election'], $this->id['station']), 'assignment');
    }

    public function test_out_of_date_term_is_not_class_authority(): void
    {
        $this->fixture();
        DB::table('terms')->where('id', $this->id['term'])->update(['end_date' => now()->subDay()->toDateString()]);
        $this->denied(fn () => $this->service()->pair(R::user($this->id), $this->id['election'], $this->id['station']), 'assignment');
    }

    public function test_attendance_correction_after_approval_blocks_submission(): void
    {
        $this->fixture();
        $state = $this->ready();
        DB::table('learner_attendance')->where('id', $this->id['attendance'])->update(['finalized' => false]);
        $this->denied(fn () => $this->submit($state), 'attendance');
        $this->assertSame(0, $this->ballotCount());
    }

    public function test_approval_expiry_is_explicit_even_in_another_database_timezone(): void
    {
        $this->fixture();
        DB::statement("SET LOCAL TIME ZONE 'Pacific/Honolulu'");
        $state = $this->ready();
        $seconds = DB::selectOne('SELECT EXTRACT(EPOCH FROM (expires_at - clock_timestamp())) AS n FROM student_election_station_sessions WHERE id = ?', [$state['session_id']])->n;
        $this->assertGreaterThan(100, (float) $seconds);
        $this->assertLessThanOrEqual(121, (float) $seconds);
        $this->assertTrue($this->submit($state)['confirmed']);
    }

    public function test_expired_session_requires_fresh_teacher_authorization(): void
    {
        $this->fixture();
        $state = $this->ready();
        DB::table('student_election_station_sessions')->where('id', $state['session_id'])->update(['expires_at' => now()->subSecond()->utc()->toIso8601String()]);
        $this->denied(fn () => $this->submit($state), 'session');
        $this->assertSame('free', $this->service()->state($this->id['credential'])['status']);
        $this->assertSame(0, $this->ballotCount());
    }

    public function test_reset_cancels_approval_but_does_not_undo_accepted_participation(): void
    {
        $this->fixture();
        $state = $this->ready();
        $this->service()->reset(R::user($this->id), $this->id['election'], $this->id['station']);
        $this->denied(fn () => $this->submit($state), 'session');
        $state = $this->ready();
        $this->submit($state);
        $this->service()->reset(R::user($this->id), $this->id['election'], $this->id['station']);
        $this->assertSame(2, $this->ballotCount());
        $this->denied(fn () => $this->ready(), 'vote');
    }

    public function test_changed_candidate_set_requires_fresh_approval(): void
    {
        $this->fixture();
        $state = $this->ready();
        DB::table('learners')->where('id', $this->id['learner'])->update(['first_name' => 'Changed after approval']);
        $this->denied(fn () => $this->submit($state), 'ballots');
        $this->assertSame(0, $this->ballotCount());
    }

    public function test_fault_after_one_ballot_rolls_back_every_position_and_session(): void
    {
        $this->fixture();
        $state = $this->ready();
        $once = true;
        DB::listen(function ($query) use (&$once): void {
            if ($once && str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'student_election_ballots')) {
                $once = false;
                throw new \RuntimeException('Injected ballot persistence fault.');
            }
        });
        try {
            $this->submit($state);
            $this->fail('Fault was not raised.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected ballot persistence fault.', $error->getMessage());
        }
        $this->assertSame(0, $this->ballotCount());
        $this->assertSame(0, DB::table('student_election_voters')->where('election_id', $this->id['election'])->where('has_voted', true)->count());
        $this->assertSame('approved', DB::table('student_election_station_sessions')->where('id', $state['session_id'])->value('status'));
    }

    public function test_approval_audit_failure_leaves_request_pending(): void
    {
        $this->fixture();
        $admission = DB::table('learners')->where('id', $this->id['learner'])->value('admission_no');
        $request = $this->service()->request($this->id['credential'], $admission);
        $once = true;
        DB::listen(function ($query) use (&$once): void {
            if ($once && str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'student_election_audit_logs')) {
                $once = false;
                throw new \RuntimeException('Injected approval audit fault.');
            }
        });
        try {
            $this->service()->decide(R::user($this->id), $this->id['election'], $this->id['station'], $request['session_id'], true, $this->id['attendance']);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected approval audit fault.', $error->getMessage());
        }
        $this->assertSame('pending', DB::table('student_election_station_sessions')->where('id', $request['session_id'])->value('status'));
        $this->assertSame(0, $this->ballotCount());
    }

    public function test_legacy_position_primitive_cannot_bypass_complete_submission(): void
    {
        $this->fixture();
        $this->denied(fn () => app(StudentElectionVotingService::class)->vote(R::user($this->id, 'user'), $this->id['election'], $this->id['ep'], $this->id['candidate']), 'vote');
        $this->assertSame(0, $this->ballotCount());
    }

    public function test_progress_is_counts_only_and_does_not_show_candidate_totals(): void
    {
        $this->fixture();
        $state = $this->ready();
        $this->submit($state);
        $queue = $this->service()->queue(R::user($this->id), $this->id['election'], $this->id['assignment']);
        $this->assertSame(['eligible' => 2, 'completed' => 1, 'remaining' => 1], $queue['progress']);
        $this->assertSame([], $queue['requests']);
        foreach (['candidate', 'candidate2', 'candidate3', 'learner', 'learner2'] as $key) {
            $this->assertStringNotContainsString($this->id[$key], json_encode($queue));
        }
    }

    public function test_ballots_have_no_session_device_or_named_approval_reference(): void
    {
        $this->fixture();
        $state = $this->ready();
        $this->submit($state);
        $cols = Schema::getColumnListing('student_election_ballots');
        foreach (['learner_id', 'user_id', 'session_id', 'station_id', 'device_hash', 'definition_digest', 'cast_at', 'created_at'] as $column) {
            $this->assertNotContains($column, $cols);
        }
    }
}
