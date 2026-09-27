<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherDutyWeeklyReport;
use App\Services\Pdf\TeacherDutyWeeklyReportPdfService;
use App\Services\TeacherDuty\TeacherDutyAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class TeacherDutyWeeklyReportPdfController extends Controller
{
    public function __construct(
        private readonly TeacherDutyWeeklyReportPdfService $pdfService,
        private readonly TeacherDutyAuthorizationService $authorization
    ) {}

    public function stream(Request $request, string $report)
    {
        $schoolId = $this->schoolId($request);
        $weeklyReport = $this->report($schoolId, $report);

        $this->authorizeRead(
            $schoolId,
            (string) $weeklyReport->duty_period_id,
            (string) $request->user()->id
        );

        $document = $this->pdfService->make(
            $schoolId,
            (string) $weeklyReport->id
        );

        return response($document['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document['filename'].'"',
        ]);
    }

    public function download(Request $request, string $report)
    {
        $schoolId = $this->schoolId($request);
        $weeklyReport = $this->report($schoolId, $report);

        $this->authorizeRead(
            $schoolId,
            (string) $weeklyReport->duty_period_id,
            (string) $request->user()->id
        );

        $document = $this->pdfService->make(
            $schoolId,
            (string) $weeklyReport->id
        );

        return response($document['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document['filename'].'"',
        ]);
    }

    private function authorizeRead(
        string $schoolId,
        string $periodId,
        string $actorId
    ): void {
        try {
            $this->authorization->reporter(
                $schoolId,
                $periodId,
                $actorId
            );

            return;
        } catch (ValidationException) {
            // Weekly PDF follows the same dual-authority read model
            // as weekly report state: responsible reporter OR reviewer.
        }

        $this->authorization->reviewer(
            $schoolId,
            $actorId
        );
    }

    private function report(
        string $schoolId,
        string $reportId
    ): TeacherDutyWeeklyReport {
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

        return $report;
    }

    private function schoolId(Request $request): string
    {
        $user = $request->user();

        if (! $user || ! $user->school_id) {
            throw ValidationException::withMessages([
                'school' => 'School context is required.',
            ]);
        }

        $tenantSchoolId = $request->attributes->get(
            'tenant_school_id'
        );

        if (
            ! $tenantSchoolId ||
            (string) $tenantSchoolId !== (string) $user->school_id
        ) {
            throw ValidationException::withMessages([
                'school' => 'Invalid school context.',
            ]);
        }

        return (string) $user->school_id;
    }
}
