<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Security\File\Contracts\VirusScannerInterface;
use App\Core\Security\File\FileHash;
use App\Core\Security\File\FileQuarantine;
use App\Core\Security\File\FileSecurityManager;
use App\Core\Security\File\Scanners\NullVirusScanner;
use App\Core\Security\File\SecureFileStorage;
use App\Services\SchoolSetupReadinessService;
use App\Services\StudentElection\StudentElectionApplicationService;
use App\Services\StudentElection\StudentElectionPhotoService;
use App\Services\StudentElection\StudentElectionReadService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StudentElectionApplicationFixture as F;
use Tests\Support\StudentElectionApplicationRaceFixture as Race;
use Tests\Support\StudentElectionPhotoTestScanner;
use Tests\TestCase;

class StudentElectionPhotoVersionTest extends TestCase
{
    private array $id = [];

    private array $other = [];

    private string $directory;

    private StudentElectionPhotoTestScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(10));
        $this->id = Race::create();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'shuleos-photo-'.Str::uuid();
        mkdir($this->directory, 0700, true);
        $hash = new FileHash;
        $this->app->instance(FileQuarantine::class, new FileQuarantine($hash, $this->directory.'/quarantine'));
        $this->app->instance(SecureFileStorage::class, new SecureFileStorage($hash, $this->directory.'/encrypted', 'isolated-photo-fixture-key'));
        $this->scanner = new StudentElectionPhotoTestScanner;
        $this->app->instance(VirusScannerInterface::class, $this->scanner);
        $this->app->forgetInstance(FileSecurityManager::class);
        config(['jwt.secret' => str_repeat('photo-http-test-secret-', 4)]);
        $this->mock(SchoolSetupReadinessService::class)->shouldReceive('isReady')->andReturn(true);
    }

    protected function tearDown(): void
    {
        try {
            foreach ([$this->other, $this->id] as $id) {
                if ($id !== []) {
                    Race::cleanup($id);
                }
            }
        } finally {
            if (isset($this->directory) && is_dir($this->directory)) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($this->directory);
            }
            parent::tearDown();
        }
    }

    private function photos(): StudentElectionPhotoService
    {
        return app(StudentElectionPhotoService::class);
    }

    private function image(int $width = 20, int $height = 10, bool $metadata = false): UploadedFile
    {
        $im = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);
        if ($metadata) {
            $text = 'Comment'.chr(0).'GPS:PRIVATE-PHOTO-METADATA';
            $chunk = 'tEXt'.$text;
            $bytes = substr($bytes, 0, -12).pack('N', strlen($text)).$chunk.pack('N', crc32($chunk)).substr($bytes, -12);
        }
        $path = tempnam($this->directory, 'input-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'untrusted-name.png', 'image/png', null, true);
    }

    private function upload(?string $expected = null, ?UploadedFile $file = null): array
    {
        return $this->photos()->upload(F::user($this->id, 'user'), $this->id['learner'], $expected, $file ?? $this->image(), true);
    }

    private function denied(callable $action, int $code): void
    {
        try {
            $action();
            $this->fail('Unsafe photo action succeeded.');
        } catch (HttpException $error) {
            $this->assertSame($code, $error->getStatusCode());
        }
    }

    private function login(string $key): void
    {
        Auth::forgetGuards();
        $token = JWTAuth::fromUser(F::user($this->id, $key));
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    private function apply(string $photo, ?string $version = null): array
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'nominations_open']);
        $this->travel(2)->minutes();

        return app(StudentElectionApplicationService::class)->submit(F::user($this->id, 'user'), $this->id['election'], $version,
            F::data($this->id) + ['photo_version' => $photo]);
    }

    public function test_photo_versions_are_private_encrypted_and_metadata_is_removed(): void
    {
        $input = $this->image(metadata: true);
        $this->assertStringContainsString('GPS:PRIVATE-PHOTO-METADATA', file_get_contents($input->getRealPath()));
        $photo = $this->upload(null, $input);
        $this->assertSame(['version', 'revision', 'width', 'height', 'mime'], array_keys($photo));
        $row = DB::table('student_election_photo_versions')->where('id', $photo['version'])->first();
        $this->assertSame('test_only', $row->scan_basis);
        $this->assertSame(2, $this->scanner->scans);
        $this->assertSame(20, $photo['width']);
        $this->assertSame(10, $photo['height']);
        $stored = app(SecureFileStorage::class)->retrieve($row->storage_id);
        $this->assertStringNotContainsString('GPS:PRIVATE', file_get_contents($stored));
        $response = $this->photos()->profileImage(F::user($this->id, 'user'), $this->id['learner']);
        $decoded = file_get_contents($response->getFile()->getPathname());
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($decoded)[2]);
        $this->assertStringNotContainsString('GPS:PRIVATE', $decoded);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame([], glob($this->directory.'/quarantine/*.pending'));
    }

    public function test_replacing_profile_preserves_the_earlier_version(): void
    {
        $first = $this->upload();
        $second = $this->upload($first['version'], $this->image(30, 15));
        $this->assertNotSame($first['version'], $second['version']);
        $this->assertSame(2, $second['revision']);
        $this->assertSame(2, DB::table('student_election_photo_versions')->where('learner_id', $this->id['learner'])->count());
        $this->assertSame($second, $this->photos()->profile(F::user($this->id, 'user'), $this->id['learner']));
    }

    public function test_stale_profile_update_is_rejected_before_storage(): void
    {
        $this->upload();
        $this->denied(fn () => $this->upload(), 409);
        $this->assertCount(1, glob($this->directory.'/encrypted/*'));
    }

    public function test_another_learner_cannot_upload_for_the_owner(): void
    {
        $this->denied(fn () => $this->photos()->upload(F::user($this->id, 'user2'), $this->id['learner'], null, $this->image(), true), 403);
    }

    public function test_guardian_cannot_replace_the_profile_by_consent_authority(): void
    {
        $this->denied(fn () => $this->photos()->upload(F::user($this->id, 'parent_user'), $this->id['learner'], null, $this->image(), true), 403);
    }

    public function test_school_profile_manager_can_attest_the_owned_learner_photo(): void
    {
        $permission = DB::table('permissions')->where('permission_name', 'manage_learners')->value('id');
        $this->assertNotNull($permission);
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $this->id['admin_role'], 'permission_id' => $permission]);
        $photo = $this->photos()->upload(F::user($this->id), $this->id['learner'], null, $this->image(), true);
        $this->assertSame($this->id['admin'], DB::table('student_election_photo_versions')->where('id', $photo['version'])->value('attested_by'));
    }

    public function test_identity_attestation_is_required(): void
    {
        $this->denied(fn () => $this->photos()->upload(F::user($this->id, 'user'), $this->id['learner'], null, $this->image(), false), 422);
    }

    public function test_null_scanner_cannot_create_verified_versions_even_in_testing(): void
    {
        $this->app->instance(VirusScannerInterface::class, new NullVirusScanner);
        $this->app->forgetInstance(FileSecurityManager::class);
        $this->denied(fn () => $this->upload(), 503);
        $this->assertSame(0, DB::table('student_election_photo_versions')->where('school_id', $this->id['school'])->count());
    }

    public function test_unavailable_scanner_blocks_upload(): void
    {
        $this->scanner->online = false;
        $this->denied(fn () => $this->upload(), 503);
    }

    public function test_rejected_scan_leaves_no_file_or_photo_record(): void
    {
        $this->scanner->clean = false;
        try {
            $this->upload();
            $this->fail('Rejected photo accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('photo', $error->errors());
        }
        $this->assertSame([], glob($this->directory.'/encrypted/*'));
        $this->assertSame([], glob($this->directory.'/quarantine/*'));
        $this->assertSame(0, DB::table('student_election_photo_versions')->where('school_id', $this->id['school'])->count());
    }

    public function test_non_image_content_is_rejected(): void
    {
        $path = tempnam($this->directory, 'bad-');
        file_put_contents($path, '<?php echo "not an image";');
        $this->expectException(ValidationException::class);
        $this->upload(null, new UploadedFile($path, 'photo.png', 'image/png', null, true));
    }

    public function test_excessive_dimensions_are_rejected_before_decoding(): void
    {
        $this->expectException(ValidationException::class);
        $this->upload(null, $this->image(4097, 1));
    }

    public function test_normalized_dimensions_are_bounded(): void
    {
        $photo = $this->upload(null, $this->image(1800, 900));
        $this->assertSame(1024, $photo['width']);
        $this->assertSame(512, $photo['height']);
    }

    public function test_test_scans_cannot_be_used_in_a_production_environment(): void
    {
        $photo = $this->upload();
        try {
            $this->app->instance('env', 'production');
            $this->denied(fn () => $this->photos()->requireVersion($this->id['school'], $this->id['learner'], $photo['version']), 503);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_application_binds_the_original_photo_after_profile_replacement(): void
    {
        $photo = $this->upload();
        $a = $this->apply($photo['version']);
        $this->upload($photo['version'], $this->image(30, 15));
        $view = app(StudentElectionApplicationService::class)->view(F::user($this->id, 'parent_user'), $this->id['election'], $a['id']);
        $this->assertSame($photo['version'], $view['photo_version']);
        $this->assertSame($photo['version'], DB::table('student_election_candidates')->where('id', $a['candidate_id'])->value('photo_version_id'));
        $response = $this->photos()->applicationImage(F::user($this->id, 'parent_user'), $this->id['election'], $a['id']);
        $this->assertSame([20, 10], array_slice(getimagesize($response->getFile()->getPathname()), 0, 2));
    }

    public function test_photo_change_creates_a_new_application_and_requires_fresh_consent(): void
    {
        $first = $this->upload();
        $a = $this->apply($first['version']);
        $service = app(StudentElectionApplicationService::class);
        $service->consent(F::user($this->id, 'parent_user'), $this->id['election'], $a['id'], $a['version'], $this->id['designation']['version'], 'accept');
        $service->review(F::user($this->id), $this->id['election'], $a['id'], $a['version'], true, 'Exact application and learner photo independently reviewed.', false, true);
        $second = $this->upload($first['version'], $this->image(30, 15));
        $b = $this->apply($second['version'], $a['version']);
        $this->assertNotSame($a['version'], $b['version']);
        $this->assertSame('pending_consent', $b['status']);
        $this->denied(fn () => $service->consent(F::user($this->id, 'parent_user'), $this->id['election'], $a['id'], $a['version'], $this->id['designation']['version'], 'accept'), 409);
    }

    public function test_the_previous_profile_version_cannot_be_selected_for_a_new_application(): void
    {
        $first = $this->upload();
        $this->upload($first['version']);
        $this->denied(fn () => $this->apply($first['version']), 409);
    }

    private function requirePhotoPolicy(): void
    {
        $data = $this->id['policy_data'];
        $data['photo_required'] = true;
        $this->id['policy'] = app(StudentElectionApplicationService::class)->publishPolicy(F::user($this->id), $this->id['election'], $this->id['policy']['version'], $data);
    }

    public function test_required_photo_blocks_submission_without_verified_selection(): void
    {
        $this->requirePhotoPolicy();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'nominations_open']);
        $this->travel(2)->minutes();
        $this->expectException(ValidationException::class);
        app(StudentElectionApplicationService::class)->submit(F::user($this->id, 'user'), $this->id['election'], null, F::data($this->id));
    }

    public function test_required_photo_accepts_only_the_owned_current_verified_version(): void
    {
        $this->requirePhotoPolicy();
        $photo = $this->upload();
        $a = $this->apply($photo['version']);
        $this->assertSame($photo['version'], DB::table('student_election_application_versions')->where('id', $a['version'])->value('photo_version_id'));
    }

    public function test_upload_http_requires_identity_attestation(): void
    {
        $this->login('user');
        $this->withHeader('Accept', 'application/json')->post('/api/student-election-photos/learners/'.$this->id['learner'], ['photo' => $this->image()])->assertUnprocessable();
    }

    public function test_upload_http_uses_the_authenticated_learner_and_returns_an_opaque_version(): void
    {
        $this->login('user');
        $response = $this->withHeader('Accept', 'application/json')->post('/api/student-election-photos/learners/'.$this->id['learner'], ['photo' => $this->image(), 'identity_attested' => true])->assertOk();
        $this->assertTrue(Str::isUuid($response->json('data.version')));
        $this->assertArrayNotHasKey('storage_id', $response->json('data'));
    }

    public function test_another_school_photo_cannot_be_bound_to_the_application(): void
    {
        $this->other = Race::create();
        $photo = $this->photos()->upload(F::user($this->other, 'user'), $this->other['learner'], null, $this->image(), true);
        $this->denied(fn () => $this->apply($photo['version']), 404);
    }

    public function test_database_prevents_cross_learner_candidate_photo_assignment(): void
    {
        $photo = $this->upload();
        try {
            DB::transaction(fn () => DB::table('student_election_candidates')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'],
                'election_position_id' => $this->id['ep'], 'learner_id' => $this->id['learner2'], 'nomination_type' => 'manual',
                'status' => 'pending_review', 'is_deleted' => false, 'photo_version_id' => $photo['version']]));
            $this->fail('Cross-learner photo assignment succeeded.');
        } catch (QueryException $error) {
            $this->assertSame('23503', $error->errorInfo[0]);
        }
    }

    private function approvedPhotoCandidate(): array
    {
        $photo = $this->upload();
        $a = $this->apply($photo['version']);
        $service = app(StudentElectionApplicationService::class);
        $service->consent(F::user($this->id, 'parent_user'), $this->id['election'], $a['id'], $a['version'], $this->id['designation']['version'], 'accept');
        $service->review(F::user($this->id), $this->id['election'], $a['id'], $a['version'], true, 'Application and its verified photo independently reviewed.', false, true);
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'campaigning']);

        return $a;
    }

    public function test_campaign_image_is_available_only_before_voting_day_to_eligible_school_learners(): void
    {
        $a = $this->approvedPhotoCandidate();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['voting_opens_at' => now()->addDay(), 'voting_closes_at' => now()->addDay()->addHour()]);
        $response = $this->photos()->campaignImage(F::user($this->id, 'user2'), $this->id['election'], $this->id['ep'], $a['candidate_id']);
        $this->assertSame(IMAGETYPE_PNG, getimagesize($response->getFile()->getPathname())[2]);
    }

    public function test_campaign_image_and_manifesto_are_hidden_on_voting_day(): void
    {
        $a = $this->approvedPhotoCandidate();
        $this->denied(fn () => $this->photos()->campaignImage(F::user($this->id, 'user2'), $this->id['election'], $this->id['ep'], $a['candidate_id']), 403);
        $this->denied(fn () => app(StudentElectionReadService::class)->candidates(F::user($this->id, 'user2'), $this->id['election'], $this->id['ep']), 403);
    }

    public function test_integrity_hold_blocks_campaign_image_delivery(): void
    {
        $a = $this->approvedPhotoCandidate();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['voting_opens_at' => now()->addDay(), 'voting_closes_at' => now()->addDay()->addHour(), 'integrity_hold' => true]);
        $this->denied(fn () => $this->photos()->campaignImage(F::user($this->id, 'user2'), $this->id['election'], $this->id['ep'], $a['candidate_id']), 403);
    }

    public function test_photo_bytes_tampering_blocks_serving(): void
    {
        $photo = $this->upload();
        $row = DB::table('student_election_photo_versions')->where('id', $photo['version'])->first();
        file_put_contents(app(SecureFileStorage::class)->retrieve($row->storage_id), 'tampered encrypted bytes');
        $this->denied(fn () => $this->photos()->profileImage(F::user($this->id, 'user'), $this->id['learner']), 503);
    }

    public function test_photo_record_cannot_be_rewritten(): void
    {
        $photo = $this->upload();
        try {
            DB::transaction(fn () => DB::table('student_election_photo_versions')->where('id', $photo['version'])->update(['width' => 1]));
            $this->fail('Photo evidence changed.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('immutable', $error->getMessage());
        }
    }

    public function test_audit_failure_rolls_back_rows_and_removes_the_uncommitted_file(): void
    {
        $c = DB::connection();
        $original = $c->getEventDispatcher();
        $events = clone $original;
        $c->setEventDispatcher($events);
        $events->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if (str_starts_with($event->sql, 'insert into "student_election_audit_logs"')) {
                throw new \RuntimeException('Injected photo audit failure.');
            }
        });
        try {
            try {
                $this->upload();
                $this->fail('Photo audit failure was swallowed.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Injected photo audit failure.', $error->getMessage());
            }
        } finally {
            $c->setEventDispatcher($original);
        }
        $this->assertSame(0, DB::table('student_election_photo_versions')->where('school_id', $this->id['school'])->count());
        $this->assertSame([], glob($this->directory.'/encrypted/*'));
        $this->assertSame([], glob($this->directory.'/quarantine/*'));
    }

    public function test_unauthenticated_photo_http_request_is_rejected(): void
    {
        $this->getJson('/api/student-election-photos/learners/'.$this->id['learner'])->assertUnauthorized();
    }

    public function test_photo_http_response_omits_storage_identifiers(): void
    {
        $photo = $this->upload();
        $this->login('user');
        $response = $this->getJson('/api/student-election-photos/learners/'.$this->id['learner'])->assertOk()->assertJsonPath('data.version', $photo['version']);
        foreach (['storage_id', 'source_hash', 'stored_hash', 'scanner', 'attested_by'] as $key) {
            $this->assertArrayNotHasKey($key, $response->json('data'));
        }
    }

    public function test_other_learner_cannot_read_photo_through_http(): void
    {
        $this->upload();
        $this->login('user2');
        $this->getJson('/api/student-election-photos/learners/'.$this->id['learner'])->assertForbidden();
    }

    public function test_real_profile_metadata_and_image_reads_share_the_learner_lock(): void
    {
        $this->upload();
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains($query->sql, 'from "learners"') && str_contains($query->sql, 'for ')) {
                $locks[] = $query->sql;
            }
        });
        $this->photos()->profile(F::user($this->id, 'user'), $this->id['learner']);
        $this->photos()->profileImage(F::user($this->id, 'user'), $this->id['learner']);
        $this->assertCount(2, $locks);
        foreach ($locks as $sql) {
            $this->assertStringContainsString('for share', $sql);
            $this->assertStringNotContainsString('for update', $sql);
        }
    }
}
