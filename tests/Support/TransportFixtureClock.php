<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Shared relative dates for Transport tests; never used by application code. */
final class TransportFixtureClock
{
    private static ?int $shiftDays = null;

    private static function assertIsolated(): void
    {
        $db = DB::connection();
        if (! app()->environment('testing') || app()->configurationIsCached()
            || $db->getDriverName() !== 'pgsql'
            || $db->getConfig('database') !== 'shuleos_assessment_fk_test'
            || ! empty($db->getConfig('url'))
            || $db->selectOne('SELECT current_database() AS name')->name !== 'shuleos_assessment_fk_test') {
            throw new RuntimeException('Relative Transport fixtures require the exact isolated test database.');
        }
    }

    public static function days(): int
    {
        if (self::$shiftDays === null) {
            self::assertIsolated();
            $real = new DateTimeImmutable(DB::selectOne('SELECT clock_timestamp() AS instant')->instant);
            $anchor = $real->setTimezone(new DateTimeZone('UTC'))
                ->modify('+2 days')->modify('next monday')->setTime(0, 0);
            $baseline = new DateTimeImmutable('2026-11-09T00:00:00Z');
            self::$shiftDays = (int) $baseline->diff($anchor)->format('%r%a');
        }

        return self::$shiftDays;
    }

    /** Child PostgreSQL sessions must use exactly the parent's date anchor. */
    public static function adopt(int $days): void
    {
        self::assertIsolated();
        if ($days % 7 !== 0 || (self::$shiftDays !== null && self::$shiftDays !== $days)) {
            throw new RuntimeException('Invalid or inconsistent Transport fixture week.');
        }
        self::$shiftDays = $days;
    }

    public static function shift(string $value): string
    {
        $days = self::days();
        $shifted = preg_replace_callback('/(?<!\d)202[67]-\d{2}-\d{2}(?!\d)/', static function (array $match) use ($days): string {
            $day = new DateTimeImmutable($match[0], new DateTimeZone('UTC'));

            return $day->modify(sprintf('%+d days', $days))->format('Y-m-d');
        }, $value);
        if ($shifted === null) {
            throw new RuntimeException('Could not shift a Transport fixture date.');
        }

        return $shifted;
    }
}
