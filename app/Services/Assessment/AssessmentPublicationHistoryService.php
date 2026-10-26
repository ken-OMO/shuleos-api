<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Models\ReportCard;
use App\Models\ReportCardLearningArea;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentPublicationHistoryService
{
    public function reportCard(User $actor, string $versionId): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $versionId): array {
            $version = DB::table('assessment_publication_versions')
                ->where('school_id', $actor->school_id)
                ->where('output_type', 'report_card')
                ->where('id', $versionId)->first();

            if (! $version) {
                throw ValidationException::withMessages([
                    'publication_version' => 'Report publication version not found for this school.',
                ]);
            }

            // Read-only schools retain authorized historical access.
            app(ExamResultLifecycleService::class)
                ->lockExam($actor->school_id, $version->exam_id);

            $payload = json_decode($version->payload, true, 512, JSON_THROW_ON_ERROR);
            if (! hash_equals(
                $version->payload_sha256,
                app(AssessmentPublicationArchiveService::class)->fingerprint($payload)
            )) {
                throw ValidationException::withMessages([
                    'publication_version' => 'The archived publication payload failed verification.',
                ]);
            }

            $attributes = $payload['output'] ?? [];
            if (($payload['schema_version'] ?? null) !== 1
                || ($attributes['id'] ?? null) !== $version->output_id
                || ($attributes['school_id'] ?? null) !== $actor->school_id
                || ($attributes['exam_id'] ?? null) !== $version->exam_id
                || ($attributes['learner_id'] ?? null) !== $version->learner_id
                || ($attributes['status'] ?? null) !== 'published'
                || ! is_array($payload['learning_areas'] ?? null)) {
                throw ValidationException::withMessages([
                    'publication_version' => 'The archived report identity is invalid.',
                ]);
            }

            $card = new ReportCard;
            $card->setRawAttributes($attributes, true);
            $card->useArchivedPublicationRevision((int) $version->revision_number);

            foreach ([
                'meritList', 'overallGradingSystem', 'overallGradingScale',
                'generatedBy', 'publishedBy', 'deletedBy',
            ] as $relation) {
                $card->setRelation($relation, null);
            }

            $details = [];
            foreach ($payload['learning_areas'] as $attributes) {
                if (($attributes['report_card_id'] ?? null) !== $card->id) {
                    throw ValidationException::withMessages([
                        'publication_version' => 'An archived detail belongs to another report.',
                    ]);
                }

                if ($attributes['is_deleted']) {
                    continue;
                }

                $detail = new ReportCardLearningArea;
                $detail->setRawAttributes($attributes, true);
                $detail->setRelation('gradingScale', null);
                $details[] = $detail;
            }

            $card->setRelation('learningAreas', new Collection($details));
            $card = app(ReportCardPresentationSnapshotService::class)->forDisplay($card);

            return [
                'card' => $card,
                'revision_number' => (int) $version->revision_number,
                'version_id' => $version->id,
            ];
        });
    }

    public function meritList(User $actor, string $versionId): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $versionId): array {
            $version = DB::table('assessment_publication_versions')
                ->where('school_id', $actor->school_id)
                ->where('output_type', 'merit_list')
                ->where('id', $versionId)->first();

            if (! $version) {
                throw ValidationException::withMessages([
                    'publication_version' => 'Merit publication version not found for this school.',
                ]);
            }

            app(ExamResultLifecycleService::class)
                ->lockExam($actor->school_id, $version->exam_id);

            $payload = json_decode($version->payload, true, 512, JSON_THROW_ON_ERROR);
            $row = $payload['output'] ?? [];

            if (! hash_equals(
                $version->payload_sha256,
                app(AssessmentPublicationArchiveService::class)->fingerprint($payload)
            ) || ($payload['schema_version'] ?? null) !== 1
                || ($row['id'] ?? null) !== $version->output_id
                || ($row['school_id'] ?? null) !== $actor->school_id
                || ($row['exam_id'] ?? null) !== $version->exam_id
                || ($row['learner_id'] ?? null) !== $version->learner_id
                || ($row['status'] ?? null) !== 'published') {
                throw ValidationException::withMessages([
                    'publication_version' => 'The archived merit payload failed verification.',
                ]);
            }

            // Project recorded fields only. Never load current identity labels.
            $fields = [
                'id', 'exam_id', 'learner_id', 'grade_id', 'stream_id',
                'total_score', 'maximum_marks', 'average_percentage',
                'total_points', 'grading_snapshot', 'ranking_method',
                'stream_position', 'grade_position', 'school_position',
                'status', 'generated_at', 'published_at',
            ];
            $output = array_intersect_key($row, array_flip($fields));

            if (is_string($output['grading_snapshot'] ?? null)) {
                $output['grading_snapshot'] = json_decode(
                    $output['grading_snapshot'], true, 512, JSON_THROW_ON_ERROR
                );
            }

            return [
                'version_id' => $version->id,
                'revision_number' => (int) $version->revision_number,
                'merit_list' => $output,
            ];
        });
    }

    private function authorize(User $actor): void
    {
        if (! $actor->school_id || ! app(AuthContextService::class)
            ->hasPermission($actor, 'view_assessment_publication_history')) {
            throw new AuthorizationException('You cannot view assessment publication history.');
        }
    }
}
