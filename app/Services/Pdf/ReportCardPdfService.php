<?php

namespace App\Services\Pdf;

use App\Models\ReportCard;
use App\Models\User;
use App\Services\Assessment\AssessmentPublicationHistoryService;
use App\Services\Assessment\ExamResultLifecycleService;
use App\Services\Assessment\ReportCardPresentationSnapshotService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportCardPdfService
{
    private const RELATIONS = ['school.settings', 'learner.grade', 'learner.stream', 'exam', 'academicYear', 'term', 'grade', 'stream', 'overallGradingScale', 'pathwayRecommendation', 'learningAreas.learningArea', 'learningAreas.gradingScale'];

    public function make(string $schoolId, string $id): array
    {
        return DB::transaction(function () use ($schoolId, $id): array {
            $candidate = ReportCard::current()->where('school_id', $schoolId)
                ->findOrFail($id);

            app(ExamResultLifecycleService::class)
                ->lockExam($schoolId, $candidate->exam_id);

            $card = ReportCard::current()->where('school_id', $schoolId)
                ->with(self::RELATIONS)->findOrFail($id);
            if (! in_array($card->status, ['generated', 'published'], true)) {
                throw ValidationException::withMessages([
                    'report_card' => 'Only generated or published report cards can be rendered.',
                ]);
            }

            $revision = $card->status === 'published'
                ? (int) (DB::table('assessment_publication_versions')
                    ->where('school_id', $schoolId)
                    ->where('exam_id', $card->exam_id)
                    ->where('output_type', 'report_card')
                    ->where('output_id', $card->id)
                    ->max('revision_number') ?? 1)
                : null;

            return $this->render($card, $revision);
        });
    }

    public function makeVersion(User $actor, string $versionId): array
    {
        $version = app(
            AssessmentPublicationHistoryService::class
        )->reportCard($actor, $versionId);

        return $this->render($version['card'], $version['revision_number']);
    }

    private function render(ReportCard $card, ?int $revisionNumber = null): array
    {
        $presentations = app(ReportCardPresentationSnapshotService::class);
        $card = $presentations->forDisplay($card);
        $settings = $card->school->settings;
        $logo = $card->status === 'published'
            ? $presentations->asset($card, 'logo')
            : $this->safeImage($settings?->school_logo_url ?: $card->school->logo_url);
        $signature = $card->status === 'published'
            ? $presentations->asset($card, 'principal_signature')
            : $this->safeImage($settings?->principal_signature_url);
        $pdf = Pdf::loadView('pdf.report-card', compact('card', 'settings', 'logo', 'signature', 'revisionNumber'))->setPaper('a4', 'portrait');

        return [
            'pdf' => $pdf,
            'filename' => $revisionNumber === null
                ? $this->filename($card)
                : 'report-card-'.$card->id.'-revision-'.$revisionNumber.'.pdf',
        ];
    }

    private function filename(ReportCard $card): string
    {
        $parts = [$card->learner->admission_no ?: 'learner', $card->exam->exam_name ?: 'exam', $card->term->term_name ?? 'term'];

        return Str::slug(implode('-', $parts)).'-report-card.pdf';
    }

    private function safeImage(?string $value): ?string
    {
        if (! $value || str_contains($value, "\0") || preg_match('#^[a-z]+://#i', $value) || str_contains(str_replace('\\', '/', $value), '../')) {
            return null;
        }
        $relative = ltrim(str_replace('\\', '/', $value), '/');
        $candidates = [public_path($relative), storage_path('app/public/'.$relative)];
        $roots = array_filter([realpath(public_path()), realpath(storage_path('app/public'))]);
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if (! $real || ! is_file($real) || ! collect($roots)->contains(fn ($root) => str_starts_with(strtolower($real), strtolower($root.DIRECTORY_SEPARATOR)))) {
                continue;
            }
            $mime = @mime_content_type($real);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                return $real;
            }
        }

        return null;
    }
}
