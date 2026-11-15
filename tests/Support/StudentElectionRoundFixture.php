<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Services\StudentElection\StudentElectionRoundService;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionVotingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StudentElectionRoundFixture
{
    public static function user(array $id, string $key = 'admin'): User
    {
        return User::withoutGlobalScopes()->findOrFail($id[$key]);
    }

    public static function create(bool $unopposed = false, bool $noTurnout = false, bool $resolvedOffice = false): array
    {
        $id = StudentElectionConcurrencyFixture::create();
        DB::table('schools')->where('id', $id['school'])->update(['timezone' => 'Africa/Nairobi']);
        foreach (['manage_student_elections', 'manage_election_voters', 'tally_student_elections', 'publish_student_election_results'] as $name) {
            $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
            if (! $permission) {
                throw new \RuntimeException('Required election permission missing.');
            }
            DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $id['admin_role'], 'permission_id' => $permission]);
        }
        if (! $unopposed) {
            $id['candidate2'] = (string) Str::uuid();
            DB::table('student_election_candidates')->insert(['id' => $id['candidate2'], 'school_id' => $id['school'],
                'election_position_id' => $id['ep'], 'learner_id' => $id['learner2'], 'nomination_type' => 'manual', 'status' => 'approved', 'is_deleted' => false]);
        }
        if ($resolvedOffice) {
            foreach (['resolved_position', 'resolved_ep', 'resolved_candidate', 'resolved_other'] as $key) {
                $id[$key] = (string) Str::uuid();
            }
            $position = (array) DB::table('student_leadership_positions')->where('id', $id['position'])->first();
            $position['id'] = $id['resolved_position'];
            $position['name'] = 'Secretary';
            $position['code'] = 'SECRETARY';
            DB::table('student_leadership_positions')->insert($position);
            DB::table('student_election_positions')->insert(['id' => $id['resolved_ep'], 'school_id' => $id['school'],
                'election_id' => $id['election'], 'position_id' => $id['resolved_position'], 'active' => true]);
            foreach (['resolved_candidate', 'resolved_other'] as $key) {
                $learner = (array) DB::table('learners')->where('id', $id['learner'])->first();
                $learner['id'] = (string) Str::uuid();
                $learner['user_id'] = null;
                $learner['admission_no'] = 'OTHER-'.Str::random(12);
                DB::table('learners')->insert($learner);
                DB::table('student_election_candidates')->insert(['id' => $id[$key], 'school_id' => $id['school'],
                    'election_position_id' => $id['resolved_ep'], 'learner_id' => $learner['id'], 'nomination_type' => 'manual', 'status' => 'approved', 'is_deleted' => false]);
            }
            foreach (['learner', 'learner2'] as $key) {
                DB::table('student_election_voters')->insert(['id' => (string) Str::uuid(), 'school_id' => $id['school'],
                    'election_id' => $id['election'], 'election_position_id' => $id['resolved_ep'], 'learner_id' => $id[$key], 'eligible' => true, 'has_voted' => false]);
            }
        }
        // Open normally so the original eligibility set is frozen before votes.
        DB::table('student_elections')->where('id', $id['election'])->update(['status' => 'campaigning']);
        app(StudentElectionService::class)->transition($id['school'], $id['election'], 'voting_open', $id['admin']);
        if (! $noTurnout) {
            foreach (['user', 'user2'] as $key) {
                app(StudentElectionVotingService::class)->vote(self::user($id, $key), $id['election'], $id['ep'],
                    $id[! $unopposed && $key === 'user2' ? 'candidate2' : 'candidate']);
            }
        }
        if ($resolvedOffice) {
            foreach (['user', 'user2'] as $key) {
                app(StudentElectionVotingService::class)->vote(self::user($id, $key), $id['election'], $id['resolved_ep'], $id['resolved_candidate']);
            }
        }
        app(StudentElectionService::class)->transition($id['school'], $id['election'], 'voting_closed', $id['admin']);
        app(StudentElectionService::class)->tally($id['school'], $id['election'], $id['admin']);
        foreach (['session', 'teacher', 'term', 'register', 'attendance', 'attendance2'] as $key) {
            $id[$key] = (string) Str::uuid();
        }
        DB::table('teachers')->insert(['id' => $id['teacher'], 'school_id' => $id['school'], 'user_id' => $id['admin'], 'active' => true, 'is_deleted' => false]);
        DB::table('terms')->insert(['id' => $id['term'], 'school_id' => $id['school'], 'academic_year_id' => $id['academic_year'], 'term_name' => 'Round fixture', 'active' => true]);
        DB::table('attendance_sessions')->insert(['id' => $id['session'], 'school_id' => $id['school'], 'session_name' => 'Round day', 'active' => true]);
        DB::table('attendance_registers')->insert(['id' => $id['register'], 'school_id' => $id['school'], 'attendance_session_id' => $id['session'],
            'teacher_id' => $id['teacher'], 'grade_id' => $id['grade'], 'stream_id' => $id['stream'], 'academic_year_id' => $id['academic_year'],
            'term_id' => $id['term'], 'attendance_date' => now('Africa/Nairobi')->toDateString(), 'register_type' => 'daily', 'status' => 'finalized',
            'opened_by' => $id['admin'], 'opened_at' => now(), 'is_deleted' => false]);
        $present = DB::table('attendance_statuses')->where('status_code', 'PRESENT')->where('active', true)->value('id');
        if (! $present) {
            throw new \RuntimeException('PRESENT attendance status missing.');
        }
        foreach ([['learner', 'attendance'], ['learner2', 'attendance2']] as [$learner, $attendance]) {
            DB::table('learner_attendance')->insert(['id' => $id[$attendance], 'school_id' => $id['school'], 'learner_id' => $id[$learner],
                'grade_id' => $id['grade'], 'stream_id' => $id['stream'], 'attendance_session_id' => $id['session'], 'attendance_status_id' => $present,
                'attendance_date' => now('Africa/Nairobi')->toDateString(), 'attendance_register_id' => $id['register'], 'marked_by' => $id['admin'], 'finalized' => true]);
        }

        return $id;
    }

    public static function schedule(array $id): array
    {
        return app(StudentElectionRoundService::class)->create(self::user($id), $id['election'], $id['ep'],
            now()->addSeconds(2)->toIso8601String(), now()->addMinutes(20)->toIso8601String());
    }

    public static function open(array $id, array $round): void
    {
        // Only a short fixture wait: child workers use real clocks too.
        $remaining = CarbonImmutable::parse($round['opens_at'])->getTimestamp() - time();
        if ($remaining > 0) {
            usleep(($remaining * 1000000) + 20000);
        }
        app(StudentElectionRoundService::class)->transition(self::user($id), $id['election'], $round['id'], 'open');
    }

    public static function approve(array $id, array $round, bool $second = false): string
    {
        return app(StudentElectionRoundService::class)->approve(self::user($id), $id['election'], $round['id'],
            $id[$second ? 'learner2' : 'learner'], $id[$second ? 'attendance2' : 'attendance']);
    }

    public static function cast(array $id, array $round, string $choice, bool $second = false, ?string $candidate = null): array
    {
        return app(StudentElectionRoundService::class)->cast(self::user($id, $second ? 'user2' : 'user'), $id['election'], $round['id'],
            self::approve($id, $round, $second), $choice, $candidate);
    }

    public static function finish(array $id, array $round): array
    {
        $service = app(StudentElectionRoundService::class);
        $service->transition(self::user($id), $id['election'], $round['id'], 'closed');

        return $service->tally(self::user($id), $id['election'], $round['id']);
    }

    public static function cleanup(array $id): void
    {
        StudentElectionSubscriptionFixture::clearOpenings($id['school']);
        $rounds = DB::table('student_election_rounds')->where('school_id', $id['school'])->pluck('id');
        DB::table('student_election_round_ballots')->whereIn('round_id', $rounds)->delete();
        DB::table('student_election_round_voters')->whereIn('round_id', $rounds)->delete();
        DB::table('student_election_rounds')->where('school_id', $id['school'])->delete();
        DB::table('learner_attendance')->where('school_id', $id['school'])->delete();
        DB::table('attendance_registers')->where('school_id', $id['school'])->delete();
        DB::table('attendance_sessions')->where('school_id', $id['school'])->delete();
        DB::table('teachers')->where('school_id', $id['school'])->delete();
        DB::table('terms')->where('school_id', $id['school'])->delete();
        DB::table('role_permissions')->where('role_id', $id['admin_role'])->delete();
        StudentElectionConcurrencyFixture::cleanup($id);
    }
}
