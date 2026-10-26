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

class AssessmentReissuePreparationService
{
    public function prepare(User $actor, string $requestId, string $reason): array
    {
        if (! $actor->school_id || ! app(AuthContextService::class)
            ->hasPermission($actor, 'prepare_assessment_reissues')) {
            throw new AuthorizationException('You cannot prepare assessment revisions.');
        }

        $reason = trim($reason);
        Validator::make(['reason' => $reason], [
            'reason' => 'required|string|max:5000',
        ])->validate();

        return DB::transaction(function () use ($actor, $requestId, $reason): array {
            $schoolId = (string) $actor->school_id;
            $candidate = DB::table('assessment_reissue_requests')
                ->where('school_id', $schoolId)->where('id', $requestId)->first();

            if (! $candidate) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'Correction request not found for this school.',
                ]);
            }

            app(ExamResultLifecycleService::class)
                ->lockExamForWrite($schoolId, $candidate->exam_id);
            $request = DB::table('assessment_reissue_requests')
                ->where('school_id', $schoolId)->where('id', $requestId)
                ->lockForUpdate()->firstOrFail();

            if ($request->status !== 'approved'
                || ! $request->decided_by
                || ! $request->decided_at
                || $request->decided_by === $request->requested_by) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'A separately approved correction request is required.',
                ]);
            }

            if (DB::table('assessment_reissue_drafts')
                ->where('request_id', $requestId)->exists()) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'Drafts already exist for this request.',
                ]);
            }

            $archive = app(AssessmentPublicationArchiveService::class);
            $requests = app(AssessmentReissueRequestService::class);
            $proposal = json_decode(
                $request->proposed_changes, true, 512, JSON_THROW_ON_ERROR
            );

            if (! hash_equals($request->proposal_sha256, $archive->fingerprint($proposal))
                || ! hash_equals(
                    $request->source_sha256,
                    $requests->sourceFingerprint($schoolId, $request->exam_id)
                )) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'The approved proposal or exam source changed.',
                ]);
            }

            $changes = array_map(fn (array $item): array => [
                'exam_result_id' => $item['exam_result_id'],
                'marks' => $item['after']['marks'],
                'is_absent' => $item['after']['is_absent'],
            ], $proposal);

            $validated = app(AssessmentCorrectionProposalService::class)
                ->normalize($schoolId, $request->exam_id, $changes);

            if (! hash_equals($request->proposal_sha256, $archive->fingerprint($validated))) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'The approved correction no longer matches its source.',
                ]);
            }

            $archive->preserveOriginals($schoolId, $request->exam_id);
            $calculated = app(AssessmentReissueCalculationService::class)
                ->calculate($schoolId, $request->exam_id, $changes, $actor->id);
            $reports = app(AssessmentReissueReportAssembler::class)
                ->assemble($schoolId, $request->exam_id, $calculated);

            $count = 0;
            foreach ([
                'paper_result' => 'paper_results',
                'learning_area_result' => 'learning_area_results',
                'merit_list' => 'merit_lists',
            ] as $type => $key) {
                foreach ($calculated[$key] as $row) {
                    $this->store($request, $actor, $type, [
                        'schema_version' => 1,
                        'output' => $row,
                    ]);
                    $count++;
                }
            }

            foreach ($reports as $payload) {
                $this->store($request, $actor, 'report_card', $payload);
                $count++;
            }

            // Calculation and assembly must not alter source data.
            if (! hash_equals(
                $request->source_sha256,
                $requests->sourceFingerprint($schoolId, $request->exam_id)
            )) {
                throw new \LogicException('Draft preparation changed exam source data.');
            }

            $fingerprint = $this->draftFingerprint($schoolId, $requestId);
            DB::table('assessment_reissue_requests')->where('id', $requestId)->update([
                'status' => 'prepared',
                'prepared_sha256' => $fingerprint,
                'updated_at' => now(),
            ]);

            DB::table('assessment_reissue_events')->insert([
                'id' => (string) Str::uuid(),
                'request_id' => $requestId,
                'school_id' => $schoolId,
                'actor_id' => $actor->id,
                'action' => 'prepared',
                'reason' => $reason,
                'details' => json_encode([
                    'draft_count' => $count,
                    'prepared_sha256' => $fingerprint,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return ['draft_count' => $count, 'prepared_sha256' => $fingerprint];
        });
    }

    public function draftFingerprint(string $schoolId, string $requestId): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Draft verification requires a transaction.');
        }

        $rows = DB::table('assessment_reissue_drafts')
            ->where('school_id', $schoolId)->where('request_id', $requestId)
            ->orderBy('output_type')->orderBy('output_id')
            ->lockForUpdate()->get();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'reissue_request' => 'No prepared revision drafts were found.',
            ]);
        }

        $archive = app(AssessmentPublicationArchiveService::class);
        $manifest = [];

        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            if (! hash_equals($row->payload_sha256, $archive->fingerprint($payload))) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'A prepared revision payload has changed.',
                ]);
            }

            $manifest[] = [
                'id' => $row->id,
                'request_id' => $row->request_id,
                'school_id' => $row->school_id,
                'exam_id' => $row->exam_id,
                'learner_id' => $row->learner_id,
                'output_type' => $row->output_type,
                'output_id' => $row->output_id,
                'revision_number' => $row->revision_number === null
                    ? null : (int) $row->revision_number,
                'source_sha256' => $row->source_sha256,
                'payload_sha256' => $row->payload_sha256,
                'prepared_by' => $row->prepared_by,
            ];
        }

        return $archive->fingerprint($manifest);
    }

    private function store(object $request, User $actor, string $type, array $payload): void
    {
        $tables = [
            'paper_result' => 'exam_results',
            'learning_area_result' => 'learning_area_results',
            'merit_list' => 'merit_lists',
            'report_card' => 'report_cards',
        ];
        $row = $payload['output'];
        $query = DB::table($tables[$type])->where('id', $row['id'])
            ->where('exam_id', $request->exam_id);
        if ($type !== 'paper_result') {
            $query->where('school_id', $request->school_id);
        }

        $stored = $query->lockForUpdate()->firstOrFail();
        $original = ['schema_version' => 1, 'output' => (array) $stored];

        if ($type === 'report_card') {
            $original['learning_areas'] = DB::table('report_card_learning_areas')
                ->where('report_card_id', $stored->id)->orderBy('id')
                ->get()->map(fn ($detail): array => (array) $detail)->all();
        }

        $revision = null;
        if (in_array($type, ['merit_list', 'report_card'], true)) {
            $latest = DB::table('assessment_publication_versions')
                ->where('school_id', $request->school_id)
                ->where('output_type', $type)->where('output_id', $stored->id)
                ->max('revision_number');
            $revision = (int) ($latest ?? 0) + 1;
        }

        $archive = app(AssessmentPublicationArchiveService::class);
        DB::table('assessment_reissue_drafts')->insert([
            'id' => (string) Str::uuid(),
            'request_id' => $request->id,
            'school_id' => $request->school_id,
            'exam_id' => $request->exam_id,
            'learner_id' => $stored->learner_id,
            'output_type' => $type,
            'output_id' => $stored->id,
            'revision_number' => $revision,
            'source_sha256' => $archive->fingerprint($original),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_sha256' => $archive->fingerprint($payload),
            'prepared_by' => $actor->id,
            'created_at' => now(),
        ]);
    }
}
