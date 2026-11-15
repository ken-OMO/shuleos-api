<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StudentElectionApplicationRaceFixture
{
    public static function create(): array
    {
        StudentElectionConcurrencyFixture::assertIsolated();
        if (DB::selectOne('SELECT current_database() AS name', [], false)->name !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Exact isolated writer database required.');
        }

        if (DB::transactionLevel() !== 0) {
            throw new \RuntimeException('Committed isolated fixtures required.');
        }

        return DB::transaction(function () {
            $id = StudentElectionApplicationFixture::create();
            $id['race_marker'] = 'Election application race '.Str::uuid();
            DB::table('schools')->where('id', $id['school'])->update(['school_name' => $id['race_marker']]);

            return $id;
        });
    }

    public static function cleanup(array $id): void
    {
        StudentElectionConcurrencyFixture::assertIsolated();
        if (DB::selectOne('SELECT current_database() AS name', [], false)->name !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Exact isolated writer database required.');
        }

        if (DB::transactionLevel() !== 0 || ! isset($id['race_marker'])
            || DB::table('schools')->where('id', $id['school'])->value('school_name') !== $id['race_marker']) {
            throw new \RuntimeException('Owned committed race fixture required for cleanup.');
        }
        DB::transaction(function () use ($id) {
            DB::statement("SET LOCAL lock_timeout = '15s'");
            // No other connection can write these tables during scoped cleanup.
            // PostgreSQL rolls trigger DDL back too if any cleanup step fails.
            DB::statement('LOCK TABLE student_elections, student_election_applications, student_election_application_versions, student_election_application_events, student_election_application_policies, student_election_guardian_designations, student_election_photo_versions, student_election_profile_photos, student_election_cancellation_proposals, student_election_cancellation_decisions, student_election_replacements IN ACCESS EXCLUSIVE MODE');
            $triggers = [
                'student_election_replacements' => 'election_cancellation_evidence',
                'student_election_cancellation_decisions' => 'election_cancellation_evidence',
                'student_election_cancellation_proposals' => 'election_cancellation_evidence',
                'student_election_application_versions' => 'election_application_immutable',
                'student_election_application_events' => 'election_application_immutable',
                'student_election_application_policies' => 'election_application_immutable',
                'student_election_guardian_designations' => 'election_guardian_evidence',
                'student_election_photo_versions' => 'election_photo_immutable',
            ];
            foreach ($triggers as $table => $trigger) {
                $state = DB::selectOne('SELECT t.tgenabled FROM pg_trigger t WHERE t.tgrelid = ?::regclass AND t.tgname = ?', [$table, $trigger]);
                if (! $state || $state->tgenabled !== 'O') {
                    throw new \RuntimeException('Expected enabled evidence trigger before isolated cleanup.');
                }
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
            }
            foreach (['student_election_replacements', 'student_election_cancellation_decisions', 'student_election_cancellation_proposals'] as $table) {
                DB::table($table)->where('school_id', $id['school'])->delete();
            }
            $applications = DB::table('student_election_applications')->where('school_id', $id['school'])->pluck('id');
            DB::table('student_elections')->where('school_id', $id['school'])->update(['application_policy_id' => null]);
            DB::table('student_election_applications')->whereIn('id', $applications)->update(['version_id' => null]);
            DB::table('student_election_application_events')->whereIn('application_id', $applications)->delete();
            DB::table('student_election_application_versions')->whereIn('application_id', $applications)->delete();
            DB::table('student_election_applications')->whereIn('id', $applications)->delete();
            DB::table('student_election_application_policies')->where('school_id', $id['school'])->delete();
            DB::table('student_election_guardian_designations')->where('school_id', $id['school'])->delete();
            DB::table('student_election_candidates')->where('school_id', $id['school'])->update(['photo_version_id' => null]);
            DB::table('student_election_profile_photos')->where('school_id', $id['school'])->delete();
            DB::table('student_election_photo_versions')->where('school_id', $id['school'])->delete();
            foreach ($triggers as $table => $trigger) {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
            }
            $parents = DB::table('parents')->where('school_id', $id['school'])->pluck('id');
            DB::table('learner_parents')->whereIn('parent_id', $parents)->delete();
            DB::table('parents')->whereIn('id', $parents)->delete();
            $users = DB::table('users')->where('school_id', $id['school'])->pluck('id');
            DB::table('user_roles')->whereIn('user_id', $users)->delete();
            DB::table('role_permissions')->where('role_id', $id['admin_role'])->delete();
            $stations = DB::table('student_election_stations')->where('school_id', $id['school'])->pluck('id');
            DB::table('student_election_station_sessions')->whereIn('station_id', $stations)->delete();
            DB::table('student_election_stations')->where('school_id', $id['school'])->delete();
            DB::table('teacher_assignments')->where('school_id', $id['school'])->delete();
            DB::table('learner_attendance')->where('school_id', $id['school'])->delete();
            DB::table('attendance_registers')->where('school_id', $id['school'])->delete();
            DB::table('attendance_sessions')->where('school_id', $id['school'])->delete();
            DB::table('teachers')->where('school_id', $id['school'])->delete();
            DB::table('terms')->where('school_id', $id['school'])->delete();
            if (isset($id['learning_area'])) {
                DB::table('learning_areas')->where('id', $id['learning_area'])->delete();
            }
            StudentElectionConcurrencyFixture::cleanup($id);
        });
    }
}
