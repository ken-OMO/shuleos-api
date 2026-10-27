<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssessmentReissuePublicationService
{
    private const TABLES = [
        'paper_result' => 'exam_results',
        'learning_area_result' => 'learning_area_results',
        'merit_list' => 'merit_lists',
        'report_card' => 'report_cards',
    ];

    private const FIELDS = [
        'paper_result' => ['marks', 'is_absent'],
        'learning_area_result' => [
            'marks_obtained', 'maximum_marks', 'percentage', 'is_absent',
            'grading_system_id', 'grading_scale_id', 'grading_snapshot',
            'processing_status', 'processed_by', 'processed_at',
        ],
        'merit_list' => [
            'total_score', 'maximum_marks', 'average_percentage', 'total_points',
            'overall_grading_system_id', 'overall_grading_scale_id',
            'grading_snapshot', 'ranking_method',
            'stream_position', 'grade_position', 'school_position',
        ],
        'report_card' => [
            'has_absence', 'overall_score', 'maximum_marks', 'average_percentage',
            'grading_snapshot', 'overall_grade', 'overall_grading_system_id',
            'overall_grading_scale_id', 'total_points',
            'stream_position', 'grade_position', 'school_position',
            'total_learners',
        ],
    ];

    public function publish(
        User $actor,
        string $requestId,
        string $expectedFingerprint,
        string $reason
    ): array {
        if (! $actor->school_id || ! app(AuthContextService::class)
            ->hasPermission($actor, 'publish_assessment_reissues')) {
            throw new AuthorizationException('You cannot publish assessment revisions.');
        }

        $reason = trim($reason);
        Validator::make([
            'reason' => $reason,
            'prepared_sha256' => $expectedFingerprint,
        ], [
            'reason' => 'required|string|max:5000',
            'prepared_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
        ])->validate();

        return DB::transaction(function () use ($actor, $requestId, $expectedFingerprint, $reason): array {
            $schoolId = (string) $actor->school_id;
            $candidate = DB::table('assessment_reissue_requests')
                ->where('school_id', $schoolId)->where('id', $requestId)->first();

            if (! $candidate) {
                $this->invalid('Correction request not found for this school.');
            }

            app(ExamResultLifecycleService::class)
                ->lockExamForWrite($schoolId, $candidate->exam_id);
            $request = DB::table('assessment_reissue_requests')
                ->where('school_id', $schoolId)->where('id', $requestId)
                ->lockForUpdate()->firstOrFail();

            if ($request->status !== 'ready'
                || ! $request->reviewed_by || ! $request->reviewed_at
                || ! $request->decided_by || ! $request->decided_at
                || $request->decided_by === $request->requested_by
                || $request->reviewed_by === $request->requested_by
                || blank($request->decision_reason)
                || blank($request->review_reason)
                || ! $request->prepared_sha256) {
                $this->invalid('A separately approved and reviewed revision is required.');
            }

            $archive = app(AssessmentPublicationArchiveService::class);
            $preparation = app(AssessmentReissuePreparationService::class);
            $actual = $preparation->draftFingerprint($schoolId, $requestId);
            $proposal = json_decode(
                $request->proposed_changes, true, 512, JSON_THROW_ON_ERROR
            );

            if (! hash_equals($request->prepared_sha256, $expectedFingerprint)
                || ! hash_equals($request->prepared_sha256, $actual)
                || ! hash_equals($request->proposal_sha256, $archive->fingerprint($proposal))
                || ! hash_equals(
                    $request->source_sha256,
                    app(AssessmentReissueRequestService::class)
                        ->sourceFingerprint($schoolId, $request->exam_id)
                )) {
                $this->invalid('The approved proposal, drafts or source data changed.');
            }

            $drafts = DB::table('assessment_reissue_drafts')
                ->where('school_id', $schoolId)->where('request_id', $requestId)
                ->orderBy('output_type')->orderBy('output_id')
                ->lockForUpdate()->get();

            if ($drafts->contains(fn ($draft) => $draft->prepared_by === $request->reviewed_by)) {
                $this->invalid('The reviewer cannot also be a draft preparer.');
            }

            $validated = [];

            // Validate every source and revision number before changing any row.
            foreach ($drafts as $draft) {
                if (! isset(self::TABLES[$draft->output_type])
                    || $draft->exam_id !== $request->exam_id) {
                    $this->invalid('A revision draft has an invalid output identity.');
                }

                $payload = json_decode($draft->payload, true, 512, JSON_THROW_ON_ERROR);
                $row = $payload['output'] ?? [];
                if (($row['id'] ?? null) !== $draft->output_id
                    || ($row['exam_id'] ?? null) !== $request->exam_id
                    || ($row['learner_id'] ?? null) !== $draft->learner_id
                    || ($draft->output_type !== 'paper_result'
                        && ($row['school_id'] ?? null) !== $schoolId)) {
                    $this->invalid('A revision payload has an invalid output identity.');
                }

                $original = $this->storedPayload($draft, $schoolId, $request->exam_id);
                if (! hash_equals(
                    $draft->source_sha256, $archive->fingerprint($original)
                )) {
                    $this->invalid('An output changed after revision preparation.');
                }

                $published = in_array($draft->output_type, ['merit_list', 'report_card'], true)
                    && $original['output']['status'] === 'published';

                if ($published) {
                    $latest = DB::table('assessment_publication_versions')
                        ->where('school_id', $schoolId)
                        ->where('output_type', $draft->output_type)
                        ->where('output_id', $draft->output_id)
                        ->orderByDesc('revision_number')->lockForUpdate()->first();

                    if (! $latest
                        || (int) $draft->revision_number !== (int) $latest->revision_number + 1
                        || ! hash_equals($latest->payload_sha256, $draft->source_sha256)
                        || ! hash_equals(
                            $latest->payload_sha256,
                            $archive->fingerprint(json_decode(
                                $latest->payload, true, 512, JSON_THROW_ON_ERROR
                            ))
                        )) {
                        $this->invalid('Publication history does not match the prepared revision.');
                    }
                }

                $validated[] = compact('draft', 'payload', 'original', 'published');
            }

            $publishedAt = now()->format('Y-m-d H:i:s');
            foreach ($validated as $item) {
                $this->apply($item, $schoolId, $request->exam_id, $actor, $publishedAt);
            }

            app(ExamCompletenessService::class)
                ->assertComplete($schoolId, $request->exam_id);

            $versionCount = 0;
            foreach ($validated as $item) {
                if (! $item['published']) {
                    continue;
                }

                $draft = $item['draft'];
                // Archive actual persisted values, including database numeric formatting.
                $payload = $this->storedPayload($draft, $schoolId, $request->exam_id);

                DB::table('assessment_publication_versions')->insert([
                    'id' => (string) Str::uuid(),
                    'school_id' => $schoolId,
                    'exam_id' => $request->exam_id,
                    'learner_id' => $draft->learner_id,
                    'output_type' => $draft->output_type,
                    'output_id' => $draft->output_id,
                    'revision_number' => $draft->revision_number,
                    'reissue_request_id' => $requestId,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'payload_sha256' => $archive->fingerprint($payload),
                    'published_at' => $publishedAt,
                    'published_by' => $actor->id,
                    'recorded_at' => now(),
                ]);
                $versionCount++;
            }

            DB::table('assessment_reissue_requests')->where('id', $requestId)->update([
                'status' => 'published',
                'published_by' => $actor->id,
                'published_at' => $publishedAt,
                'updated_at' => now(),
            ]);

            DB::table('assessment_reissue_events')->insert([
                'id' => (string) Str::uuid(),
                'request_id' => $requestId,
                'school_id' => $schoolId,
                'actor_id' => $actor->id,
                'action' => 'published',
                'reason' => $reason,
                'details' => json_encode([
                    'prepared_sha256' => $actual,
                    'applied_count' => count($validated),
                    'publication_version_count' => $versionCount,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return [
                'applied_count' => count($validated),
                'publication_version_count' => $versionCount,
            ];
        });
    }

    private function storedPayload(object $draft, string $schoolId, string $examId): array
    {
        $query = DB::table(self::TABLES[$draft->output_type])
            ->where('id', $draft->output_id)->where('exam_id', $examId);
        if ($draft->output_type !== 'paper_result') {
            $query->where('school_id', $schoolId);
        }

        $row = $query->lockForUpdate()->first();
        if (! $row || $row->learner_id !== $draft->learner_id) {
            $this->invalid('A revision source row is unavailable.');
        }

        $payload = ['schema_version' => 1, 'output' => (array) $row];
        if ($draft->output_type === 'report_card') {
            $payload['learning_areas'] = DB::table('report_card_learning_areas')
                ->where('report_card_id', $row->id)->orderBy('id')
                ->lockForUpdate()->get()
                ->map(fn ($detail): array => (array) $detail)->all();
        }

        return $payload;
    }

    private function apply(
        array $item,
        string $schoolId,
        string $examId,
        User $actor,
        string $publishedAt
    ): void {
        $draft = $item['draft'];
        $row = $item['payload']['output'];
        $stored = $item['original']['output'];
        $values = [];

        foreach (self::FIELDS[$draft->output_type] as $field) {
            if (! array_key_exists($field, $row)) {
                $this->invalid('A prepared output is missing a required field.');
            }
            $values[$field] = is_array($row[$field])
                ? json_encode($row[$field], JSON_THROW_ON_ERROR) : $row[$field];
        }

        if (array_key_exists('updated_at', $stored)) {
            $values['updated_at'] = $publishedAt;
        }

        if ($item['published']) {
            $values['published_at'] = $publishedAt;
            if (array_key_exists('published_by', $stored)) {
                $values['published_by'] = $actor->id;
            }
        }

        $query = DB::table(self::TABLES[$draft->output_type])
            ->where('id', $draft->output_id)->where('exam_id', $examId);
        if ($draft->output_type !== 'paper_result') {
            $query->where('school_id', $schoolId);
        }
        if ($query->update($values) !== 1) {
            $this->invalid('An output could not be updated exactly once.');
        }

        if ($draft->output_type !== 'report_card') {
            return;
        }

        $fields = [
            'learning_area_result_id', 'is_absent', 'score', 'marks_obtained',
            'maximum_marks', 'percentage', 'grading_system_id', 'grading_scale_id',
            'grading_snapshot', 'grade_code', 'points',
        ];

        foreach ($item['payload']['learning_areas'] as $detail) {
            if ($detail['is_deleted']) {
                continue;
            }

            $values = [];
            foreach ($fields as $field) {
                $values[$field] = is_array($detail[$field])
                    ? json_encode($detail[$field], JSON_THROW_ON_ERROR) : $detail[$field];
            }
            if (array_key_exists('updated_at', $detail)) {
                $values['updated_at'] = $publishedAt;
            }

            if (DB::table('report_card_learning_areas')
                ->where('id', $detail['id'])
                ->where('report_card_id', $draft->output_id)
                ->where('is_deleted', false)->update($values) !== 1) {
                $this->invalid('A report detail could not be updated exactly once.');
            }
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['reissue_request' => $message]);
    }
}
