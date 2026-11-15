<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SchoolSetupReadinessService;
use App\Services\StudentElection\StudentElectionApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\StudentElectionApplicationFixture as F;
use Tests\TestCase;

class StudentElectionApplicationHttpTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('application-http-secret-', 4)]);
        $this->mock(SchoolSetupReadinessService::class)->shouldReceive('isReady')->andReturn(true);
        $this->travelTo(now()->startOfSecond());
        $this->id = F::create();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'nominations_open']);
        $this->travel(2)->minutes();
    }

    private function login(string $key): void
    {
        Auth::forgetGuards();
        $token = JWTAuth::fromUser(F::user($this->id, $key));
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/student-election-applications/'.$this->id['election'].$suffix;
    }

    private function application(): array
    {
        return app(StudentElectionApplicationService::class)->submit(F::user($this->id, 'user'), $this->id['election'], null, F::data($this->id));
    }

    public function test_unauthenticated_application_submission_is_rejected(): void
    {
        $this->postJson($this->url(), F::data($this->id))->assertUnauthorized();
    }

    public function test_learner_can_submit_and_receive_an_allowlisted_response(): void
    {
        $this->login('user');
        $r = $this->postJson($this->url(), F::data($this->id))->assertOk()->assertJsonPath('data.status', 'pending_consent');
        $this->assertSame(['id', 'version', 'status', 'candidate_id'], array_keys($r->json('data')));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }

    public function test_designated_guardian_can_read_exact_terms_and_consent(): void
    {
        $a = $this->application();
        $this->login('parent_user');
        $this->getJson($this->url('/'.$a['id']))->assertOk()->assertJsonPath('data.policy_version', $this->id['policy']['version']);
        $this->postJson($this->url('/'.$a['id'].'/consent'), ['version' => $a['version'], 'designation' => $this->id['designation']['version'], 'decision' => 'accept'])
            ->assertOk()->assertJsonPath('data.status', 'pending_review');
    }

    public function test_other_learner_cannot_read_private_material(): void
    {
        $a = $this->application();
        $this->login('user2');
        $r = $this->getJson($this->url('/'.$a['id']))->assertForbidden();
        $this->assertStringNotContainsString(F::data($this->id)['manifesto'], $r->getContent());
    }

    public function test_staff_cannot_submit_guardian_consent(): void
    {
        $a = $this->application();
        $this->login('admin');
        $this->postJson($this->url('/'.$a['id'].'/consent'), ['version' => $a['version'], 'designation' => $this->id['designation']['version'], 'decision' => 'accept'])->assertForbidden();
    }

    public function test_learner_cannot_use_review_endpoint(): void
    {
        $a = $this->application();
        $this->login('user');
        $this->postJson($this->url('/'.$a['id'].'/review'), ['version' => $a['version'], 'approve' => true, 'reason' => 'Attempted self-approval bypass.', 'conflict_free' => true])->assertForbidden();
    }

    public function test_cross_school_application_id_is_not_visible(): void
    {
        $a = $this->application();
        $other = F::create();
        Auth::forgetGuards();
        $token = JWTAuth::fromUser(F::user($other, 'admin'));
        JWTAuth::setToken($token);
        $this->withToken($token);
        $this->getJson($this->url('/'.$a['id']))->assertNotFound();
    }

    public function test_photo_path_is_rejected_at_http_boundary(): void
    {
        $this->login('user');
        $this->postJson($this->url(), F::data($this->id) + ['photo_path' => '/private/other-school/photo.jpg'])->assertUnprocessable();
    }
}
