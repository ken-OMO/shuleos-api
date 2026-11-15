<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Services\StudentElection\StudentElectionApplicationService;
use App\Services\StudentElection\StudentElectionGuardianService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StudentElectionApplicationFixture
{
    public static function user(array $id, string $key = 'admin'): User
    {
        return User::withoutGlobalScopes()->findOrFail($id[$key]);
    }

    public static function create(): array
    {
        $id = StudentElectionConcurrencyFixture::create();
        DB::table('student_election_candidates')->where('id', $id['candidate'])->delete();
        DB::table('student_elections')->where('id', $id['election'])->update(['status' => 'draft', 'voting_opens_at' => now()->addHour(), 'voting_closes_at' => now()->addMinutes(90)]);
        DB::table('school_settings')->where('school_id', $id['school'])->update(['parent_portal_enabled' => true, 'learner_portal_enabled' => true]);
        foreach (['manage_student_elections', 'manage_election_candidates', 'tally_student_elections', 'publish_student_election_results'] as $name) {
            $p = DB::table('permissions')->where('permission_name', $name)->value('id');
            if (! $p) {
                throw new \RuntimeException('Election permission missing: '.$name);
            }
            DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $id['admin_role'], 'permission_id' => $p]);
        }
        foreach (['parent', 'parent_user', 'link', 'appeal_user'] as $key) {
            $id[$key] = (string) Str::uuid();
        }
        foreach (['parent_user', 'appeal_user'] as $key) {
            $u = (array) DB::table('users')->where('id', $id['admin'])->first();
            $u['id'] = $id[$key];
            $u['username'] = 'application-'.Str::random(14);
            $u['email'] = null;
            if ($key === 'parent_user') {
                $u['role_id'] = DB::table('roles')->where('role_name', 'Parent')->where('active', true)->whereNull('school_id')->value('id');
                if (! $u['role_id']) {
                    throw new \RuntimeException('Migrated Parent role required.');
                }
            }
            DB::table('users')->insert($u);
        }
        DB::table('parents')->insert(['id' => $id['parent'], 'school_id' => $id['school'], 'user_id' => $id['parent_user'],
            'first_name' => 'Private', 'last_name' => 'Guardian', 'phone' => '0700000000', 'relationship' => 'parent', 'active' => true, 'is_deleted' => false]);
        DB::table('learner_parents')->insert(['id' => $id['link'], 'learner_id' => $id['learner'], 'parent_id' => $id['parent'],
            'active' => true, 'is_deleted' => false, 'portal_enabled' => true]);
        $id['policy_data'] = ['terms' => 'I accept the published office duties and the school election conduct terms.', 'photo_required' => false,
            'reviewer_id' => $id['admin'], 'appeal_reviewer_id' => $id['appeal_user'],
            'opens_at' => now()->addMinute()->toIso8601String(), 'applications_close_at' => now()->addMinutes(10)->toIso8601String(),
            'consent_close_at' => now()->addMinutes(20)->toIso8601String(), 'reviews_close_at' => now()->addMinutes(30)->toIso8601String(),
            'appeals_close_at' => now()->addMinutes(40)->toIso8601String()];
        $id['policy'] = app(StudentElectionApplicationService::class)->publishPolicy(self::user($id), $id['election'], null, $id['policy_data']);
        $id['designation'] = app(StudentElectionGuardianService::class)->designate(self::user($id), $id['election'], $id['learner'], $id['parent'], null, 'School verified the consenting guardian.');

        return $id;
    }

    public static function data(array $id): array
    {
        return ['election_position_id' => $id['ep'], 'manifesto' => 'I will listen to learners and represent their concerns.',
            'slogan' => 'Listen and serve', 'policy_version' => $id['policy']['version'], 'accept_terms' => true];
    }
}
