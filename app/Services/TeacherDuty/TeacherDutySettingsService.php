<?php

declare(strict_types=1);

namespace App\Services\TeacherDuty;

use App\Models\School;
use App\Models\SchoolSettings;
use App\Services\School\SchoolSettingsProvisioningService;
use Illuminate\Support\Carbon;

final class TeacherDutySettingsService
{
    public function __construct(
        private SchoolSettingsProvisioningService $provisioning
    ) {}

    /**
     * @return array{
     *     teacher_duty_report_deadline_time: string,
     *     teacher_duty_report_grace_minutes: int
     * }
     */
    public function get(string $schoolId): array
    {
        $settings = $this->settings($schoolId);

        return $this->resource($settings);
    }

    /**
     * @param array{
     *     teacher_duty_report_deadline_time: string,
     *     teacher_duty_report_grace_minutes: int
     * } $data
     * @return array{
     *     settings: SchoolSettings,
     *     old_values: array{
     *         teacher_duty_report_deadline_time: string,
     *         teacher_duty_report_grace_minutes: int
     *     },
     *     new_values: array{
     *         teacher_duty_report_deadline_time: string,
     *         teacher_duty_report_grace_minutes: int
     *     }
     * }
     */
    public function update(string $schoolId, array $data): array
    {
        $settings = $this->settings($schoolId);
        $oldValues = $this->resource($settings);

        $settings->update([
            'teacher_duty_report_deadline_time' => Carbon::createFromFormat(
                'H:i',
                $data['teacher_duty_report_deadline_time']
            )->format('H:i:s'),
            'teacher_duty_report_grace_minutes' => $data['teacher_duty_report_grace_minutes'],
        ]);

        $settings->refresh();

        return [
            'settings' => $settings,
            'old_values' => $oldValues,
            'new_values' => $this->resource($settings),
        ];
    }

    private function settings(string $schoolId): SchoolSettings
    {
        $school = School::query()
            ->withoutGlobalScopes()
            ->whereKey($schoolId)
            ->firstOrFail();

        $this->provisioning->provision($school);

        return SchoolSettings::query()
            ->where('school_id', $schoolId)
            ->firstOrFail();
    }

    /**
     * @return array{
     *     teacher_duty_report_deadline_time: string,
     *     teacher_duty_report_grace_minutes: int
     * }
     */
    private function resource(SchoolSettings $settings): array
    {
        return [
            'teacher_duty_report_deadline_time' => (string) $settings->teacher_duty_report_deadline_time,
            'teacher_duty_report_grace_minutes' => (int) $settings->teacher_duty_report_grace_minutes,
        ];
    }
}
