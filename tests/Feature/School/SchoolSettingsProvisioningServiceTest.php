<?php

declare(strict_types=1);

namespace Tests\Feature\School;

use App\Models\School;
use App\Services\School\SchoolSettingsProvisioningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchoolSettingsProvisioningServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_provisions_missing_school_settings_with_database_defaults(): void
    {
        $school = $this->school();

        $this->assertFalse(
            DB::table('school_settings')
                ->where('school_id', $school->id)
                ->exists()
        );

        $this->service()->provision($school);

        $settings = DB::table('school_settings')
            ->where('school_id', $school->id)
            ->first();

        $this->assertNotNull($settings);
        $this->assertSame(
            (string) $school->id,
            (string) $settings->school_id
        );
        $this->assertSame(
            '17:00:00',
            (string) $settings->teacher_duty_report_deadline_time
        );
        $this->assertSame(
            120,
            (int) $settings->teacher_duty_report_grace_minutes
        );
    }

    public function test_repeated_provisioning_keeps_exactly_one_settings_row(): void
    {
        $school = $this->school();
        $service = $this->service();

        $service->provision($school);

        $idBefore = DB::table('school_settings')
            ->where('school_id', $school->id)
            ->value('id');

        $service->provision($school);

        $rows = DB::table('school_settings')
            ->where('school_id', $school->id)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(
            (string) $idBefore,
            (string) $rows->first()->id
        );
    }

    public function test_repeated_provisioning_preserves_existing_customized_settings(): void
    {
        $school = $this->school();
        $service = $this->service();

        $service->provision($school);

        DB::table('school_settings')
            ->where('school_id', $school->id)
            ->update([
                'school_motto' => 'Knowledge and Service',
                'teacher_duty_report_deadline_time' => '18:30:00',
                'teacher_duty_report_grace_minutes' => 45,
                'updated_at' => now(),
            ]);

        $before = DB::table('school_settings')
            ->where('school_id', $school->id)
            ->first();

        $service->provision($school);

        $after = DB::table('school_settings')
            ->where('school_id', $school->id)
            ->first();

        $this->assertSame($before->id, $after->id);
        $this->assertSame(
            'Knowledge and Service',
            $after->school_motto
        );
        $this->assertSame(
            '18:30:00',
            (string) $after->teacher_duty_report_deadline_time
        );
        $this->assertSame(
            45,
            (int) $after->teacher_duty_report_grace_minutes
        );
    }

    private function service(): SchoolSettingsProvisioningService
    {
        return app(
            SchoolSettingsProvisioningService::class
        );
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Settings Provisioning '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'SSP-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'SSP',
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
