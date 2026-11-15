<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\StudentElection\StudentElectionStationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\StudentElectionRoundFixture as R;
use Tests\Support\StudentElectionStationFixture as F;
use Tests\TestCase;

class StudentElectionStationHttpTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('classroom-election-http-', 4)]);
        $this->id = F::create();
    }

    private function device(): void
    {
        Auth::forgetGuards();
        $this->withCredentials();
        $this->withHeaders(['Origin' => 'https://school.test', 'X-Election-Station' => '1']);
        $this->withUnencryptedCookie('shuleos_election_device', $this->id['credential']);
    }

    private function teacher(): void
    {
        Auth::forgetGuards();
        $token = JWTAuth::fromUser(R::user($this->id));
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    public function test_teacher_can_approve_through_the_real_tenant_http_pipeline(): void
    {
        $admission = DB::table('learners')->where('id', $this->id['learner'])->value('admission_no');
        $request = app(StudentElectionStationService::class)->request($this->id['credential'], $admission);
        $this->teacher();
        $this->postJson('/api/student-elections/'.$this->id['election'].'/stations/'.$this->id['station'].'/sessions/'.$request['session_id'].'/decision',
            ['allow' => true, 'attendance_id' => $this->id['attendance']])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->device();
        $response = $this->getJson('/api/election-station/state')->assertOk()->assertJsonPath('data.status', 'approved');
        foreach (['registry_id', 'manifesto', 'photo_path', 'learner_id', 'review_comments'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', $response->getContent());
        }
        foreach (['learner', 'learner2', 'voter', 'voter2'] as $key) {
            $this->assertStringNotContainsString($this->id[$key], $response->getContent());
        }
    }

    public function test_manager_can_register_through_tenant_middleware_without_client_ownership(): void
    {
        $this->teacher();
        $this->postJson('/api/student-elections/'.$this->id['election'].'/stations', [
            'teacher_assignment_id' => $this->id['assignment'], 'label' => 'device 3'])->assertCreated();
        $this->postJson('/api/student-elections/'.$this->id['election'].'/stations', [
            'teacher_assignment_id' => $this->id['assignment'], 'label' => 'device 4', 'school_id' => $this->id['school']])->assertUnprocessable();
    }

    public function test_device_cannot_vote_without_teacher_authorization(): void
    {
        $this->device();
        $admission = DB::table('learners')->where('id', $this->id['learner'])->value('admission_no');
        $r = $this->postJson('/api/election-station/request', ['admission_no' => $admission])->assertOk();
        $this->getJson('/api/election-station/state')->assertOk()->assertJsonPath('data.status', 'waiting_for_teacher')->assertJsonMissingPath('data.ballot');
        $this->postJson('/api/election-station/submit', ['session_id' => $r->json('data.session_id'), 'definition_digest' => str_repeat('0', 64),
            'ballots' => [['slot_id' => $this->id['ep'], 'choice' => 'abstain']]])->assertUnprocessable();
        $this->assertSame(0, DB::table('student_election_ballots')->where('election_id', $this->id['election'])->count());
    }

    public function test_complete_http_submission_returns_only_confirmation_and_no_ballot_link(): void
    {
        $state = F::ready($this->id);
        $this->device();
        $response = $this->postJson('/api/election-station/submit', ['session_id' => $state['session_id'], 'definition_digest' => $state['definition_digest'],
            'ballots' => F::answers($state)])->assertOk()->assertJsonPath('data.confirmed', true);
        $this->assertSame(['confirmed', 'message'], array_keys($response->json('data')));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/api/election-station/state')->assertOk()->assertJsonPath('data.status', 'free')->assertJsonMissingPath('data.ballot');
    }

    public function test_cookie_is_required_even_when_a_learner_has_a_jwt(): void
    {
        Auth::forgetGuards();
        $token = JWTAuth::fromUser(R::user($this->id, 'user'));
        $this->withToken($token);
        $this->withHeaders(['Origin' => 'https://school.test', 'X-Election-Station' => '1']);
        $this->getJson('/api/election-station/state')->assertUnauthorized();
    }

    public function test_device_outside_trusted_school_network_is_rejected(): void
    {
        $this->device();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        $this->getJson('/api/election-station/state')->assertForbidden();
    }

    public function test_unconfigured_school_network_is_denied_by_default(): void
    {
        $this->device();
        config(['student_election_stations.school_networks.'.$this->id['school'] => []]);
        $this->getJson('/api/election-station/state')->assertForbidden();
    }

    public function test_wrong_origin_cannot_use_a_valid_device_cookie(): void
    {
        $this->device();
        $this->withHeader('Origin', 'https://attacker.test');
        $this->getJson('/api/election-station/state')->assertForbidden();
    }

    public function test_simple_cross_site_form_without_custom_header_is_rejected(): void
    {
        $this->withCredentials();
        $this->withUnencryptedCookie('shuleos_election_device', $this->id['credential']);
        $this->withHeader('Origin', 'https://school.test');
        $this->postJson('/api/election-station/request', ['admission_no' => 'x'])->assertForbidden();
    }

    public function test_disabled_rollout_flag_blocks_the_prepared_http_surface(): void
    {
        $this->device();
        config(['student_election_stations.enabled' => false]);
        $this->getJson('/api/election-station/state')->assertStatus(503);
    }

    public function test_foreign_classroom_admission_is_not_accepted(): void
    {
        $other = F::create();
        $this->device();
        $admission = DB::table('learners')->where('id', $other['learner'])->value('admission_no');
        $this->postJson('/api/election-station/request', ['admission_no' => $admission])->assertUnprocessable();
    }

    public function test_teacher_permission_without_class_assignment_cannot_approve(): void
    {
        $admission = DB::table('learners')->where('id', $this->id['learner'])->value('admission_no');
        $request = app(StudentElectionStationService::class)->request($this->id['credential'], $admission);
        DB::table('teacher_assignments')->where('id', $this->id['assignment'])->update(['is_class_teacher' => false]);
        $this->teacher();
        $this->postJson('/api/student-elections/'.$this->id['election'].'/stations/'.$this->id['station'].'/sessions/'.$request['session_id'].'/decision',
            ['allow' => true, 'attendance_id' => $this->id['attendance']])->assertUnprocessable();
    }

    public function test_management_role_without_station_permission_cannot_pair(): void
    {
        $permission = DB::table('permissions')->where('permission_name', 'operate_student_election_stations')->value('id');
        DB::table('role_permissions')->where('role_id', $this->id['admin_role'])->where('permission_id', $permission)->delete();
        $this->teacher();
        $this->postJson('/api/student-elections/'.$this->id['election'].'/stations/'.$this->id['station'].'/pair')->assertForbidden();
    }

    public function test_other_device_cannot_redeem_an_approved_session(): void
    {
        $state = F::ready($this->id);
        $service = app(StudentElectionStationService::class);
        $station = $service->register(R::user($this->id), $this->id['election'], $this->id['assignment'], 'device 2');
        $pair = $service->pair(R::user($this->id), $this->id['election'], $station['id']);
        $credential = $service->activate($station['id'], $pair['pairing_token'])['credential'];
        $this->device();
        $this->withUnencryptedCookie('shuleos_election_device', $credential);
        $this->postJson('/api/election-station/submit', ['session_id' => $state['session_id'], 'definition_digest' => $state['definition_digest'], 'ballots' => F::answers($state)])->assertNotFound();
    }

    public function test_pairing_returns_only_a_secure_http_only_device_cookie(): void
    {
        $service = app(StudentElectionStationService::class);
        $pair = $service->pair(R::user($this->id), $this->id['election'], $this->id['station']);
        $this->withHeaders(['Origin' => 'https://school.test', 'X-Election-Station' => '1']);
        $r = $this->postJson('/api/election-station/activate', $pair)->assertOk()->assertJsonMissingPath('data.credential');
        $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === 'shuleos_election_device');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->postJson('/api/election-station/activate', $pair)->assertUnauthorized();
    }

    public function test_admission_lookup_is_throttled_without_guess_disclosure(): void
    {
        $this->device();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/election-station/request', ['admission_no' => 'not-a-classroom-learner'])->assertUnprocessable();
        }
        $this->postJson('/api/election-station/request', ['admission_no' => 'not-a-classroom-learner'])->assertStatus(429);
    }
}
