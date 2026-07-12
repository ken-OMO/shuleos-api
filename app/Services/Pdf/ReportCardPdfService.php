<?php

namespace App\Services\Pdf;

use App\Models\ReportCard;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportCardPdfService
{
    private const RELATIONS = ['school.settings', 'learner.grade', 'learner.stream', 'exam', 'academicYear', 'term', 'grade', 'stream', 'overallGradingScale', 'pathwayRecommendation', 'learningAreas.learningArea', 'learningAreas.gradingScale'];

    public function make(string $schoolId, string $id): array
    {
        $card = ReportCard::current()->where('school_id', $schoolId)->with(self::RELATIONS)->findOrFail($id);
        if (! in_array($card->status, ['generated', 'published'], true)) {
            throw ValidationException::withMessages(['report_card' => 'Only generated or published report cards can be rendered.']);
        }
        $settings = $card->school->settings;
        $logo = $this->safeImage($settings?->school_logo_url ?: $card->school->logo_url);
        $signature = $this->safeImage($settings?->principal_signature_url);
        $pdf = Pdf::loadView('pdf.report-card', compact('card', 'settings', 'logo', 'signature'))->setPaper('a4', 'portrait');

        return ['pdf' => $pdf, 'filename' => $this->filename($card)];
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
