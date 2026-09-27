<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Core\Security\File\SecureFileStorage;
use App\Models\TeacherDutyWeeklyReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class TeacherDutyWeeklyReportPdfService
{
    public function __construct(
        private readonly SecureFileStorage $storage
    ) {}

    public function make(
        string $schoolId,
        string $reportId
    ): array {
        $report = TeacherDutyWeeklyReport::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($reportId)
            ->first();

        if (! $report) {
            throw ValidationException::withMessages([
                'report' => 'Teacher duty weekly report was not found.',
            ]);
        }

        $logo = $this->schoolLogo($report);
        $printData = $this->printData($report);

        try {
            $pdf = Pdf::loadView(
                'pdf.teacher-duty-weekly-report',
                [
                    'report' => $report,
                    'logo' => $logo,
                    'printData' => $printData,
                ]
            )->setPaper('a4', 'portrait');

            $content = $pdf->output();
        } finally {
            if (is_string($logo) && is_file($logo)) {
                @unlink($logo);
            }
        }

        return [
            'content' => $content,
            'filename' => 'teacher-duty-weekly-report-'.$report->id.'.pdf',
        ];
    }

    private function printData(TeacherDutyWeeklyReport $report): array
    {
        $schoolName = DB::table('schools')
            ->where('id', $report->school_id)
            ->value('school_name');

        $teachers = DB::table('teacher_duty_assignments as assignments')
            ->join('teachers', function ($join) {
                $join->on('teachers.id', '=', 'assignments.teacher_id')
                    ->on('teachers.school_id', '=', 'assignments.school_id');
            })
            ->join('users', function ($join) {
                $join->on('users.id', '=', 'teachers.user_id')
                    ->on('users.school_id', '=', 'teachers.school_id');
            })
            ->where('assignments.school_id', $report->school_id)
            ->where(
                'assignments.duty_period_id',
                $report->duty_period_id
            )
            ->orderBy('assignments.created_at')
            ->orderBy('assignments.id')
            ->get([
                'users.first_name',
                'users.middle_name',
                'users.last_name',
                'teachers.tsc_no',
            ])
            ->map(function (object $teacher): array {
                $name = collect([
                    $teacher->first_name,
                    $teacher->middle_name,
                    $teacher->last_name,
                ])
                    ->filter(
                        static fn ($part): bool => is_string($part)
                            && trim($part) !== ''
                    )
                    ->map(
                        static fn (string $part): string => trim($part)
                    )
                    ->implode(' ');

                return [
                    'name' => $name,
                    'tsc_no' => is_string($teacher->tsc_no)
                        && trim($teacher->tsc_no) !== ''
                            ? trim($teacher->tsc_no)
                            : "\u{2014}",
                ];
            })
            ->values()
            ->all();

        $period = DB::table('teacher_duty_periods')
            ->where('school_id', $report->school_id)
            ->where('id', $report->duty_period_id)
            ->first([
                'academic_week_id',
                'start_date',
                'end_date',
            ]);

        $academicWeek = null;

        if (
            $period
            && is_string($period->academic_week_id)
            && $period->academic_week_id !== ''
        ) {
            $academicWeek = DB::table('academic_weeks as weeks')
                ->join('terms', function ($join) {
                    $join->on('terms.id', '=', 'weeks.term_id')
                        ->on('terms.school_id', '=', 'weeks.school_id');
                })
                ->join('academic_years as years', function ($join) {
                    $join->on(
                        'years.id',
                        '=',
                        'weeks.academic_year_id'
                    )->on(
                        'years.school_id',
                        '=',
                        'weeks.school_id'
                    );
                })
                ->where('weeks.school_id', $report->school_id)
                ->where('weeks.id', $period->academic_week_id)
                ->first([
                    'weeks.week_number',
                    'terms.term_name',
                    'years.year_name',
                ]);
        }

        $userName = static function (
            ?string $userId
        ) use ($report): ?string {
            if (! is_string($userId) || $userId === '') {
                return null;
            }

            $user = DB::table('users')
                ->where('school_id', $report->school_id)
                ->where('id', $userId)
                ->first([
                    'first_name',
                    'middle_name',
                    'last_name',
                ]);

            if (! $user) {
                return null;
            }

            $name = collect([
                $user->first_name,
                $user->middle_name,
                $user->last_name,
            ])
                ->filter(
                    static fn ($part): bool => is_string($part)
                        && trim($part) !== ''
                )
                ->map(
                    static fn (string $part): string => trim($part)
                )
                ->implode(' ');

            return $name !== '' ? $name : null;
        };

        return [
            'school_name' => $schoolName,
            'title' => 'TEACHER DUTY WEEKLY REPORT',
            'teachers' => $teachers,
            'report' => [
                'summary' => $report->summary,
                'highlights' => $report->highlights,
                'challenges' => $report->challenges,
                'recommendations' => $report->recommendations,
            ],
            'evidence' => $report->evidence_snapshot,
            'workflow' => [
                'status' => $report->status,
                'submitted_by_name' => $userName(
                    $report->submitted_by
                ),
                'submitted_at' => $report->submitted_at?->toISOString(),
                'reviewed_by_name' => $userName(
                    $report->reviewed_by
                ),
                'reviewed_at' => $report->reviewed_at?->toISOString(),
                'review_comment' => $report->review_comment,
            ],
            'week' => [
                'number' => $academicWeek?->week_number,
                'start_date' => $period?->start_date,
                'end_date' => $period?->end_date,
                'term' => $academicWeek?->term_name,
                'academic_year' => $academicWeek?->year_name,
            ],
        ];
    }

    private function schoolLogo(
        TeacherDutyWeeklyReport $report
    ): ?string {
        $asset = DB::table('administrator_branding_assets')
            ->where('school_id', $report->school_id)
            ->where('asset_type', 'logo')
            ->where('status', 'approved')
            ->whereNull('archived_at')
            ->orderByDesc('approved_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $asset || ! is_string($asset->storage_id) || $asset->storage_id === '') {
            return null;
        }

        $extension = match ($asset->mime_type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        if ($extension === null) {
            return null;
        }

        $directory = storage_path('app/private/pdf-temp');

        $path = $directory
            .DIRECTORY_SEPARATOR
            .'teacher-duty-logo-'
            .Str::uuid()
            .'.'
            .$extension;

        try {
            $this->storage->decryptToPath(
                $asset->storage_id,
                $path
            );
        } catch (Throwable) {
            return null;
        }

        return is_file($path) ? $path : null;
    }
}
