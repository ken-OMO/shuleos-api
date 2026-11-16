<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\StudentElectionApplicationRaceFixture as Fixture;
use Tests\Support\StudentElectionApplicationRaceRunner as Runner;
use Tests\TestCase;

class StudentElectionPhotoReadConcurrencyTest extends TestCase
{
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(10));
        $this->id = Fixture::create();
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

    private function job(string $operation): array
    {
        return ['operation' => $operation, 'school' => $this->id['school'],
            'actor' => $this->id['user'], 'learner' => $this->id['learner'],
            'clock' => now()->toIso8601String()];
    }

    public function test_profile_readers_complete_in_separate_sessions_without_serializing(): void
    {
        $race = (new Runner)->run($this->job('photo_profile_read'), $this->job('photo_profile_read'), false);
        $this->assertTrue($race['separate_sessions']);
        $this->assertTrue($race['parallel_commit_verified']);
        $this->assertSame('committed', $race['first']['status']);
        $this->assertSame('committed', $race['second']['status']);
    }

    public function test_profile_reader_waits_for_the_exclusive_upload_ownership_lock(): void
    {
        // Acquire the learner lock used by upload, without invoking file scanning.
        $race = (new Runner)->run($this->job('photo_learner_write'), $this->job('photo_profile_read'));
        $this->assertTrue($race['blocking_verified']);
        $this->assertSame('committed', $race['second']['status']);
    }

    public function test_learner_access_revocation_waits_for_an_in_progress_profile_read(): void
    {
        $race = (new Runner)->run($this->job('photo_profile_read'), $this->job('photo_learner_revoke'));
        $this->assertTrue($race['blocking_verified']);
        $this->assertSame('committed', $race['second']['status']);
        $this->assertFalse((bool) DB::table('learners')->where('id', $this->id['learner'])->value('portal_enabled'));
    }

    public function test_profile_read_rechecks_access_after_waiting_for_revocation(): void
    {
        $race = (new Runner)->run($this->job('photo_learner_revoke'), $this->job('photo_profile_read'));
        $this->assertTrue($race['blocking_verified']);
        $this->assertSame('rejected', $race['second']['status']);
    }
}
