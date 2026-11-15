<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\StudentElection\StudentElectionApplicationService;
use App\Services\StudentElection\StudentElectionGuardianService;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionStationService;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\StudentElectionApplicationFixture as F;
use Tests\Support\StudentElectionApplicationRaceFixture as Fixture;
use Tests\Support\StudentElectionApplicationRaceRunner as Runner;
use Tests\TestCase;

class StudentElectionApplicationConcurrencyTest extends TestCase
{
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(10));
        $this->id = Fixture::create();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'nominations_open']);
        $this->travel(2)->minutes();
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            if ($this->id !== []) {
                Fixture::cleanup($this->id);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function service(): StudentElectionApplicationService
    {
        return app(StudentElectionApplicationService::class);
    }

    private function submit(): array
    {
        return $this->service()->submit(F::user($this->id, 'user'), $this->id['election'], null, F::data($this->id));
    }

    private function accept(array $a): void
    {
        $this->service()->consent(F::user($this->id, 'parent_user'), $this->id['election'], $a['id'], $a['version'], $this->id['designation']['version'], 'accept');
    }

    private function approve(array $a): void
    {
        $this->service()->review(F::user($this->id), $this->id['election'], $a['id'], $a['version'], true, 'Independent exact-version review with no conflict.', false, true);
    }

    private function job(string $operation, ?array $a = null): array
    {
        $actor = match ($operation) {
            'submit', 'vote' => 'user',
            'consent', 'withdraw' => 'parent_user',
            default => 'admin',
        };

        return ['operation' => $operation, 'school' => $this->id['school'], 'election' => $this->id['election'],
            'actor' => $this->id[$actor], 'clock' => now()->toIso8601String(), 'data' => F::data($this->id),
            'expected_version' => $a['version'] ?? null, 'application' => $a['id'] ?? null,
            'version' => $a['version'] ?? null, 'designation' => $this->id['designation']['version'],
            'ep' => $this->id['ep'], 'candidate' => $a['candidate_id'] ?? null];
    }

    private function race(array $first, array $second, string $firstStatus = 'committed', string $secondStatus = 'committed'): array
    {
        $race = (new Runner)->run($first, $second);
        $this->assertTrue($race['separate_sessions']);
        $this->assertTrue($race['blocking_verified']);
        $this->assertFalse($race['parallel_commit_verified']);
        $this->assertSame($firstStatus, $race['first']['status']);
        $this->assertSame($secondStatus, $race['second']['status']);

        return $race;
    }

    private function application(array $a): object
    {
        return DB::table('student_election_applications')->where('id', $a['id'])->firstOrFail();
    }

    private function events(array $a, string $kind): int
    {
        return DB::table('student_election_application_events')->where('application_id', $a['id'])->where('kind', $kind)->count();
    }

    private function ballots(): int
    {
        return DB::table('student_election_ballots')->where('election_id', $this->id['election'])->count();
    }

    public function test_competing_initial_applications_commit_only_one_version(): void
    {
        $race = $this->race($this->job('submit'), $this->job('submit'), 'committed', 'rejected');
        $this->assertSame(409, $race['second']['http_status']);
        $this->assertSame(1, DB::table('student_election_applications')->where('election_id', $this->id['election'])->count());
        $this->assertSame(1, DB::table('student_election_application_versions')->whereIn('application_id', DB::table('student_election_applications')->where('election_id', $this->id['election'])->pluck('id'))->count());
        $this->assertSame(1, DB::table('student_election_candidates')->where('school_id', $this->id['school'])->count());
    }

    public function test_competing_edits_cannot_overwrite_the_winning_version(): void
    {
        $a = $this->submit();
        $first = $this->job('submit', $a);
        $first['data']['slogan'] = 'First committed revision';
        $second = $this->job('submit', $a);
        $second['data']['slogan'] = 'Stale competing revision';
        $race = $this->race($first, $second, 'committed', 'rejected');
        $this->assertSame(409, $race['second']['http_status']);
        $current = $this->application($a);
        $this->assertNotSame($a['version'], $current->version_id);
        $this->assertSame('First committed revision', DB::table('student_election_application_versions')->where('id', $current->version_id)->value('slogan'));
        $this->assertSame(2, DB::table('student_election_application_versions')->where('application_id', $a['id'])->count());
    }

    public function test_edit_committing_first_rejects_consent_for_the_old_version(): void
    {
        $a = $this->submit();
        $race = $this->race($this->job('submit', $a), $this->job('consent', $a), 'committed', 'rejected');
        $this->assertSame(409, $race['second']['http_status']);
        $this->assertSame(0, $this->events($a, 'consent'));
        $this->assertSame('pending_consent', $this->application($a)->status);
    }

    public function test_consent_committing_first_is_preserved_but_does_not_approve_an_edit(): void
    {
        $a = $this->submit();
        $this->race($this->job('consent', $a), $this->job('submit', $a));
        $this->assertSame(1, $this->events($a, 'consent'));
        $this->assertSame($a['version'], DB::table('student_election_application_events')->where('application_id', $a['id'])->where('kind', 'consent')->value('version_id'));
        $this->assertNotSame($a['version'], $this->application($a)->version_id);
        $this->assertSame('pending_consent', $this->application($a)->status);
        $this->assertSame('pending_review', DB::table('student_election_candidates')->where('id', $a['candidate_id'])->value('status'));
    }

    public function test_edit_committing_first_rejects_review_of_the_old_version(): void
    {
        $a = $this->submit();
        $this->accept($a);
        $race = $this->race($this->job('submit', $a), $this->job('review', $a), 'committed', 'rejected');
        $this->assertSame(409, $race['second']['http_status']);
        $this->assertSame(0, $this->events($a, 'review'));
        $this->assertSame('pending_consent', $this->application($a)->status);
    }

    public function test_review_committing_first_is_retained_but_the_edit_invalidates_approval(): void
    {
        $a = $this->submit();
        $this->accept($a);
        $this->race($this->job('review', $a), $this->job('submit', $a));
        $this->assertSame(1, $this->events($a, 'review'));
        $this->assertSame($a['version'], DB::table('student_election_application_events')->where('application_id', $a['id'])->where('kind', 'review')->value('version_id'));
        $this->assertSame('pending_consent', $this->application($a)->status);
        $this->assertSame('pending_review', DB::table('student_election_candidates')->where('id', $a['candidate_id'])->value('status'));
    }

    public function test_rolled_back_edit_leaves_the_original_version_available_for_consent(): void
    {
        $a = $this->submit();
        $first = $this->job('submit', $a);
        $first['rollback'] = true;
        $this->race($first, $this->job('consent', $a), 'rolled_back', 'committed');
        $this->assertSame($a['version'], $this->application($a)->version_id);
        $this->assertSame(1, DB::table('student_election_application_versions')->where('application_id', $a['id'])->count());
        $this->assertSame('pending_review', $this->application($a)->status);
    }

    public function test_consent_deadline_is_rechecked_after_an_observed_lock_wait(): void
    {
        $a = $this->submit();
        $second = $this->job('consent', $a);
        $second['after_wait_clock'] = now()->addMinutes(25)->toIso8601String();
        $race = $this->race($this->job('hold_election'), $second, 'committed', 'rejected');
        $this->assertContains('window', $race['second']['fields']);
        $this->assertSame(0, $this->events($a, 'consent'));
    }

    private function open(array $a): void
    {
        $this->accept($a);
        $this->approve($a);
        $this->travel(60)->minutes();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'campaigning']);
        app(StudentElectionService::class)->transition($this->id['school'], $this->id['election'], 'voting_open', $this->id['admin']);
    }

    public function test_withdrawal_committing_first_blocks_the_waiting_vote(): void
    {
        $a = $this->submit();
        $this->open($a);
        $race = $this->race($this->job('withdraw', $a), $this->job('vote', $a), 'committed', 'rejected');
        $this->assertContains('election', $race['second']['fields']);
        $this->assertSame(0, $this->ballots());
        $this->assertTrue((bool) DB::table('student_elections')->where('id', $this->id['election'])->value('integrity_hold'));
        $this->assertSame(1, $this->events($a, 'incident'));
    }

    public function test_an_accepted_vote_finishes_before_waiting_withdrawal_and_is_preserved(): void
    {
        $a = $this->submit();
        $this->open($a);
        $this->race($this->job('vote', $a), $this->job('withdraw', $a));
        $this->assertSame(1, $this->ballots());
        $this->assertTrue((bool) DB::table('student_election_voters')->where('id', $this->id['voter'])->value('has_voted'));
        $this->assertTrue((bool) DB::table('student_elections')->where('id', $this->id['election'])->value('integrity_hold'));
        $this->assertSame(1, $this->events($a, 'incident'));
    }

    public function test_rolled_back_withdrawal_does_not_block_a_waiting_vote(): void
    {
        $a = $this->submit();
        $this->open($a);
        $first = $this->job('withdraw', $a);
        $first['rollback'] = true;
        $this->race($first, $this->job('vote', $a), 'rolled_back', 'committed');
        $this->assertFalse((bool) DB::table('student_elections')->where('id', $this->id['election'])->value('integrity_hold'));
        $this->assertSame(0, $this->events($a, 'incident'));
        $this->assertSame(1, $this->ballots());
    }

    private function classroomReady(array $a): array
    {
        config(['student_election_stations.enabled' => true, 'student_election_stations.origin' => 'https://school.test']);
        foreach (['manage_election_voters', 'operate_student_election_stations'] as $name) {
            $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission);
            DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $this->id['admin_role'], 'permission_id' => $permission]);
        }
        foreach (['teacher', 'term', 'assignment', 'attendance_session', 'register', 'attendance', 'learning_area'] as $key) {
            $this->id[$key] = (string) Str::uuid();
        }
        DB::table('learning_areas')->insert(['id' => $this->id['learning_area'], 'learning_area_name' => 'Election race '.$this->id['learning_area'], 'is_custom' => true, 'active' => true]);
        DB::table('teachers')->insert(['id' => $this->id['teacher'], 'school_id' => $this->id['school'], 'user_id' => $this->id['admin'], 'active' => true, 'is_deleted' => false]);
        DB::table('terms')->insert(['id' => $this->id['term'], 'school_id' => $this->id['school'], 'academic_year_id' => $this->id['academic_year'], 'term_name' => 'Application race', 'active' => true,
            'start_date' => now('Africa/Nairobi')->subDay()->toDateString(), 'end_date' => now('Africa/Nairobi')->addDay()->toDateString()]);
        DB::table('teacher_assignments')->insert(['id' => $this->id['assignment'], 'school_id' => $this->id['school'], 'teacher_id' => $this->id['teacher'],
            'learning_area_id' => $this->id['learning_area'], 'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream'], 'academic_year_id' => $this->id['academic_year'],
            'term_id' => $this->id['term'], 'is_class_teacher' => true, 'active' => true, 'is_deleted' => false]);
        DB::table('attendance_sessions')->insert(['id' => $this->id['attendance_session'], 'school_id' => $this->id['school'], 'session_name' => 'Application race', 'active' => true]);
        DB::table('attendance_registers')->insert(['id' => $this->id['register'], 'school_id' => $this->id['school'], 'attendance_session_id' => $this->id['attendance_session'],
            'teacher_id' => $this->id['teacher'], 'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream'], 'academic_year_id' => $this->id['academic_year'],
            'term_id' => $this->id['term'], 'attendance_date' => now('Africa/Nairobi')->toDateString(), 'register_type' => 'daily', 'status' => 'finalized',
            'opened_by' => $this->id['admin'], 'opened_at' => now(), 'is_deleted' => false]);
        $present = DB::table('attendance_statuses')->where('status_code', 'PRESENT')->where('active', true)->value('id');
        $this->assertNotNull($present);
        DB::table('learner_attendance')->insert(['id' => $this->id['attendance'], 'school_id' => $this->id['school'], 'learner_id' => $this->id['learner'],
            'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream'], 'attendance_session_id' => $this->id['attendance_session'], 'attendance_status_id' => $present,
            'attendance_date' => now('Africa/Nairobi')->toDateString(), 'attendance_register_id' => $this->id['register'], 'marked_by' => $this->id['admin'], 'finalized' => true]);
        DB::table('student_elections')->where('id', $this->id['election'])->update(['ballot_method' => 'classroom_single_seat_v1']);
        $this->open($a);
        $service = app(StudentElectionStationService::class);
        $station = $service->register(F::user($this->id), $this->id['election'], $this->id['assignment'], 'race-device');
        $pair = $service->pair(F::user($this->id), $this->id['election'], $station['id']);
        $credential = $service->activate($station['id'], $pair['pairing_token'])['credential'];
        $request = $service->request($credential, DB::table('learners')->where('id', $this->id['learner'])->value('admission_no'));
        $service->decide(F::user($this->id), $this->id['election'], $station['id'], $request['session_id'], true, $this->id['attendance']);
        $state = $service->state($credential);
        $answers = array_map(fn ($slot) => ['slot_id' => $slot['slot_id'], 'choice' => $slot['kind'] === 'confirmation' ? 'approve' : 'selection',
            'candidate_id' => $slot['kind'] === 'confirmation' ? null : $slot['candidates'][0]['id']], $state['ballot']['slots']);

        return $this->job('station_submit', $a) + ['credential' => $credential, 'session' => $state['session_id'], 'digest' => $state['definition_digest'], 'answers' => $answers];
    }

    public function test_withdrawal_committing_first_blocks_classroom_device_submission(): void
    {
        $a = $this->submit();
        $device = $this->classroomReady($a);
        $race = $this->race($this->job('withdraw', $a), $device, 'committed', 'rejected');
        $this->assertContains('election', $race['second']['fields']);
        $this->assertSame(0, $this->ballots());
        $this->assertSame('approved', DB::table('student_election_station_sessions')->where('id', $device['session'])->value('status'));
    }

    public function test_classroom_submission_finishes_before_waiting_withdrawal(): void
    {
        $a = $this->submit();
        $device = $this->classroomReady($a);
        $this->race($device, $this->job('withdraw', $a));
        $this->assertSame(1, $this->ballots());
        $session = DB::table('student_election_station_sessions')->where('id', $device['session'])->firstOrFail();
        $this->assertSame('submitted', $session->status);
        $this->assertNull($session->learner_id);
        $this->assertTrue((bool) DB::table('student_elections')->where('id', $this->id['election'])->value('integrity_hold'));
    }

    private function talliedContest(): array
    {
        $a = $this->submit();
        DB::table('learner_parents')->insert(['id' => (string) Str::uuid(), 'learner_id' => $this->id['learner2'], 'parent_id' => $this->id['parent'], 'active' => true, 'is_deleted' => false, 'portal_enabled' => true]);
        $designation = app(StudentElectionGuardianService::class)->designate(F::user($this->id), $this->id['election'], $this->id['learner2'], $this->id['parent'], null, 'School verified this learner consenting guardian.');
        $b = $this->service()->submit(F::user($this->id, 'user2'), $this->id['election'], null, F::data($this->id));
        $this->service()->consent(F::user($this->id, 'parent_user'), $this->id['election'], $b['id'], $b['version'], $designation['version'], 'accept');
        $this->approve($b);
        $this->open($a);
        app(StudentElectionVotingService::class)->vote(F::user($this->id, 'user'), $this->id['election'], $this->id['ep'], $a['candidate_id']);
        app(StudentElectionService::class)->transition($this->id['school'], $this->id['election'], 'voting_closed', $this->id['admin']);
        app(StudentElectionService::class)->tally($this->id['school'], $this->id['election'], $this->id['admin']);

        return $a;
    }

    public function test_withdrawal_committing_first_blocks_waiting_publication(): void
    {
        $a = $this->talliedContest();
        $snapshot = DB::table('student_election_tally_snapshots')->where('election_id', $this->id['election'])->value('payload');
        $race = $this->race($this->job('withdraw', $a), $this->job('publish', $a), 'committed', 'rejected');
        $this->assertContains('election', $race['second']['fields']);
        $this->assertSame(0, DB::table('student_election_declarations')->where('election_id', $this->id['election'])->count());
        $this->assertSame(0, DB::table('student_leadership_terms')->where('school_id', $this->id['school'])->count());
        $this->assertSame($snapshot, DB::table('student_election_tally_snapshots')->where('election_id', $this->id['election'])->value('payload'));
        $this->assertSame(1, $this->ballots());
    }

    public function test_publication_committing_first_is_preserved_and_followed_by_an_incident_hold(): void
    {
        $a = $this->talliedContest();
        $this->race($this->job('publish', $a), $this->job('withdraw', $a));
        $this->assertSame('published', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
        $this->assertTrue((bool) DB::table('student_elections')->where('id', $this->id['election'])->value('integrity_hold'));
        $this->assertSame(1, DB::table('student_election_declarations')->where('election_id', $this->id['election'])->count());
        $this->assertSame(1, DB::table('student_leadership_terms')->where('school_id', $this->id['school'])->count());
        $this->assertSame(1, $this->events($a, 'incident'));
        $this->assertSame(1, $this->ballots());
    }
}
