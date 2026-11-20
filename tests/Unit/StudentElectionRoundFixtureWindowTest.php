<?php

declare(strict_types=1);

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\StudentElectionRoundFixture;

class StudentElectionRoundFixtureWindowTest extends TestCase
{
    public function test_daytime_window_preserves_the_short_wait_and_twenty_minute_duration(): void
    {
        $now = CarbonImmutable::parse('2026-11-20T12:00:00+03:00');
        [$opens, $closes] = StudentElectionRoundFixture::window($now);

        $this->assertSame('2026-11-20T12:00:02+03:00', $opens->toIso8601String());
        $this->assertSame('2026-11-20T12:20:02+03:00', $closes->toIso8601String());
    }

    public function test_late_nairobi_windows_never_extend_into_a_second_voting_day(): void
    {
        foreach (['2026-11-20T23:45:00+03:00', '2026-11-20T23:59:57+03:00', '2026-11-20T23:59:59+03:00'] as $instant) {
            $now = CarbonImmutable::parse($instant);
            [$opens, $closes] = StudentElectionRoundFixture::window($now);

            $this->assertTrue($opens->gt($now));
            $this->assertTrue($closes->gt($opens));
            $this->assertSame($opens->toDateString(), $closes->subSecond()->toDateString());
            $this->assertTrue($closes->lte($opens->startOfDay()->addDay()));
        }
    }

    public function test_window_uses_nairobi_even_when_the_input_clock_is_utc(): void
    {
        [$opens, $closes] = StudentElectionRoundFixture::window(CarbonImmutable::parse('2026-11-20T20:45:00Z'));

        $this->assertSame('2026-11-20T23:45:02+03:00', $opens->toIso8601String());
        $this->assertSame('2026-11-21T00:00:00+03:00', $closes->toIso8601String());
    }
}
