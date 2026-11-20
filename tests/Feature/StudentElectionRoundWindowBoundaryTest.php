<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\StudentElection\StudentElectionRoundService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\StudentElectionRoundFixture as F;
use Tests\TestCase;

class StudentElectionRoundWindowBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_late_nairobi_fixture_is_accepted_but_cross_midnight_window_still_fails(): void
    {
        $id = F::create(true);
        $late = CarbonImmutable::now('Africa/Nairobi')->addDay()->setTime(23, 50);
        [$opens, $closes] = F::window($late);
        $service = app(StudentElectionRoundService::class);

        try {
            $service->create(F::user($id), $id['election'], $id['ep'],
                $opens->toIso8601String(), $late->addMinutes(20)->toIso8601String());
            $this->fail('A cross-midnight round must remain prohibited.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('window', $error->errors(), json_encode($error->errors()));
        }

        $this->assertSame(0, DB::table('student_election_rounds')->where('election_id', $id['election'])->count());

        $round = $service->create(F::user($id), $id['election'], $id['ep'],
            $opens->toIso8601String(), $closes->toIso8601String());

        $actualOpens = CarbonImmutable::parse($round['opens_at'])->setTimezone('Africa/Nairobi');
        $actualCloses = CarbonImmutable::parse($round['closes_at'])->setTimezone('Africa/Nairobi');

        $this->assertSame($opens->toIso8601String(), $actualOpens->toIso8601String());
        $this->assertSame($late->startOfDay()->addDay()->toIso8601String(), $actualCloses->toIso8601String());
        $this->assertSame($actualOpens->toDateString(), $actualCloses->subSecond()->toDateString());
    }
}
