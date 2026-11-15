<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StudentElectionConcurrencyFixture
{
    public static function assertIsolated(): void
    {
        $c = DB::connection()->getConfig();
        if (! app()->environment('testing') || app()->configurationIsCached()
            || ($c['driver'] ?? null) !== 'pgsql' || ($c['database'] ?? null) !== 'shuleos_assessment_fk_test'
            || ! empty($c['url']) || DB::selectOne('SELECT current_database() AS name')->name !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Exact isolated Elections PostgreSQL database required.');
        }
    }

    public static function create(): array
    {
        self::assertIsolated();

        return DB::transaction(function () {
            $id = [];
            foreach (['school', 'role', 'user', 'grade', 'stream', 'academic_year', 'learner', 'election', 'position', 'ep', 'candidate', 'voter'] as $k) {
                $id[$k] = (string) Str::uuid();
            }

            DB::table('schools')->insert([
                'id' => $id['school'],
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

            $id['role'] = $learnerRoleId;
            DB::table('users')->insert([
                'id' => $id['user'],
                'school_id' => $id['school'],
                'role_id' => $id['role'],
                'username' => 'student-election-'.Str::lower(Str::random(10)),
                'password_hash' => bcrypt('password'),
                'first_name' => 'Election',
                'last_name' => 'Learner',
                'active' => true,
                'is_deleted' => false,
            ]);
            DB::table('school_settings')->insert(['id' => (string) Str::uuid(), 'school_id' => $id['school'], 'learner_portal_enabled' => 1]);
            DB::table('grades')->insert([
                'id' => $id['grade'],
                'school_id' => $id['school'],
                'grade_name' => 'Student Election Grade',
                'grade_order' => 99,
                'active' => true,
            ]);

            DB::table('streams')->insert([
                'id' => $id['stream'],
                'school_id' => $id['school'],
                'grade_id' => $id['grade'],
                'stream_name' => 'Student Election Stream',
                'active' => true,
            ]);
            DB::table('learners')->insert(['id' => $id['learner'], 'school_id' => $id['school'], 'user_id' => $id['user'], 'admission_no' => 'SET-'.strtoupper(Str::random(10)), 'first_name' => 'Election', 'last_name' => 'Learner', 'grade_id' => $id['grade'], 'stream_id' => $id['stream'], 'active' => 1, 'portal_enabled' => 1, 'is_deleted' => 0]);
            DB::table('student_leadership_positions')->insert(['id' => $id['position'], 'school_id' => $id['school'], 'name' => 'President', 'scope' => 'school', 'number_of_winners' => 1, 'created_by' => $id['user'], 'active' => 1, 'is_deleted' => 0]);
            DB::table('academic_years')->insert([
                'id' => $id['academic_year'],
                'school_id' => $id['school'],
                'year_name' => '2026',
                'active' => true,
            ]);
            DB::table('student_elections')->insert(['id' => $id['election'], 'school_id' => $id['school'], 'academic_year_id' => $id['academic_year'], 'title' => 'Student Election Test', 'created_by' => $id['user'], 'status' => 'voting_open', 'voting_opens_at' => now()->subHour(), 'voting_closes_at' => now()->addHour(), 'is_deleted' => 0]);
            DB::table('student_election_positions')->insert(['id' => $id['ep'], 'school_id' => $id['school'], 'election_id' => $id['election'], 'position_id' => $id['position'], 'active' => 1]);
            DB::table('student_election_candidates')->insert(['id' => $id['candidate'], 'school_id' => $id['school'], 'election_position_id' => $id['ep'], 'learner_id' => $id['learner'], 'nomination_type' => 'manual', 'status' => 'approved', 'is_deleted' => 0]);
            DB::table('student_election_voters')->insert(['id' => $id['voter'], 'school_id' => $id['school'], 'election_id' => $id['election'], 'election_position_id' => $id['ep'], 'learner_id' => $id['learner'], 'eligible' => 1, 'has_voted' => 0]);
            foreach (['user2', 'learner2', 'voter2', 'admin', 'admin_role'] as $key) {
                $id[$key] = (string) Str::uuid();
            }
            $user = (array) DB::table('users')->where('id', $id['user'])->first();
            $user['id'] = $id['user2'];
            $user['username'] = 'election-second-'.Str::random(12);
            $user['email'] = null;
            DB::table('users')->insert($user);
            $learner = (array) DB::table('learners')->where('id', $id['learner'])->first();
            $learner['id'] = $id['learner2'];
            $learner['user_id'] = $id['user2'];
            $learner['admission_no'] = 'SEC-'.Str::random(12);
            $learner['first_name'] = 'Second';
            DB::table('learners')->insert($learner);
            $voter = (array) DB::table('student_election_voters')->where('id', $id['voter'])->first();
            $voter['id'] = $id['voter2'];
            $voter['learner_id'] = $id['learner2'];
            DB::table('student_election_voters')->insert($voter);
            DB::table('roles')->insert(['id' => $id['admin_role'], 'role_name' => 'Election concurrency '.Str::random(12), 'active' => true]);
            $user['id'] = $id['admin'];
            $user['username'] = 'election-admin-'.Str::random(12);
            $user['role_id'] = $id['admin_role'];
            DB::table('users')->insert($user);

            StudentElectionSubscriptionFixture::activate($id['school']);

            return $id;
        });
    }

    public static function cleanup(array $id): void
    {
        self::assertIsolated();
        DB::transaction(function () use ($id) {
            StudentElectionSubscriptionFixture::cleanup($id['school']);
            $stations = DB::table('student_election_stations')->where('school_id', $id['school'])->pluck('id');
            DB::table('student_election_station_sessions')->whereIn('station_id', $stations)->delete();
            DB::table('student_election_stations')->where('school_id', $id['school'])->delete();
            $rounds = DB::table('student_election_rounds')->where('school_id', $id['school'])->pluck('id');
            DB::table('student_election_round_ballots')->whereIn('round_id', $rounds)->delete();
            DB::table('student_election_round_voters')->whereIn('round_id', $rounds)->delete();
            DB::table('student_election_rounds')->where('school_id', $id['school'])->delete();
            DB::table('student_election_electorates')->where('school_id', $id['school'])->delete();
            DB::table('student_election_declarations')->where('school_id', $id['school'])->delete();
            DB::table('student_election_tally_snapshots')->where('school_id', $id['school'])->delete();
            foreach (['student_election_audit_logs', 'student_leadership_terms', 'student_election_results',
                'student_election_ballots', 'student_election_voters', 'student_election_candidates',
                'student_election_positions', 'student_elections', 'student_leadership_positions'] as $table) {
                DB::table($table)->where('school_id', $id['school'])->delete();
            }
            DB::table('learners')->where('school_id', $id['school'])->delete();
            DB::table('user_roles')->whereIn('user_id', [$id['user'], $id['user2'], $id['admin']])->delete();
            DB::table('users')->where('school_id', $id['school'])->delete();
            DB::table('roles')->where('id', $id['admin_role'])->delete();
            DB::table('streams')->where('school_id', $id['school'])->delete();
            DB::table('grades')->where('school_id', $id['school'])->delete();
            DB::table('academic_years')->where('school_id', $id['school'])->delete();
            DB::table('school_settings')->where('school_id', $id['school'])->delete();
            DB::table('schools')->where('id', $id['school'])->delete();
        });
    }
}
