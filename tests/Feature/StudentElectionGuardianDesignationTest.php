<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\StudentElection\StudentElectionGuardianService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StudentElectionConcurrencyFixture as F;
use Tests\TestCase;

class StudentElectionGuardianDesignationTest extends TestCase
{
    use DatabaseTransactions;

    private array $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = F::create();
        DB::table('school_settings')->where('school_id', $this->id['school'])->update(['parent_portal_enabled' => true]);
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'draft']);
        $permission = DB::table('permissions')->where('permission_name', 'manage_student_elections')->value('id');
        if (! $permission) {
            throw new \RuntimeException('Election permission migration required.');
        }
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $this->id['admin_role'], 'permission_id' => $permission]);
        $role = DB::table('roles')->where('role_name', 'Parent')->where('active', true)->whereNull('school_id')->value('id');
        if (! $role) {
            throw new \RuntimeException('Migrated Parent role required.');
        }
        foreach (['parent', 'parent_user', 'link'] as $key) {
            $this->id[$key] = (string) Str::uuid();
        }
        $user = (array) DB::table('users')->where('id', $this->id['user'])->first();
        $user['id'] = $this->id['parent_user'];
        $user['role_id'] = $role;
        $user['username'] = 'election-guardian-'.Str::random(12);
        $user['email'] = null;
        DB::table('users')->insert($user);
        DB::table('parents')->insert(['id' => $this->id['parent'], 'school_id' => $this->id['school'], 'user_id' => $this->id['parent_user'],
            'first_name' => 'Private', 'last_name' => 'Guardian', 'phone' => '0700000000', 'relationship' => 'parent', 'active' => true, 'is_deleted' => false]);
        DB::table('learner_parents')->insert(['id' => $this->id['link'], 'learner_id' => $this->id['learner'], 'parent_id' => $this->id['parent'],
            'active' => true, 'is_deleted' => false, 'portal_enabled' => true, 'is_primary_contact' => false, 'can_pick_learner' => false]);
    }

    private function user(string $key = 'admin'): User
    {
        return User::withoutGlobalScopes()->findOrFail($this->id[$key]);
    }

    private function service(): StudentElectionGuardianService
    {
        return app(StudentElectionGuardianService::class);
    }

    private function designate(?string $version = null): array
    {
        return $this->service()->designate($this->user(), $this->id['election'], $this->id['learner'], $this->id['parent'], $version, 'School verified the consenting guardian.');
    }

    private function consent(array $head, string $key = 'parent_user'): array
    {
        return DB::transaction(fn () => $this->service()->guardianForConsent($this->user($key), $this->id['election'], $this->id['learner'], $head['version']));
    }

    private function denied(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Unsafe authority action succeeded.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_designation_requires_explicit_action_not_contact_or_pickup_flags(): void
    {
        DB::table('learner_parents')->where('id', $this->id['link'])->update(['is_primary_contact' => true, 'can_pick_learner' => true]);
        $this->denied(fn () => $this->consent(['version' => (string) Str::uuid()]), 409);
        $this->assertSame(0, DB::table('student_election_guardian_designations')->count());
        $head = $this->designate();
        $this->assertSame(1, $head['revision']);
        $this->assertSame($head, $this->consent($head));
        $this->assertSame(['version', 'revision', 'action', 'learner_id', 'parent_id'], array_keys($head));
        $this->assertSame(1, DB::table('student_election_audit_logs')->where('entity_id', $head['version'])->count());
    }

    public function test_non_primary_non_pickup_guardian_can_be_explicitly_designated(): void
    {
        $head = $this->designate();
        $this->assertSame('designate', $this->consent($head)['action']);
    }

    public function test_staff_cannot_impersonate_the_designated_guardian(): void
    {
        $this->denied(fn () => $this->consent($this->designate(), 'admin'), 403);
    }

    public function test_other_learner_account_cannot_act_for_guardian(): void
    {
        $this->denied(fn () => $this->consent($this->designate(), 'user2'), 403);
    }

    public function test_manager_permission_is_checked_in_service(): void
    {
        $this->denied(fn () => $this->service()->designate($this->user('user'), $this->id['election'], $this->id['learner'], $this->id['parent'], null, 'Attempted unauthorized designation.'), 403);
        $this->assertSame(0, DB::table('student_election_guardian_designations')->count());
    }

    public function test_repeat_change_requires_latest_version(): void
    {
        $first = $this->designate();
        $this->denied(fn () => $this->designate(), 409);
        $second = $this->designate($first['version']);
        $this->assertSame(2, $second['revision']);
        $this->assertNotSame($first['version'], $second['version']);
        $this->denied(fn () => $this->consent($first), 409);
        $this->assertSame($second, $this->consent($second));
    }

    public function test_revoke_preserves_prior_record_and_invalidates_authority(): void
    {
        $first = $this->designate();
        $revoked = $this->service()->revoke($this->user(), $this->id['election'], $this->id['learner'], $first['version'], 'Guardian designation withdrawn after verification.');
        $this->assertSame('revoke', $revoked['action']);
        $this->assertNull($revoked['parent_id']);
        $this->assertSame(2, DB::table('student_election_guardian_designations')->count());
        $this->denied(fn () => $this->consent($first), 409);
        $this->denied(fn () => $this->consent($revoked), 409);
        $fresh = $this->designate($revoked['version']);
        $this->assertSame(3, $fresh['revision']);
        $this->assertSame($fresh, $this->consent($fresh));
    }

    public function test_reason_is_mandatory(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->designate($this->user(), $this->id['election'], $this->id['learner'], $this->id['parent'], null, '');
    }

    public static function unusableLinks(): array
    {
        return [['active', false], ['is_deleted', true], ['portal_enabled', false]];
    }

    #[DataProvider('unusableLinks')]
    public function test_link_change_invalidates_authority(string $field, bool $value): void
    {
        $head = $this->designate();
        DB::table('learner_parents')->where('id', $this->id['link'])->update([$field => $value]);
        $this->expectException(ValidationException::class);
        $this->consent($head);
    }

    public function test_parent_account_relink_cannot_reuse_old_authority(): void
    {
        $head = $this->designate();
        DB::table('parents')->where('id', $this->id['parent'])->update(['user_id' => $this->id['user2']]);
        $this->denied(fn () => $this->consent($head), 403);
    }

    public function test_disabled_guardian_account_is_rechecked(): void
    {
        $head = $this->designate();
        DB::table('users')->where('id', $this->id['parent_user'])->update(['active' => false]);
        $this->expectException(AuthenticationException::class);
        $this->consent($head);
    }

    public static function frozenStates(): array
    {
        return [['voting_open'], ['voting_closed'], ['tallied'], ['published'], ['cancelled']];
    }

    #[DataProvider('frozenStates')]
    public function test_this_foundation_does_not_allow_post_opening_changes(string $state): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => $state]);
        $this->expectException(ValidationException::class);
        $this->designate();
    }

    public function test_frozen_electorate_blocks_change_even_if_status_was_reverted(): void
    {
        DB::table('student_election_electorates')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'],
            'election_id' => $this->id['election'], 'election_position_id' => $this->id['ep'], 'learner_ids' => json_encode([$this->id['learner']]), 'digest' => str_repeat('a', 64), 'ballot_method' => 'legacy_single_selection']);
        $this->expectException(ValidationException::class);
        $this->designate();
    }

    public function test_event_is_immutable_at_database_layer(): void
    {
        $head = $this->designate();
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($head, $operation) {
                    $q = DB::table('student_election_guardian_designations')->where('id', $head['version']);
                    $operation === 'update' ? $q->update(['reason' => 'Rewritten reason']) : $q->delete();
                });
                $this->fail('Immutable evidence was modified.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('append-only', $error->getMessage());
            }
        }
        $this->assertSame($head, $this->consent($head));
    }

    public function test_foreign_school_parent_is_rejected_without_evidence(): void
    {
        // An existing same-database parent cannot be made tenant-local by passing its UUID.
        $foreign = (array) DB::table('schools')->where('id', $this->id['school'])->first();
        $foreign['id'] = (string) Str::uuid();
        $foreign['school_code'] = 'FOREIGN-'.Str::random(10);
        DB::table('schools')->insert($foreign);
        DB::table('parents')->where('id', $this->id['parent'])->update(['school_id' => $foreign['id']]);
        $this->expectException(RecordNotFoundException::class);
        $this->designate();
    }

    public function test_direct_insert_cannot_cross_school_scope(): void
    {
        $head = $this->designate();
        $other = F::create();
        $row = (array) DB::table('student_election_guardian_designations')->where('id', $head['version'])->first();
        $row['id'] = (string) Str::uuid();
        $row['school_id'] = $other['school'];
        $row['revision'] = 2;
        try {
            DB::transaction(fn () => DB::table('student_election_guardian_designations')->insert($row));
            $this->fail('Foreign evidence was accepted.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('tenant scope mismatch', $error->getMessage());
        }
        $this->assertSame(1, DB::table('student_election_guardian_designations')->count());
    }

    public function test_audit_failure_rolls_back_the_designation(): void
    {
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $dispatcher = clone $original;
        $connection->setEventDispatcher($dispatcher);
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if (str_starts_with($event->sql, 'insert into "student_election_audit_logs"')) {
                throw new \RuntimeException('Injected guardian audit failure.');
            }
        });
        try {
            try {
                $this->designate();
                $this->fail('Audit failure was swallowed.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Injected guardian audit failure.', $error->getMessage());
            }
        } finally {
            $connection->setEventDispatcher($original);
        }
        $this->assertSame(0, DB::table('student_election_guardian_designations')->count());
        $this->assertSame(0, DB::table('student_election_audit_logs')->where('entity_type', 'guardian_designation')->count());
    }
}
