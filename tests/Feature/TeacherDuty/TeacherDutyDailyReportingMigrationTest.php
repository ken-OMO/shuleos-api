<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\School;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherDutyDailyReportingMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_migration_backfills_missing_settings_and_preserves_existing_settings(): void
    {
        $missingSettingsSchool = $this->school('MISSING');

        $existingSettingsSchool = $this->school('EXISTING');

        DB::table('school_settings')->insert([
            'school_id' => $existingSettingsSchool->id,
            'school_motto' => 'Preserve This Motto',
        ]);

        $this->assertFalse(
            DB::table('school_settings')
                ->where('school_id', $missingSettingsSchool->id)
                ->exists()
        );

        $this->assertSame(
            1,
            DB::table('school_settings')
                ->where('school_id', $existingSettingsSchool->id)
                ->count()
        );

        $migration = $this->migration();

        $migration->down();

        $this->assertFalse(
            Schema::hasColumn(
                'school_settings',
                'teacher_duty_report_deadline_time'
            )
        );

        $this->assertFalse(
            Schema::hasColumn(
                'school_settings',
                'teacher_duty_report_grace_minutes'
            )
        );

        $this->assertDatabaseHas(
            'school_settings',
            [
                'school_id' => $existingSettingsSchool->id,
                'school_motto' => 'Preserve This Motto',
            ]
        );

        $migration->up();

        $this->assertTrue(
            Schema::hasColumn(
                'school_settings',
                'teacher_duty_report_deadline_time'
            )
        );

        $this->assertTrue(
            Schema::hasColumn(
                'school_settings',
                'teacher_duty_report_grace_minutes'
            )
        );

        $missingSettings = DB::table('school_settings')
            ->where('school_id', $missingSettingsSchool->id)
            ->get();

        $existingSettings = DB::table('school_settings')
            ->where('school_id', $existingSettingsSchool->id)
            ->get();

        $this->assertCount(
            1,
            $missingSettings
        );

        $this->assertCount(
            1,
            $existingSettings
        );

        $this->assertSame(
            '17:00:00',
            (string) $missingSettings
                ->first()
                ->teacher_duty_report_deadline_time
        );

        $this->assertSame(
            120,
            (int) $missingSettings
                ->first()
                ->teacher_duty_report_grace_minutes
        );

        $this->assertSame(
            'Preserve This Motto',
            $existingSettings
                ->first()
                ->school_motto
        );

        $this->assertSame(
            '17:00:00',
            (string) $existingSettings
                ->first()
                ->teacher_duty_report_deadline_time
        );

        $this->assertSame(
            120,
            (int) $existingSettings
                ->first()
                ->teacher_duty_report_grace_minutes
        );
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_09_11_183618_add_teacher_duty_daily_reporting_domain.php'
        );
    }

    private function school(string $suffix): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Reporting Migration '.$suffix.' '.Str::upper(
                Str::random(6)
            ),
            'school_code' => 'TDR-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'TDR',
            'registration_number' => 'REG-'.Str::upper(
                Str::random(10)
            ),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(
                10000000,
                99999999
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }
}
