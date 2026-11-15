<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionStationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StudentElectionStationFixture
{
    public static function create(bool $original = true, bool $twoPositions = true): array
    {
        config(['student_election_stations.enabled' => true, 'student_election_stations.origin' => 'https://school.test']);
        $id = StudentElectionRoundFixture::create(true, true);
        config(['student_election_stations.school_networks.'.$id['school'] => ['127.0.0.1/32']]);
        $id['assignment'] = (string) Str::uuid();
        $permission = DB::table('permissions')->where('permission_name', 'operate_student_election_stations')->value('id');
        if (! $permission) {
            throw new \RuntimeException('Station permission migration is required.');
        }
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $id['admin_role'], 'permission_id' => $permission]);
        $id['learning_area'] = (string) Str::uuid();
        $learning = $id['learning_area'];
        DB::table('learning_areas')->insert([
            'id' => $learning,
            'learning_area_name' => 'Election station fixture '.$learning,
            'is_custom' => true,
            'active' => true,
        ]);
        DB::table('terms')->where('id', $id['term'])->update(['start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString()]);
        DB::table('teacher_assignments')->insert(['id' => $id['assignment'], 'school_id' => $id['school'], 'teacher_id' => $id['teacher'],
            'learning_area_id' => $learning, 'grade_id' => $id['grade'], 'stream_id' => $id['stream'], 'academic_year_id' => $id['academic_year'],
            'term_id' => $id['term'], 'is_class_teacher' => true, 'active' => true, 'is_deleted' => false]);
        if ($original) {
            StudentElectionSubscriptionFixture::clearOpenings($id['school']);
            DB::table('student_election_results')->where('election_id', $id['election'])->delete();
            DB::table('student_election_tally_snapshots')->where('election_id', $id['election'])->delete();
            DB::table('student_election_electorates')->where('election_id', $id['election'])->delete();
            if ($twoPositions) {
                $id['candidate2'] = (string) Str::uuid();
                $id['ep2'] = (string) Str::uuid();
                $id['position2'] = (string) Str::uuid();
                $id['candidate3'] = (string) Str::uuid();
                DB::table('student_election_candidates')->insert(['id' => $id['candidate2'], 'school_id' => $id['school'],
                    'election_position_id' => $id['ep'], 'learner_id' => $id['learner2'], 'nomination_type' => 'manual', 'status' => 'approved', 'is_deleted' => false]);
                $p = (array) DB::table('student_leadership_positions')->where('id', $id['position'])->first();
                $p['id'] = $id['position2'];
                $p['name'] = 'Secretary';
                $p['code'] = 'SEC';
                DB::table('student_leadership_positions')->insert($p);
                DB::table('student_election_positions')->insert(['id' => $id['ep2'], 'school_id' => $id['school'], 'election_id' => $id['election'], 'position_id' => $id['position2'], 'active' => true]);
                $nominee = (array) DB::table('learners')->where('id', $id['learner'])->first();
                $nominee['id'] = (string) Str::uuid();
                $nominee['user_id'] = null;
                $nominee['admission_no'] = 'STN-'.Str::random(12);
                DB::table('learners')->insert($nominee);
                DB::table('student_election_candidates')->insert(['id' => $id['candidate3'], 'school_id' => $id['school'],
                    'election_position_id' => $id['ep2'], 'learner_id' => $nominee['id'], 'nomination_type' => 'manual', 'status' => 'approved', 'is_deleted' => false]);
                foreach (['learner', 'learner2'] as $key) {
                    DB::table('student_election_voters')->insert(['id' => (string) Str::uuid(), 'school_id' => $id['school'], 'election_id' => $id['election'],
                        'election_position_id' => $id['ep2'], 'learner_id' => $id[$key], 'eligible' => true, 'has_voted' => false]);
                }
            }
            DB::table('student_elections')->where('id', $id['election'])->update(['status' => 'campaigning', 'ballot_method' => 'classroom_single_seat_v1',
                'voting_opens_at' => now('Africa/Nairobi')->startOfDay()->setTimezone(config('app.timezone'))->toDateTimeString(),
                'voting_closes_at' => now()->addMinutes(20)->min(now('Africa/Nairobi')->endOfDay()->addSecond()->startOfSecond())->setTimezone(config('app.timezone'))->toDateTimeString()]);
            app(StudentElectionService::class)->transition($id['school'], $id['election'], 'voting_open', $id['admin']);
        } else {
            $id['round'] = StudentElectionRoundFixture::schedule($id);
            StudentElectionRoundFixture::open($id, $id['round']);
        }
        $svc = app(StudentElectionStationService::class);
        $id['station'] = $svc->register(StudentElectionRoundFixture::user($id), $id['election'], $id['assignment'], 'device 1')['id'];
        $pair = $svc->pair(StudentElectionRoundFixture::user($id), $id['election'], $id['station']);
        $id['credential'] = $svc->activate($id['station'], $pair['pairing_token'])['credential'];

        return $id;
    }

    public static function ready(array $id, bool $second = false): array
    {
        $s = app(StudentElectionStationService::class);
        $learner = DB::table('learners')->where('id', $id[$second ? 'learner2' : 'learner'])->firstOrFail();
        $request = $s->request($id['credential'], $learner->admission_no);
        $s->decide(StudentElectionRoundFixture::user($id), $id['election'], $id['station'], $request['session_id'], true, $id[$second ? 'attendance2' : 'attendance']);

        return $s->state($id['credential']);
    }

    public static function answers(array $state, bool $abstain = false): array
    {
        return array_map(fn ($slot) => ['slot_id' => $slot['slot_id'], 'choice' => $abstain ? 'abstain' : ($slot['kind'] === 'confirmation' ? 'approve' : 'selection'),
            'candidate_id' => ! $abstain && $slot['kind'] !== 'confirmation' ? $slot['candidates'][0]['id'] : null], $state['ballot']['slots']);
    }

    public static function cleanup(array $id): void
    {
        $stations = DB::table('student_election_stations')->where('school_id', $id['school'])->pluck('id');
        DB::table('student_election_station_sessions')->whereIn('station_id', $stations)->delete();
        DB::table('student_election_stations')->where('school_id', $id['school'])->delete();
        DB::table('teacher_assignments')->where('school_id', $id['school'])->delete();
        StudentElectionRoundFixture::cleanup($id);
        if (isset($id['learning_area'])) {
            DB::table('learning_areas')->where('id', $id['learning_area'])->delete();
        }
    }
}
