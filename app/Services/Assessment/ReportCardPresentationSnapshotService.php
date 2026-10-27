<?php

namespace App\Services\Assessment;

use App\Models\ReportCard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ReportCardPresentationSnapshotService
{
    public function capture(ReportCard $card): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Publication capture requires a transaction.');
        }

        $card->loadMissing([
            'school.settings', 'learner', 'exam', 'academicYear',
            'term', 'grade', 'stream', 'pathwayRecommendation',
            'learningAreas.learningArea',
        ]);

        $settings = $card->school?->settings;
        $logo = $settings?->school_logo_url ?: $card->school?->logo_url;

        return [
            'version' => 1,
            'captured_at' => now()->toIso8601String(),
            'school' => $this->fields($card->school, [
                'id', 'school_name', 'postal_address', 'physical_address',
                'phone', 'email', 'website',
            ]),
            'settings' => $this->fields($settings, [
                'school_motto', 'report_header', 'report_footer', 'principal_name',
            ]),
            'learner' => $this->fields($card->learner, [
                'id', 'first_name', 'middle_name', 'last_name',
                'admission_no', 'assessment_no', 'upi', 'kemis_no', 'gender',
            ]),
            'exam' => $this->fields($card->exam, ['id', 'exam_name']),
            'academic_year' => $this->fields($card->academicYear, [
                'id', 'year_name', 'academic_year',
            ]),
            'term' => $this->fields($card->term, ['id', 'term_name']),
            'grade' => $this->fields($card->grade, ['id', 'grade_name']),
            'stream' => $this->fields($card->stream, ['id', 'stream_name']),
            'pathway' => $this->fields($card->pathwayRecommendation, [
                'id', 'recommended_pathway', 'confidence_score',
                'strengths', 'improvement_areas',
            ]),
            'learning_areas' => $card->learningAreas->map(fn ($detail) => [
                'detail_id' => $detail->id,
                'learning_area_id' => $detail->learning_area_id,
                'name' => $detail->learningArea?->learning_area_name
                    ?? $detail->learningArea?->name,
            ])->values()->all(),
            'assets' => [
                'logo' => app(ReportBrandingAssetService::class)
                    ->read((string) $card->school_id, 'logo') ?? $this->image($logo),
                'principal_signature' => app(ReportBrandingAssetService::class)
                    ->read((string) $card->school_id, 'principal_signature')
                    ?? $this->image($settings?->principal_signature_url),
            ],
        ];
    }

    public function forDisplay(ReportCard $card): ReportCard
    {
        if ($card->status !== 'published') {
            $card->loadMissing('learningAreas.learningArea');

            return $this->alphabetical($card);
        }

        // Replace relations on a display copy; never persist this projection.
        $display = clone $card;
        $snapshot = $card->presentation_snapshot;
        if ($snapshot !== null && ($snapshot['version'] ?? null) !== 1) {
            throw new LogicException('Unsupported report presentation snapshot version.');
        }

        foreach ([
            'school' => 'school',
            'learner' => 'learner',
            'exam' => 'exam',
            'academicYear' => 'academic_year',
            'term' => 'term',
            'grade' => 'grade',
            'stream' => 'stream',
            'pathwayRecommendation' => 'pathway',
        ] as $relation => $key) {
            $attributes = $snapshot[$key] ?? null;

            if ($relation === 'pathwayRecommendation' && $attributes === null) {
                // This scalar was already captured on the report card.
                $attributes = $card->pathway_recommendation === null ? null : [
                    'recommended_pathway' => $card->pathway_recommendation,
                ];
            }

            $model = $attributes === null && $relation === 'pathwayRecommendation'
                ? null : $card->$relation()->getRelated()->newInstance();
            $model?->forceFill($attributes ?? []);
            $display->setRelation($relation, $model);
        }

        $settings = $display->school->settings()->getRelated()->newInstance();
        $settings->forceFill($snapshot['settings'] ?? []);
        $display->school->setRelation('settings', $settings);

        $labels = collect($snapshot['learning_areas'] ?? [])->keyBy('detail_id');
        $display->setRelation('learningAreas', $card->learningAreas->map(
            function ($detail) use ($labels) {
                $copy = clone $detail;
                $label = $labels->get($detail->id);
                $area = $detail->learningArea()->getRelated()->newInstance();
                $area->forceFill([
                    'id' => $detail->learning_area_id,
                    'learning_area_name' => $label['name'] ?? 'Historical label unavailable',
                ]);
                $copy->setRelation('learningArea', $area);

                return $copy;
            }
        ));

        return $this->alphabetical($display);
    }

    private function alphabetical(ReportCard $card): ReportCard
    {
        $display = clone $card;
        $display->setRelation('learningAreas', $card->learningAreas->sort(
            function ($left, $right): int {
                $leftName = trim((string) (
                    $left->learningArea?->learning_area_name
                    ?? $left->learningArea?->name ?? ''
                ));
                $rightName = trim((string) (
                    $right->learningArea?->learning_area_name
                    ?? $right->learningArea?->name ?? ''
                ));

                return strnatcasecmp($leftName, $rightName)
                    ?: strcmp((string) $left->id, (string) $right->id);
            }
        )->values());

        return $display;
    }

    public function asset(ReportCard $card, string $name): ?string
    {
        $asset = $card->presentation_snapshot['assets'][$name] ?? null;
        if (($asset['status'] ?? null) !== 'captured') {
            return null;
        }

        $mime = $asset['mime'] ?? null;
        $encoded = $asset['base64'] ?? '';
        $bytes = base64_decode($encoded, true);

        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)
            || $bytes === false || strlen($bytes) > 2097152
            || ! hash_equals($asset['sha256'] ?? '', hash('sha256', $bytes))) {
            throw new LogicException('Invalid captured report branding image.');
        }

        return 'data:'.$mime.';base64,'.$encoded;
    }

    private function fields(?Model $model, array $names): ?array
    {
        if ($model === null) {
            return null;
        }

        $values = [];
        foreach ($names as $name) {
            $values[$name] = $model->getAttribute($name);
        }

        return $values;
    }

    private function image(?string $value): ?array
    {
        if (! $value) {
            return null;
        }

        if (str_contains($value, "\0") || preg_match('#^[a-z]+://#i', $value)
            || str_contains(str_replace('\\', '/', $value), '../')) {
            return ['status' => 'unavailable'];
        }

        $relative = ltrim(str_replace('\\', '/', $value), '/');
        $roots = array_filter([
            realpath(public_path()), realpath(storage_path('app/public')),
        ]);

        foreach ([public_path($relative), storage_path('app/public/'.$relative)] as $candidate) {
            $real = realpath($candidate);
            if (! $real || ! is_file($real)
                || ! collect($roots)->contains(fn ($root) => str_starts_with(
                    strtolower($real), strtolower($root.DIRECTORY_SEPARATOR)
                ))) {
                continue;
            }

            $mime = @mime_content_type($real);
            if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                continue;
            }

            // Read a bounded copy so later replacement of the source cannot alter history.
            $bytes = @file_get_contents($real, false, null, 0, 2097153);
            if ($bytes === false) {
                return ['status' => 'unavailable'];
            }
            if (strlen($bytes) > 2097152) {
                throw ValidationException::withMessages([
                    'presentation_snapshot' => 'Report branding images must not exceed 2 MB each.',
                ]);
            }

            return [
                'status' => 'captured',
                'mime' => $mime,
                'sha256' => hash('sha256', $bytes),
                'base64' => base64_encode($bytes),
            ];
        }

        return ['status' => 'unavailable'];
    }
}
